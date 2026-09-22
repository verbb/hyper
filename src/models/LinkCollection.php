<?php
namespace verbb\hyper\models;

use verbb\hyper\Hyper;
use verbb\hyper\base\Link;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\helpers\LinkCollectionFilter;

use craft\base\ElementInterface;

use yii\base\Component;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;

use Twig\Markup;

class LinkCollection extends Component implements LinkCollectionInterface, IteratorAggregate, Countable, ArrayAccess
{
    // Properties
    // =========================================================================

    private HyperField $_field;
    private array $_links = [];
    private ?ElementInterface $_element = null;
    private ?int $_ownerSiteId = null;
    private ?bool $_empty = false;
    private array $_condition = [];
    private ?Closure $_predicate = null;
    private array $_orderBy = [];
    private ?int $_limit = null;
    private int $_offset = 0;


    // Public Methods
    // =========================================================================

    public function __construct(HyperField $field, array $links = [], ?ElementInterface $element = null, ?int $ownerSiteId = null)
    {
        $this->_element = $element;
        $this->_ownerSiteId = $element?->siteId ?? $ownerSiteId;
        $this->_field = $field;

        $this->setLinks($links);

        parent::__construct();
    }

    public function __toString(): string
    {
        return (string)($this->getUrl() ?? '');
    }

    public function __debugInfo(): array
    {
        return $this->all();
    }

    public function __clone()
    {
        foreach ($this->_links as $key => $link) {
            $this->_links[$key] = clone $link;
        }
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->all());
    }

    public function offsetExists(mixed $offset): bool
    {
        return (is_int($offset) || ctype_digit((string)$offset)) && isset($this->all()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->all()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $keys = array_keys($this->_selectedLinks());

        if ($offset !== null && (!is_int($offset) || $offset < 0 || $offset > count($keys))) {
            throw new OutOfBoundsException('The link index must identify a selected link or the next position.');
        }

        if (!$link = $this->_createLink($value)) {
            return;
        }

        if ($offset === null || $offset === count($keys)) {
            $this->_links[] = $link;
        } else {
            $this->_links[$keys[$offset]] = $link;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $keys = array_keys($this->_selectedLinks());

        if (isset($keys[$offset])) {
            unset($this->_links[$keys[$offset]]);
        }
    }

    public function count(): int
    {
        return count($this->_selectedLinks());
    }

    public function isEmpty(): bool
    {
        return !$this->exists();
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * Stored links are independent of the template selection. Editing, validation,
     * propagation and serialization must retain unfinished or unavailable links.
     */
    public function getLinks(): array
    {
        return $this->_links;
    }

    public function all(): array
    {
        return array_values($this->_selectedLinks());
    }

    public function first(): ?LinkInterface
    {
        $links = $this->_selectedLinks();

        return $links ? reset($links) : null;
    }

    public function empty(?bool $empty = false): self
    {
        $collection = $this->_copySelection();
        $collection->_empty = $empty;

        return $collection;
    }

    public function where(array $condition): self
    {
        $collection = $this->_copySelection();
        $collection->_condition = $condition;
        $collection->_predicate = LinkCollectionFilter::predicate($condition);

        return $collection;
    }

    public function andWhere(array $condition): self
    {
        return $this->where(['and', $this->_condition, $condition]);
    }

    public function orWhere(array $condition): self
    {
        return $this->_condition === [] ? $this->where($condition) : $this->where(['or', $this->_condition, $condition]);
    }

    public function orderBy(array|string $columns): self
    {
        $collection = $this->_copySelection();
        $collection->_orderBy = LinkCollectionFilter::orderBy($columns);

        return $collection;
    }

    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('The link limit must be null or a non-negative integer.');
        }

        $collection = $this->_copySelection();
        $collection->_limit = $limit;

        return $collection;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('The link offset must be a non-negative integer.');
        }

        $collection = $this->_copySelection();
        $collection->_offset = $offset;

        return $collection;
    }

    public function getLink(array $attributes = []): ?Markup
    {
        return $this->first()?->getLink($attributes);
    }

    public function getUrl(): ?string
    {
        return $this->first()?->getUrl();
    }

    public function getText(?string $defaultText = null): ?string
    {
        return $this->first()?->getText($defaultText);
    }

    public function getTarget(): ?string
    {
        return $this->first()?->getTarget();
    }

    public function getTitle(): ?string
    {
        return $this->first()?->getTitle();
    }

    public function getLinkText(): ?string
    {
        return $this->first()?->getLinkText();
    }

    public function getCustomLinkText(): ?string
    {
        return $this->first()?->getCustomLinkText();
    }

    public function getLinkUrl(): ?string
    {
        return $this->first()?->getLinkUrl();
    }

    public function setLinks(array $value): void
    {
        $links = [];

        foreach ($value as $data) {
            if ($link = $this->_createLink($data)) {
                $links[] = $link;
            }
        }

        $this->_links = $links;
    }

    public function withLinks(array $links): self
    {
        $collection = clone $this;
        $collection->setLinks($links);

        return $collection;
    }

    public function withFieldContext(HyperField $field, ?ElementInterface $element = null): self
    {
        if ($this->_field === $field && $this->_element === $element) {
            return $this;
        }

        // Normalized values can be assigned to a different field or owner. Their
        // links must use the receiving settings without changing the source value.
        return new self($field, $this->_links, $element, $this->_ownerSiteId);
    }

    public function serializeValues(?ElementInterface $element = null): array
    {
        $values = [];

        foreach ($this->_links as $link) {
            if ($link instanceof LinkInterface) {
                $values[] = $link->getSerializedValues();
            }
        }

        return $values;
    }


    // Private Methods
    // =========================================================================

    private function _createLink(mixed $data): ?LinkInterface
    {
        // Every insertion path applies the destination layout and settings.
        if ($data instanceof LinkInterface) {
            $link = $this->_rebindLinkObject($this->_field, $data);
        } elseif (is_array($data)) {
            $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($this->_field, $data);
        } else {
            return null;
        }

        if ($link && $this->_ownerSiteId !== null) {
            $link->ownerSiteId = $this->_ownerSiteId;
            $link->siteId = $this->_ownerSiteId;
        }

        return $link;
    }

    /**
     * Rebind a bare Link object onto this field’s configured prototype/layout so
     * programmatic `new Url(); $link->fields = […]` examples work (Astra H3-A17).
     */
    private function _rebindLinkObject(HyperField $field, LinkInterface $link): LinkInterface
    {
        if ($link instanceof \verbb\hyper\links\MissingLink) {
            return Hyper::$plugin->getLinks()->createLinkFromSerialized($field, $link->getSerializedValues());
        }

        if ($link instanceof Link) {
            // Build an instance without calling getSerializedValues() — bare objects may
            // lack a field layout and that path would NPE on custom fields (A17).
            $instance = new LinkInstance();
            $instance->linkTypeHandle = (string)($link->handle ?: $link::typeKey());
            $instance->uid = $link->uid;
            $instance->newWindow = $link->newWindow;
            $instance->linkValue = $link->linkValue;
            $instance->linkText = $link->linkText;
            $instance->ariaLabel = $link->ariaLabel;
            $instance->urlSuffix = $link->urlSuffix;
            $instance->linkTitle = $link->linkTitle;
            $instance->classes = $link->classes;
            $instance->customAttributes = $link->customAttributes ?? [];
            $instance->fields = $link->fields ?? [];

            if ($link instanceof \verbb\hyper\base\ElementLink && $link->linkSiteId) {
                $instance->linkSiteId = (int)$link->linkSiteId;
            }

            $rebound = Hyper::$plugin->getLinks()->createLinkFromInstance($field, $instance);

            if ($rebound) {
                if ($this->_ownerSiteId !== null) {
                    $rebound->ownerSiteId = $this->_ownerSiteId;
                    $rebound->siteId = $this->_ownerSiteId;
                }

                if ($instance->fields && $rebound instanceof Link) {
                    foreach ($instance->fields as $handle => $value) {
                        try {
                            $rebound->setFieldValue($handle, $value);
                        } catch (\Throwable) {
                            // Unknown handle on destination layout.
                        }
                    }

                    $rebound->fields = $instance->fields;
                }

                return $rebound;
            }
        }

        $link->field = $field;

        if ($this->_ownerSiteId !== null) {
            $link->ownerSiteId = $this->_ownerSiteId;
            $link->siteId = $this->_ownerSiteId;
        }

        return $link;
    }

    private function _copySelection(): self
    {
        // A new selection shares its Link objects but never clones or rehydrates
        // their custom fields. Explicitly cloning a collection still copies links.
        $collection = new self($this->_field, [], $this->_element, $this->_ownerSiteId);
        $collection->_links = $this->_links;
        $collection->_empty = $this->_empty;
        $collection->_condition = $this->_condition;
        $collection->_predicate = $this->_predicate;
        $collection->_orderBy = $this->_orderBy;
        $collection->_limit = $this->_limit;
        $collection->_offset = $this->_offset;

        return $collection;
    }

    private function _selectedLinks(): array
    {
        // Cheap attribute filters run before destination resolution. The empty
        // scope is outside the Boolean expression, so OR cannot bypass it.
        $links = array_filter($this->_links, fn(LinkInterface $link): bool =>
            (!$this->_predicate || ($this->_predicate)($link))
            && ($this->_empty === null || $link->isEmpty() === $this->_empty)
        );

        if ($this->_orderBy) {
            // PHP's stable sort retains authored order for equal field values.
            uasort($links, function(LinkInterface $a, LinkInterface $b): int {
                foreach ($this->_orderBy as $attribute => $direction) {
                    $comparison = LinkCollectionFilter::value($a, $attribute) <=> LinkCollectionFilter::value($b, $attribute);

                    if ($comparison !== 0) {
                        return $direction === SORT_DESC ? -$comparison : $comparison;
                    }
                }

                return 0;
            });
        }

        return array_slice($links, $this->_offset, $this->_limit, true);
    }
}
