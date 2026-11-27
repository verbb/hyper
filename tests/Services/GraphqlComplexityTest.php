<?php

use craft\base\EagerLoadingFieldInterface;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\fields\Users;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\services\Gql as GqlService;
use GraphQL\Type\Definition\Type;
use Tests\Support\Fixtures\HyperFixtureFactory as F;
use verbb\hyper\links\Entry as EntryLink;
use verbb\hyper\links\Url;

// An eager-loading field that returns a bare GraphQL type rather than a field definition.
class HyperComplexityBareTypeField extends PlainText implements EagerLoadingFieldInterface
{
    public function getEagerLoadingMap(array $sourceElements): array|null|false
    {
        return false;
    }

    public function getContentGqlType(): Type|array
    {
        return Type::string();
    }
}

// Reports the complexity Craft's validation rule calculates, or null when the query passes the limit.
function hyperComplexityRun(GqlSchema $schema, string $query, int $limit): array
{
    $config = Craft::$app->getConfig()->getGeneral();
    $previous = [$config->maxGraphqlComplexity, $config->enableGraphqlCaching];
    $config->maxGraphqlComplexity = $limit;
    $config->enableGraphqlCaching = false;
    // Craft's field-argument and type caches outlive flushCaches(), so each run uses a fresh service.
    Craft::$app->gql->flushCaches();
    $gql = new GqlService();
    Craft::$app->set('gql', $gql);

    try {
        $result = $gql->executeQuery($schema, $query, debugMode: true);
    } finally {
        [$config->maxGraphqlComplexity, $config->enableGraphqlCaching] = $previous;
        $gql->flushCaches();
    }

    $message = $result['errors'][0]['message'] ?? '';

    return [
        'result' => $result,
        'complexity' => preg_match('/Max query complexity should be \d+ but got (\d+)/', $message, $match) ? (int)$match[1] : null,
    ];
}

function hyperComplexity(GqlSchema $schema, string $query): int
{
    $run = hyperComplexityRun($schema, $query, 1);
    expect($run['complexity'])->not->toBeNull(json_encode($run['result']));

    return $run['complexity'];
}

it('counts fields on linked elements only when they would query once per link', function() {
    $field = F::hyperField(['linkTypes' => [EntryLink::class]]);
    $section = F::entrySection($field);
    $related = F::entriesField();
    F::attachFieldToSection($section, $related);
    $section = Craft::$app->entries->getSectionById($section->id);
    $target = F::plainEntry($section, 'Destination');
    $owner = F::plainEntry($section, 'Owner', [
        $field->handle => [F::entryLinkPayload($target)],
        $related->handle => [$target->id],
    ]);
    $entryType = $section->getEntryTypes()[0]->handle . '_Entry';
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Hyper complexity', 'scope' => ['sections.' . $section->uid . ':read']]);
    $query = fn(string $selection) => '{ entries(id: ' . $owner->id . ') { ... on ' . $entryType . ' { ' . $selection . ' } } }';
    $original = Craft::$app->gql;

    try {
        $linksOnly = hyperComplexity($schema, $query($field->handle . ' { url }'));
        $withElement = hyperComplexity($schema, $query($field->handle . ' { url element { id } }'));
        $relationSelection = $field->handle . ' { url element { ... on ' . $entryType . ' { ' . $related->handle . ' { id } } } }';
        $withRelation = hyperComplexity($schema, $query($relationSelection));
        $native = hyperComplexity($schema, $query($related->handle . ' { id }'));
        $docs = hyperComplexity($schema, $query($field->handle . ' { type text url target newWindow isElement element { id title } }'));
        $entries = GqlService::GRAPHQL_COMPLEXITY_QUERY;
        $simple = GqlService::GRAPHQL_COMPLEXITY_SIMPLE_FIELD;
        $eagerLoad = GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD;

        // Links load in one batch per request, which Craft scores like its eager-loaded fields.
        expect($native)->toBe($entries + GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD + $simple);
        expect($linksOnly)->toBe($entries + GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD + $simple);
        // Targets are batched, so plain fields on the linked element score as plain fields.
        expect($withElement - $linksOnly)->toBe($simple + $simple);
        // The documented `HyperLinkInterface` query: seven fields, two of them on the element.
        expect($docs)->toBe($entries + $eagerLoad + 6 * $simple + $simple + 2 * $simple);
        // Fields Craft would eager-load aren't eager-loaded on linked elements, so each link runs its own queries.
        expect($withRelation - $linksOnly)->toBe(GqlService::GRAPHQL_COMPLEXITY_NPLUS1 + $eagerLoad + $simple);
        expect($withRelation)->toBeGreaterThanOrEqual(GqlService::GRAPHQL_COMPLEXITY_NPLUS1);

        // A configured limit now sees fields on the linked element that query once per link, while results stay the same.
        $limit = GqlService::GRAPHQL_COMPLEXITY_NPLUS1;
        expect(hyperComplexityRun($schema, $query($relationSelection), $limit)['complexity'])->toBe($withRelation);
        $allowed = hyperComplexityRun($schema, $query($field->handle . ' { url element { id } }'), $limit)['result'];
        expect($allowed['errors'] ?? [])->toBe([], json_encode($allowed));
        expect($allowed['data']['entries'][0][$field->handle])->toBe([['url' => $target->getUrl(), 'element' => ['id' => (string)$target->id]]]);
        $unlimited = hyperComplexityRun($schema, $query($relationSelection), 0)['result'];
        expect($unlimited['errors'] ?? [])->toBe([], json_encode($unlimited));
        expect($unlimited['data']['entries'][0][$field->handle])->toBe([[
            'url' => $target->getUrl(), 'element' => [$related->handle => []],
        ]]);
    } finally {
        Craft::$app->set('gql', $original);
        $original->flushCaches();
        Craft::$app->fields->deleteField($related);
    }
});

it('counts relational fields inside link layouts as running once per link', function() {
    $text = new PlainText(['name' => 'Caption', 'handle' => F::handle('complexityCaption')]);
    $users = new Users(['name' => 'People', 'handle' => F::handle('complexityPeople')]);
    $bare = new HyperComplexityBareTypeField(['name' => 'Bare', 'handle' => F::handle('complexityBare')]);
    expect(Craft::$app->fields->saveField($text))->toBeTrue();
    expect(Craft::$app->fields->saveField($users))->toBeTrue();
    expect(Craft::$app->fields->saveField($bare))->toBeTrue();
    $url = new Url();
    $layout = Url::getDefaultFieldLayout();
    $tab = $layout->getTabs()[0];
    $tab->setElements([...$tab->getElements(), new CustomField($text), new CustomField($users), new CustomField($bare)]);
    $url->setFieldLayout($layout);
    $field = F::hyperFieldWithLinkTypes([F::linkTypeConfig($url)]);
    $section = F::entrySection($field);
    $person = User::find()->admin()->one();
    $owner = F::plainEntry($section, 'Owner', [$field->handle => [[
        'handle' => 'url', 'linkValue' => 'https://example.test/people', 'fields' => [$text->handle => 'Team', $users->handle => [$person->id], $bare->handle => 'Bare value'],
    ]]]);
    $entryType = $section->getEntryTypes()[0]->handle . '_Entry';
    $linkType = $field->handle . '_Url_LinkType';
    $schema = new GqlSchema(['uid' => StringHelper::UUID(), 'name' => 'Link field complexity', 'scope' => [
        'sections.' . $section->uid . ':read', 'usergroups.everyone:read',
    ]]);
    $query = fn(string $selection) => '{ entries(id: ' . $owner->id . ') { ... on ' . $entryType . ' { ' . $field->handle . ' { url ... on ' . $linkType . ' { ' . $selection . ' } } } } }';
    $original = Craft::$app->gql;

    try {
        $plain = hyperComplexity($schema, $query($text->handle));
        $relational = hyperComplexity($schema, $query($users->handle . ' { id }'));
        $withArgument = hyperComplexity($schema, $query($users->handle . '(relatedTo: [' . $person->id . ']) { id }'));
        $bareType = hyperComplexity($schema, $query($bare->handle));

        // Plain values come from the link's own content, while a relational field queries once per link.
        // Both selections add one simple field (the caption, or the person's id). The N+1 score is added
        // to the field's own eager-load score.
        expect($relational - $plain)->toBe(GqlService::GRAPHQL_COMPLEXITY_NPLUS1 + GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD);
        // The field's own argument costs still count.
        expect($withArgument - $relational)->toBe(GqlService::GRAPHQL_COMPLEXITY_QUERY);
        // A bare type has no score of its own, so it scores as a field that queries once per link.
        expect($bareType - $plain)->toBe(GqlService::GRAPHQL_COMPLEXITY_NPLUS1 - GqlService::GRAPHQL_COMPLEXITY_SIMPLE_FIELD);

        $result = hyperComplexityRun($schema, $query($text->handle . ' ' . $users->handle . ' { id } ' . $bare->handle), 0)['result'];
        expect($result['errors'] ?? [])->toBe([], json_encode($result));
        expect($result['data']['entries'][0][$field->handle])->toBe([[
            'url' => 'https://example.test/people', $text->handle => 'Team', $users->handle => [['id' => (string)$person->id]], $bare->handle => 'Bare value',
        ]]);
    } finally {
        Craft::$app->set('gql', $original);
        $original->flushCaches();
        Craft::$app->fields->deleteField($bare);
        Craft::$app->fields->deleteField($users);
        Craft::$app->fields->deleteField($text);
    }
});
