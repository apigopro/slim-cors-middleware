<?php

declare(strict_types=1);

namespace SlimCors\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use SlimCors\CorsMiddleware;

final class CorsMiddlewareTest extends TestCase
{
    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse(200);
                $response->getBody()->write('ok');
                return $response;
            }
        };
    }

    public function testNoOriginHeaderPassesThroughUntouched(): void
    {
        $cors = new CorsMiddleware(['origin' => ['https://app.example.com']]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://api.example.com/data');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAllowedOriginGetsCorsHeaders(): void
    {
        $cors = new CorsMiddleware(['origin' => ['https://app.example.com']]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testDisallowedOriginIsRejected(): void
    {
        $cors = new CorsMiddleware(['origin' => ['https://app.example.com']]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://evil.example.net');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testWildcardOriginWithoutCredentialsReflectsLiteralAsterisk(): void
    {
        $cors = new CorsMiddleware(['origin' => ['*']]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://anything.example.org');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginWithCredentialsReflectsActualOrigin(): void
    {
        $cors = new CorsMiddleware(['origin' => ['*'], 'credentials' => true]);
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://anything.example.org');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame('https://anything.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testSubdomainWildcardPattern(): void
    {
        $cors = new CorsMiddleware(['origin' => ['https://*.example.com']]);

        $matching = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://foo.example.com');
        $response = $cors->process($matching, $this->okHandler());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://foo.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));

        $nonMatching = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://foo.evil.com');
        $response = $cors->process($nonMatching, $this->okHandler());
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testPreflightRequestShortCircuitsBeforeReachingHandler(): void
    {
        $reached = false;
        $handler = new class ($reached) implements RequestHandlerInterface {
            public function __construct(private bool &$reached)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $cors = new CorsMiddleware([
            'origin' => ['https://app.example.com'],
            'methods' => ['GET', 'POST', 'DELETE'],
            'cache' => 3600,
        ]);

        $preflight = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'DELETE')
            ->withHeader('Access-Control-Request-Headers', 'X-Custom-Header, Authorization');

        $response = $cors->process($preflight, $handler);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($reached);
        $this->assertSame('GET, POST, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('X-Custom-Header, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('3600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testPreflightWithConfiguredAllowedHeadersIgnoresRequestedHeaders(): void
    {
        $cors = new CorsMiddleware([
            'origin' => ['https://app.example.com'],
            'headers.allow' => ['Authorization', 'Content-Type'],
        ]);

        $preflight = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'X-Something-Else');

        $response = $cors->process($preflight, $this->okHandler());

        $this->assertSame('Authorization, Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testPlainOptionsWithoutRequestMethodIsNotTreatedAsPreflight(): void
    {
        $reached = false;
        $handler = new class ($reached) implements RequestHandlerInterface {
            public function __construct(private bool &$reached)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        $cors = new CorsMiddleware(['origin' => ['https://app.example.com']]);
        $plainOptions = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://app.example.com');

        $cors->process($plainOptions, $handler);

        $this->assertTrue($reached);
    }

    public function testExposeHeadersOptionSetsExposeHeader(): void
    {
        $cors = new CorsMiddleware([
            'origin' => ['https://app.example.com'],
            'headers.expose' => ['X-Request-Id', 'X-RateLimit-Remaining'],
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://app.example.com');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame('X-Request-Id, X-RateLimit-Remaining', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function testCustomErrorCallbackOverridesDefaultResponse(): void
    {
        $cors = new CorsMiddleware([
            'origin' => ['https://app.example.com'],
            'error' => function ($request, $response, array $arguments) {
                $response->getBody()->write('blocked:' . $arguments['origin']);
                return $response->withStatus(403);
            },
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://api.example.com/data')
            ->withHeader('Origin', 'https://evil.example.net');

        $response = $cors->process($request, $this->okHandler());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked:https://evil.example.net', (string) $response->getBody());
    }
}
