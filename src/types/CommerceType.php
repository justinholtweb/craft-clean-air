<?php

namespace justinholtweb\cleanair\types;

use Craft;

/**
 * Shared plumbing for the Craft Commerce drivers.
 *
 * Commerce is optional, and Clean Air must load cleanly without it — so nothing here imports
 * a Commerce class. Class names stay strings until `isAvailable()` has said yes.
 */
abstract class CommerceType extends BaseType
{
    public function isAvailable(): bool
    {
        if (!class_exists($this->elementType())) {
            return false;
        }
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /** @return object|null The Commerce plugin instance, or null when it isn't installed. */
    protected function commerce(): ?object
    {
        if (!$this->isAvailable()) {
            return null;
        }
        /** @var class-string $class */
        $class = 'craft\\commerce\\Plugin';
        return method_exists($class, 'getInstance') ? $class::getInstance() : null;
    }

    /** @return object[] */
    protected function productTypes(): array
    {
        $commerce = $this->commerce();
        return $commerce ? $commerce->getProductTypes()->getAllProductTypes() : [];
    }
}
