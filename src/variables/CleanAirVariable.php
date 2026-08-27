<?php

namespace justinholtweb\cleanair\variables;

use craft\elements\db\ElementQueryInterface;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;

/**
 * `craft.cleanair` — saved filters on the front end.
 *
 * A saved filter is a question about content, and questions asked in the control panel are
 * usually the same ones a template wants to ask. Naming one here rather than repeating its
 * conditions in Twig means an editor can change what "featured, in stock, published this
 * month" means without a deploy.
 *
 * Pro only.
 */
class CleanAirVariable
{
    /**
     * An element query for a saved filter, by handle.
     *
     * Returns null when the filter doesn't exist or the edition doesn't allow this, so a
     * template can fall back rather than error.
     */
    public function filter(string $handle): ?ElementQueryInterface
    {
        $saved = $this->savedFilter($handle);

        if (!$saved) {
            return null;
        }

        $warnings = [];

        return Plugin::getInstance()->query->build($saved->filter, $warnings);
    }

    /**
     * The same, with overrides applied on top — a site, a limit, a different sort.
     *
     * @param array $overrides Any FilterSet property: `limit` isn't one of them, so set that
     *                         on the returned query.
     */
    public function query(string $handle, array $overrides = []): ?ElementQueryInterface
    {
        $saved = $this->savedFilter($handle);

        if (!$saved) {
            return null;
        }

        $config = array_merge($saved->filter->toArray(), $overrides);
        $warnings = [];

        return Plugin::getInstance()->query->build(FilterSet::fromArray($config), $warnings);
    }

    public function count(string $handle): ?int
    {
        $query = $this->filter($handle);

        return $query ? (int)$query->count() : null;
    }

    public function savedFilter(string $handle): ?SavedFilter
    {
        if (!$this->enabled()) {
            return null;
        }

        return Plugin::getInstance()->savedFilters->getByHandle($handle);
    }

    /**
     * @return SavedFilter[]
     */
    public function savedFilters(?string $elementType = null): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return Plugin::getInstance()->savedFilters->all(null, $elementType);
    }

    private function enabled(): bool
    {
        return Edition::allowsProgrammaticAccess(Plugin::getInstance()->isPro());
    }
}
