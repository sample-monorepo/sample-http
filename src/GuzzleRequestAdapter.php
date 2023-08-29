<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http;


use DateInterval;
use DateTime;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use InvalidArgumentException;
use League\Uri\Contracts\UriException;
use Microsoft\Kiota\Abstractions\ApiClientBuilder;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\Enum;
use Microsoft\Kiota\Abstractions\RequestAdapter;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\Serialization\Parsable;
use Microsoft\Kiota\Abstractions\Serialization\ParseNode;
use Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactory;
use Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactoryRegistry;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactory;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactoryRegistry;
use Microsoft\Kiota\Abstractions\Store\BackingStoreFactory;
use Microsoft\Kiota\Abstractions\Store\BackingStoreFactorySingleton;
use Microsoft\Kiota\Abstractions\Types\Date;
use Microsoft\Kiota\Abstractions\Types\Time;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use Microsoft\Kiota\Http\Middleware\Options\ParametersDecodingOption;
use Microsoft\Kiota\Http\Middleware\Options\ResponseHandlerOption;
use Microsoft\Kiota\Http\Middleware\ParametersNameDecodingHandler;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Class GuzzleRequestAdapter
 * @package Microsoft\Kiota\Http
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class GuzzleRequestAdapter implements RequestAdapter
{
    /**
     * @var ClientInterface
     */
    private ClientInterface $guzzleClient;

    /**
     * @var AuthenticationProvider
     */
    private AuthenticationProvider $authenticationProvider;
    private TracerInterface $tracer;

    /**
     * @var ParseNodeFactory|ParseNodeFactoryRegistry
     */
    private ParseNodeFactory $parseNodeFactory;

    /**
     * @var SerializationWriterFactory|SerializationWriterFactoryRegistry
     */
    private SerializationWriterFactory $serializationWriterFactory;

    private string $baseUrl = '';

    private static string $wwwAuthenticateHeader = 'WWW-Authenticate';

    private static string $claimsRegex = "/claims=\"(.+)\"/";
    private ObservabilityOption $observabilityOptions;

    /**
     * @param AuthenticationProvider $authenticationProvider
     * @param ParseNodeFactory|null $parseNodeFactory
     * @param SerializationWriterFactory|null $serializationWriterFactory
     * @param ClientInterface|null $guzzleClient
     * @param ObservabilityOption|null $observabilityOption
     */
    public function __construct(AuthenticationProvider $authenticationProvider,
                                ?ParseNodeFactory $parseNodeFactory = null,
                                ?SerializationWriterFactory $serializationWriterFactory = null,
                                ?ClientInterface $guzzleClient = null,
                                ?ObservabilityOption $observabilityOption = null
    )
    {
        $this->authenticationProvider = $authenticationProvider;
        $this->parseNodeFactory = ($parseNodeFactory) ?: ParseNodeFactoryRegistry::getDefaultInstance();
        $this->serializationWriterFactory = ($serializationWriterFactory) ?: SerializationWriterFactoryRegistry::getDefaultInstance();
        $this->guzzleClient = ($guzzleClient) ?: KiotaClientFactory::create();
        $this->observabilityOptions= $observabilityOption ?? new ObservabilityOption(true);
        $this->tracer = $this->observabilityOptions->getTracer();
    }

    /**
     * @param RequestInformation $requestInfo
     * @param ResponseInterface $result
     * @param array<string,array{string,string}>|null $errorMappings
     * @return Promise|null
     */
    private function tryHandleResponse(RequestInformation $requestInfo, ResponseInterface $result, ?array $errorMappings = null): ?Promise
    {
        $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
        if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
            $responseHandler = $responseHandlerOption->getResponseHandler();
            /** @phpstan-ignore-next-line False alarm?*/
            return $responseHandler->handleResponseAsync($result, $errorMappings);
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function sendAsync(RequestInformation $requestInfo, array $targetCallable, ?array $errorMappings = null): Promise
    {
        $span = $this->startTracingSpan($requestInfo, 'sendAsync');
        $scope = $span->activate();
        $currentContext = $span->storeInContext(Context::getCurrent());
        try {

            $responseMessage = $this->getHttpResponseMessage($requestInfo, '', $span);
            $finalResponse   = $responseMessage->then(
                function (ResponseInterface $result) use ($targetCallable, $requestInfo, $errorMappings, &$span) {
                    $response = $this->tryHandleResponse($requestInfo, $result, $errorMappings);
                    if ($response !== null) {
                        $span->addEvent(self::EVENT_RESPONSE_HANDLER_INVOKED_KEY);
                        return $response;
                    }

                    $this->throwFailedResponse($result, $errorMappings, $span);
                    $span->setStatus(StatusCode::STATUS_OK, 'response_handle_success');
                    if ($this->is204NoContentResponse($result)) {
                        return null;
                    }
                    $rootNode          = $this->getRootParseNode($result, $span);
                    $this->setResponseType($targetCallable[0], $span);
                    return $rootNode->getObjectValue($targetCallable);
                }
            );
            $span->setStatus(StatusCode::STATUS_OK, 'sendAsync() success');
        } finally {
            $scope->detach();
            $span->end();
        }
        return $finalResponse;
    }

    /**
     * @inheritDoc
     */
    public function getSerializationWriterFactory(): SerializationWriterFactory
    {
        return $this->serializationWriterFactory;
    }

    /**
     * @inheritDoc
     */
    public function getParseNodeFactory(): ParseNodeFactory
    {
        return $this->parseNodeFactory;
    }

    public const EVENT_RESPONSE_HANDLER_INVOKED_KEY = 'com.microsoft.kiota.response_handler_invoked';
    /**
     * @inheritDoc
     */
    public function sendCollectionAsync(RequestInformation $requestInfo, array $targetCallable, ?array $errorMappings = null): Promise
    {
        $span = self::startTracingSpan($requestInfo, 'sendCollectionAsync');
        $scope = $span->activate();
        try {
            $finalResponse = $this->getHttpResponseMessage($requestInfo)->then(
                function (ResponseInterface $result) use ($targetCallable, $requestInfo, $errorMappings, $span) {
                    $response = $this->tryHandleResponse($requestInfo, $result, $errorMappings);

                    if ($response !== null) {
                        $span->addEvent(self::EVENT_RESPONSE_HANDLER_INVOKED_KEY);
                        return $result;
                    }
                    $this->throwFailedResponse($result, $errorMappings, $span);
                    if ($this->is204NoContentResponse($result)) {
                        return new FulfilledPromise(null);
                    }
                    $rootNode               = $this->getRootParseNode($result, $span);
                    $spanForDeserialization = $this->tracer->spanBuilder('ParseNode.getCollectionOfObjectValues')
                        ->addLink($span->getContext())
                        ->startSpan();
                    $this->setResponseType($targetCallable[0], $spanForDeserialization);
                    $spanForDeserialization->end();
                    return $rootNode->getCollectionOfObjectValues($targetCallable);
                }
            );
        } finally {
            $scope->detach();
            $span->end();
        }
        return $finalResponse;
    }

    /**
     * @inheritDoc
     */
    public function sendPrimitiveAsync(RequestInformation $requestInfo, string $primitiveType, ?array $errorMappings = null): Promise
    {
        $span = $this->startTracingSpan($requestInfo, 'sendPrimitiveAsync');
        $scope = $span->activate();
        try {
            $finalResponse = $this->getHttpResponseMessage($requestInfo)->then(
                function (ResponseInterface $result) use ($primitiveType, $requestInfo, $errorMappings, &$span) {
                    $response = $this->tryHandleResponse($requestInfo, $result, $errorMappings);

                    if ($response !== null) {
                        return $result;
                    }
                    $this->throwFailedResponse($result, $errorMappings, $span);
                    if ($this->is204NoContentResponse($result)) {
                        return null;
                    }
                    if ($primitiveType === StreamInterface::class) {
                        return $result->getBody();
                    }
                    $rootParseNode = $this->getRootParseNode($result, $span);
                    if (is_subclass_of($primitiveType, Enum::class)) {
                        return $rootParseNode->getEnumValue($primitiveType);
                    }
                    switch ($primitiveType) {
                        case 'int':
                        case 'long':
                            return $rootParseNode->getIntegerValue();
                        case 'float':
                            return $rootParseNode->getFloatValue();
                        case 'bool':
                            return $rootParseNode->getBooleanValue();
                        case 'string':
                            return $rootParseNode->getStringValue();
                        case DateTime::class:
                            return $rootParseNode->getDateTimeValue();
                        case DateInterval::class:
                            return $rootParseNode->getDateIntervalValue();
                        case Date::class:
                            return $rootParseNode->getDateValue();
                        case Time::class:
                            return $rootParseNode->getTimeValue();
                        default:
                            throw new InvalidArgumentException("Unsupported primitive type $primitiveType");
                    }
                }
            );
        } finally {
            $scope->detach();
            $span->end();
        }
        return $finalResponse;
    }

    /**
     * @inheritDoc
     */
    public function sendPrimitiveCollectionAsync(RequestInformation $requestInfo, string $primitiveType, ?array $errorMappings = null): Promise
    {
        $span = $this->startTracingSpan($requestInfo, 'sendPrimitiveCollectionAsync');
        $scope = $span->activate();
        try {
            $finalResponse = $this->getHttpResponseMessage($requestInfo)->then(
                function (ResponseInterface $result) use ($primitiveType, $requestInfo, $errorMappings, &$span) {
                    $response = $this->tryHandleResponse($requestInfo, $result, $errorMappings);

                    if ($response !== null) {
                        return $result;
                    }
                    $this->throwFailedResponse($result, $errorMappings, $span);
                    if ($this->is204NoContentResponse($result)) {
                        return null;
                    }
                    return $this->getRootParseNode($result, $span)->getCollectionOfPrimitiveValues($primitiveType);
                }
            );
        } finally {
            $scope->detach();
            $span->end();
        }
        return $finalResponse;
    }

    /**
     * @inheritDoc
     */
    public function sendNoContentAsync(RequestInformation $requestInfo, ?array $errorMappings = null): Promise
    {
        $span = $this->startTracingSpan($requestInfo, 'sendNoContentAsync');
        $scope = $span->activate();
        try {
            $finalResponse = $this->getHttpResponseMessage($requestInfo)->then(
                function (ResponseInterface $result) use ($requestInfo, $errorMappings, &$span) {
                    $response = $this->tryHandleResponse($requestInfo, $result, $errorMappings);

                    if ($response !== null) {
                        return $result;
                    }
                    $this->throwFailedResponse($result, $errorMappings, $span);
                    return null;
                }
            );
        } finally {
            $scope->detach();
            $span->end();
        }
        return $finalResponse;
    }

    /**
     * @inheritDoc
     */
    public function enableBackingStore(BackingStoreFactory $backingStoreFactory): void
    {
        $this->parseNodeFactory = ApiClientBuilder::enableBackingStoreForParseNodeFactory($this->parseNodeFactory);
        $this->serializationWriterFactory = ApiClientBuilder::enableBackingStoreForSerializationWriterFactory($this->serializationWriterFactory);
        BackingStoreFactorySingleton::setInstance($backingStoreFactory);
    }

    /**
     * @inheritDoc
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @inheritDoc
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Create and returns a PSR 7 Request object from {@link RequestInformation}
     *
     * @param RequestInformation $requestInformation
     * @param SpanInterface|null $span
     * @return RequestInterface
     * @throws UriException
     */
    public function getPsrRequestFromRequestInformation(RequestInformation $requestInformation, ?SpanInterface $span = null): RequestInterface
    {
        $span = $span ?? $this->tracer->spanBuilder('getHttpResponseMessage')
            ->startSpan();
        $currentContext = Context::getCurrent();
        $convertorSpan = $this->tracer->spanBuilder('getPsrRequestFromRequestInformation')
            ->setParent($currentContext)
            ->addLink($span->getContext())
            ->startSpan();
        $scope = $convertorSpan->activate();
        try {
            $requestInformation->pathParameters["baseurl"] = $this->getBaseUrl();
            $span->setAttribute('http.method', $requestInformation->httpMethod);
            $span->setAttribute('http.scheme', explode(':', $requestInformation->getUri())[0]);
            if ($this->observabilityOptions->getIncludeEUIIAttributes()) {
                $span->setAttribute('http.uri', $requestInformation->getUri());
            }
            if (!empty($requestInformation->content)) {
                $span->setAttribute('http.request_content_length', $requestInformation->content->getSize());
            }

            $result = new Request(
                $requestInformation->httpMethod,
                $requestInformation->getUri(),
                $requestInformation->getHeaders()->getAll(),
                $requestInformation->content
            );
        } finally {
            $scope->detach();
            $convertorSpan->end();
        }
        return $result;
    }

    /**
     * Converts RequestInformation object to an authenticated(containing auth header) PSR-7 Request Object.
     *
     * @param RequestInformation $requestInformation
     * @param SpanInterface|null $span
     * @return Promise
     */
    public function convertToNative(RequestInformation $requestInformation, ?SpanInterface $span = null): Promise
    {
        $currentContext = Context::getCurrent();
        $thisSpan = $this->tracer->spanBuilder('convertToNative');
        if ($span !== null) {
            $thisSpan = $thisSpan->setParent($span->storeInContext($currentContext));
        }
        $thisSpan = $thisSpan->startSpan();
        try {
            $result = $this->authenticationProvider->authenticateRequest($requestInformation)->then(
                fn(RequestInformation $authenticatedRequest): RequestInterface => $this->getPsrRequestFromRequestInformation($authenticatedRequest, $span)
            );
        } finally {
            $thisSpan->end();
            if ($span !== null) $span->end();
        }
        return $result;
    }

    /**
     * Gets the root parse node using the parseNodeFactory based on the Content-Type
     *
     * @param ResponseInterface $response
     * @param SpanInterface $span
     * @return ParseNode
     */
    private function getRootParseNode(ResponseInterface $response, SpanInterface $span): ParseNode
    {
        $serializationSpan = $this->tracer->spanBuilder('ParseNode::getObjectValue')
            ->addLink($span->getContext())
            ->startSpan();
        $scope = $serializationSpan->activate();
        try {
            if (!$response->hasHeader(RequestInformation::$contentTypeHeader)) {
                throw new RuntimeException("No response content type header for deserialization");
            }
            $contentType = explode(';', $response->getHeaderLine(RequestInformation::$contentTypeHeader));

            $result = $this->parseNodeFactory->getRootParseNode($contentType[0], $response->getBody());
            $serializationSpan->setStatus(StatusCode::STATUS_OK, 'deserialize_success');
            return $result;
        } finally {
            $scope->detach();
            $serializationSpan->end();
        }
    }

    /**
     * Authenticates and executes the request
     *
     * @param RequestInformation $requestInformation
     * @param string $claims additional claims to request if CAE fails
     * @param SpanInterface|null $span
     * @return Promise
     */
    private function getHttpResponseMessage(RequestInformation $requestInformation, string $claims = '', ?SpanInterface $span = null): Promise
    {
        $httpResponseSpan = $this->tracer->spanBuilder('getHttpResponseMessage');
        if ($span !== null) {
            $httpResponseSpan = $httpResponseSpan->addLink($span->getContext())->setParent($span->storeInContext(Context::getCurrent()));
        }
        $httpResponseSpan = $httpResponseSpan->startSpan();
        $scope = $httpResponseSpan->activate();
        try {
            $requestInformation->pathParameters['baseurl'] = $this->getBaseUrl();
            $additionalAuthContext                         = $claims ? ['claims' => $claims] : [];
            $request                                       = $this->authenticationProvider->authenticateRequest($requestInformation, $additionalAuthContext);
            $finalResult                                   = $request->then(
                function () use ($requestInformation, &$httpResponseSpan) {
                    $psrRequest = $this->getPsrRequestFromRequestInformation($requestInformation, $httpResponseSpan);
                    $httpResponseSpan->setStatus(StatusCode::STATUS_OK, 'Request Information Success');
                    return $this->guzzleClient->send($psrRequest, $requestInformation->getRequestOptions());
                }
            )->then(
                function (ResponseInterface $response) use ($requestInformation, $claims, &$httpResponseSpan) {
                    return $this->retryCAEResponseIfRequired($response, $requestInformation, $claims, $httpResponseSpan);
                }
            );
        } finally {
            $scope->detach();
            $httpResponseSpan->end();
        }
        return $finalResult;
    }

    /**
     * @param ResponseInterface $response
     * @param RequestInformation $requestInformation
     * @param string $claims
     * @param SpanInterface $parentSpan
     * @return ResponseInterface
     * @throws Exception
     */
    private function retryCAEResponseIfRequired(
        ResponseInterface  $response,
        RequestInformation $requestInformation,
        string             $claims,
        SpanInterface      $parentSpan
    ): ResponseInterface
    {
        if ($response->getStatusCode() == 401
            && !$claims // fail if previous claims exist. Means request has already been retried before.
            && $response->getHeader(self::$wwwAuthenticateHeader)
        ) {
            $span = $this->tracer->spanBuilder('retryCAEResponseIfRequired')
                ->setParent(Context::getCurrent())
                ->addLink($parentSpan->getContext())
                ->startSpan();
            try {
                if (!is_null($requestInformation->content)) {
                    if (!$requestInformation->content->isSeekable()) {
                        return $response;
                    }
                    $requestInformation->content->rewind();
                }
                $wwwAuthHeader = $response->getHeaderLine(self::$wwwAuthenticateHeader);
                $matches       = [];
                if (!preg_match(self::$claimsRegex, $wwwAuthHeader, $matches)) {
                    return $response;
                }
                $claims = $matches[1];
                /** @var ResponseInterface $response */
                $response = $this->getHttpResponseMessage($requestInformation, $claims)->wait();
            } finally {
                $span->end();
            }
        }
        return $response;
    }

    public const ERROR_BODY_FOUND_ATTRIBUTE_NAME = "com.microsoft.kiota.error.body_found";
    public const ERROR_MAPPING_FOUND_ATTRIBUTE_NAME = "com.microsoft.kiota.error.mapping_found";

    /**
     * @template T of Parsable
     * @param ResponseInterface $response
     * @param array<string, array{class-string<T>, string}>|null $errorMappings
     * @param SpanInterface $span
     * @throws ApiException
     */
    private function throwFailedResponse(ResponseInterface $response, ?array $errorMappings, SpanInterface $span): void {
        $currentContext = Context::getCurrent();
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 400) {
            return;
        }
        $errorSpan = $this->tracer->spanBuilder('throwFailedResponse')
            ->setParent($currentContext)
            ->startSpan();
        $scope = $errorSpan->activate();
        try {
            $responseBodyContents = $response->getBody()->getContents();

            $errorSpan->setStatus(StatusCode::STATUS_ERROR, 'received_error_response');
            $statusCodeAsString = "$statusCode";
            if ($errorMappings === null || (!array_key_exists($statusCodeAsString, $errorMappings) &&
                    !($statusCode >= 400 && $statusCode < 500 && isset($errorMappings['4XX'])) &&
                    !($statusCode >= 500 && $statusCode < 600 && isset($errorMappings["5XX"])))) {
                $span->setAttribute(self::ERROR_BODY_FOUND_ATTRIBUTE_NAME, false);
                $ex = new ApiException("the server returned an unexpected status code and no error class is registered for this code $statusCode $responseBodyContents.");
                $ex->setResponseStatusCode($response->getStatusCode());
                $ex->setResponseHeaders($response->getHeaders());
                $errorSpan->recordException($ex, ['message' => $responseBodyContents, 'know_error' => false]);
                throw $ex;
            }
            $span->setAttribute(self::ERROR_MAPPING_FOUND_ATTRIBUTE_NAME, true);
            $errorClass = array_key_exists($statusCodeAsString, $errorMappings) ? $errorMappings[$statusCodeAsString] : ($errorMappings[$statusCodeAsString[0] . 'XX'] ?? null);

            $rootParseNode = $this->getRootParseNode($response, $errorSpan);
            if ($errorClass !== null) {
                $spanForDeserialization = $this->tracer->spanBuilder('ParseNode.GetObjectValue()')
                    ->setParent(Context::getCurrent())
                    ->addLink($errorSpan->getContext())
                    ->startSpan();
                $error                  = $rootParseNode->getObjectValue($errorClass);
                $this->setResponseType($errorClass[0], $spanForDeserialization);
                $spanForDeserialization->end();

            } else {
                $error = null;
            }

            if ($error && is_subclass_of($error, ApiException::class)) {
                $span->setAttribute(self::ERROR_BODY_FOUND_ATTRIBUTE_NAME, true);
                $error->setResponseStatusCode($response->getStatusCode());
                $error->setResponseHeaders($response->getHeaders());
                $errorSpan->recordException($error, ['know_error' => true, 'message' => $responseBodyContents]);
                throw $error;
            }
            $otherwise = new ApiException("Unsupported error type " . get_debug_type($error));
            $otherwise->setResponseStatusCode($response->getStatusCode());
            $otherwise->setResponseHeaders($response->getHeaders());
            $errorSpan->recordException($otherwise, ['known_error' => false, 'message' => $responseBodyContents]);
            throw $otherwise;
        } finally {
            $scope->detach();
            $errorSpan->end();
            $span->end();
        }
    }

    /**
     * @param ResponseInterface $response
     * @return bool
     */
    private function is204NoContentResponse(ResponseInterface $response): bool
    {
        return $response->getStatusCode() === 204;
    }
    private const QUERY_PARAMETERS_CLEANUP_REGEX = "/\{+?[^}]+}/";

    private function startTracingSpan(RequestInformation $requestInfo, string $methodName): SpanInterface
    {
        $queryReg = self::QUERY_PARAMETERS_CLEANUP_REGEX;
        $parametersToDecode = (new ParametersDecodingOption())->getParametersToDecode();
        $decUriTemplate = ParametersNameDecodingHandler::decodeUriEncodedString($requestInfo->urlTemplate, $parametersToDecode);
        $telemetryPathValue = empty($decUriTemplate) ? '' : preg_replace($queryReg, '', $decUriTemplate);
        $span = $this->tracer->spanBuilder("$methodName - $telemetryPathValue")->startSpan();
        $span->setAttribute("http.uri_template", $decUriTemplate);
        return $span;
    }

    /**
     * @param string|null $typeName
     * @param SpanInterface $span
     * @return void
     */
    private function setResponseType(?string $typeName, SpanInterface $span): void
    {
        if ($typeName !== null) {
            $span->setAttribute('microsoft.kiota.response.type', $typeName);
        }
    }

    /**
     * @param TracerInterface $tracer
     * @return void
     */
    public function setTracer(TracerInterface $tracer): void
    {
        $this->tracer = $tracer;
    }
}
