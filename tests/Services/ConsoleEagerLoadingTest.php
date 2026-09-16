<?php

use Symfony\Component\Process\Process;
use Tests\Support\Fixtures\HyperFixtureFactory as F;

it('honors explicit eager-loading paths in a real non-testing console request', function() {
    $related = F::entriesField();
    $section = F::entrySectionWithField($related);
    $child = F::plainEntry($section, 'Related destination');
    $field = F::hyperField();
    $ownerSection = F::entrySection($field);
    $ownerIds = $targetIds = [];
    for ($i = 0; $i < 4; $i++) {
        $target = F::plainEntry($section, 'Target ' . $i, [$related->handle => [$child->id]]);
        $targetIds[] = $target->id;
        $ownerIds[] = F::entryWithLinks($ownerSection, [F::entryLinkPayload($target)])->id;
    }
    $fixture = ['relatedFieldId' => $related->id, 'hyperFieldId' => $field->id, 'childId' => $child->id, 'ownerIds' => $ownerIds, 'targetIds' => $targetIds];
    $process = new Process([PHP_BINARY, __DIR__ . '/../Support/console-eager-loading.php', json_encode($fixture)]);
    $process->setTimeout(120);
    $process->mustRun();
    $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['results'] as $result) {
        expect($result['correctTargets'])->toBeTrue();
        expect($result['relatedIds'])->toBe($data['expectedRelated']);
    }
    expect($data['results']['none']['targetQueries'])->toBeGreaterThan(0);
    expect($data['results']['plain']['targetQueries'])->toBe(0);
    expect($data['results']['nested']['targetQueries'])->toBe(0);
    expect($data['results']['nested']['eagerRelated'])->toBe(array_fill(0, 4, true));
});
