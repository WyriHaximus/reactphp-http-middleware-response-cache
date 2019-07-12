<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Http\Middleware;

use DateTimeImmutable;
use Lcobucci\Clock\FrozenClock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Io\MiddlewareRunner;
use React\Http\Io\ServerRequest;
use React\Http\Response;
use function React\Promise\resolve;
use React\Stream\ThroughStream;
use function RingCentral\Psr7\stream_for;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Http\Middleware\CacheConfiguration;
use WyriHaximus\React\Http\Middleware\ResponseCacheMiddleware;

/**
 * @internal
 */
final class ResponseCacheMiddlewareTest extends AsyncTestCase
{
    public function testBasics(): void
    {
        $sessionCache = new ArrayCache();
        $thenCalledCount = 0;
        $time = new DateTimeImmutable('now');
        $clock = new FrozenClock($time);
        $now = (int)$clock->now()->format('U');
        $cache = $this->prophesize(CacheInterface::class);
        $cache->get('/')->shouldBeCalled()->willReturn(resolve(msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => [
                'foo' => 'bar',
            ],
            'body' => \md5('/'),
        ])));
        $cache->get('/no.cache')->shouldBeCalled()->willReturn(resolve());
        $cache->set('/no.cache', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => [
                'foo' => 'bar',
            ],
            'body' => \md5('/no.cache'),
        ]), null)->shouldBeCalled();
        $cache->get('/stream')->shouldBeCalled()->willReturn(resolve());
        $cache->set('/stream', $this->any())->shouldNotBeCalled();
        $cache->get('/wildcard/blaat')->shouldBeCalled()->willReturn(resolve());
        $cache->set('/wildcard/blaat', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => [
                'foo' => 'bar',
            ],
            'body' => \md5('/wildcard/blaat'),
        ]), null)->shouldBeCalled();
        $cache->get('/api/blaat?q=q')->shouldBeCalled()->willReturn(resolve());
        $cache->set('/api/blaat?q=q', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => [
                'foo' => 'bar',
            ],
            'body' => \md5('/api/blaat'),
        ]), null)->shouldBeCalled();
        $middleware = new ResponseCacheMiddleware(new CacheConfiguration([
            '/',
            '/no.cache',
            '/stream',
            '/wildcard***',
            '/api???',
        ], ['foo'], $clock), $cache->reveal());
        $next = function (ServerRequestInterface $request) {
            return new Response(200, ['foo' => 'bar', 'bar' => 'foo'], stream_for(\md5($request->getUri()->getPath())));
        };

        resolve((new MiddlewareRunner([$middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            self::assertTrue($response->hasHeader('foo'));
            self::assertSame('bar', $response->getHeaderLine('foo'));
            self::assertSame(\md5('/'), (string)$response->getBody());
            self::assertSame('0', $response->getHeaderLine('Age'));
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/no.cache')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(\md5('/no.cache'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([
            $middleware,
            function (ServerRequestInterface $request) {
                $stream = new HttpBodyStream(new ThroughStream(), 1024);

                return new Response(200, [], $stream);
            },
        ]))(
            new ServerRequest('GET', 'https://example.com/stream')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/wildcard/blaat?q=q')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(\md5('/wildcard/blaat'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve((new MiddlewareRunner([$middleware, $next]))(
            new ServerRequest('GET', 'https://example.com/api/blaat?q=q')
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(\md5('/api/blaat'), (string)$response->getBody());
            $thenCalledCount++;
        });

        $clock->setTo($time->modify('+1 second'));

        resolve($middleware(
            new ServerRequest('GET', 'https://example.com/'),
            $next
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount): void {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('1', $response->getHeaderLine('Age'));
            $thenCalledCount++;
        });

        self::assertSame(6, $thenCalledCount);
    }
}
