<?php
namespace verbb\hyper\links;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\models\Settings;

use craft\helpers\Json;
use craft\helpers\Template;

use Throwable;
use Twig\Markup;

use Embed\Http\Crawler;
use Embed\Http\CurlClient;

class Embed extends Link
{
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

    public function getInputConfig(): array
    {
        $values = parent::getInputConfig();

        $url = $values['linkValue'] ?? null;

        if (is_array($url)) {
            $values['linkValue'] = $values['linkValue']['url'] ?? null;
        }

        return $values;
    }

    public function getSerializedValues(): array
    {
        /* @var Settings $settings */
        $settings = Hyper::$plugin->getSettings();

        $values = parent::getSerializedValues();

        $url = $values['linkValue'] ?? null;

        if (is_string($url)) {
            try {
                $client = new CurlClient();
                $client->setSettings($settings->embedClientSettings);

                $crawler = new Crawler($client);
                $crawler->addDefaultHeaders($settings->embedHeaders);

                $embed = new \Embed\Embed($crawler);
                $embed->setSettings($settings->embedDetectorsSettings);

                $info = $embed->get($url);

                $values['linkValue'] = Json::decode(Json::encode([
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
            } catch (Throwable $e) {
                $values['linkValue'] = null;
            }
        }

        return $values;
    }

    public function getLinkUrl(): ?string
    {
        if (is_array($this->linkValue)) {
            return $this->linkValue['url'] ?? null;
        }

        return $this->linkValue;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText) {
            return $this->linkText;
        }

        if (is_array($this->linkValue)) {
            return $this->linkValue['title'] ?? null;
        }

        return $this->linkValue;
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
