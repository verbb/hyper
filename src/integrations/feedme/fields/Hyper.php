<?php
namespace verbb\hyper\integrations\feedme\fields;

use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;

use craft\helpers\StringHelper;

use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\helpers\DataHelper;

use Cake\Utility\Hash;

class Hyper extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    public static $name = 'HyperField';
    public static $class = HyperField::class;


    // Templates
    // =========================================================================

    public function getMappingTemplate(): string
    {
        return 'hyper/_integrations/feed-me/field';
    }


    // Public Methods
    // =========================================================================

    public function parseField(): ?array
    {
        $multipleLinks = Hash::get($this->field, 'settings.multipleLinks');

        $preppedData = [];
        $fieldData = [];
        $complexFields = [];

        $fields = Hash::get($this->fieldInfo, 'fields');

        if (!$fields) {
            return null;
        }

        foreach ($this->feedData as $nodePath => $value) {
            // Get the field mapping info for this node in the feed
            $fieldInfo = $this->_getFieldMappingInfoForNodePath($nodePath, $fields);
            
            // If this is data concerning our Super Table field and blocks
            if ($fieldInfo) {
                $subFieldHandle = $fieldInfo['subFieldHandle'];
                $subFieldInfo = $fieldInfo['subFieldInfo'];
                $isComplexField = $fieldInfo['isComplexField'];

                if($multipleLinks) {
                    $nodePathSegments = explode('/', $nodePath);


                    $blockIndex = 0;
                    $nodePathSegments = array_reverse($nodePathSegments);
                    foreach ($nodePathSegments as $segment) {
                        if(is_numeric($segment)) {
                            $blockIndex = $segment;
                            break;
                        }
                    }

                    $key = $blockIndex . '.' . $subFieldHandle;
                }else {
                    $key = $subFieldHandle;
                }

                // Check for complex fields (think Table, Super Table, etc), essentially anything that has
                // sub-fields, and doesn't have data directly mapped to the field itself. It needs to be
                // accumulated here (so its in the right order), but grouped based on the field and block
                // its in. A little bit annoying, but no better ideas...
                if ($isComplexField) {
                    $complexFields[$key]['info'] = $subFieldInfo;
                    $complexFields[$key]['data'][$nodePath] = $value;
                    continue;
                }

                // Swap out the node-path stored in the field-mapping info, because
                // it'll be generic MatrixBlock/Images not MatrixBlock/0/Images/0 like we need
                $subFieldInfo['node'] = $nodePath;

                // Parse each field via their own fieldtype service
                $parsedValue = $this->_parseSubField($this->feedData, $subFieldHandle, $subFieldInfo);

                // Finish up with the content, also sort out cases where there's array content
                if (isset($fieldData[$key]) && is_array($fieldData[$key])) {
                    $fieldData[$key] = array_merge_recursive($fieldData[$key], $parsedValue);
                } else {
                    $fieldData[$key] = $parsedValue;
                }
            }
        }

        // Handle some complex fields that don't directly have nodes, but instead have nested properties mapped.
        // They have their mapping setup on sub-fields, and need to be processed all together, which we've already prepared.
        // Additionally, we only want to supply each field with a sub-set of data related to that specific block and field
        // otherwise, we get the field class processing all blocks in one go - not what we want.
        foreach ($complexFields as $key => $complexInfo) {
            $parts = explode('.', $key);
            $subFieldHandle = $parts[1];

            $subFieldInfo = Hash::get($complexInfo, 'info');
            $nodePaths = Hash::get($complexInfo, 'data');

            $parsedValue = $this->_parseSubField($nodePaths, $subFieldHandle, $subFieldInfo);

            if (isset($fieldData[$key])) {
                $fieldData[$key] = array_merge_recursive($fieldData[$key], $parsedValue);
            } else {
                $fieldData[$key] = $parsedValue;
            }
        }

        ksort($fieldData, SORT_NUMERIC);

        // check if all values in fieldData are empty strings
        $allEmpty = true;

        $customFieldHandles = Hash::extract($this->field->getLinkTypeFields(), '{n}.handle');

        // New, we've got a collection of prepared data, but its formatted a little rough, due to catering for
        // sub-field data that could be arrays or single values. Let's build our Matrix-ready data
        foreach ($fieldData as $blockSubFieldHandle => $value) {
            if($multipleLinks) {
                $handles = explode('.', $blockSubFieldHandle);
                $blockIndex = 'new' . ($handles[0] + 1);
                $subFieldHandle = $handles[1];

                $blockPrefix = $blockIndex . '.';
            }else {
                $subFieldHandle = $blockSubFieldHandle;
                $blockPrefix = '';
            }

            if (in_array($subFieldHandle, $customFieldHandles)) {
                $preppedData[$blockPrefix . 'fields.' . $subFieldHandle] = $value;
            } else {
                $preppedData[$blockPrefix . $subFieldHandle] = $value;
            }

            if ((is_string($value) && !empty($value)) || (is_array($value) && !empty(array_filter($value)))) {
                $allEmpty = false;
            }
        }
        
        // if there's nothing in the prepped data, return null, as if mapping doesn't exist
        if (empty($preppedData)) {
            return null;
        }

        // if everything in the $preppedData[][fields] is empty - return empty array
        if ($allEmpty === true) {
            return [];
        }

        if($multipleLinks) {
            $results = Hash::expand($preppedData);
        }else {
            $results = [$preppedData];
        }

        $fullResults = [];
        foreach ($results as $result) {
            foreach ($fields as $handle => $info) {
                if(!isset($result[$handle])) {
                    $result[$handle] = $info['default'] ?? '';
                }

                if($handle == 'type') {
                    // Convert the link type to handle
                    $typeMap = [
                        'asset' => linkTypes\Asset::class,
                        'category' => linkTypes\Category::class,
                        'custom' => linkTypes\Custom::class,
                        'email' => linkTypes\Email::class,
                        'entry' => linkTypes\Entry::class,
                        'site' => linkTypes\Site::class,
                        'tel' => linkTypes\Phone::class,
                        'url' => linkTypes\Url::class,
                        'user' => linkTypes\User::class,
                    ];
    
                    $type = $result[$handle] ?? null;
                    $linkTypeClass = $typeMap[$type] ?? null;
    
                    if ($linkTypeClass) {
                        $linkTypeHandle = 'default-' . StringHelper::toKebabCase($linkTypeClass);
                    } else {
                        $linkTypeHandle = $type;
                    }

                    $result['handle'] = $linkTypeHandle;
                    unset($result[$handle]);
                }
            }
            $fullResults[] = $result;
        }

        return $fullResults;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param $nodePath
     * @param $fields
     * @return array|null
     */
    private function _getFieldMappingInfoForNodePath($nodePath, $fields): ?array
    {
        $feedPath = preg_replace('/(\/\d+\/)/', '/', $nodePath);
        $feedPath = preg_replace('/^(\d+\/)|(\/\d+)/', '', $feedPath);

        foreach ($fields as $subFieldHandle => $subFieldInfo) {
            $node = Hash::get($subFieldInfo, 'node');

            $nestedFieldNodes = Hash::extract($subFieldInfo, 'fields.{*}.node');

            if ($nestedFieldNodes) {
                foreach ($nestedFieldNodes as $nestedFieldNode) {
                    if ($feedPath == $nestedFieldNode) {
                        return [
                            'subFieldHandle' => $subFieldHandle,
                            'subFieldInfo' => $subFieldInfo,
                            'nodePath' => $nodePath,
                            'isComplexField' => true,
                        ];
                    }
                }
            }

            // if ($feedPath == $node || $node === 'usedefault') {
            if ($feedPath == $node) {
                return [
                    'subFieldHandle' => $subFieldHandle,
                    'subFieldInfo' => $subFieldInfo,
                    'nodePath' => $nodePath,
                    'isComplexField' => false,
                ];
            }
        }

        return null;
    }

    /**
     * @param $feedData
     * @param $subFieldHandle
     * @param $subFieldInfo
     * @return mixed
     */
    private function _parseSubField($feedData, $subFieldHandle, $subFieldInfo): mixed
    {
        $subFieldClassHandle = Hash::get($subFieldInfo, 'field');

        if (!isset($subFieldClassHandle)) {
            return DataHelper::fetchValue($this->feedData, $subFieldInfo, $this->feed);
        }

        $subField = Hash::extract($this->field->getLinkTypeFields(), '{n}[handle=' . $subFieldHandle . ']')[0] ?? null;

        if (!$subField instanceof $subFieldClassHandle) {
            $subFieldClassHandle = \craft\fields\Entries::class;
        }

        $class = \craft\feedme\Plugin::$plugin->fields->getRegisteredField($subFieldClassHandle);
        $class->feedData = $feedData;
        $class->fieldHandle = $subFieldHandle;
        $class->fieldInfo = $subFieldInfo;
        $class->field = $subField;
        $class->element = $this->element;
        $class->feed = $this->feed;

        // Get our content, parsed by this fields service function
        return $class->parseField();
    }
}
