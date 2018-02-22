<?php declare(strict_types=1);

namespace WyriHaximus\React\Http\Middleware;

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
    /**
     * @var array
     */
    private $staticUrls = [];

    /**
     * @var array
     */
    private $prefixUrls = [];

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param array          $urls
     * @param CacheInterface $cache
     */
    public function __construct(array $urls, array $headers = [], CacheInterface $cache = null)
    {
        $this->staticUrls = array_filter(
            $urls,
            function ($url) {
                return !(strlen($url) >= 3 && substr($url, -3) === '***');
            }
        );
        $this->prefixUrls = array_map(
            function ($url) {
                return substr($url, 0, -3);
            },
            array_filter(
                $urls,
                function ($url) {
                    return strlen($url) >= 3 && substr($url, -3) === '***';
                }
            )
        );

        $this->headers = $headers;
        $this->cache = $cache instanceof CacheInterface ? $cache : new ArrayCache();
    }

    public function __invoke(ServerRequestInterface $request, callable $next)
    {
        if ($request->getMethod() !== 'GET') {
            return resolve($next($request));
        }

        $uri = $request->getUri()->getPath();
        if (!in_array($uri, $this->staticUrls, true) && !$this->matchesPrefixUrl($uri)) {
            return resolve($next($request));
        }

        $key = $uri;

        return $this->cache->get($key)->then(function ($json) {
            $cachedResponse = json_decode($json);

            return new Response($cachedResponse->code, (array)$cachedResponse->headers, stream_for($cachedResponse->body));
        }, function () use ($next, $request, $key) {
            return resolve($next($request))->then(function (ResponseInterface $response) use ($key) {
                if ($response->getBody() instanceof HttpBodyStream) {
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
                ]);
                $this->cache->set($key, $cachedResponse);

                return $response->withBody(stream_for($body));
            });
        });
    }

    private function matchesPrefixUrl(string $uri): bool
    {
        foreach ($this->prefixUrls as $url) {
            $urlLength = strlen($url);
            if (substr($uri, 0, $urlLength) === $url) {
                return true;
            }
        }

        return false;
    }
}
