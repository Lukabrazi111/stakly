<?php

namespace App\Support;

/**
 * SSRF guard for outbound HTTP fetches triggered by user-supplied URLs.
 *
 * The realistic attack: a chat message containing
 * `http://169.254.169.254/latest/meta-data/` (EC2 IMDS) or
 * `http://192.168.1.1/admin` (LAN gateway) routes a worker request to a
 * resource the worker can reach but the user can't. We refuse to fetch
 * anything that resolves to private, loopback, link-local, or otherwise
 * reserved address space.
 *
 * Used by `App\Support\SafeHttpClient` on every hop (including redirects)
 * so an `http://attacker.com/redirect-to-internal` URL can't trampoline
 * us into an internal range either.
 *
 * IPv4 only — `App\Support\SafeHttpClient` configures the underlying curl
 * client with `CURL_IPRESOLVE_V4`, so an IPv6 AAAA record never gets
 * dialed even if one exists. Keeping the guard IPv4-only matches that.
 */
class SsrfGuard
{
    /**
     * Cheap pre-flight: scheme + literal-IP-range check, no DNS lookup.
     * Use this on the request hot path (URL extraction during message send)
     * to filter out the obvious-trash inputs without blocking on DNS.
     * Hostnames pass through — the queued worker that actually dials the
     * URL must call `isUrlSafe` for the DNS-resolved verdict.
     */
    public static function isPlausiblySafe(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? '';

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isIpPublic($host);
        }

        return true;
    }

    /**
     * Full check including DNS resolution. Returns true iff every IPv4
     * address the host resolves to is in publicly-routable space. Use
     * this from queued workers (not from the HTTP request path) right
     * before the outbound dial — DNS can be slow and the request would
     * stall waiting for an answer.
     *
     * The all-of (not any-of) check on the resolved list is deliberate:
     * a public hostname with one private address mixed in (DNS rebinding
     * setup, misconfigured public DNS) still gets refused.
     */
    public static function isUrlSafe(string $url): bool
    {
        if (! self::isPlausiblySafe($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // PHP's gethostbynamel returns IPv4 A records (or false on lookup
        // failure). Matches the IPv4-only curl resolution policy.
        $ips = @gethostbynamel($host);

        if ($ips === false || count($ips) === 0) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! self::isIpPublic($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `FILTER_FLAG_NO_PRIV_RANGE` rejects 10/8, 172.16/12, 192.168/16 plus
     * IPv6 fc00::/7. `FILTER_FLAG_NO_RES_RANGE` rejects 0/8, 127/8,
     * 169.254/16, reserved 240/4, IPv6 ::, ::1, fe80::/10.
     *
     * Multicast (`224.0.0.0/4`) is NOT covered by either flag — PHP's
     * filter doesn't classify it as reserved. We add an explicit check
     * for it because some setups expose mDNS / SSDP / cluster gossip on
     * multicast and they're a real SSRF target.
     */
    private static function isIpPublic(string $ip): bool
    {
        $passesFilter = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if (! $passesFilter) {
            return false;
        }

        // 224.0.0.0/4 — IPv4 multicast.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $firstOctet = (int) explode('.', $ip)[0];

            if ($firstOctet >= 224 && $firstOctet <= 239) {
                return false;
            }
        }

        return true;
    }
}
