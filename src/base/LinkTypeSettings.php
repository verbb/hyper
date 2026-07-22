<?php
namespace verbb\hyper\base;

use verbb\hyper\Hyper;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkTypeDefinition;

use Craft;
use craft\base\Element;
use craft\models\FieldLayout;

class LinkTypeSettings extends Element
{
    // Static Methods
    // =========================================================================

    public static function hasContent(): bool
    {
        return true;
    }

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Link Type Settings');
    }


    // Properties
    // =========================================================================

    public ?string $registryType = null;
    public ?string $handle = null;
    public ?string $label = null;
    public bool $enabled = true;
    public bool $isCustom = false;
    public ?string $layoutUid = null;
    public ?array $layoutConfig = null;

    private ?FieldLayout $_fieldLayout = null;


    // Public Methods
    // =========================================================================

    public static function fromDefinition(LinkTypeDefinition $definition): self
    {
        $settings = new self();
        $settings->registryType = $definition->type;
        $settings->handle = $definition->handle;
        $settings->label = $definition->label;
        $settings->enabled = $definition->enabled;
        $settings->isCustom = $definition->isCustom;
        $settings->layoutUid = $definition->layoutUid;
        $settings->layoutConfig = $definition->layoutConfig;

        return $settings;
    }

    public function toDefinition(): LinkTypeDefinition
    {
        return LinkTypeDefinition::fromSettingsArray([
            'type' => $this->registryType,
            'handle' => $this->handle,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'isCustom' => $this->isCustom,
            'layoutUid' => $this->layoutUid,
            'layoutConfig' => $this->layoutConfig,
        ]);
    }

    public function getRegistryLink(?HyperField $field = null): ?LinkInterface
    {
        if (!$this->registryType) {
            return null;
        }

        $link = Hyper::$plugin->getLinks()->createSettingsPrototype($this->toDefinition()->toSettingsArray());

        if ($field) {
            $link->field = $field;
        }

        return $link;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        if ($this->_fieldLayout !== null) {
            return $this->_fieldLayout;
        }

        if ($this->layoutUid) {
            $this->_fieldLayout = Craft::$app->getFields()->getLayoutByUid($this->layoutUid);
        }

        return $this->_fieldLayout;
    }

    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $this->_fieldLayout = $fieldLayout;
    }
}
