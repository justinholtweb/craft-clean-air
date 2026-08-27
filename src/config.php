<?php

/**
 * Clean Air config.
 *
 * Copy this to `config/cleanair.php`. Anything set here overrides the control panel settings
 * and can be environment-specific in the usual Craft way.
 */

use justinholtweb\cleanair\models\FieldDefinition;

return [
    // Which sources each element type may filter, keyed by element type key. An empty array
    // means every source the user can already see.
    //
    // 'filterableSources' => [
    //     'entries' => ['section:1', 'entryType:4'],
    //     'assets' => ['volume:2'],
    // ],
    'filterableSources' => [],

    // Element type classes Clean Air won't offer.
    'disabledElementTypes' => [],

    // Null auto-detects Craft Commerce.
    'includeCommerce' => null,

    // Teach Clean Air about a field type it doesn't recognise, by naming the shape of its
    // value. CP Filters' own format — a class mapped to a list of filter labels — is also
    // accepted, so an existing `cpfilters.php` can be pasted in.
    //
    // 'additionalFieldTypes' => [
    //     'modules\\fields\\Rating' => FieldDefinition::KIND_NUMBER,
    // ],
    'additionalFieldTypes' => [],

    // Results.
    'resultsPerPage' => 50,
    'anyMatchIdLimit' => 50000,
    'shareableUrls' => true,

    // Default result columns per element type key.
    // 'defaultColumns' => ['entries' => ['id', 'title', 'status', 'postDate']],
    'defaultColumns' => [],

    // Exports.
    'queueExportThreshold' => 2000,
    'maxExportRows' => 0,
    'exportBatchSize' => 100,
    'exportRetentionDays' => 7,
    'csvDelimiter' => ',',
    'csvBom' => true,

    'logLevel' => 'warning',
];
