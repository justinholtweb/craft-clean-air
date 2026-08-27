<?php

namespace justinholtweb\cleanair\events;

use justinholtweb\cleanair\types\BaseType;
use yii\base\Event;

/**
 * Fired while the element type drivers are assembled.
 */
class RegisterTypesEvent extends Event
{
    /** @var BaseType[] Drivers keyed by type key. Add to this, or replace an entry to
     *                  override one of Clean Air's own. */
    public array $types = [];
}
