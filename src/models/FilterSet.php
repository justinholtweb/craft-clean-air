<?php

namespace justinholtweb\cleanair\models;

use craft\base\Model;
use craft\elements\Entry;

/**
 * A complete filter: what to look at, what to keep, and how to show it.
 *
 * This is the thing that gets serialised into a saved filter, encoded into a shareable URL,
 * and handed to the query builder. Nothing here touches the database — it is a description
 * of a question, not the answer.
 */
class FilterSet extends Model
{
    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    /** @var string The element class being filtered. */
    public string $elementType = Entry::class;

    /**
     * @var string|null The source key within that element type — `entryType:12`, `section:3`,
     *                  `volume:2`, `group:1`, `productType:1`. Null means every source the
     *                  current user is allowed to see.
     */
    public ?string $source = null;

    /** @var string `all` ANDs the criteria together; `any` ORs them. */
    public string $matchMode = self::MATCH_ALL;

    /** @var Criterion[] */
    public array $criteria = [];

    /** @var string|null Free-text search, run through Craft's own search index. */
    public ?string $search = null;

    /** @var string|null An element status, or null for "any status" — which is Clean Air's
     *                   default, and the whole point of a filter tool. */
    public ?string $status = null;

    /** @var int|null Site to search. Null means every site the user can edit. */
    public ?int $siteId = null;

    public bool $includeDrafts = false;
    public bool $includeRevisions = false;
    public bool $includeTrashed = false;

    /** @var string[] Column keys shown in the results table and written to exports. Empty
     *                falls back to the element type's defaults. */
    public array $columns = [];

    /** @var string|null Column key to sort by. */
    public ?string $orderBy = null;

    /** @var string `asc` or `desc`. */
    public string $sortDir = 'desc';

    public function init(): void
    {
        parent::init();
        $this->criteria = array_map(
            fn($c) => $c instanceof Criterion ? $c : Criterion::fromArray((array)$c),
            $this->criteria,
        );
    }

    /**
     * Rebuilds a filter from the array shape stored in `cleanair_filters.criteria` or posted
     * by the builder.
     */
    public static function fromArray(array $config): self
    {
        $criteria = [];
        foreach ($config['criteria'] ?? [] as $row) {
            $criterion = Criterion::fromArray((array)$row);
            if ($criterion->isComplete()) {
                $criteria[] = $criterion;
            }
        }

        $siteId = $config['siteId'] ?? null;

        return new self([
            'elementType' => (string)($config['elementType'] ?? Entry::class),
            'source' => ($config['source'] ?? null) ?: null,
            'matchMode' => ($config['matchMode'] ?? self::MATCH_ALL) === self::MATCH_ANY
                ? self::MATCH_ANY
                : self::MATCH_ALL,
            'criteria' => $criteria,
            'search' => ($config['search'] ?? null) ?: null,
            'status' => ($config['status'] ?? null) ?: null,
            'siteId' => ($siteId === null || $siteId === '') ? null : (int)$siteId,
            'includeDrafts' => self::boolish($config['includeDrafts'] ?? false),
            'includeRevisions' => self::boolish($config['includeRevisions'] ?? false),
            'includeTrashed' => self::boolish($config['includeTrashed'] ?? false),
            'columns' => array_values(array_filter((array)($config['columns'] ?? []))),
            'orderBy' => ($config['orderBy'] ?? null) ?: null,
            'sortDir' => ($config['sortDir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ]);
    }

    /**
     * Form posts, saved JSON and console flags all disagree about what "true" looks like.
     * CP Filters stored its drafts flag as the string `'y'`, so that is honoured too.
     */
    private static function boolish(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'y', 'yes', 'true', 'on'], true);
        }
        return (bool)$value;
    }

    /** Criteria that are missing a field or an operator can't be applied — drop them. */
    public function completeCriteria(): array
    {
        return array_values(array_filter($this->criteria, fn(Criterion $c) => $c->isComplete()));
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'elementType' => $this->elementType,
            'source' => $this->source,
            'matchMode' => $this->matchMode,
            'criteria' => array_map(fn(Criterion $c) => $c->toArray(), $this->criteria),
            'search' => $this->search,
            'status' => $this->status,
            'siteId' => $this->siteId,
            'includeDrafts' => $this->includeDrafts,
            'includeRevisions' => $this->includeRevisions,
            'includeTrashed' => $this->includeTrashed,
            'columns' => $this->columns,
            'orderBy' => $this->orderBy,
            'sortDir' => $this->sortDir,
        ];
    }
}
