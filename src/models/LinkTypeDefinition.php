<?php
namespace verbb\hyper\models;

use verbb\hyper\base\LinkInterface;

use craft\base\Model;

class LinkTypeDefinition extends Model
{
    // Properties
    // =========================================================================

    public string $type = '';
    public string $handle = '';
    public ?string $label = null;
    public bool $enabled = true;
    public bool $isCustom = false;
    public ?string $layoutUid = null;
    public ?array $layoutConfig = null;


    // Public Methods
    // =========================================================================

    public static function fromSettingsArray(array $config): self
    {
        $definition = new self();
        $definition->type = (string)($config['type'] ?? '');
        $definition->handle = (string)($config['handle'] ?? '');
        $definition->label = $config['label'] ?? null;
        $definition->enabled = (bool)($config['enabled'] ?? true);
        $definition->isCustom = (bool)($config['isCustom'] ?? false);
        $definition->layoutUid = $config['layoutUid'] ?? null;
        $definition->layoutConfig = $config['layoutConfig'] ?? null;

        return $definition;
    }

    public static function fromLinkType(LinkInterface $linkType): self
    {
        return self::fromSettingsArray($linkType->getSettingsConfigForDb());
    }

    public function toSettingsArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'handle' => $this->handle,
            'label' => $this->label,
            'enabled' => $this->enabled,
            'isCustom' => $this->isCustom,
            'layoutUid' => $this->layoutUid,
            'layoutConfig' => $this->layoutConfig,
        ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
