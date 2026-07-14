<?php

declare(strict_types=1);

namespace SlimCors;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SlimCors\Support\ResolvesResponseFactory;

/**
 * PSR-15 CORS middleware for Slim 4, API-compatible in spirit with
 * tuupola/cors-middleware:
 *
 *   $app->add(new CorsMiddleware([
 *       'origin'         => ['https://example.com', 'https://*.example.com'],
 *       'methods'        => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
 *       'headers.allow'  => ['Authorization', 'Content-Type'],
 *       'headers.expose' => ['X-Request-Id'],
 *       'credentials'    => true,
 *       'cache'          => 86400,
 *   ]));
 *
 * Handles CORS preflight (OPTIONS + Access-Control-Request-Method) requests
 * directly, short-circuiting the rest of the middleware stack, and adds the
 * appropriate Access-Control-* headers to actual cross-origin responses.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    use ResolvesResponseFactory;

    private const array DEFAULTS = [
        'origin' => ['*'],
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'headers.allow' => [],
        'headers.expose' => [],
        'credentials' => false,
        'cache' => 0,
        'error' => null,
        'response_factory' => null,
    ];

    private readonly array $options;
    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct(array $options = [])
    {
        $this->options = array_replace(self::DEFAULTS, $options);
        $this->responseFactory = $this->resolveResponseFactory($this->options['response_factory']);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // No Origin header means this isn't a cross-origin request at all;
        // nothing for CORS to do.
        if ($origin === '') {
            return $handler->handle($request);
        }

        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if ($allowedOrigin === null) {
            return $this->respondWithError($request, "Origin '{$origin}' is not allowed.", $origin);
        }

        if ($this->isPreflight($request)) {
            return $this->preflightResponse($request, $allowedOrigin);
        }

        return $this->withCorsHeaders($handler->handle($request), $allowedOrigin);
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return strtoupper($request->getMethod()) === 'OPTIONS'
            && $request->getHeaderLine('Access-Control-Request-Method') !== '';
    }

    private function preflightResponse(ServerRequestInterface $request, string $allowedOrigin): ResponseInterface
    {
        $requestedMethod = $request->getHeaderLine('Access-Control-Request-Method');

        if (!$this->isMethodAllowed($requestedMethod)) {
            return $this->respondWithMethodNotAllowed($request, $requestedMethod);
        }

        $response = $this->withCorsHeaders($this->responseFactory->createResponse(204), $allowedOrigin);

        $response = $response->withHeader(
            'Access-Control-Allow-Methods',
            implode(', ', (array) $this->options['methods'])
        );

        $configuredHeaders = (array) $this->options['headers.allow'];
        $requestedHeaders = $request->getHeaderLine('Access-Control-Request-Headers');

        if ($configuredHeaders !== []) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $configuredHeaders));
        } elseif ($requestedHeaders !== '') {
            // No explicit allow-list configured: reflect whatever the
            // browser asked for. This mirrors tuupola's permissive default
            // and is the same default most CORS libraries use.
            $response = $response->withHeader('Access-Control-Allow-Headers', $requestedHeaders);
        }

        if ((int) $this->options['cache'] > 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->options['cache']);
        }

        return $response;
    }

    private function isMethodAllowed(string $method): bool
    {
        $configured = array_map(strtoupper(...), (array) $this->options['methods']);

        return in_array('*', $configured, true) || in_array(strtoupper($method), $configured, true);
    }

    private function respondWithMethodNotAllowed(ServerRequestInterface $request, string $method): ResponseInterface
    {
        $allowed = implode(', ', (array) $this->options['methods']);
        $message = "Method '{$method}' is not allowed.";

        $response = $this->responseFactory->createResponse(405)->withHeader('Allow', $allowed);

        if (is_callable($this->options['error'])) {
            $result = ($this->options['error'])($request, $response, [
                'message' => $message,
                'method' => $method,
                'allowed_methods' => (array) $this->options['methods'],
            ]);

            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }

        $response->getBody()->write(json_encode(['error' => $message], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function withCorsHeaders(ResponseInterface $response, string $allowedOrigin): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withAddedHeader('Vary', 'Origin');

        if ($this->options['credentials'] === true) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $exposeHeaders = (array) $this->options['headers.expose'];
        if ($exposeHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposeHeaders));
        }

        return $response;
    }

    /**
     * Returns the value to send back in Access-Control-Allow-Origin, or
     * null if the origin isn't allowed at all.
     *
     * A configured "*" allows any origin. If credentials are enabled, the
     * actual request origin is reflected instead of a literal "*" — the
     * CORS spec forbids wildcard + credentials, and browsers reject it.
     */
    private function resolveAllowedOrigin(string $origin): ?string
    {
        foreach ((array) $this->options['origin'] as $pattern) {
            if ($pattern === '*') {
                return $this->options['credentials'] === true ? $origin : '*';
            }

            if ($this->matchesPattern((string) $pattern, $origin)) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * Supports exact origins and "*" wildcards, e.g. "https://*.example.com"
     * to allow any subdomain.
     */
    private function matchesPattern(string $pattern, string $origin): bool
    {
        if (!str_contains($pattern, '*')) {
            return strcasecmp($pattern, $origin) === 0;
        }

        $regexp = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#i';

        return (bool) preg_match($regexp, $origin);
    }

    private function respondWithError(ServerRequestInterface $request, string $message, string $origin): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);

        if (is_callable($this->options['error'])) {
            $result = ($this->options['error'])($request, $response, [
                'message' => $message,
                'origin' => $origin,
            ]);

            if ($result instanceof ResponseInterface) {
                return $result;
            }
        }

        $response->getBody()->write(json_encode(['error' => $message], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
