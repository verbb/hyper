<?php
namespace verbb\hyper\helpers;

/**
 * Shared URI / attribute / embed-fetch safety (Astra A02 / A03 / SSRF follow-up).
 */
class UrlSafety
{
    // Static Methods
    // =========================================================================

    public const DEFAULT_SCHEMES = ['http', 'https', 'mailto', 'tel', 'sms'];

    public const BLOCKED_SCHEMES = ['javascript', 'data', 'vbscript'];

    /**
     * Whether a URL may be stored or rendered. Fragment-only and scheme-less
     * relative paths are allowed. Blocked schemes never pass, even when listed
     * in $extraSchemes.
     */
    public static function isAllowedUrl(string $url, array $extraSchemes = []): bool
    {
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
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * Whether a host resolves only to public, routable addresses.
     * Blocks private, reserved, link-local, and cloud metadata ranges.
     */
    public static function isPublicFetchHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (
            $host === '' ||
            $host === 'localhost' ||
            str_ends_with($host, '.localhost') ||
            $host === 'metadata.google.internal'
        ) {
            return false;
        }

        // Literal IPs in the URL host.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $ipv4 = @gethostbynamel($host) ?: [];
            $ips = array_merge($ips, $ipv4);
        }

        if ($ips === []) {
            // Unresolvable hosts are not safe to fetch.
            return false;
        }

        foreach (array_unique($ips) as $ip) {
            if (!self::isPublicIp((string)$ip)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');

        if ($ip === '') {
            return false;
        }

        // Cloud metadata / special-use addresses FILTER_FLAG_NO_RES_RANGE can miss.
        if ($ip === '169.254.169.254') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool)filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Also reject IPv4-mapped private addresses.
            if (str_starts_with(strtolower($ip), '::ffff:')) {
                return self::isPublicIp(substr($ip, 7));
            }

            return (bool)filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
        }

        return false;
    }

    /**
     * Follow redirects manually (max hops), validating scheme + public DNS/IP on every hop.
     * Returns the final URL, or null on failure (sets $error).
     */
    public static function resolvePublicEmbedUrl(string $url, int $maxRedirects = 5, ?string &$error = null): ?string
    {
        $current = trim($url);

        for ($i = 0; $i <= $maxRedirects; $i++) {
            if (!self::isEmbedFetchUrl($current)) {
                $error = 'Embed URLs must use http or https.';

                return null;
            }

            $parts = parse_url($current);
            $host = $parts['host'] ?? '';

            if (!is_string($host) || $host === '' || !self::isPublicFetchHost($host)) {
                $error = 'Embed URL host is not allowed.';

                return null;
            }

            $probe = self::_probeHttp($current);

            if ($probe['error'] !== null) {
                $error = $probe['error'];

                return null;
            }

            $status = $probe['status'];

            // Not a redirect — current URL is the safe fetch target.
            if ($status < 300 || $status >= 400) {
                return $current;
            }

            $location = $probe['location'];

            if ($location === null || $location === '') {
                $error = 'Embed URL redirect was missing a Location header.';

                return null;
            }

            $current = self::_absolutizeUrl($current, $location);
        }

        $error = 'Embed URL exceeded the redirect limit.';

        return null;
    }

    /**
     * HEAD (then GET fallback) without following redirects. Used to inspect hop Location headers.
     *
     * @return array{status: int, location: ?string, error: ?string}
     */
    private static function _probeHttp(string $url): array
    {
        if (!function_exists('curl_init')) {
            // Without curl we cannot safely inspect redirects — refuse rather than follow blindly.
            return [
                'status' => 0,
                'location' => null,
                'error' => 'Unable to validate embed URL redirects.',
            ];
        }

        $result = self::_curlProbe($url, true);

        // Some oEmbed hosts reject HEAD — retry a short GET that still does not follow redirects.
        if ($result['status'] === 405 || $result['status'] === 501) {
            $result = self::_curlProbe($url, false);
        }

        return $result;
    }

    /**
     * @return array{status: int, location: ?string, error: ?string}
     */
    private static function _curlProbe(string $url, bool $headOnly): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return ['status' => 0, 'location' => null, 'error' => 'Unable to reach embed URL.'];
        }

        $headers = '';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => $headOnly,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
                $headers .= $headerLine;

                return strlen($headerLine);
            },
        ]);

        if (!$headOnly) {
            // Bound body download — we only need status + Location.
            curl_setopt($ch, CURLOPT_RANGE, '0-0');
        }

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($ok === false || $errno) {
            return ['status' => 0, 'location' => null, 'error' => 'Unable to reach embed URL.'];
        }

        $location = null;

        if (preg_match('/^Location:\s*(.+)$/im', $headers, $matches)) {
            $location = trim($matches[1]);
        }

        return [
            'status' => $status,
            'location' => $location,
            'error' => null,
        ];
    }

    private static function _absolutizeUrl(string $base, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(substr($path, 0, (int)strrpos($path, '/') + 1), '/') . '/';

        return $scheme . '://' . $host . $port . $dir . $location;
    }
}
