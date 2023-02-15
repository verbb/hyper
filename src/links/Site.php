<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;
use craft\models\Site as SiteModel;

class Site extends Link 
{
    // Properties
    // =========================================================================

    public string|array|null $sites = '*';
    

    // Public Methods
    // =========================================================================

    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['sites'], 'required', 'when' => function($model) {
            return $model->enabled;
        }];

        return $rules;
    }

    public function getSettingsConfig(): array
    {
        $values = parent::getSettingsConfig();
        $values['sites'] = $this->sites;

        return $values;
    }

    public function getSiteOptions(): array
    {
        $options = [];

        $sites = [];

        if (!$this->sites || $this->sites === '*') {
            $sites = Craft::$app->getSites()->getAllSites();
        } else {
            if (is_array($this->sites)) {
                foreach ($this->sites as $siteUid) {
                    if ($site = Craft::$app->getSites()->getSiteByUid($siteUid)) {
                        $sites[] = $site;
                    }
                }
            }
        }

        foreach ($sites as $site) {
            if ($site->hasUrls) {
                $options[] = [
                    'label' => $site->name,
                    'value' => $site->uid,
                ];
            }
        }

        // Sort alphabetically by label
        usort($options, function($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return $options;
    }

    public function getLinkUrl(): ?string
    {
        if ($site = $this->getLinkSite()) {
            return $site->getBaseUrl();
        }

        return null;
    }

    public function getLinkText(): ?string
    {
        if ($this->linkText) {
            return $this->linkText;
        }
        
        if ($site = $this->getLinkSite()) {
            return $site->name;
        }

        return null;
    }

    public function getLinkSite(): ?SiteModel
    {
        if (!$this->linkValue) {
            return null;
        }

        return Craft::$app->getSites()->getSiteByUid($this->linkValue);
    }
}
