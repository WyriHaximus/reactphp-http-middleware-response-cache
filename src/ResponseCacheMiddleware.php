<?php

declare(strict_types=1);

namespace WyriHaximus\React\Http\Middleware;

use Ancarda\Psr7\StringStream\StringStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/** @api */
final readonly class ResponseCacheMiddleware
{
    private CacheInterface $cache;

    /** @phpstan-ignore ergebnis.noConstructorParameterWithDefaultValue,ergebnis.noParameterWithNullDefaultValue,ergebnis.noParameterWithNullableTypeDeclaration */
    public function __construct(private CacheConfigurationInterface $cacheConfiguration, CacheInterface|null $cache = null)
    {
        $this->cache = $cache instanceof CacheInterface ? $cache : new ArrayCache();
    }

    // phpcs:disable
    /** @return PromiseInterface<ResponseInterface> */
    public function __invoke(ServerRequestInterface $request, callable $next): PromiseInterface
    {
        if (! $this->cacheConfiguration->requestIsCacheable($request)) {
            /** @phpstan-ignore return.type */
            return resolve($next($request));
        }

        $key = $this->cacheConfiguration->cacheKey($request);

        return $this->cache->get($key)->then(function (mixed $json) use ($next, $request, $key): ResponseInterface|PromiseInterface {
            if ($json !== null) {
                assert(is_string($json));

                return $this->cacheConfiguration->cacheDecode($json);
            }

            /** @phpstan-ignore argument.type */
            return resolve($next($request))->then(function (ResponseInterface $response) use ($request, $key): ResponseInterface {
                /** @phpstan-ignore instanceof.internalClass */
                if ($response->getBody() instanceof HttpBodyStream) {
                    return $response;
                }

                if (! $this->cacheConfiguration->responseIsCacheable($request, $response)) {
                    return $response;
                }

                $ttl             = $this->cacheConfiguration->cacheTtl($request, $response);
                $body            = (string) $response->getBody();
                $encodedResponse = $this->cacheConfiguration->cacheEncode($response->withBody(new StringStream($body)));
                $this->cache->set($key, $encodedResponse, $ttl);

                return $response->withBody(new StringStream($body));
            });
        });
    }
}
