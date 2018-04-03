# Middleware that caches the response code and body of an URL list

[![Build Status](https://travis-ci.org/WyriHaximus/reactphp-http-middleware-response-cache.svg?branch=master)](https://travis-ci.org/WyriHaximus/reactphp-http-middleware-response-cache)
[![Latest Stable Version](https://poser.pugx.org/WyriHaximus/react-http-middleware-response-cache/v/stable.png)](https://packagist.org/packages/WyriHaximus/react-http-middleware-response-cache)
[![Total Downloads](https://poser.pugx.org/WyriHaximus/react-http-middleware-response-cache/downloads.png)](https://packagist.org/packages/WyriHaximus/react-http-middleware-response-cache)
[![Code Coverage](https://scrutinizer-ci.com/g/WyriHaximus/reactphp-http-middleware-response-cache/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/WyriHaximus/reactphp-http-middleware-response-cache/?branch=master)
[![License](https://poser.pugx.org/WyriHaximus/react-http-middleware-response-cache/license.png)](https://packagist.org/packages/WyriHaximus/react-http-middleware-response-cache)
[![PHP 7 ready](http://php7ready.timesplinter.ch/WyriHaximus/reactphp-http-middleware-response-cache/badge.svg)](https://travis-ci.org/WyriHaximus/reactphp-http-middleware-response-cache)

# Install

To install via [Composer](http://getcomposer.org/), use the command below, it will automatically detect the latest version and bind it with `^`.

```
composer require wyrihaximus/react-http-middleware-response-cache
```

This middleware caches the response code and body from a list of given URL's. Note that no headers will be cached at this point, 
and that this middleware is might change it's behavior in the future at any time until tagged.

# Usage

```php
$server = new Server([
    /** Other middleware */
    new ResponseCacheMiddleware(
        [
            '/',
            '/robots.txt',
            '/favicon.ico',
            '/cache/***', // Anything that starts with /cache/ in the path will be cached
            '/api/???', // Anything that starts with /cache/ in the path will be cached (query is included in the cache key)
        ],
        [ // Optional, array with headers to include in the cache
            'Content-Type',
        ],
        new ArrayCache() // Optional, will default to ArrayCache but any CacheInterface cache will do: https://github.com/reactphp/react/wiki/Users#cache-implementations
    ),
    /** Other middleware */
]);
```

# License

The MIT License (MIT)

Copyright (c) 2018 Cees-Jan Kiewiet

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
