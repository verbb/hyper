<?php
namespace verbb\hyper\models;

use craft\base\Model;

class Settings extends Model
{
    // Properties
    // =========================================================================

    public bool $resolveHiResEmbedImage = false;
    public array $embedClientConfig = [];
    public array $embedClientSettings = [];
    public array $embedHeaders = [];
    public array $embedDetectorsSettings = [];
    public array $embedAllowedDomains = [];


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

    public function doesUrlMatchDomain(string $url): bool
    {
        // Parse the URL to get the domain
        $parsedUrl = parse_url($url);

        if ($parsedUrl && isset($parsedUrl['host'])) {
            // Get the domain from the host
            $domain = str_replace('www.', '', $parsedUrl['host']);

            // Check if the domain is in the TLD list
            foreach ($this->embedAllowedDomains as $tld) {
                if (strpos($domain, $tld) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

}
