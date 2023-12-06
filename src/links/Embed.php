<?php
namespace verbb\hyper\links;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\models\Settings;

use Craft;
use craft\helpers\Json;
use craft\helpers\Template;

use Throwable;
use Twig\Markup;

use Embed\Http\Crawler;
use Embed\Http\CurlClient;

class Embed extends Link
{
    // Static Methods
    // =========================================================================

    public static function fetchEmbedData(string $url): array
    {
        /* @var Settings $settings */
        $settings = Hyper::$plugin->getSettings();

        try {
            if (class_exists(CurlClient::class)) {
                // Handle Embed v4 support
                $client = new CurlClient();
                $client->setSettings($settings->embedClientSettings);

                $crawler = new Crawler($client);
                $crawler->addDefaultHeaders($settings->embedHeaders);

                $embed = new \Embed\Embed($crawler);
                $embed->setSettings($settings->embedDetectorsSettings);

                $info = $embed->get($url);

                return Json::decode(Json::encode([
                    'title' => $info->title,
                    'description' => $info->description,
                    'url' => $info->url,
                    'image' => $info->image,
                    'code' => Template::raw($info->code ?: ''),
                    'authorName' => $info->authorName,
                    'authorUrl' => $info->authorUrl,
                    'providerName' => $info->providerName,
                    'providerUrl' => $info->providerUrl,
                    'icon' => $info->icon,
                    'favicon' => $info->favicon,
                    'publishedTime' => $info->publishedTime?->format('c'),
                    'license' => $info->license,
                    'feeds' => $info->feeds,
                ]));
            } else {
                // Handle Embed v3 support
                $dispatcher = new \Embed\Http\CurlDispatcher($settings->embedClientSettings);

                $info = \Embed\Embed::create($url, null, $dispatcher);

                return Json::decode(Json::encode([
                    'title' => $info->title,
                    'description' => $info->description,
                    'url' => $info->url,
                    'image' => $info->image,
                    'code' => Template::raw($info->code ?: ''),
                    'authorName' => $info->authorName,
                    'authorUrl' => $info->authorUrl,
                    'providerName' => $info->providerName,
                    'providerUrl' => $info->providerUrl,
                    'icon' => $info->providerIcon,
                    'favicon' => $info->providerIcon,
                    'publishedTime' => $info->publishedTime?->format('c'),
                    'license' => $info->license,
                    'feeds' => $info->feeds,
                ]));
            }
        } catch (Throwable $e) {
            $error = Craft::t('hyper', 'Unable to fetch embed data for “{url}”: “{message}” {file}:{line}', [
                'url' => $url,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            Hyper::error($error);
        }

        return [];
    }


    // Properties
    // =========================================================================

    public ?string $placeholder = null;


    // Public Methods
    // =========================================================================

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;

        return $values;
    }

    public function getSerializedValues(): array
    {
        $values = parent::getSerializedValues();

        // When editing the field, we'll be saving the value as a JSON string, so be sure to decode it
        // so it doesn't get encoded twice. The field should always store an array, not the URL the user provided.
        if (isset($values['linkValue'])) {
            if (is_string($values['linkValue'])) {
                $values['linkValue'] = Json::decodeIfJson($values['linkValue']);
            }
        }

        return $values;
    }

    public function getLinkUrl(): ?string
    {
        if (is_array($this->linkValue)) {
            return $this->linkValue['url'] ?? null;
        }

        return null;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText) {
            return $this->linkText;
        }

        if (is_array($this->linkValue)) {
            return $this->linkValue['title'] ?? null;
        }

        return null;
    }

    public function getHtml(): ?Markup
    {
        $code = $this->linkValue['code'] ?? '';

        return Template::raw($code);
    }

    public function getData(): ?array
    {
        // The original URL can be saved in the field content, but that's considered invalid.
        if (is_array($this->linkValue)) {
            return $this->linkValue;
        }

        return null;
    }

    public function defaultPlaceholder(): ?string
    {
        return '';
    }

}
