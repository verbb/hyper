<?php
namespace verbb\hyper\models;

use verbb\hyper\fields\HyperField;
use verbb\hyper\services\LinkTypeConfigs;

use Craft;
use craft\base\Model;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;

class LinkTypeConfig extends Model
{
    // Properties
    // =========================================================================

    public ?string $uid = null;
    public string $name = '';
    public string $handle = '';
    public int $sortOrder = 0;
    public array $linkTypes = [];


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        if (!$this->uid) {
            $this->uid = StringHelper::UUID();
        }
    }

    public static function fromConfig(array $config): self
    {
        $config = ProjectConfigHelper::unpackAssociativeArrays($config);
        $model = new self();
        $model->uid = $config['uid'] ?? null;
        $model->name = (string)($config['name'] ?? '');
        $model->handle = (string)($config['handle'] ?? '');
        $model->sortOrder = (int)($config['sortOrder'] ?? 0);

        $linkTypes = $config['linkTypes'] ?? [];
        $model->linkTypes = is_array($linkTypes) ? array_values(array_filter($linkTypes, 'is_array')) : [];

        return $model;
    }

    public function toConfig(): array
    {
        return array_filter([
            'uid' => $this->uid,
            'name' => $this->name,
            'handle' => $this->handle,
            'sortOrder' => $this->sortOrder,
            'linkTypes' => $this->linkTypes,
        ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function getLinkTypeDefinitions(): array
    {
        return array_map(
            static fn(array $linkType): LinkTypeDefinition => LinkTypeDefinition::fromSettingsArray($linkType),
            $this->linkTypes,
        );
    }

    public function canDelete(): bool
    {
        if ($this->handle === LinkTypeConfigs::DEFAULT_HANDLE) {
            return false;
        }

        foreach (Craft::$app->getFields()->getAllFields(false) as $field) {
            if (
                $field instanceof HyperField &&
                !$field->hasCustomLinkTypes() &&
                (
                    $field->linkTypeConfig === $this->handle ||
                    $field->linkTypeConfig === $this->uid
                )
            ) {
                return false;
            }
        }

        return true;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][\w\-]*$/'];

        return $rules;
    }
}
