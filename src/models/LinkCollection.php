<?php
namespace verbb\hyper\models;

use verbb\hyper\Hyper;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use craft\base\ElementInterface;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;

class LinkCollection implements LinkCollectionInterface, IteratorAggregate, Countable, ArrayAccess
{
    // Properties
    // =========================================================================

    private HyperField $_field;
    private array $_links = [];
    private ?ElementInterface $_element = null;
    private ?LinkInterface $_firstLink = null;


    // Public Methods
    // =========================================================================

    public function __construct(HyperField $field, array $links = [], ?ElementInterface $element = null)
    {
        $this->_element = $element;
        $this->_field = $field;

        // Convert serialized data to a collection of links via LinkInstance adapter.
        foreach ($links as $data) {
            if ($data instanceof LinkInterface) {
                $this->_links[] = $data;
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            $link = Hyper::$plugin->getLinks()->createLinkFromSerialized($field, $data);

            if ($link) {
                if ($element) {
                    $link->ownerSiteId = $element->siteId;
                }

                $this->_links[] = $link;
            }
        }

        $this->_syncFirstLink();
    }

    public function __toString(): string
    {
        if ($this->_firstLink) {
            return (string)$this->_firstLink;
        }

        return '';
    }

    public function __isset($name): bool
    {
        return isset($this->_firstLink->$name);
    }

    public function __get($name)
    {
        return $this->_firstLink->$name ?? null;
    }

    public function __set($name, $value)
    {
        if ($this->_firstLink) {
            $this->_firstLink->$name = $value;
        }
    }

    public function __call($name, $params)
    {
        if ($this->_firstLink) {
            if (property_exists($this->_firstLink, $name)) {
                return $this->_firstLink->$name;
            }

            return call_user_func_array([$this->_firstLink, $name], $params);
        }

        return $this;
    }

    public function __debugInfo()
    {
        // For developer AX with `dd` and `dump`, keep things lean.
        if (!$this->_field->multipleLinks) {
            if ($this->_firstLink) {
                return $this->_firstLink->__debugInfo();
            }
        }

        return $this->_links;
    }

    public function __clone()
    {
        foreach ($this->_links as $key => $link) {
            $this->_links[$key] = clone $link;
        }

        $this->_syncFirstLink();
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->_links);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->_links[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return isset($this->_links[$offset]) ? $this->_links[$offset] : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->_links[] = $value;
        } else {
            $this->_links[$offset] = $value;
        }

        $this->_syncFirstLink();
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->_links[$offset]);

        $this->_syncFirstLink();
    }

    public function count(): int
    {
        if (!$this->_field->multipleLinks) {
            if ($this->_firstLink) {
                return $this->_firstLink->count();
            }
        }

        return count($this->_links);
    }

    public function isEmpty(): bool
    {
        if (!$this->_field->multipleLinks) {
            return $this->_firstLink === null || $this->_firstLink->isEmpty();
        }

        if ($this->_links === []) {
            return true;
        }

        foreach ($this->_links as $link) {
            if ($link instanceof LinkInterface && !$link->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    public function getLinks(): array
    {
        return $this->_links;
    }

    public function first(): ?LinkInterface
    {
        return $this->_firstLink;
    }

    public function getUrl(): ?string
    {
        return $this->_firstLink?->getUrl();
    }

    public function getText(?string $defaultText = null): ?string
    {
        return $this->_firstLink?->getText($defaultText);
    }

    public function getTarget(): ?string
    {
        return $this->_firstLink?->getTarget();
    }

    public function getTitle(): ?string
    {
        return $this->_firstLink?->getTitle();
    }

    public function getLinkText(): ?string
    {
        return $this->_firstLink?->getLinkText();
    }

    public function getCustomLinkText(): ?string
    {
        return $this->_firstLink?->getCustomLinkText();
    }

    public function getLinkUrl(): ?string
    {
        return $this->_firstLink?->getLinkUrl();
    }

    public function setLinks(array $value): void
    {
        $this->_links = $value;
        $this->_syncFirstLink();
    }

    public function withLinks(array $links): self
    {
        $collection = clone $this;
        $collection->setLinks($links);

        return $collection;
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

    private function _syncFirstLink(): void
    {
        $this->_firstLink = $this->_links !== [] ? reset($this->_links) : null;
    }
}
