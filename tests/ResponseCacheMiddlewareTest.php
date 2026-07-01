<?php

declare(strict_types=1);

namespace WyriHaximus\React\Tests\Http\Middleware;

use Ancarda\Psr7\StringStream\StringStream;
use DateTimeImmutable;
use Lcobucci\Clock\FrozenClock;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Stream\ThroughStream;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Http\Middleware\CacheConfiguration;
use WyriHaximus\React\Http\Middleware\ResponseCacheMiddleware;

use function md5;
use function msgpack_pack;
use function React\Async\await;
use function React\Promise\resolve;

final class ResponseCacheMiddlewareTest extends AsyncTestCase
{
    #[Test]
    public function basics(): void
    {
        $time  = new DateTimeImmutable('now');
        $clock = new FrozenClock($time);
        $now   = (int) $clock->now()->format('U');
        /** @var CacheInterface&MockInterface $cache */
        $cache = Mockery::mock(CacheInterface::class);
        $cache->expects('get')->with('/')->twice()->andReturn(resolve(msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => ['foo' => 'bar'],
            'body' => md5('/'),
        ])));
        $cache->expects('get')->with('/no.cache')->once()->andReturn(resolve(null));
        $cache->expects('set')->with('/no.cache', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => ['foo' => 'bar'],
            'body' => md5('/no.cache'),
        ]), null)->once();
        $cache->expects('get')->with('/stream')->once()->andReturn(resolve(null));
        $cache->expects('set')->with('/stream', Mockery::any())->never();
        $cache->expects('get')->with('/wildcard/blaat')->once()->andReturn(resolve(null));
        $cache->expects('set')->with('/wildcard/blaat', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => ['foo' => 'bar'],
            'body' => md5('/wildcard/blaat'),
        ]), null)->once();
        $cache->expects('get')->with('/api/blaat?q=q')->once()->andReturn(resolve(null));
        $cache->expects('set')->with('/api/blaat?q=q', msgpack_pack([
            'code' => 200,
            'time' => $now,
            'headers' => ['foo' => 'bar'],
            'body' => md5('/api/blaat'),
        ]), null)->once();
        $middleware = new ResponseCacheMiddleware(
            new CacheConfiguration(
                [
                    '/',
                    '/no.cache',
                    '/stream',
                    '/wildcard***',
                    '/api???',
                ],
                ['foo'],
                $clock,
            ),
            $cache,
        );
        $next       = (static fn (ServerRequestInterface $request): Response => new Response(200, ['foo' => 'bar', 'bar' => 'foo'], new StringStream(md5($request->getUri()->getPath()))));

        /** @var ResponseInterface $response */
        $response = await($middleware(new ServerRequest('GET', 'https://example.com/'), $next));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('foo'));
        self::assertSame('bar', $response->getHeaderLine('foo'));
        self::assertSame(md5('/'), (string) $response->getBody());
        // No `Age` header means it didn't hit the cache
        self::assertTrue($response->hasHeader('Age'));
        self::assertSame('0', $response->getHeaderLine('Age'));

        /** @var ResponseInterface $response */
        $response = await($middleware(new ServerRequest('GET', 'https://example.com/no.cache'), $next));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(md5('/no.cache'), (string) $response->getBody());

        /** @var ResponseInterface $response */
        $response = await($middleware(
            new ServerRequest('GET', 'https://example.com/stream'),
            /** @phpstan-ignore shipmonk.unusedParameter */
            static function (ServerRequestInterface $_request): Response {
                /** @phpstan-ignore new.internalClass,method.internalClass */
                $stream = new HttpBodyStream(new ThroughStream(), 1024);

                return new Response(200, [], $stream);
            },
        ));
        self::assertSame(200, $response->getStatusCode());

        /** @var ResponseInterface $response */
        $response = await($middleware(new ServerRequest('GET', 'https://example.com/wildcard/blaat?q=q'), $next));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(md5('/wildcard/blaat'), (string) $response->getBody());

        /** @var ResponseInterface $response */
        $response = await($middleware(new ServerRequest('GET', 'https://example.com/api/blaat?q=q'), $next));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(md5('/api/blaat'), (string) $response->getBody());

        $clock->setTo($time->modify('+1 second'));

        /** @var ResponseInterface $response */
        $response = await($middleware(
            new ServerRequest('GET', 'https://example.com/'),
            $next,
        ));
        self::assertSame(200, $response->getStatusCode());
        // No `Age` header means it didn't hit the cache
        self::assertTrue($response->hasHeader('Age'));
        self::assertSame('1', $response->getHeaderLine('Age'));
    }
}
