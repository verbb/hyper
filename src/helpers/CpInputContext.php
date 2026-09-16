<?php
namespace verbb\hyper\helpers;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;
use verbb\hyper\models\LinkCollectionInterface;

use Craft;
use craft\base\ElementInterface;
use craft\fields\BaseRelationField;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\helpers\Json;

use yii\web\ForbiddenHttpException;

class CpInputContext
{
    // Static Methods
    // =========================================================================

    public static function create(HyperField $field, ?ElementInterface $owner): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest() || !Craft::$app->getUser()->getId()) {
            return null;
        }

        // Unsaved/nested inputs need proof that this field was rendered in an authorized
        // editor. A caller cannot obtain that proof by omitting or swapping an owner ID.
        return Craft::$app->getSecurity()->hashData(Json::encode([
            'userId' => Craft::$app->getUser()->getId(),
            'fieldId' => (int)$field->id,
            'siteId' => (int)($owner?->siteId ?? Craft::$app->getSites()->getCurrentSite()->id),
            'ownerId' => $owner instanceof LinkInterface ? null : $owner?->id,
            'expires' => time() + 86400,
        ]));
    }

    public static function assertVisibleSelections(ElementInterface $element): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest() || !Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        if ($element instanceof ElementLink) {
            foreach ($element->getElements() as $selected) {
                self::_assertVisible($selected);
            }
        }

        // Posted clipboard content can contain relation fields as well as the main link.
        // Rendering a native chip does not itself enforce canView on its supplied element.
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $nestedQuery = $field instanceof Matrix || (class_exists(\benf\neo\Field::class) && $field instanceof \benf\neo\Field);

            if ($field instanceof BaseRelationField || $nestedQuery) {
                foreach ($element->getFieldValue($field->handle)->all() as $selected) {
                    if (!$nestedQuery || $selected->id) {
                        self::_assertVisible($selected);
                    }

                    if ($nestedQuery) {
                        self::assertVisibleSelections($selected);
                    }
                }
            } elseif ($field instanceof ContentBlock) {
                $block = $element->getFieldValue($field->handle);

                if ($block instanceof ElementInterface) {
                    if ($block->id) {
                        self::_assertVisible($block);
                    }

                    self::assertVisibleSelections($block);
                }
            } elseif ($field instanceof HyperField) {
                $links = $element->getFieldValue($field->handle);

                if ($links instanceof LinkCollectionInterface) {
                    foreach ($links->getLinks() as $link) {
                        self::assertVisibleSelections($link);
                    }
                }
            } elseif (class_exists(\verbb\vizy\fields\VizyField::class) && $field instanceof \verbb\vizy\fields\VizyField) {
                $nodes = $element->getFieldValue($field->handle);

                if ($nodes instanceof \verbb\vizy\models\NodeCollection) {
                    self::_assertVisibleVizySelections($nodes->getNodes(), $element);
                }
            }
        }
    }

    public static function validate(string $token, int $fieldId, int $siteId, ?int $ownerId): array
    {
        $raw = Craft::$app->getSecurity()->validateData($token);
        $data = $raw === false ? null : Json::decodeIfJson($raw);

        if (!is_array($data)
            || ($data['userId'] ?? null) !== Craft::$app->getUser()->getId()
            || ($data['fieldId'] ?? null) !== $fieldId
            || ($data['siteId'] ?? null) !== $siteId
            || ($data['expires'] ?? 0) < time()
            || ($ownerId && $ownerId !== ($data['ownerId'] ?? null))) {
            throw new ForbiddenHttpException('Invalid or expired Hyper input context. Reload the editor.');
        }

        return $data;
    }

    private static function _assertVisibleVizySelections(array $nodes, ElementInterface $owner): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof \verbb\vizy\nodes\VizyBlock) {
                $block = $node->getBlockElement($owner);

                foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    $block->setFieldValue($field->handle, $node->getFieldValue($field->handle));
                }

                self::assertVisibleSelections($block);
            }

            self::_assertVisibleVizySelections($node->getContent(), $owner);
        }
    }

    private static function _assertVisible(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->canView($element)) {
            throw new ForbiddenHttpException('User is not authorized to view a selected element.');
        }
    }
}
