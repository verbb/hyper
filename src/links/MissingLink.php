<?php
namespace verbb\hyper\links;

use verbb\hyper\base\Link;

use Craft;

class MissingLink extends Link
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('hyper', 'Missing Link');
    }


    // Properties
    // =========================================================================
    
    public ?string $expectedType = null;
    public ?string $errorMessage = null;

    private array $_missingProperties = [];


    // Public Methods
    // =========================================================================

    public function __construct(array $config = [])
    {
        Craft::configure($this, $config);
    }

    public function __set($name, $value)
    {
        // Check if the property exists in the class, if so, set it directly
        if (property_exists($this, $name)) {
            $this->$name = $value;
        } else {
            // Otherwise, store it as an unknown property
            $this->_missingProperties[$name] = $value;
        }
    }

    public function getMissingProperties(): array
    {
        return $this->_missingProperties;
    }

    public function applyMissingProperties($target)
    {
        foreach ($this->_missingProperties as $name => $value) {
            if (property_exists($target, $name)) {
                $target->$name = $value;
            }
        }
    }

    public function getSettingsConfigForDb(): array
    {
        $values = parent::getSettingsConfigForDb();

        // Before saving the field's link type settings, swap out the link type in case it comes back
        $values['type'] = $this->expectedType;

        foreach ($this->_missingProperties as $key => $value) {
            $values[$key] = $value;
        }

        return $values;
    }

    public function getLinkUrl(): ?string
    {
        return null;
    }
}
