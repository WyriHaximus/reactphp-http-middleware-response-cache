<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Http\Middleware;

use DateTimeImmutable;
use Lcobucci\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Io\MiddlewareRunner;
use React\Http\Io\ServerRequest;
use React\Http\Response;
use React\Stream\ThroughStream;
use WyriHaximus\React\Http\Middleware\ResponseCacheMiddleware;
use WyriHaximus\React\Http\Middleware\Session;
use WyriHaximus\React\Http\Middleware\SessionMiddleware;
use function React\Promise\reject;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;

final class ResponseCacheMiddlewareTest extends TestCase
{
    public function testWithHeaders()
    {
        $sessionCache = new ArrayCache();
        $thenCalledCount = 0;
        $cache = $this->prophesize(CacheInterface::class);
        $cache->get('/')->shouldBeCalled()->willReturn(resolve('{"code":200,"headers":{"foo":"bar"},"body":"' . md5('/') . '","time":' . $now . '}'));
        $cache->get('/no.cache')->shouldBeCalled()->willReturn(reject());
        $cache->set('/no.cache', '{"body":"' . md5('/no.cache') . '","headers":{"foo":"bar"},"code":200,"time":' . $now . '}')->shouldBeCalled();
        $cache->get('/stream')->shouldBeCalled()->willReturn(reject());
        $cache->set('/stream', $this->any())->shouldNotBeCalled();
        $cache->get('/wildcard/blaat')->shouldBeCalled()->willReturn(reject());
        $cache->set('/wildcard/blaat', '{"body":"' . md5('/wildcard/blaat') . '","headers":{"foo":"bar"},"code":200,"time":' . $now . '}')->shouldBeCalled();
        $cache->get('/api/blaat?q=q')->shouldBeCalled()->willReturn(reject());
        $cache->set('/api/blaat?q=q', '{"body":"' . md5('/api/blaat') . '","headers":{"foo":"bar"},"code":200}')->shouldBeCalled();
        $sessionMiddleware = new SessionMiddleware(
            'Thrall',
            $sessionCache
        );
        $middleware = new ResponseCacheMiddleware(
            [
                '/',
                '/no.cache',
                '/stream',
                '/wildcard***',
                '/api???',
            ],
            [
                'foo',
            ],
            $cache->reveal()
        );
        $cache->set('/api/blaat?q=q', '{"body":"' . md5('/api/blaat') . '","headers":{"foo":"bar"},"code":200,"time":' . $now . '}')->shouldBeCalled();
        $middleware = new ResponseCacheMiddleware([
            '/',
            '/no.cache',
            '/stream',
            '/wildcard***',
            '/api???',
        ], ['foo'], $cache->reveal(), $clock);
        $next = function (ServerRequestInterface $request) {
            return new Response(200, ['foo' => 'bar', 'bar' => 'foo'], stream_for(md5($request->getUri()->getPath())));
        };

        resolve((new MiddlewareRunner([$sessionMiddleware, $middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($response->hasHeader('foo'));
            self::assertSame('bar', $response->getHeaderLine('foo'));
            self::assertSame(md5('/'), (string)$response->getBody());
            self::assertSame('0', $response->getHeaderLine('Age'));
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$sessionMiddleware, $middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/no.cache')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(md5('/no.cache'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([
            $sessionMiddleware,
            $middleware,
            function (ServerRequestInterface $request) {
                $stream = new HttpBodyStream(new ThroughStream(), 1024);

                return new Response(200, [], $stream);
            },
        ]))(
            new ServerRequest('GET', 'https://example.com/stream')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$sessionMiddleware, $middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/wildcard/blaat?q=q')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(md5('/wildcard/blaat'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$sessionMiddleware, $middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/api/blaat?q=q')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(md5('/api/blaat'), (string)$response->getBody());
            $thenCalledCount++;
        });

        /** @var string $sessionId */
        $sessionId = null;
        resolve((new MiddlewareRunner([
            $sessionMiddleware,
            $middleware,
            function (ServerRequestInterface $request) use (&$sessionId) {
                /** @var Session $session */
                $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME);
                $session->begin();
                $session->setContents(['beer' => 'All the stouts!']);
                $sessionId = $session->getId();

                return new Response(200, [], stream_for('craft-session'));
            },
        ]))(
            new ServerRequest('GET', 'https://example.com/craft-session')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('craft-session', (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([
            $sessionMiddleware,
            $middleware,
            function (ServerRequestInterface $request) {
                return new Response(200, [], stream_for('no-cache'));
            },
        ]))(
            (new ServerRequest('GET', 'https://example.com/'))->withCookieParams(['Thrall' => $sessionId])
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('no-cache', (string)$response->getBody());
            $thenCalledCount++;
        });

        self::assertSame(7, $thenCalledCount);
        sleep(1);
        $clock->setTo(new DateTimeImmutable('now'));

        resolve($middleware(
            new ServerRequest('GET', 'https://example.com/'),
            $next
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('Age'));
            $thenCalledCount++;
        });

        self::assertSame(6, $thenCalledCount);
    }
}
