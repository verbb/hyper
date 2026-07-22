<?php
namespace verbb\hyper\models;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use craft\base\Model;

class LinkInstance extends Model
{
    // Properties
    // =========================================================================

    public string $linkTypeHandle = '';
    public ?bool $newWindow = null;
    public mixed $linkValue = null;
    public ?int $linkSiteId = null;
    public ?string $linkText = null;
    public ?string $ariaLabel = null;
    public ?string $urlSuffix = null;
    public ?string $linkTitle = null;
    public ?string $classes = null;
    public array $customAttributes = [];
    public array $fields = [];
    public ?string $uid = null;


    // Public Methods
    // =========================================================================

    public static function fromSerialized(array $data, HyperField $field): self
    {
        $instance = new self();
        $instance->linkTypeHandle = self::_resolveLinkTypeHandle($data, $field);
        $instance->newWindow = $data['newWindow'] ?? null;
        $instance->linkValue = self::_normalizeLinkValue($data['linkValue'] ?? null);
        $instance->linkSiteId = isset($data['linkSiteId']) ? (int)$data['linkSiteId'] : null;
        $instance->linkText = $data['linkText'] ?? null;
        $instance->ariaLabel = $data['ariaLabel'] ?? null;
        $instance->urlSuffix = $data['urlSuffix'] ?? null;
        $instance->linkTitle = $data['linkTitle'] ?? null;
        $instance->classes = $data['classes'] ?? null;
        $instance->customAttributes = $data['customAttributes'] ?? [];
        $instance->fields = $data['fields'] ?? [];
        $instance->uid = !empty($data['uid']) ? (string)$data['uid'] : null;

        return $instance;
    }

    public static function fromLink(LinkInterface $link, HyperField $field): self
    {
        if ($link instanceof Link) {
            return $link->toInstance();
        }

        return new self();
    }

    public function toLinkAttributes(): array
    {
        return array_filter([
            'handle' => $this->linkTypeHandle,
            'linkTypeHandle' => $this->linkTypeHandle,
            'uid' => $this->uid,
            'newWindow' => $this->newWindow,
            'linkValue' => $this->linkValue,
            'linkSiteId' => $this->linkSiteId,
            'linkText' => $this->linkText,
            'ariaLabel' => $this->ariaLabel,
            'urlSuffix' => $this->urlSuffix,
            'linkTitle' => $this->linkTitle,
            'classes' => $this->classes,
            'customAttributes' => $this->customAttributes,
            'fields' => $this->fields,
        ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function toSerialized(): array
    {
        return array_filter([
            'linkTypeHandle' => $this->linkTypeHandle,
            'uid' => $this->uid,
            'newWindow' => $this->newWindow,
            'linkValue' => $this->linkValue,
            'linkSiteId' => $this->linkSiteId,
            'linkText' => $this->linkText,
            'ariaLabel' => $this->ariaLabel,
            'urlSuffix' => $this->urlSuffix,
            'linkTitle' => $this->linkTitle,
            'classes' => $this->classes,
            'customAttributes' => $this->customAttributes,
            'fields' => $this->fields,
        ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    public function toLegacySerialized(): array
    {
        $values = $this->toSerialized();
        unset($values['linkTypeHandle']);

        $values['handle'] = $this->linkTypeHandle;

        return $values;
    }

    public function hasMeaningfulAttributes(): bool
    {
        if ($this->newWindow !== null) {
            return true;
        }

        if ($this->linkText || $this->linkTitle || $this->ariaLabel || $this->classes || $this->urlSuffix) {
            return true;
        }

        if ($this->customAttributes) {
            return true;
        }

        foreach ($this->fields as $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasLinkValue(): bool
    {
        if (is_array($this->linkValue)) {
            return (bool)array_filter($this->linkValue, static fn(mixed $value): bool => $value !== null && $value !== '');
        }

        return $this->linkValue !== null && $this->linkValue !== '';
    }


    // Private Methods
    // =========================================================================

    private static function _resolveLinkTypeHandle(array $data, HyperField $field): string
    {
        if (!empty($data['linkTypeHandle'])) {
            return (string)$data['linkTypeHandle'];
        }

        if (!empty($data['handle'])) {
            return (string)$data['handle'];
        }

        if (!empty($data['type'])) {
            foreach ($field->getLinkTypes() as $linkType) {
                // Accept either the FQCN (verbb\hyper\links\Url) or the short, author-owned
                // type key (`url`) as `type` — the latter is what we now document for
                // programmatic content, so developers never have to reference class names.
                if ($linkType::class === $data['type'] || $linkType::typeKey() === $data['type']) {
                    return (string)$linkType->handle;
                }
            }
        }

        return (string)$field->defaultLinkType;
    }

    private static function _normalizeLinkValue(mixed $linkValue): mixed
    {
        if ($linkValue === '' || $linkValue === []) {
            return null;
        }

        return $linkValue;
    }
}
