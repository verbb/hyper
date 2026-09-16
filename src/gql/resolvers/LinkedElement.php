<?php
namespace verbb\hyper\gql\resolvers;

use verbb\hyper\base\LinkInterface;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Gql;

class LinkedElement
{
    // Static Methods
    // =========================================================================

    public static function resolve(LinkInterface $link): ?ElementInterface
    {
        $element = $link->getElement();

        if (!$element) {
            return null;
        }

        // Shared entry/user GraphQL types do not imply access to every instance.
        // Inspect scope on the primed element without introducing a per-link entry query.
        $allowed = Gql::extractAllowedEntitiesFromSchema('read');

        if ($element::isLocalized() && isset($allowed['sites']) && !in_array($element->getSite()->uid, $allowed['sites'], true)) {
            return null;
        }

        if ($element instanceof Entry) {
            $scope = $element->sectionId ? 'sections' : 'nestedentryfields';
            $uid = $element->sectionId ? $element->getSection()?->uid : $element->getField()?->uid;

            return $uid && in_array($uid, $allowed[$scope] ?? [], true) ? $element : null;
        }

        if ($element instanceof User) {
            $groups = $allowed['usergroups'] ?? [];

            if (in_array('everyone', $groups, true)) {
                return $element;
            }

            foreach ($element->getGroups() as $group) {
                if (in_array($group->uid, $groups, true)) {
                    return $element;
                }
            }

            return null;
        }

        // Other element types use container-specific GraphQL types and Craft's type gate.
        return $element;
    }
}
