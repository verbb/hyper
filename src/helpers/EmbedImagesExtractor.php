<?php
namespace verbb\hyper\helpers;

use verbb\hyper\Hyper;
use verbb\hyper\http\EmbedClient;
use verbb\hyper\models\Settings;

use Embed\Detectors\Detector;
use Embed\Detectors\Image;
use Embed\Http\Crawler;
use Psr\Http\Message\UriInterface;

class EmbedImagesExtractor extends Detector
{
    // Static Methods
    // =========================================================================

    public static function youtubeMaxResCandidate(string $imageUrl): ?string
    {
        if (!preg_match('#^(https?://(?:i\d*\.)?ytimg\.com/vi(?:_webp)?/)([^/]+)/(hqdefault|mqdefault|sddefault|default)(\.[a-z]+)(?:\?.*)?$#i', $imageUrl, $matches)) {
            return null;
        }

        return $matches[1] . $matches[2] . '/maxresdefault' . $matches[4];
    }

    public static function preferYouTubeMaxRes(string $imageUrl, ?Crawler $crawler = null): ?string
    {
        $maxResUrl = self::youtubeMaxResCandidate($imageUrl);

        if ($maxResUrl === null) {
            return null;
        }

        try {
            $settings = Hyper::$plugin->getSettings();
            $crawler ??= new Crawler(new EmbedClient($settings->embedAllowedDomains, ['timeout' => 3]));
            $response = $crawler->sendRequest($crawler->createRequest('HEAD', $maxResUrl));
            $status = $response->getStatusCode();

            // YouTube sometimes returns 200 with a tiny placeholder; prefer GET content-length when present
            if ($status >= 200 && $status < 300) {
                $length = (int)($response->getHeaderLine('Content-Length') ?: 0);

                // Placeholder maxres responses are typically very small (~1KB); real thumbs are larger
                if ($length === 0 || $length > 2000) {
                    return $maxResUrl;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }


    // Public Methods
    // =========================================================================

    public function detect(): ?array
    {
        /* @var Settings $settings */
        $settings = Hyper::$plugin->getSettings();

        // There are performance concerns, as it requires us to fetch each image, so ensure it's opt-in.
        if (!$settings->resolveHiResEmbedImage) {
            // But always return an array, to ensure it's treated the one way. Fallback to the default
            $image = (new Image($this->extractor))->detect();

            // Prefer YouTube maxresdefault when the detector returns a lower-res variant
            if ($image instanceof UriInterface) {
                $image = (string)$image;
            }

            if (is_string($image) && $image !== '') {
                $image = self::preferYouTubeMaxRes($image, $this->extractor->getCrawler()) ?? $image;
            }

            return ['image' => $image];
        }

        $oembed = $this->extractor->getOEmbed();
        $document = $this->extractor->getDocument();
        $metas = $this->extractor->getMetas();
        $ld = $this->extractor->getLinkedData();

        // Find all available images
        $imageUrls = array_filter([
            $oembed->url('image'),
            $oembed->url('thumbnail'),
            $oembed->url('thumbnail_url'),
            $metas->url('og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src', 'lp:image'),
            $document->link('image_src'),
            $ld->url('image.url'),
            $this->_detectFromContentType(),
        ]);

        $normalized = [];

        foreach (array_unique($imageUrls) as $imageUrl) {
            $url = $imageUrl instanceof UriInterface ? (string)$imageUrl : (string)$imageUrl;
            $normalized[] = self::preferYouTubeMaxRes($url, $this->extractor->getCrawler()) ?? $url;
        }

        $crawler = $this->extractor->getCrawler();
        $largestImage = null;
        $largestSize = 0;

        // Fetch them, returning just the largest
        foreach (array_unique($normalized) as $imageUrl) {
            // Fetch the image content
            $response = $crawler->sendRequest($crawler->createRequest('GET', $imageUrl));
            $imageContent = $response->getBody()->getContents();

            // Get image dimensions
            $dimensions = @getimagesizefromstring($imageContent);

            if (!$dimensions) {
                continue;
            }

            [$width, $height] = $dimensions;

            $size = $width * $height;

            // Compare with the current largest image
            if ($size > $largestSize) {
                $largestSize = $size;

                $largestImage = [
                    'image' => $imageUrl,
                    'imageWidth' => $width,
                    'imageHeight' => $height,
                ];
            }
        }

        return $largestImage;
    }


    // Private Methods
    // =========================================================================

    private function _detectFromContentType()
    {
        if (!$this->extractor->getResponse()->hasHeader('content-type')) {
            return null;
        }

        $contentType = $this->extractor->getResponse()->getHeader('content-type')[0];

        if (strpos($contentType, 'image/') === 0) {
            return $this->extractor->getUri();
        }
    }
}
