<?php
namespace verbb\hyper\helpers;

/**
 * Shared URI / attribute / embed-fetch safety (Astra A02 / A03 / SSRF follow-up).
 */
class UrlSafety
{
    // Static Methods
    // =========================================================================

    /**
     * Whether a URL may be stored or rendered. Fragment-only and scheme-less
     * relative paths are allowed. Blocked schemes never pass, even when listed
     * in $extraSchemes.
     */
    public static function isAllowedUrl(string $url, array $extraSchemes = []): bool
    {
        // Browsers strip URL control characters before interpreting a scheme.
        if (preg_match('/[\x00-\x1f\x7f]/', trim($url, ' '))) {
            return false;
        }

        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }

        // Protocol-relative URLs are treated as http(s)-family navigations.
        if (str_starts_with($url, '//')) {
            return true;
        }

        if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches)) {
            // Relative / path-only values — no scheme to abuse.
            return true;
        }

        $scheme = strtolower($matches[1]);

        if (in_array($scheme, self::BLOCKED_SCHEMES, true)) {
            return false;
        }

        $allowed = array_map('strtolower', array_merge(self::DEFAULT_SCHEMES, $extraSchemes));

        return in_array($scheme, $allowed, true);
    }

    /** HTML attribute names only — rejects event handlers and free-form injection. */
    public static function isSafeAttributeName(string $name): bool
    {
        $name = trim($name);

        if ($name === '' || preg_match('/^on/i', $name)) {
            return false;
        }

        return (bool)preg_match('/^[a-zA-Z_:][\w:.-]*$/', $name);
    }

    /** Embed fetches are http(s) only — never mailto/tel/custom schemes. */
    public static function isEmbedFetchUrl(string $url): bool
    {
        // Browsers strip URL control characters before interpreting a scheme.
        if (preg_match('/[\x00-\x1f\x7f]/', trim($url, ' '))) {
            return false;
        }

        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton(trim($ip, '[]'));

        if ($packed === false) {
            return false;
        }

        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
            : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];

        // Only global unicast IPv6; exclude mapped/translation/tunnel address families.
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20) {
            return false;
        }

        foreach ($blocked as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $network = inet_pton($network);
            $bytes = intdiv((int)$bits, 8);
            $remaining = (int)$bits % 8;

            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && (!$remaining || (ord($packed[$bytes]) & (0xff << (8 - $remaining))) === (ord($network[$bytes]) & (0xff << (8 - $remaining))))) {
                return false;
            }
        }

        return true;
    }


    // Constants
    // =========================================================================

    public const DEFAULT_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms'];
    public const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript'];
}
