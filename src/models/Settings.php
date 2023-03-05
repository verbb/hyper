<?php
namespace verbb\hyper\models;

use craft\base\Model;

class Settings extends Model
{
    // Properties
    // =========================================================================

    public array $embedClientSettings = [];
    public array $embedHeaders = [];
    public array $embedDetectorsSettings = [];

}
