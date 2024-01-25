<?php
namespace verbb\hyper\links;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\helpers\EmbedImagesExtractor;
use verbb\hyper\models\Settings;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\Template;

use DateTime;
use Exception;
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

        $url = trim($url);

        if (!$url) {
            return [];
        }

        try {
            if (class_exists(CurlClient::class)) {
                // Handle Embed v4 support
                $client = new CurlClient();
                $client->setSettings($settings->embedClientSettings);

                $crawler = new Crawler($client);
                $crawler->addDefaultHeaders($settings->embedHeaders);

                $embed = new \Embed\Embed($crawler);
                $embed->setSettings($settings->embedDetectorsSettings);

                // Override the image detector. Restores Embed v3 behaviour.
                $embed->getExtractorFactory()->addDetector('image', EmbedImagesExtractor::class);

                $info = $embed->get($url);
                $image = $info->image ?? [];

                $data = Json::decode(Json::encode([
                    'title' => $info->title,
                    'description' => $info->description,
                    'url' => $info->url,
                    'code' => Template::raw($info->code ?: ''),
                    'authorName' => $info->authorName,
                    'authorUrl' => $info->authorUrl,
                    'providerName' => $info->providerName,
                    'providerUrl' => $info->providerUrl,
                    'icon' => $info->icon,
                    'favicon' => $info->favicon,
                    'publishedTime' => $info->publishedTime instanceof DateTime ? $info->publishedTime->format('c') : $info->publishedTime,
                    'license' => $info->license,
                    'feeds' => $info->feeds,

                    // Images will always be an array to handle if we are fetching image metadata
                    ...$image,
                ]));

                // Flag an invalid embed URL - still a response, but no code
                if (!trim($data['code'])) {
                    throw new Exception('Embed URL invalid.');
                }

                return $data;
            } else {
                // Handle Embed v3 support
                $dispatcher = new \Embed\Http\CurlDispatcher($settings->embedClientSettings);

                $info = \Embed\Embed::create($url, $settings->getEmbedClientConfig(), $dispatcher);

                $data = Json::decode(Json::encode([
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
                    'publishedTime' => $info->publishedTime instanceof DateTime ? $info->publishedTime->format('c') : $info->publishedTime,
                    'license' => $info->license,
                    'feeds' => $info->feeds,
                ]));

                // Flag an invalid embed URL - still a response, but no code
                if (!trim($data['code'])) {
                    throw new Exception('Embed URL invalid.');
                }

                return $data;
            }
        } catch (Throwable $e) {
            $error = Craft::t('hyper', 'Unable to fetch embed data for “{url}”: “{message}” {file}:{line}', [
                'url' => $url,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            Hyper::error($error);

            return ['error' => Craft::t('hyper', 'Unable to fetch embed data for “{url}”: “{message}”', [
                'url' => $url,
                'message' => $e->getMessage(),
            ])];
        }

        return [];
    }

    public static function getPreviewHtml(string $html): ?string
    {
        // Check if this contains an iframe already, if not - create one
        if (!str_contains($html, '<iframe')) {
            $src = htmlspecialchars('data:text/html,' . rawurlencode($html));
            $html = Html::tag('iframe', '', ['src' => $src, 'height' => 200]);
        }

        return Html::tag('div', $html, ['class' => 'hyper-iframe-container']);
    }


    // Properties
    // =========================================================================

    public ?string $placeholder = null;


    // Public Methods
    // =========================================================================

    public function setAttributes($values, $safeOnly = true): void
    {
        // Normalize the link value
        $linkValue = $values['linkValue'] ?? null;

        if (is_string($linkValue)) {
            if (Json::isJsonObject($linkValue)) {
                // We might be sending JSON from the CP
                $values['linkValue'] = Json::decodeIfJson($values['linkValue']);
            } else {
                // Or, we provided just the URL
                $values['linkValue'] = self::fetchEmbedData($values['linkValue']);
            }
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $settings = Hyper::$plugin->getSettings();

        $rules[] = [['linkValue'], function($attribute) use ($settings) {
            $linkValue = trim($this->$attribute['url'] ?? '');

            if ($linkValue && !$settings->doesUrlMatchDomain($linkValue)) {
                $this->addError($attribute, Craft::t('hyper', 'URL domain not allowed.'));
            }
        }, 'when' => function($model) use ($settings) {
            return $settings->embedAllowedDomains;
        }];

        // Check if we have an invalid payload
        $rules[] = [['linkValue'], function($attribute) {
            $fetchError = $this->$attribute['error'] ?? null;

            if ($fetchError) {
                $this->addError($attribute, $fetchError);
            }
        }];

        return $rules;
    }

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;

        return $values;
    }

    public function getLinkUrl(): ?string
    {
        return $this->linkValue['url'] ?? null;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText) {
            return $this->linkText;
        }

        return $this->linkValue['title'] ?? null;
    }

    public function getLinkTitle(): ?string
    {
        // Use the description for the link title - only if that field is enabled
        if ($this->getFieldLayout()->isFieldIncluded('linkTitle')) {
            return $this->linkValue['description'] ?? null;
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
        return $this->linkValue;
    }

    public function defaultPlaceholder(): ?string
    {
        return '';
    }

}
