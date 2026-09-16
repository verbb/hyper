<?php
namespace verbb\hyper\http;

use verbb\hyper\helpers\UrlSafety;
use verbb\hyper\models\Settings;

use RuntimeException;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** One guarded transport for initial pages, redirects and detector/oEmbed requests. */
class EmbedClient implements ClientInterface
{
    // Properties
    // =========================================================================

    private int $_requests = 0;
    private int $_bytes = 0;
    private float $_deadline;


    // Public Methods
    // =========================================================================

    public function __construct(private array $_domains = [], private array $_settings = [])
    {
        $this->_deadline = microtime(true) + 30;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            throw new RuntimeException('Unsupported embed request method.');
        }

        for ($hop = 0; $hop <= 5; $hop++) {
            if (++$this->_requests > 20 || microtime(true) >= $this->_deadline) {
                throw new RuntimeException('Embed request budget exceeded.');
            }

            $uri = $request->getUri();
            $host = strtolower(trim($uri->getHost(), '[]'));

            if (!UrlSafety::isEmbedFetchUrl((string)$uri) || $uri->getUserInfo() !== '' || $host === '') {
                throw new RuntimeException('Embed URLs must use http or https without credentials.');
            }

            if (!(new Settings())->doesUrlMatchDomain((string)$uri, $this->_domains)) {
                throw new RuntimeException('Embed URL domain not allowed.');
            }

            $ips = $this->resolveHost($host);

            if ($ips === [] || array_filter($ips, static fn($ip) => !UrlSafety::isPublicIp($ip))) {
                throw new RuntimeException('Embed URL host is not allowed.');
            }

            // Use exactly a validated address. Curl never gets a second DNS decision,
            // even if the public answer changes before this connection is made.
            $response = $this->requestPinned($request, $ips[0]);
            $status = $response->getStatusCode();

            if (!in_array($status, [301, 302, 303, 307, 308], true)) {
                return $response->withHeader('Content-Location', (string)$uri);
            }

            $location = $response->getHeaderLine('Location');

            if ($location === '') {
                throw new RuntimeException('Embed redirect is missing its destination.');
            }

            $next = UriResolver::resolve($uri, new Uri($location));

            if ($next->getHost() !== $uri->getHost() || $next->getScheme() !== $uri->getScheme() || $next->getPort() !== $uri->getPort()) {
                $request = $request->withoutHeader('Authorization')->withoutHeader('Cookie');
            }

            $request = $request->withUri($next);
        }

        throw new RuntimeException('Embed URL exceeded the redirect limit.');
    }


    // Protected Methods
    // =========================================================================

    /** Separate DNS from transport so tests can exercise rebinding without real network I/O. */
    protected function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // Reject alternate numeric IPv4 notation rather than letting Curl reinterpret it.
        if (!preg_match('/^[a-z0-9.-]+$/i', $host) || !preg_match('/[a-z]/i', $host)) {
            return [];
        }

        $ips = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $ips[] = $record['ip'] ?? $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    protected function requestPinned(RequestInterface $request, string $ip): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $ch = curl_init((string)$uri);
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $tooLarge = false;
        $requestHeaders = [];

        foreach ($request->getHeaders() as $name => $values) {
            // Host comes from the checked URL; never from project-supplied headers.
            if (strtolower($name) !== 'host') {
                $requestHeaders[] = $name . ': ' . implode(', ', $values);
            }
        }

        $timeout = max(1, min(10, (int)($this->_settings['timeout'] ?? 10), (int)ceil($this->_deadline - microtime(true))));
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $address],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_NOBODY => $request->getMethod() === 'HEAD',
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$headers, &$headerBytes, &$tooLarge): int {
                $headerBytes += strlen($line);

                if ($headerBytes > 65536) {
                    $tooLarge = true;
                    return 0;
                }

                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function($curl, string $chunk) use (&$body, &$tooLarge): int {
                $length = strlen($chunk);
                $this->_bytes += $length;

                // Enforce bytes actually received/decompressed, independent of Content-Length/Range.
                if (strlen($body) + $length > 2 * 1024 * 1024 || $this->_bytes > 8 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return $length;
            },
        ]);

        try {
            $ok = curl_exec($ch);

            if ($ok === false) {
                throw new RuntimeException($tooLarge ? 'Embed response exceeded the size limit.' : 'Unable to fetch embed URL.');
            }

            return new Response((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $headers, $body);
        } finally {
            curl_close($ch);
        }
    }
}
