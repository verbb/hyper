<?php
namespace verbb\hyper\helpers;

use verbb\hyper\Hyper;
use verbb\hyper\models\Settings;

use Embed\Detectors\Detector;
use Embed\Detectors\Image;

use GuzzleHttp\Client;

use Psr\Http\Message\UriInterface;

class EmbedImagesExtractor extends Detector
{
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
            $this->detectFromContentType(),
        ]);

        $client = new Client();
        $largestImage = null;
        $largestSize = 0;

        // Fetch them, returning just the largest
        foreach (array_unique($imageUrls) as $imageUrl) {
            // Fetch the image content
            $response = $client->get($imageUrl);
            $imageContent = $response->getBody()->getContents();

            // Get image dimensions
            [$width, $height] = getimagesizefromstring($imageContent);

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

    private function detectFromContentType()
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
