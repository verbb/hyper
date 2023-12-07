<?php
namespace verbb\hyper\helpers;

use Embed\Detectors\Detector;

use GuzzleHttp\Client;

use Psr\Http\Message\UriInterface;

class EmbedImagesExtractor extends Detector
{
    // Public Methods
    // =========================================================================

    public function detect(): ?UriInterface
    {
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
                $largestImage = $imageUrl;
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
