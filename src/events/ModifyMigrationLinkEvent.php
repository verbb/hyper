<?php
namespace verbb\hyper\events;

use craft\events\CancelableEvent;

class ModifyMigrationLinkEvent extends CancelableEvent
{
    // Properties
    // =========================================================================

    public ?string $oldClass = '';
    public ?string $newClass = '';
    
}
