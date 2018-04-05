<?php declare(strict_types=1);

namespace WyriHaximus\React\Http\Middleware;

use Lcobucci\Clock\Clock;
use Lcobucci\Clock\SystemClock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Cache\ArrayCache;
use React\Cache\CacheInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Response;
use function React\Promise\resolve;
use function RingCentral\Psr7\stream_for;

final class ResponseCacheMiddleware
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
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @param array               $urls
     * @param array               $headers
     * @param CacheInterface|null $cache
     * @param Clock               $clock
     */
    public function __construct(array $urls, array $headers = [], CacheInterface $cache = null, Clock $clock = null)
    {
        $this->sortUrls($urls);
        $this->headers = $headers;
        $this->cache = $cache instanceof CacheInterface ? $cache : new ArrayCache();
        $this->clock = $clock instanceof Clock ? $clock : new SystemClock();
    }

    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        if ($request->getMethod() !== 'GET') {
            return resolve($next($request));
        }

        /** @var Session $session */
        $session = null;
        if (
            class_exists(SessionMiddleware::class) &&
            $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME) !== null
        ) {
            $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE_NAME);
        }

        if ($session !== null && $session->isActive() === true) {
            return resolve($next($request));
        }

        $uri = $request->getUri()->getPath();
        if (!in_array($uri, $this->staticUrls, true) && !$this->matchesPrefixUrl($uri)) {
            return resolve($next($request));
        }

        $key = $uri;
        $query = $request->getUri()->getQuery();
        if (strlen($query) > 0 && $this->queryInKey($uri)) {
            $key .= '?' . $query;
        }

        return $this->cache->get($key)->then(function ($json) {
            $cachedResponse = json_decode($json);
            $headers = (array)$cachedResponse->headers;
            // Todo at 2 October 2018 remove the check around this assignment
            if (isset($cachedResponse->time)) {
                $headers['Age'] = (int)$this->clock->now()->format('U') - (string)$cachedResponse->time;
            }

            return new Response($cachedResponse->code, $headers, stream_for($cachedResponse->body));
        }, function () use ($next, $request, $key, $session) {
            return resolve($next($request))->then(function (ResponseInterface $response) use ($key, $session) {
                if ($response->getBody() instanceof HttpBodyStream) {
                    return $response;
                }

                if ($session !== null && $session->isActive() === true) {
                    return $response;
                }

                $body = (string)$response->getBody();
                $headers = [];
                foreach ($this->headers as $header) {
                    if (!$response->hasHeader($header)) {
                        continue;
                    }

                    $headers[$header] = $response->getHeaderLine($header);
                }
                $cachedResponse = json_encode([
                    'body' => $body,
                    'headers' => $headers,
                    'code' => $response->getStatusCode(),
                    'time' => (int)$this->clock->now()->format('U'),
                ]);
                $this->cache->set($key, $cachedResponse);

                return $response->withBody(stream_for($body));
            });
        });
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
