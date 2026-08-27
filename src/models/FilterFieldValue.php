<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\cleanair\Plugin;

/**
 * What a Clean Air Filter field hands a template.
 *
 * It resolves lazily: an entry with this field on it doesn't run anybody's filter until the
 * template actually asks for the elements.
 */
class FilterFieldValue extends Model
{
    public string $handle = '';

    private ?SavedFilter $_filter = null;
    private bool $_loaded = false;

    public function __toString(): string
    {
        return $this->handle;
    }

    public function getFilter(): ?SavedFilter
    {
        if (!$this->_loaded) {
            $this->_loaded = true;
            $this->_filter = Plugin::getInstance()->savedFilters->getByHandle($this->handle);
        }

        return $this->_filter;
    }

    public function getName(): string
    {
        return $this->getFilter()?->name ?? $this->handle;
    }

    /** The element query this filter describes, or null if the filter has gone. */
    public function getQuery(): ?ElementQueryInterface
    {
        $filter = $this->getFilter();

        if (!$filter) {
            return null;
        }

        $warnings = [];

        return Plugin::getInstance()->query->build($filter->filter, $warnings);
    }

    /** @return \craft\base\ElementInterface[] */
    public function all(?int $limit = null): array
    {
        $query = $this->getQuery();

        if (!$query) {
            return [];
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->all();
    }

    public function one(): mixed
    {
        return $this->getQuery()?->one();
    }

    public function count(): int
    {
        return (int)($this->getQuery()?->count() ?? 0);
    }
}
