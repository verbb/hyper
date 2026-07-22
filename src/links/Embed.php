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

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Embed');
    }

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
                $imageMeta = self::_normalizeEmbedImageMeta($info->image ?? null);

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

                    // Named image keys (image, imageWidth, imageHeight) from EmbedImagesExtractor
                    ...$imageMeta,
                ]));

                // If no embed code, create it
                if (!trim($data['code'])) {
                    $data['code'] = '<iframe src="' . $info->url . '"></iframe>';
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

                // If no embed code, create it
                if (!trim($data['code'])) {
                    $data['code'] = '<iframe src="' . $info->url . '"></iframe>';
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
    public array $allowedDomains = [];


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
                // Or, we provided just the URL — persist the URL even if embed fetch fails or is slow
                $url = trim($linkValue);
                $data = self::fetchEmbedData($url);

                if (isset($data['error']) || ($url && empty($data))) {
                    $values['linkValue'] = $url ? ['url' => $url] : null;
                } else {
                    $values['linkValue'] = $data ?: null;
                }
            }
        }

        // Settings UI posts domains as a newline-separated string
        if (isset($values['allowedDomains']) && is_string($values['allowedDomains'])) {
            $values['allowedDomains'] = self::parseListSetting($values['allowedDomains']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['linkValue'], function($attribute) {
            $url = trim($this->$attribute['url'] ?? '');

            if ($url && !$this->isEmbedUrlAllowed($url)) {
                $this->addError($attribute, Craft::t('hyper', 'URL domain not allowed.'));
            }
        }, 'when' => function() {
            return $this->getEffectiveAllowedDomains() !== [];
        }];

        // Check if we have an invalid payload (allow url-only payloads saved before embed preview completes)
        $rules[] = [['linkValue'], function($attribute) {
            $linkValue = $this->$attribute;
            $fetchError = is_array($linkValue) ? ($linkValue['error'] ?? null) : null;
            $url = is_array($linkValue) ? trim($linkValue['url'] ?? '') : '';

            if ($fetchError && !$url) {
                $this->addError($attribute, $fetchError);
            }
        }];

        return $rules;
    }

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['placeholder'] = $this->placeholder;
        $values['allowedDomains'] = array_values($this->allowedDomains);

        return $values;
    }

    public function getEffectiveAllowedDomains(): array
    {
        if ($this->allowedDomains !== []) {
            return array_values($this->allowedDomains);
        }

        return array_values(Hyper::$plugin->getSettings()->embedAllowedDomains);
    }

    public function isEmbedUrlAllowed(string $url): bool
    {
        $domains = $this->getEffectiveAllowedDomains();

        if ($domains === []) {
            return true;
        }

        return Hyper::$plugin->getSettings()->doesUrlMatchDomain($url, $domains);
    }

    public static function parseListSetting(string $value): array
    {
        $lines = preg_split('/\R+/', $value) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_values(array_unique($out));
    }

    private static function _normalizeEmbedImageMeta(mixed $image): array
    {
        if ($image === null || $image === '') {
            return [];
        }

        if ($image instanceof \Stringable || is_scalar($image)) {
            return ['image' => (string)$image];
        }

        if (!is_array($image)) {
            return [];
        }

        // Detector returned ['image' => …, 'imageWidth' => …] — keep named keys
        if (array_key_exists('image', $image) || array_key_exists('imageWidth', $image)) {
            $meta = [];

            if (array_key_exists('image', $image)) {
                $meta['image'] = $image['image'] instanceof \Stringable || is_scalar($image['image'] ?? null)
                    ? (string)$image['image']
                    : null;
            }

            if (isset($image['imageWidth'])) {
                $meta['imageWidth'] = (int)$image['imageWidth'];
            }

            if (isset($image['imageHeight'])) {
                $meta['imageHeight'] = (int)$image['imageHeight'];
            }

            return $meta;
        }

        // List-shaped fallback (legacy)
        $first = $image[0] ?? null;

        if ($first instanceof \Stringable || is_scalar($first)) {
            return ['image' => (string)$first];
        }

        return [];
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

        if ($code === '' || $code === null) {
            return null;
        }

        return Template::raw((string)$code);
    }

    public function getIframeSrc(): ?string
    {
        $code = trim((string)($this->linkValue['code'] ?? ''));

        if ($code === '') {
            return null;
        }

        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $code, $matches)) {
            return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    public function getEmbedProviderName(): ?string
    {
        $name = $this->linkValue['providerName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function getEmbedImage(): ?string
    {
        $image = $this->linkValue['image'] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }

    public function getData(): ?array
    {
        return $this->linkValue;
    }

}
