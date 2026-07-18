<?php

declare(strict_types=1);

namespace WyriHaximus\React\Http\Middleware;

use Ancarda\Psr7\StringStream\StringStream;
use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_any;
use function in_array;
use function msgpack_pack;
use function msgpack_unpack;
use function str_starts_with;
use function strlen;
use function substr;

/** @api */
final class CacheConfiguration implements CacheConfigurationInterface
{
    private const string PREFIX_WITHOUT_QUERY = '***';
    private const string PREFIX_WITH_QUERY    = '???';
    private const array PREFIXES              = [
        self::PREFIX_WITH_QUERY,
        self::PREFIX_WITHOUT_QUERY,
    ];

    /** @var array<string> */
    private array $staticUrls = [];

    /** @var array<string> */
    private array $prefixUrlsWithoutQuery = [];

    /** @var array<string> */
    private array $prefixUrlsWithQuery = [];

    private readonly Clock $clock;

    /** @var (callable(ServerRequestInterface, ResponseInterface): (int|null))|null */
    private $ttl;

    /**
     * @param array<string>                                                          $urls
     * @param array<string>                                                          $headers
     * @param (callable(ServerRequestInterface, ResponseInterface): (int|null))|null $ttl
     *
     * @phpstan-ignore ergebnis.noParameterWithNullDefaultValue,ergebnis.noParameterWithNullDefaultValue,ergebnis.noConstructorParameterWithDefaultValue,ergebnis.noConstructorParameterWithDefaultValue,ergebnis.noConstructorParameterWithDefaultValue,ergebnis.noParameterWithNullableTypeDeclaration,ergebnis.noParameterWithNullableTypeDeclaration
     */
    public function __construct(array $urls, private readonly array $headers = [], Clock|null $clock = null, callable|null $ttl = null)
    {
        $this->sortUrls($urls);
        $this->clock = $clock instanceof Clock ? $clock : SystemClock::fromSystemTimezone();
        $this->ttl   = $ttl;
    }

    public function requestIsCacheable(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        $uri = $request->getUri()->getPath();

        return in_array($uri, $this->staticUrls, true) || $this->matchesPrefixUrl($uri);
    }

    public function responseIsCacheable(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        return true;
    }

    public function cacheKey(ServerRequestInterface $request): string
    {
        $key   = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if ($query !== '' && $this->queryInKey($key)) {
            $key .= '?' . $query;
        }

        return $key;
    }

    public function cacheTtl(ServerRequestInterface $request, ResponseInterface $response): int|null
    {
        if ($this->ttl === null) {
            return null;
        }

        return ($this->ttl)($request, $response);
    }

    public function cacheEncode(ResponseInterface $response): string
    {
        $headers = [];
        foreach ($this->headers as $header) {
            if (! $response->hasHeader($header)) {
                continue;
            }

            $headers[$header] = $response->getHeaderLine($header);
        }

        return msgpack_pack([
            'code' => $response->getStatusCode(),
            'time' => (int) $this->clock->now()->format('U'),
            'headers' => $headers,
            'body' => (string) $response->getBody(),
        ]);
    }

    public function cacheDecode(string $response): ResponseInterface
    {
        /** @var array{code: int, time: int, headers: array<string, string>, body: string} $decoded */
        $decoded                   = msgpack_unpack($response);
        $decoded['headers']['Age'] = (int) $this->clock->now()->format('U') - $decoded['time'];

        return new Response($decoded['code'], $decoded['headers'], new StringStream($decoded['body']));
    }

    /** @param array<string> $urls */
    private function sortUrls(array $urls): void
    {
        foreach ($urls as $url) {
            if (strlen($url) < 3 || ! in_array(substr($url, -3), self::PREFIXES, true)) {
                $this->staticUrls[] = $url;

                continue;
            }

            /** @phpstan-ignore greaterOrEqual.alwaysTrue */
            if (strlen($url) >= 3 && substr($url, -3) === self::PREFIX_WITHOUT_QUERY) {
                $this->prefixUrlsWithoutQuery[] = substr($url, 0, -3);

                continue;
            }

            /** @phpstan-ignore smaller.alwaysFalse */
            if (strlen($url) < 3 || substr($url, -3) !== self::PREFIX_WITH_QUERY) {
                continue;
            }

            $this->prefixUrlsWithQuery[] = substr($url, 0, -3);
        }
    }

    private function matchesPrefixUrl(string $uri): bool
    {
        if ($this->urlMatchesPrefixes($this->prefixUrlsWithoutQuery, $uri)) {
            return true;
        }

        return $this->urlMatchesPrefixes($this->prefixUrlsWithQuery, $uri);
    }

    private function queryInKey(string $uri): bool
    {
        return $this->urlMatchesPrefixes($this->prefixUrlsWithQuery, $uri);
    }

    /** @param array<string> $urls */
    private function urlMatchesPrefixes(array $urls, string $uri): bool
    {
        return array_any($urls, static fn (string $url): bool => str_starts_with($uri, $url));
    }
}
