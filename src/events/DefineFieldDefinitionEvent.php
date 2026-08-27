<?php

namespace justinholtweb\cleanair\events;

use craft\base\FieldInterface;
use justinholtweb\cleanair\models\FieldDefinition;
use yii\base\Event;

/**
 * Fired for each custom field as Clean Air decides what shape its value is.
 *
 * Set `definition` to null to hide a field from the filter builder, or adjust its kind and
 * options to teach Clean Air about a field type it doesn't ship support for.
 */
class DefineFieldDefinitionEvent extends Event
{
    public FieldInterface $field;
    public ?FieldDefinition $definition = null;
}
