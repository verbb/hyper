<?php
namespace verbb\hyper\models;

use verbb\hyper\helpers\UrlSafety;

use craft\base\Model;

class Settings extends Model
{
    // Properties
    // =========================================================================

    public bool $backupOnMigrate = true;
    public bool $resolveHiResEmbedImage = false;
    public array $embedClientConfig = [];
    public array $embedClientSettings = [];
    public array $embedHeaders = [];
    public array $embedDetectorsSettings = [];
    public array $embedAllowedDomains = [];

    /**
     * Extra URI schemes allowed on URL link values beyond UrlSafety::DEFAULT_SCHEMES
     * (e.g. ['slack', 'ftp']). javascript/data/vbscript are always blocked.
     */
    public array $allowedUriSchemes = [];


    // Public Methods
    // =========================================================================

    public function getEmbedClientConfig(): array
    {
        $defaults = [
            'min_image_width' => 16,
            'min_image_height' => 16,
        ];

        return array_replace_recursive($defaults, $this->embedClientConfig);
    }

    /** Transport security cannot be disabled by legacy Curl settings. */
    public function getEmbedClientSettings(): array
    {
        return [
            'timeout' => max(1, min(10, (int)($this->embedClientSettings['timeout'] ?? 10))),
            'ssl_verify_peer' => true,
            'ssl_verify_host' => 2,
            'follow_location' => false,
            'max_redirs' => 0,
        ];
    }

    /**
     * Exact host or subdomain match against an allowlist entry.
     * `media.example.test` matches itself and `foo.media.example.test`, but not
     * `media.example.test.unrelated.test` (Astra H3-A03).
     */
    public function doesUrlMatchDomain(string $url, ?array $allowedDomains = null): bool
    {
        $domains = $allowedDomains ?? $this->embedAllowedDomains;

        if ($domains === []) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(preg_replace('/^www\./i', '', $host) ?? $host);

        foreach ($domains as $allowed) {
            $allowed = strtolower(preg_replace('/^www\./i', '', trim((string)$allowed)) ?? '');

            if ($allowed === '') {
                continue;
            }

            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function isUriSchemeAllowed(string $url): bool
    {
        return UrlSafety::isAllowedUrl($url, $this->allowedUriSchemes);
    }
}
