<?php declare(strict_types=1);

namespace WyriHaximus\React\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\CacheInterface;
use React\Http\HttpBodyStream;
use React\Http\Response;
use React\Http\ServerRequest;
use React\Stream\ThroughStream;
use WyriHaximus\React\Http\Middleware\ResponseCacheMiddleware;
use function React\Promise\reject;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;

final class ResponseCacheMiddlewareTest extends TestCase
{
    public function testWithHeaders()
    {
        $thenCalledCount = 0;
        $cache = $this->prophesize(CacheInterface::class);
        $cache->get('/')->shouldBeCalled()->willReturn(resolve('{"code":200,"body":"' . md5('/') . '"}'));
        $cache->get('/no.cache')->shouldBeCalled()->willReturn(reject());
        $cache->set('/no.cache', '{"body":"' . md5('/no.cache') . '","code":200}')->shouldBeCalled();
        $cache->get('/stream')->shouldBeCalled()->willReturn(reject());
        $cache->set('/stream', $this->any())->shouldNotBeCalled();
        $middleware = new ResponseCacheMiddleware([
            '/',
            '/no.cache',
            '/stream',
        ], $cache->reveal());
        $next = function (ServerRequestInterface $request) {
            return new Response(200, [], stream_for(md5($request->getUri()->getPath())));
        };

        resolve($middleware(
            new ServerRequest('GET', 'https://example.com/'),
            $next
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(md5('/'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve($middleware(
            new ServerRequest('GET', 'https://example.com/no.cache'),
            $next
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(md5('/no.cache'), (string)$response->getBody());
            $thenCalledCount++;
        });

        resolve($middleware(
            new ServerRequest('GET', 'https://example.com/stream'),
            function (ServerRequestInterface $request) {
                $stream = new HttpBodyStream(new ThroughStream(), 1024);

                return new Response(200, [], $stream);
            }
        ))->done(function (ResponseInterface $response) use (&$thenCalledCount) {
            self::assertSame(200, $response->getStatusCode());
            $thenCalledCount++;
        });

        self::assertSame(3, $thenCalledCount);
    }
}
