<?php

use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\Hyper;
use yii\queue\ExecEvent;
use yii\queue\Queue;

it('clears linked targets and eager-loading choices between queue jobs', function(string $eventName) {
    $field = F::hyperField();
    $section = F::entrySection($field);
    $target = F::plainEntry($section, 'Queue target');
    $owner = F::entryWithLinks($section, [F::entryLinkPayload($target)]);
    $relations = Hyper::$plugin->getLinkRelations();
    $relations->registerOwner($owner->id, $owner->siteId);
    $relations->primePendingOwners();
    $relations->registerLinkedElementWith($field->id, 'thumbnail');
    expect($relations->getPrimedElement($target->id, $target->siteId))->not->toBeNull();
    expect($relations->hasRequestedEagerLoading())->toBeTrue();

    Craft::$app->getQueue()->trigger($eventName, new ExecEvent());
    expect($relations->getPrimedElement($target->id, $target->siteId))->toBeNull();
    expect($relations->hasRequestedEagerLoading())->toBeFalse();

    // The next job can request the same owner again and load a fresh target.
    $relations->registerOwner($owner->id, $owner->siteId);
    $relations->primePendingOwners();
    expect($relations->getPrimedElement($target->id, $target->siteId)?->id)->toBe($target->id);
})->with([Queue::EVENT_BEFORE_EXEC, Queue::EVENT_AFTER_EXEC, Queue::EVENT_AFTER_ERROR]);
