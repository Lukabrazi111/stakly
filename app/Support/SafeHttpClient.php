<?php

namespace App\Support;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * PSR-18 client wrapper that applies `SsrfGuard::isUrlSafe()` on every
 * request, including each HTTP redirect hop.
 *
 * Why this exists: oscarotero/embed's default `CurlClient` lets curl
 * follow up to 10 redirects automatically (`CURLOPT_FOLLOWLOCATION`).
 * That means even if we SSRF-check the URL a user pasted, an
 * `https://shortener.com/x` → `http://169.254.169.254/...` chain would
 * land in internal address space without our knowledge.
 *
 * We disable curl-level redirects on the inner client (`follow_location:
 * false`) and walk the chain here instead — SSRF-checking each `Location`
 * before issuing the next request. Cap at 3 hops; legit publishers
 * rarely need more than one (http→https) and an unbounded chain is a
 * crawler tarpit.
 *
 * 30x → GET conversion follows the de-facto web rule (RFC 7231 §6.4
 * permits client method downgrade). Embed is read-only metadata fetching,
 * so dropping the method to GET is always safe.
 */
class SafeHttpClient implements ClientInterface
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly ClientInterface $inner,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->sendWithRedirects($request, 0);
    }

    private function sendWithRedirects(RequestInterface $request, int $hop): ResponseInterface
    {
        if ($hop > self::MAX_REDIRECTS) {
            throw new RuntimeException('Refused to follow more than '.self::MAX_REDIRECTS.' redirects');
        }

        $url = (string) $request->getUri();

        if (! SsrfGuard::isUrlSafe($url)) {
            throw new SafeHttpException('Refused to fetch URL that resolves to private or reserved address space: '.$url);
        }

        try {
            $response = $this->inner->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Surface as our wrapper so callers can distinguish a refusal
            // from a transport error if needed. Same exception hierarchy
            // (`ClientExceptionInterface` is a marker interface implemented
            // by `SafeHttpException` too).
            throw new SafeHttpException('Underlying client failed: '.$e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status < 300 || $status >= 400) {
            return $response;
        }

        $location = $response->getHeaderLine('Location');

        if ($location === '') {
            return $response;
        }

        // PSR-7 URI resolution handles absolute and relative Locations
        // (e.g. `/path`, `https://other.host/path`) against the current
        // request URI per RFC 3986.
        $nextUri = UriResolver::resolve($request->getUri(), Utils::uriFor($location));

        $nextRequest = $request
            ->withUri($nextUri)
            ->withMethod('GET')
            ->withoutHeader('Authorization')
            ->withoutHeader('Cookie');

        // GET has no body — strip whatever the previous request carried.
        $nextRequest = $nextRequest->withBody(Utils::streamFor(''));

        return $this->sendWithRedirects($nextRequest, $hop + 1);
    }
}
