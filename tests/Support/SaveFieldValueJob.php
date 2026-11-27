<?php

namespace Tests\Support;

use Craft;
use craft\elements\Entry;
use craft\errors\InvalidElementException;
use craft\queue\BaseJob;

/** Saves one field value in a queue job, as import and resave jobs do. */
final class SaveFieldValueJob extends BaseJob
{
    public int $elementId;
    public string $fieldHandle;
    public mixed $value = null;

    public function execute($queue): void
    {
        $element = Entry::find()->id($this->elementId)->status(null)->one();
        $element->setFieldValue($this->fieldHandle, $this->value);

        if (!Craft::$app->getElements()->saveElement($element, false)) {
            throw new InvalidElementException($element);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Saving a Hyper test value';
    }
}
