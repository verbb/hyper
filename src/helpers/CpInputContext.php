<?php
namespace verbb\hyper\helpers;

use verbb\hyper\base\ElementLink;
use verbb\hyper\base\LinkInterface;
use verbb\hyper\fields\HyperField;

use Craft;
use craft\base\ElementInterface;
use craft\fields\BaseRelationField;
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
            if ($field instanceof BaseRelationField || $field instanceof Matrix) {
                foreach ($element->getFieldValue($field->handle)->all() as $selected) {
                    if ($field instanceof Matrix && !$selected->id) {
                        self::assertVisibleSelections($selected);
                    } else {
                        self::_assertVisible($selected);
                    }
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

    private static function _assertVisible(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->canView($element)) {
            throw new ForbiddenHttpException('User is not authorized to view a selected element.');
        }
    }
}
