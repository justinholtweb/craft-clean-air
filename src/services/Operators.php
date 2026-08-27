<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\cleanair\models\Criterion;
use justinholtweb\cleanair\models\FieldDefinition;
use yii\base\Component;

/**
 * The filter language: what you can ask of a value, and how that question becomes a query.
 *
 * Operators are declared against a *kind* rather than a field class, so every text field —
 * a plain text field, a URL, an email, a variant SKU, an address line — gets the same nine
 * operators without any of them being taught about each other.
 *
 * `build()` returns an instruction rather than touching a query, which keeps this class
 * free of Craft's query internals and makes the whole language testable in isolation. The
 * strategies are:
 *
 *   param          — set a value on an element query parameter or column condition
 *   exclude        — run the positive form, then exclude what it matched (Craft has no
 *                    "not related to" parameter, so negation is done honestly)
 *   relationCount  — count rows in the relations table and compare
 *   abort          — the criterion can never match; return nothing rather than everything
 */
class Operators extends Component
{
    public const STRATEGY_PARAM = 'param';
    public const STRATEGY_EXCLUDE = 'exclude';
    public const STRATEGY_RELATION_COUNT = 'relationCount';
    public const STRATEGY_ABORT = 'abort';

    public const INPUT_NONE = 'none';
    public const INPUT_TEXT = 'text';
    public const INPUT_TEXTAREA = 'textarea';
    public const INPUT_NUMBER = 'number';
    public const INPUT_DATE = 'date';
    public const INPUT_SELECT = 'select';
    public const INPUT_MULTISELECT = 'multiselect';
    public const INPUT_ELEMENT = 'element';
    public const INPUT_RANGE = 'range';
    public const INPUT_DATE_RANGE = 'dateRange';
    public const INPUT_RELATIVE = 'relative';

    /**
     * Which operators each kind of value supports, in the order they're offered.
     *
     * @var array<string,string[]>
     */
    private const KIND_OPERATORS = [
        FieldDefinition::KIND_TEXT => ['contains', 'notContains', 'startsWith', 'endsWith', 'eq', 'ne', 'in', 'notIn', 'empty', 'notEmpty'],
        FieldDefinition::KIND_NUMBER => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'notEmpty'],
        FieldDefinition::KIND_MONEY => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'notEmpty'],
        FieldDefinition::KIND_DATE => ['on', 'before', 'after', 'betweenDates', 'inLast', 'inNext', 'empty', 'notEmpty'],
        FieldDefinition::KIND_BOOLEAN => ['isTrue', 'isFalse'],
        FieldDefinition::KIND_OPTIONS => ['eq', 'ne', 'in', 'notIn', 'empty', 'notEmpty'],
        FieldDefinition::KIND_MULTI_OPTIONS => ['contains', 'notContains', 'empty', 'notEmpty'],
        FieldDefinition::KIND_RELATION => ['isAssigned', 'isNotAssigned', 'notEmpty', 'empty', 'countAtLeast', 'countAtMost'],
        FieldDefinition::KIND_STATUS => ['eq', 'ne', 'in'],
        FieldDefinition::KIND_NESTED => ['notEmpty', 'empty', 'countAtLeast', 'countAtMost'],
    ];

    /**
     * @return array<string,array{label:string,arity:int,input:string}>
     */
    public function all(): array
    {
        return [
            'contains' => ['label' => Craft::t('cleanair', 'contains'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'notContains' => ['label' => Craft::t('cleanair', 'doesn’t contain'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'startsWith' => ['label' => Craft::t('cleanair', 'starts with'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'endsWith' => ['label' => Craft::t('cleanair', 'ends with'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'eq' => ['label' => Craft::t('cleanair', 'is'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'ne' => ['label' => Craft::t('cleanair', 'is not'), 'arity' => 1, 'input' => self::INPUT_TEXT],
            'in' => ['label' => Craft::t('cleanair', 'is one of'), 'arity' => 1, 'input' => self::INPUT_TEXTAREA],
            'notIn' => ['label' => Craft::t('cleanair', 'is none of'), 'arity' => 1, 'input' => self::INPUT_TEXTAREA],
            'gt' => ['label' => Craft::t('cleanair', 'is greater than'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
            'gte' => ['label' => Craft::t('cleanair', 'is at least'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
            'lt' => ['label' => Craft::t('cleanair', 'is less than'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
            'lte' => ['label' => Craft::t('cleanair', 'is at most'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
            'between' => ['label' => Craft::t('cleanair', 'is between'), 'arity' => 2, 'input' => self::INPUT_RANGE],
            'empty' => ['label' => Craft::t('cleanair', 'is empty'), 'arity' => 0, 'input' => self::INPUT_NONE],
            'notEmpty' => ['label' => Craft::t('cleanair', 'is not empty'), 'arity' => 0, 'input' => self::INPUT_NONE],
            'on' => ['label' => Craft::t('cleanair', 'is on'), 'arity' => 1, 'input' => self::INPUT_DATE],
            'before' => ['label' => Craft::t('cleanair', 'is before'), 'arity' => 1, 'input' => self::INPUT_DATE],
            'after' => ['label' => Craft::t('cleanair', 'is after'), 'arity' => 1, 'input' => self::INPUT_DATE],
            'betweenDates' => ['label' => Craft::t('cleanair', 'is between'), 'arity' => 2, 'input' => self::INPUT_DATE_RANGE],
            'inLast' => ['label' => Craft::t('cleanair', 'is in the last'), 'arity' => 2, 'input' => self::INPUT_RELATIVE],
            'inNext' => ['label' => Craft::t('cleanair', 'is in the next'), 'arity' => 2, 'input' => self::INPUT_RELATIVE],
            'isTrue' => ['label' => Craft::t('cleanair', 'is on'), 'arity' => 0, 'input' => self::INPUT_NONE],
            'isFalse' => ['label' => Craft::t('cleanair', 'is off'), 'arity' => 0, 'input' => self::INPUT_NONE],
            'isAssigned' => ['label' => Craft::t('cleanair', 'is assigned'), 'arity' => 1, 'input' => self::INPUT_ELEMENT],
            'isNotAssigned' => ['label' => Craft::t('cleanair', 'is not assigned'), 'arity' => 1, 'input' => self::INPUT_ELEMENT],
            'countAtLeast' => ['label' => Craft::t('cleanair', 'has at least'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
            'countAtMost' => ['label' => Craft::t('cleanair', 'has at most'), 'arity' => 1, 'input' => self::INPUT_NUMBER],
        ];
    }

    public function definition(string $handle): ?array
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The operators offered for a field, with the value input each one needs. Option and
     * relation kinds get their inputs upgraded here — "is one of" against a dropdown should
     * be a multi-select, not a textarea.
     *
     * @return array<string,array{label:string,arity:int,input:string}>
     */
    public function forField(FieldDefinition $definition): array
    {
        $all = $this->all();
        $handles = self::KIND_OPERATORS[$definition->kind] ?? self::KIND_OPERATORS[FieldDefinition::KIND_TEXT];

        $operators = [];
        foreach ($handles as $handle) {
            if (!isset($all[$handle])) {
                continue;
            }
            $operator = $all[$handle];

            if ($definition->kind === FieldDefinition::KIND_OPTIONS || $definition->kind === FieldDefinition::KIND_STATUS) {
                if (in_array($handle, ['eq', 'ne'], true)) {
                    $operator['input'] = self::INPUT_SELECT;
                } elseif (in_array($handle, ['in', 'notIn'], true)) {
                    $operator['input'] = self::INPUT_MULTISELECT;
                }
            } elseif ($definition->kind === FieldDefinition::KIND_MULTI_OPTIONS) {
                if (in_array($handle, ['contains', 'notContains'], true) && $definition->options) {
                    $operator['input'] = self::INPUT_SELECT;
                }
            }

            $operators[$handle] = $operator;
        }

        // Counting relations means counting rows in the relations table, which only exists for
        // custom fields. A native attribute like an entry's author holds one ID in a column.
        if ($definition->native && $definition->kind === FieldDefinition::KIND_RELATION) {
            unset($operators['countAtLeast'], $operators['countAtMost']);
        }

        return $operators;
    }

    public function supports(FieldDefinition $definition, string $operator): bool
    {
        return isset($this->forField($definition)[$operator]);
    }

    /**
     * Turns one criterion into an instruction the query builder can carry out.
     *
     * @return array{strategy:string, value?:mixed, op?:string, count?:int}|null
     */
    public function build(FieldDefinition $definition, Criterion $criterion): ?array
    {
        $operator = (string)$criterion->operator;

        if (!$this->supports($definition, $operator)) {
            return null;
        }

        return match ($operator) {
            'contains' => $this->param('*' . $this->escape($criterion->value) . '*', $criterion),
            'notContains' => $this->param('not *' . $this->escape($criterion->value) . '*', $criterion),
            'startsWith' => $this->param($this->escape($criterion->value) . '*', $criterion),
            'endsWith' => $this->param('*' . $this->escape($criterion->value), $criterion),
            'eq' => $this->param($this->escape($criterion->value), $criterion),
            'ne' => $this->param('not ' . $this->escape($criterion->value), $criterion),
            'in' => $this->listParam($criterion, false),
            'notIn' => $this->listParam($criterion, true),
            'gt' => $this->numeric('>', $criterion),
            'gte' => $this->numeric('>=', $criterion),
            'lt' => $this->numeric('<', $criterion),
            'lte' => $this->numeric('<=', $criterion),
            'between' => $this->betweenNumbers($criterion),
            'empty' => $this->param(':empty:', $criterion),
            'notEmpty' => $this->param(':notempty:', $criterion),
            'on' => $this->onDate($criterion),
            'before' => $this->dateBound('<', $criterion->value),
            'after' => $this->dateBound('>=', $criterion->value),
            'betweenDates' => $this->betweenDates($criterion),
            'inLast' => $this->relativeDates($criterion, true),
            'inNext' => $this->relativeDates($criterion, false),
            'isTrue' => ['strategy' => self::STRATEGY_PARAM, 'value' => true],
            'isFalse' => ['strategy' => self::STRATEGY_PARAM, 'value' => false],
            'isAssigned' => $this->assigned($criterion, false),
            'isNotAssigned' => $this->assigned($criterion, true),
            'countAtLeast' => ['strategy' => self::STRATEGY_RELATION_COUNT, 'op' => '>=', 'count' => max(0, (int)$criterion->value)],
            'countAtMost' => ['strategy' => self::STRATEGY_RELATION_COUNT, 'op' => '<=', 'count' => max(0, (int)$criterion->value)],
            default => null,
        };
    }

    // Builders
    // =========================================================================

    private function param(mixed $value, ?Criterion $criterion = null): array
    {
        // The flag travels alongside the value rather than wrapped around it: Craft's custom
        // field conditions understand a `['value' => …, 'caseInsensitive' => …]` wrapper, but
        // its native attribute parameters do not, and the query builder knows which is which.
        return [
            'strategy' => self::STRATEGY_PARAM,
            'value' => $value,
            'caseInsensitive' => $criterion !== null && $criterion->caseInsensitive,
        ];
    }

    /**
     * `*` and `,` mean something to Craft's parameter syntax, so a title that genuinely
     * contains a comma has to be escaped or it silently becomes two separate searches.
     */
    private function escape(mixed $value): string
    {
        return Db::escapeParam((string)$value);
    }

    /**
     * "is one of" takes a multi-select's array, a textarea's lines, or a comma-separated
     * string — whichever the input gave us.
     *
     * @return string[]
     */
    private function values(Criterion $criterion): array
    {
        $value = $criterion->value;

        if (is_array($value)) {
            $values = $value;
        } else {
            $values = preg_split('/[\r\n]+/', (string)$value) ?: [];
            if (count($values) === 1) {
                $values = explode(',', (string)$value);
            }
        }

        $values = array_map(fn($v) => trim((string)$v), $values);
        return array_values(array_filter($values, fn($v) => $v !== ''));
    }

    private function listParam(Criterion $criterion, bool $negate): array
    {
        $values = $this->values($criterion);

        if (!$values) {
            // "is one of nothing" matches nothing; "is none of nothing" excludes nothing.
            return $negate
                ? ['strategy' => self::STRATEGY_PARAM, 'value' => null]
                : ['strategy' => self::STRATEGY_ABORT];
        }

        $escaped = array_map(fn($v) => Db::escapeParam($v), $values);

        if ($negate) {
            return $this->param(array_merge(['and'], array_map(fn($v) => "not $v", $escaped)), $criterion);
        }

        return $this->param(array_merge(['or'], $escaped), $criterion);
    }

    private function numeric(string $operator, Criterion $criterion): array
    {
        $value = trim((string)$criterion->value);
        if ($value === '') {
            return ['strategy' => self::STRATEGY_ABORT];
        }
        return ['strategy' => self::STRATEGY_PARAM, 'value' => "$operator $value"];
    }

    private function betweenNumbers(Criterion $criterion): array
    {
        $low = trim((string)$criterion->value);
        $high = trim((string)$criterion->value2);

        if ($low === '' || $high === '') {
            return ['strategy' => self::STRATEGY_ABORT];
        }

        // Entered backwards is a slip, not a request for no results.
        if (is_numeric($low) && is_numeric($high) && $low > $high) {
            [$low, $high] = [$high, $low];
        }

        return ['strategy' => self::STRATEGY_PARAM, 'value' => ['and', ">= $low", "<= $high"]];
    }

    private function toDate(mixed $value, bool $endOfDay = false): ?DateTime
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Craft's date input posts `{date: …, time: …, timezone: …}`, which DateTimeHelper
        // reads natively — including the timezone, which matters on a site whose editors
        // aren't in the same one as the server. The console and saved filters post a string.
        if (is_array($value)) {
            $hasParts = ($value['date'] ?? '') !== '' || ($value['time'] ?? '') !== '';
            if (!$hasParts) {
                return null;
            }
        }

        // Assume the *system* timezone, not UTC. A criterion's date is something a person
        // typed, and "is on 25 August" means their 25th of August — reading it as UTC shifts
        // the whole day and quietly returns the wrong one.
        $date = $value !== null ? DateTimeHelper::toDateTime($value, true, true) : false;
        if ($date === false) {
            return null;
        }

        if ($endOfDay && !$this->hasTime($value)) {
            $date = (clone $date)->setTime(23, 59, 59);
        }

        return $date;
    }

    private function hasTime(mixed $value): bool
    {
        if (is_array($value)) {
            return ($value['time'] ?? '') !== '';
        }

        return is_string($value) && preg_match('/\d{1,2}:\d{2}/', $value) === 1;
    }

    private function dateBound(string $operator, mixed $value): array
    {
        $date = $this->toDate($value);
        if (!$date) {
            return ['strategy' => self::STRATEGY_ABORT];
        }
        return ['strategy' => self::STRATEGY_PARAM, 'value' => $operator . ' ' . Db::prepareDateForDb($date)];
    }

    /**
     * "is on" means the whole day, not midnight exactly — which is what a bare date
     * comparison would otherwise mean, and why nobody's date filters ever matched anything.
     */
    private function onDate(Criterion $criterion): array
    {
        $date = $this->toDate($criterion->value);
        if (!$date) {
            return ['strategy' => self::STRATEGY_ABORT];
        }

        $start = (clone $date)->setTime(0, 0, 0);
        $end = (clone $date)->modify('+1 day')->setTime(0, 0, 0);

        return ['strategy' => self::STRATEGY_PARAM, 'value' => [
            'and',
            '>= ' . Db::prepareDateForDb($start),
            '< ' . Db::prepareDateForDb($end),
        ]];
    }

    private function betweenDates(Criterion $criterion): array
    {
        $start = $this->toDate($criterion->value);
        $end = $this->toDate($criterion->value2, true);

        if (!$start || !$end) {
            return ['strategy' => self::STRATEGY_ABORT];
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return ['strategy' => self::STRATEGY_PARAM, 'value' => [
            'and',
            '>= ' . Db::prepareDateForDb($start),
            '<= ' . Db::prepareDateForDb($end),
        ]];
    }

    /**
     * "in the last 30 days" — the filter that stays true tomorrow, which is the whole reason
     * to save one. A fixed date range in a saved filter is stale the day after you write it.
     */
    private function relativeDates(Criterion $criterion, bool $past): array
    {
        $amount = max(1, (int)$criterion->value);
        $unit = in_array($criterion->value2, ['hours', 'days', 'weeks', 'months', 'years'], true)
            ? $criterion->value2
            : 'days';

        $now = DateTimeHelper::now(new DateTimeZone(Craft::$app->getTimeZone()));
        $bound = (clone $now)->modify(($past ? '-' : '+') . "$amount $unit");

        [$from, $to] = $past ? [$bound, $now] : [$now, $bound];

        return ['strategy' => self::STRATEGY_PARAM, 'value' => [
            'and',
            '>= ' . Db::prepareDateForDb($from),
            '<= ' . Db::prepareDateForDb($to),
        ]];
    }

    /**
     * Relations. Craft can ask "related to element 7"; it has no parameter for "*not*
     * related to element 7", so the negative form is run as its positive and subtracted.
     */
    private function assigned(Criterion $criterion, bool $negate): array
    {
        $ids = array_values(array_filter(array_map(
            'intval',
            is_array($criterion->value) ? $criterion->value : [$criterion->value],
        )));

        if (!$ids) {
            return ['strategy' => self::STRATEGY_ABORT];
        }

        return [
            'strategy' => $negate ? self::STRATEGY_EXCLUDE : self::STRATEGY_PARAM,
            'value' => $ids,
        ];
    }
}
