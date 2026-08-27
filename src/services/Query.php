<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query as DbQuery;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\cleanair\models\Criterion;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\types\BaseType;
use yii\base\Component;
use yii\db\Expression;

/**
 * Turns a filter into an element query.
 *
 * Everything goes through Craft's own query parameters wherever a parameter exists, which
 * means a Clean Air filter behaves exactly like the equivalent hand-written query — same
 * multi-site handling, same field storage, same drafts and revisions rules — and stays
 * correct when Craft changes how a field is stored. Raw SQL is reserved for the handful of
 * attributes Craft exposes as columns but not as parameters.
 */
class Query extends Component
{
    /**
     * @param string[] $warnings Filled with anything that couldn't be applied, so the UI can
     *        say "this filter mentions a field that no longer exists" instead of quietly
     *        returning the wrong rows.
     */
    public function build(FilterSet $filter, array &$warnings = []): ElementQueryInterface
    {
        $type = Plugin::getInstance()->types->forElementType($filter->elementType);

        if (!$type) {
            $warnings[] = Craft::t('cleanair', 'Unknown element type “{type}”.', ['type' => $filter->elementType]);

            // A query we can't build must return nothing. `id(null)` would drop the
            // constraint and return the whole site, which is the opposite of safe.
            /** @var class-string<ElementInterface> $fallback */
            $fallback = Entry::class;
            $query = $fallback::find();
            $this->matchNothing($query);

            return $query;
        }

        $query = $this->baseQuery($filter, $type);
        $criteria = $filter->completeCriteria();

        if ($filter->matchMode === FilterSet::MATCH_ANY && count($criteria) > 1) {
            $this->applyAnyMatch($query, $filter, $type, $criteria, $warnings);
        } else {
            foreach ($criteria as $criterion) {
                $this->apply($query, $type, $filter->source, $criterion, $warnings);
            }
        }

        $this->applySort($query, $filter, $type);

        return $query;
    }

    /**
     * The query before any criteria: element type, source, site, status and what to do about
     * drafts, revisions and the trash.
     */
    public function baseQuery(FilterSet $filter, BaseType $type): ElementQuery
    {
        /** @var class-string<ElementInterface> $class */
        $class = $type->elementType();
        /** @var ElementQuery $query */
        $query = $class::find();

        $type->applySource($query, $filter->source);

        // Craft's indexes default to "live"; a filter tool that did the same would hide the
        // disabled and expired entries people come here to find.
        $query->status = $filter->status ?: null;

        if ($filter->siteId) {
            $query->siteId = $filter->siteId;
        }

        // `null` here means "both", which is not the same as `true`.
        $query->drafts($filter->includeDrafts ? null : false);
        $query->revisions($filter->includeRevisions ? null : false);
        $query->trashed($filter->includeTrashed ? null : false);

        if ($filter->search) {
            $query->search($filter->search);
        }

        return $query;
    }

    /**
     * `match any` can't be expressed in query parameters — two parameters on one query always
     * AND. So each criterion is run on its own and the IDs are unioned. That costs a query
     * per criterion, and is capped, which is why it's a deliberate mode rather than the
     * default.
     *
     * @param Criterion[] $criteria
     */
    private function applyAnyMatch(ElementQuery $query, FilterSet $filter, BaseType $type, array $criteria, array &$warnings): void
    {
        $limit = Plugin::getInstance()->getSettings()->anyMatchIdLimit;
        $ids = [];
        $truncated = false;

        foreach ($criteria as $criterion) {
            $probe = $this->baseQuery($filter, $type);
            $this->apply($probe, $type, $filter->source, $criterion, $warnings);

            if ($limit > 0) {
                $probe->limit($limit + 1);
            }

            $found = $probe->ids();

            if ($limit > 0 && count($found) > $limit) {
                $truncated = true;
                $found = array_slice($found, 0, $limit);
            }

            foreach ($found as $id) {
                $ids[$id] = true;
            }
        }

        if ($truncated) {
            $warnings[] = Craft::t('cleanair', 'This “match any” filter matched more than {limit} elements for a single condition, so the results are incomplete. Narrow the source, or switch to “match all”.', ['limit' => $limit]);
        }

        if (!$ids) {
            $this->matchNothing($query);
            return;
        }

        $query->id(array_keys($ids));
    }

    /**
     * Applies one criterion.
     */
    public function apply(ElementQuery $query, BaseType $type, ?string $source, Criterion $criterion, array &$warnings = []): void
    {
        $definition = Plugin::getInstance()->fields->definition($type, $source, (string)$criterion->field);

        if (!$definition) {
            $warnings[] = Craft::t('cleanair', 'No field or attribute named “{handle}” — that condition was ignored.', ['handle' => $criterion->field]);
            return;
        }

        // Statuses aren't a value comparison in Craft; they're a named set of conditions, so
        // they get their own path rather than being forced through the parameter syntax.
        if ($definition->kind === FieldDefinition::KIND_STATUS) {
            $this->applyStatus($query, $type, $criterion);
            return;
        }

        $instruction = Plugin::getInstance()->operators->build($definition, $criterion);

        if ($instruction === null) {
            $warnings[] = Craft::t('cleanair', '“{operator}” can’t be used on {field} — that condition was ignored.', [
                'operator' => $criterion->operator,
                'field' => $definition->label,
            ]);
            return;
        }

        match ($instruction['strategy']) {
            Operators::STRATEGY_ABORT => $this->matchNothing($query),
            Operators::STRATEGY_PARAM => $this->applyParam($query, $definition, $instruction),
            Operators::STRATEGY_EXCLUDE => $this->applyExclude($query, $type, $source, $definition, $instruction),
            Operators::STRATEGY_RELATION_COUNT => $this->applyRelationCount($query, $definition, $instruction, $warnings),
            default => null,
        };
    }

    private function applyParam(ElementQuery $query, FieldDefinition $definition, array $instruction): void
    {
        $value = $instruction['value'];

        if ($value === null) {
            return;
        }

        $caseInsensitive = (bool)($instruction['caseInsensitive'] ?? false);

        if ($definition->isCustomField()) {
            $query->{$definition->handle} = $this->wrapForField($definition, $value, $caseInsensitive);
            return;
        }

        if ($definition->column !== null) {
            $query->andWhere($this->columnCondition($definition, $value, $caseInsensitive));
            return;
        }

        $param = $definition->param ?? $definition->handle;
        $query->$param = $value;
    }

    /**
     * Craft's *base* field condition understands a `['value' => …, 'caseInsensitive' => …]`
     * wrapper and uses it to decide whether to compare with `LOWER()` — which is what makes a
     * text filter behave the same on a case-sensitive Postgres collation as on MySQL.
     *
     * Several field types override that method and parse the value themselves: Number and
     * Money hand it straight to the numeric parser, Date to the date parser. Hand any of them
     * the wrapper and they try to compare against the literal `true` inside it. So the wrapper
     * goes only where it is understood — text, and text-shaped values — and never around the
     * `:empty:` sentinels, which are not comparisons at all.
     */
    private function wrapForField(FieldDefinition $definition, mixed $value, bool $caseInsensitive): mixed
    {
        if (!$caseInsensitive || !is_string($value) || str_starts_with($value, ':')) {
            return $value;
        }

        $textual = in_array($definition->kind, [
            FieldDefinition::KIND_TEXT,
            FieldDefinition::KIND_OPTIONS,
            FieldDefinition::KIND_MULTI_OPTIONS,
        ], true);

        return $textual ? ['value' => $value, 'caseInsensitive' => true] : $value;
    }

    /**
     * A condition against a column, for the native attributes Craft selects but doesn't
     * expose as a query parameter. The three parsers differ in more than formatting — dates
     * need timezone conversion and booleans need null handling — so the kind picks one.
     */
    private function columnCondition(FieldDefinition $definition, mixed $value, bool $caseInsensitive): mixed
    {
        $column = (string)$definition->column;

        return match ($definition->kind) {
            FieldDefinition::KIND_DATE => Db::parseDateParam($column, $value) ?? new Expression('1=1'),
            FieldDefinition::KIND_BOOLEAN => Db::parseBooleanParam($column, $value),
            FieldDefinition::KIND_NUMBER, FieldDefinition::KIND_MONEY => Db::parseNumericParam($column, $value) ?? new Expression('1=1'),
            default => Db::parseParam($column, $value, caseInsensitive: $caseInsensitive) ?: new Expression('1=1'),
        };
    }

    /**
     * "is not assigned to X". Craft can express the positive but not its negation, so the
     * positive is run and its results subtracted. Doing it this way rather than hand-writing
     * a NOT EXISTS means the two operators are guaranteed to be exact opposites, including
     * for relations that live inside nested entries.
     */
    private function applyExclude(ElementQuery $query, BaseType $type, ?string $source, FieldDefinition $definition, array $instruction): void
    {
        // A cloned element query shares its behaviours, and custom field parameters live on
        // one — so the probe is built from scratch rather than cloned, with only what it needs.
        /** @var class-string<ElementInterface> $class */
        $class = $type->elementType();
        /** @var ElementQuery $probe */
        $probe = $class::find();
        $type->applySource($probe, $source);
        $probe->status = null;
        $probe->drafts(null);
        $probe->revisions(null);
        $probe->trashed(null);
        $probe->siteId = $query->siteId;

        $this->applyParam($probe, $definition, $instruction);

        $limit = Plugin::getInstance()->getSettings()->anyMatchIdLimit;
        if ($limit > 0) {
            $probe->limit($limit);
        }

        $ids = $probe->ids();

        if ($ids) {
            $query->andWhere(['not', ['elements.id' => $ids]]);
        }
    }

    /**
     * "has at least / at most N related elements".
     *
     * Relation fields keep their rows in `relations`; Matrix and other nested-entry fields
     * keep theirs in `elements_owners`. Both are counted the same way, and "at most" is
     * written as the negation of "more than" so that elements with no relations at all are
     * included — which is what everyone means by "has at most 2".
     */
    private function applyRelationCount(ElementQuery $query, FieldDefinition $definition, array $instruction, array &$warnings): void
    {
        $field = $definition->field;

        if (!$field || !$field->id) {
            $warnings[] = Craft::t('cleanair', 'Counting related elements isn’t supported for {field}.', ['field' => $definition->label]);
            return;
        }

        $count = (int)$instruction['count'];
        $atLeast = $instruction['op'] === '>=';

        if ($atLeast && $count <= 0) {
            // "at least zero" is every element.
            return;
        }

        $subQuery = $definition->kind === FieldDefinition::KIND_NESTED
            ? $this->nestedCountSubQuery($field->id)
            : $this->relationCountSubQuery($field->id);

        if ($subQuery === null) {
            $warnings[] = Craft::t('cleanair', 'Counting related elements isn’t supported for {field}.', ['field' => $definition->label]);
            return;
        }

        if ($atLeast) {
            $subQuery->having(['>=', 'COUNT(*)', $count]);
            $query->andWhere(['elements.id' => $subQuery]);
        } else {
            $subQuery->having(['>', 'COUNT(*)', $count]);
            $query->andWhere(['not', ['elements.id' => $subQuery]]);
        }
    }

    private function relationCountSubQuery(int $fieldId): DbQuery
    {
        return (new DbQuery())
            ->select(['sourceId'])
            ->from([Table::RELATIONS])
            ->where(['fieldId' => $fieldId])
            ->groupBy(['sourceId']);
    }

    /**
     * Nested entries — Matrix fields in Craft 5 — are entries owned by another element, so
     * the count is over `elements_owners` joined to the entries that name the field.
     */
    private function nestedCountSubQuery(int $fieldId): ?DbQuery
    {
        $schema = Craft::$app->getDb()->getTableSchema(Table::ENTRIES);
        if (!$schema || !isset($schema->columns['fieldId'])) {
            return null;
        }

        return (new DbQuery())
            ->select(['elements_owners.ownerId'])
            ->from(['elements_owners' => Table::ELEMENTS_OWNERS])
            ->innerJoin(['nested_entries' => Table::ENTRIES], '[[nested_entries.id]] = [[elements_owners.elementId]]')
            ->where(['nested_entries.fieldId' => $fieldId])
            ->groupBy(['elements_owners.ownerId']);
    }

    private function applyStatus(ElementQuery $query, BaseType $type, Criterion $criterion): void
    {
        $statuses = array_keys($type->statusOptions());
        $value = $criterion->value;
        $values = array_values(array_filter(array_map(
            fn($v) => trim((string)$v),
            is_array($value) ? $value : [$value],
        )));

        $selected = match ($criterion->operator) {
            'eq' => $values,
            'in' => $values,
            'ne' => array_values(array_diff($statuses, $values)),
            default => $values,
        };

        if (!$selected) {
            $this->matchNothing($query);
            return;
        }

        $query->status = count($selected) === 1 ? reset($selected) : $selected;
    }

    /**
     * A condition that can never be true — an empty "is one of", a date that wouldn't parse.
     * Returning nothing is the honest answer; returning everything is how people delete the
     * wrong records.
     */
    private function matchNothing(ElementQuery $query): void
    {
        $query->andWhere(new Expression('1=0'));
    }

    /**
     * Sorting. Craft adds custom field handles to the query's column map, so a field handle
     * works here as well as a native attribute — but only for fields that store a scalar,
     * hence the `sortable` flag on native attributes and the dbType check on fields.
     */
    private function applySort(ElementQuery $query, FilterSet $filter, BaseType $type): void
    {
        $direction = $filter->sortDir === 'asc' ? 'asc' : 'desc';
        $orderBy = $filter->orderBy;

        if (!$orderBy) {
            $query->orderBy(['elements.dateUpdated' => SORT_DESC]);
            return;
        }

        $definition = Plugin::getInstance()->fields->definition($type, $filter->source, $orderBy);

        if (!$definition) {
            $query->orderBy(['elements.dateUpdated' => SORT_DESC]);
            return;
        }

        if ($definition->native) {
            $column = $definition->column ?? $definition->handle;
            $query->orderBy("$column $direction");
            return;
        }

        if ($definition->field && $definition->field::dbType() !== null) {
            $query->orderBy("{$definition->handle} $direction");
            return;
        }

        $query->orderBy(['elements.dateUpdated' => SORT_DESC]);
    }
}
