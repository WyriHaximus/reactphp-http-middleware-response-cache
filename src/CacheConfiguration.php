<?php declare(strict_types=1);

namespace WyriHaximus\React\Http\Middleware;

use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7\Response;
use function RingCentral\Psr7\stream_for;

final class CacheConfiguration implements CacheConfigurationInterface
{
    const PREFIX_WITHOUT_QUERY = '***';
    const PREFIX_WITH_QUERY = '???';
    const PREFIXES = [
        self::PREFIX_WITH_QUERY,
        self::PREFIX_WITHOUT_QUERY,
    ];

    /**
     * @var array
     */
    private $staticUrls = [];

    /**
     * @var array
     */
    private $prefixUrlsWithoutQuery = [];

    /**
     * @var array
     */
    private $prefixUrlsWithQuery = [];

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @param array      $urls
     * @param array      $headers
     * @param Clock|null $clock
     */
    public function __construct(array $urls, array $headers = [], Clock $clock = null)
    {
        $this->sortUrls($urls);
        $this->headers = $headers;
        $this->clock = $clock instanceof Clock ? $clock : new SystemClock();
    }

    public function requestIsCacheable(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'GET') {
            return false;
        }

        if (
            class_exists(SessionMiddleware::class) &&
            $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME) !== null &&
            $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME)->isActive() === true
        ) {
            return false;
        }

        $uri = $request->getUri()->getPath();
        if (!in_array($uri, $this->staticUrls, true) && !$this->matchesPrefixUrl($uri)) {
            return false;
        }

        return true;
    }

    public function responseIsCacheable(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if (
            class_exists(SessionMiddleware::class) &&
            $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME) !== null &&
            $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME)->isActive() === true
        ) {
            return false;
        }

        return true;
    }

    public function cacheKey(ServerRequestInterface $request): string
    {
        $key = $request->getUri()->getPath();
        $query = $request->getUri()->getQuery();
        if (\strlen($query) > 0 && $this->queryInKey($key)) {
            $key .= '?' . $query;
        }

        return $key;
    }

    public function cacheEncode(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($this->headers as $header) {
            if (!$response->hasHeader($header)) {
                continue;
            }

            $headers[$header] = $response->getHeaderLine($header);
        }

        return [
            'body' => (string)$response->getBody(),
            'headers' => $headers,
            'code' => $response->getStatusCode(),
            'time' => (int)$this->clock->now()->format('U'),
        ];
    }

    public function cacheDecode(array $response): ResponseInterface
    {
        $headers = (array)$response['headers'];
        $headers['Age'] = (int)$this->clock->now()->format('U') - (int)$response['time'];

        return new Response($response['code'], $headers, stream_for($response['body']));
    }

    private function sortUrls(array $urls)
    {
        foreach ($urls as $url) {
            if (!(strlen($url) >= 3 && in_array(substr($url, -3), self::PREFIXES, true))) {
                $this->staticUrls[] = $url;

                continue;
            }

            if (strlen($url) >= 3 && substr($url, -3) === self::PREFIX_WITHOUT_QUERY) {
                $this->prefixUrlsWithoutQuery[] = substr($url, 0, -3);

                continue;
            }

            if (strlen($url) >= 3 && substr($url, -3) === self::PREFIX_WITH_QUERY) {
                $this->prefixUrlsWithQuery[] = substr($url, 0, -3);

                continue;
            }
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

    private function urlMatchesPrefixes(array $urls, string $uri): bool
    {
        foreach ($urls as $url) {
            $urlLength = strlen($url);
            if (substr($uri, 0, $urlLength) === $url) {
                return true;
            }
        }

        return false;
    }
}
