<?php
namespace verbb\hyper\console\controllers;

use verbb\hyper\Hyper;

use Craft;
use craft\base\FieldInterface;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use craft\fields\Matrix;
use craft\helpers\Db;
use craft\helpers\Json;

use yii\console\Controller;
use yii\console\ExitCode;

use lenz\linkfield\fields\LinkField;
use lenz\linkfield\records\LinkRecord;

use verbb\supertable\fields\SuperTableField;

/**
 * Manages Hyper migrations from Typed Link (legacy).
 */
class TypedLinkLegacyController extends Controller
{
    // Static Methods
    // =========================================================================

    public static function updateAllSettings(): void
    {
        Db::update(Table::FIELDS, [
            'type' => 'lenz\\linkfield\\fields\\LinkField',
        ], [
            'type' => 'typedlinkfield\\fields\\LinkField',
        ]);

        $rows = (new Query())
            ->select(['id', 'settings'])
            ->from(Table::FIELDS)
            ->where(['type' => 'lenz\\linkfield\\fields\\LinkField'])
            ->all();

        foreach ($rows as $row) {
            Db::update(Table::FIELDS, [
                'settings' => self::updateSettings($row['settings']),
            ], [
                'id' => $row['id'],
            ]);
        }
    }

    public static function updateSettings(string $settings): string
    {
        $settings = Json::decode($settings);
        
        if (!is_array($settings)) {
            $settings = [];
        }

        if (!array_key_exists('typeSettings', $settings)) {
            $settings['typeSettings'] = [];
        }

        if (isset($settings['allowedLinkNames'])) {
            $allowedLinkNames = $settings['allowedLinkNames'];
            
            if (!is_array($allowedLinkNames)) {
                $allowedLinkNames = [$allowedLinkNames];
            }

            foreach ($allowedLinkNames as $linkName) {
                if ($linkName == '*') {
                    $settings['enableAllLinkTypes'] = true;
                } else {
                    $settings['typeSettings'][$linkName]['enabled'] = true;
                }
            }
        }

        unset($settings['allowedLinkNames']);

        return Json::encode($settings);
    }
    

    // Public Methods
    // =========================================================================

    /**
     * Migrates Typed Link fields (legacy) to the latest Typed Link field schema.
     */
    public function actionIndex(): int
    {
        self::updateAllSettings();

        return ExitCode::OK;
    }
}
