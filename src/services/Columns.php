<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use DateTimeInterface;
use justinholtweb\cleanair\models\FieldDefinition;
use justinholtweb\cleanair\models\FilterSet;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\types\BaseType;
use Throwable;
use yii\base\Component;

/**
 * What each result column shows.
 *
 * One value reader, two renderers: a plain one for exports and an HTML one for the table.
 * Keeping them beside each other is deliberate — CP Filters had a preview renderer for the
 * table and dumped raw `ArrayHelper::toArray()` output into its CSV, so what you saw on
 * screen and what you got in the file were two different things.
 */
class Columns extends Component
{
    private const TRUNCATE_AT = 90;

    /**
     * Columns that can be shown, keyed by handle.
     *
     * @return FieldDefinition[]
     */
    public function available(BaseType $type, ?string $source): array
    {
        return array_filter(
            Plugin::getInstance()->fields->definitions($type, $source),
            fn(FieldDefinition $definition) => $definition->exportable,
        );
    }

    /**
     * The columns a filter will actually show: what the user chose, what the settings say for
     * this element type, or the driver's own defaults — first one that yields anything.
     *
     * @return string[]
     */
    public function resolve(FilterSet $filter, BaseType $type): array
    {
        $available = $this->available($type, $filter->source);

        $chosen = array_values(array_filter(
            $filter->columns,
            fn(string $key) => isset($available[$key]),
        ));

        if ($chosen) {
            return $chosen;
        }

        $configured = Plugin::getInstance()->getSettings()->defaultColumns[$type->key()] ?? [];
        $configured = array_values(array_filter($configured, fn($key) => isset($available[$key])));

        if ($configured) {
            return $configured;
        }

        $defaults = array_values(array_filter($type->defaultColumns(), fn($key) => isset($available[$key])));

        return $defaults ?: array_slice(array_keys($available), 0, 6);
    }

    public function label(BaseType $type, ?string $source, string $key): string
    {
        $definition = Plugin::getInstance()->fields->definition($type, $source, $key);
        return $definition?->label ?? $key;
    }

    /**
     * Eager-loads the relation fields among the chosen columns, so a page of 50 results isn't
     * 50 queries per relation column.
     *
     * @param string[] $columns
     */
    public function eagerLoad(ElementQueryInterface $query, BaseType $type, ?string $source, array $columns): void
    {
        $with = [];

        foreach ($columns as $key) {
            $definition = Plugin::getInstance()->fields->definition($type, $source, $key);
            if ($definition && $definition->isCustomField() && $definition->kind === FieldDefinition::KIND_RELATION) {
                $with[] = $definition->handle;
            }
        }

        if ($with) {
            $query->with($with);
        }
    }

    /**
     * The value as it goes into an export — a string, never markup.
     */
    public function value(ElementInterface $element, BaseType $type, ?string $source, string $key): string
    {
        $definition = Plugin::getInstance()->fields->definition($type, $source, $key);

        if (!$definition) {
            return '';
        }

        $raw = $this->rawValue($element, $definition);

        return match ($definition->kind) {
            FieldDefinition::KIND_DATE => $raw instanceof DateTimeInterface
                ? $raw->format('Y-m-d H:i:s')
                : $this->stringify($raw),
            FieldDefinition::KIND_BOOLEAN => $raw === null ? '' : ($raw ? Craft::t('app', 'Yes') : Craft::t('app', 'No')),
            FieldDefinition::KIND_OPTIONS, FieldDefinition::KIND_STATUS => $this->optionLabel($definition, $raw),
            FieldDefinition::KIND_MULTI_OPTIONS => $this->multiOptionLabels($definition, $raw),
            FieldDefinition::KIND_RELATION, FieldDefinition::KIND_NESTED => $this->relatedTitles($raw),
            default => $this->stringify($raw),
        };
    }

    /**
     * The value as it appears in the control panel table. Relations become links, assets
     * become thumbnails, long text is truncated with the whole value in a title attribute.
     */
    public function html(ElementInterface $element, BaseType $type, ?string $source, string $key): string
    {
        $definition = Plugin::getInstance()->fields->definition($type, $source, $key);

        if (!$definition) {
            return '';
        }

        if ($key === 'title') {
            $url = $element->getCpEditUrl();
            $title = (string)($element->title ?? $element->getUiLabel());
            return $url
                ? Html::a(Html::encode($title), $url)
                : Html::encode($title);
        }

        if ($key === 'status') {
            $status = $element->getStatus();

            // Craft styles `.status` as the dot itself, so the label has to sit beside it
            // rather than inside it — text in the span gets squeezed into the dot's box.
            return $status === null
                ? ''
                : Html::tag('span', '', ['class' => "status $status"])
                    . Html::encode($this->optionLabel($definition, $status));
        }

        $raw = $this->rawValue($element, $definition);

        if ($definition->kind === FieldDefinition::KIND_RELATION || $definition->kind === FieldDefinition::KIND_NESTED) {
            return $this->relatedHtml($raw);
        }

        if ($element instanceof Asset && $key === 'filename') {
            return Html::a(Html::encode((string)$element->filename), (string)$element->getCpEditUrl());
        }

        $value = $this->value($element, $type, $source, $key);

        if ($definition->kind === FieldDefinition::KIND_MONEY || $definition->kind === FieldDefinition::KIND_NUMBER) {
            return Html::tag('span', Html::encode($value), ['class' => 'cleanair-num']);
        }

        if (mb_strlen($value) > self::TRUNCATE_AT) {
            return Html::tag(
                'span',
                Html::encode(StringHelper::safeTruncate($value, self::TRUNCATE_AT)),
                ['title' => mb_substr($value, 0, 1000)],
            );
        }

        return Html::encode($value);
    }

    /**
     * Reads a value off an element without ever letting a broken field take the whole page
     * down — a results table is exactly where a half-migrated field will surface.
     */
    private function rawValue(ElementInterface $element, FieldDefinition $definition): mixed
    {
        try {
            if ($definition->isCustomField()) {
                return $element->getFieldValue($definition->handle);
            }

            if ($definition->handle === 'status') {
                return $element->getStatus();
            }

            return $element->{$definition->handle} ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function optionLabel(FieldDefinition $definition, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_object($value) && property_exists($value, 'value')) {
            $value = $value->value;
        }

        $key = (string)$value;

        return $definition->options[$key] ?? $key;
    }

    private function multiOptionLabels(FieldDefinition $definition, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $values = is_iterable($value) ? $value : [$value];
        $labels = [];

        foreach ($values as $item) {
            $label = $this->optionLabel($definition, $item);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Relation and Matrix values arrive as queries. Titles are what people want in a column;
     * a hard limit keeps a field holding four hundred related entries from turning one row
     * into a paragraph.
     */
    private function relatedTitles(mixed $value): string
    {
        $elements = $this->relatedElements($value, 10);

        if ($elements === null) {
            return $this->stringify($value);
        }

        $titles = array_map(
            fn(ElementInterface $element) => (string)$element->getUiLabel(),
            $elements['elements'],
        );

        $suffix = $elements['more'] > 0
            ? ' ' . Craft::t('cleanair', '(+{count} more)', ['count' => $elements['more']])
            : '';

        return $titles ? implode(', ', $titles) . $suffix : '';
    }

    private function relatedHtml(mixed $value): string
    {
        $elements = $this->relatedElements($value, 5);

        if ($elements === null) {
            return Html::encode($this->stringify($value));
        }

        if (!$elements['elements']) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $links = [];
        foreach ($elements['elements'] as $element) {
            $label = Html::encode((string)$element->getUiLabel());
            $url = $element->getCpEditUrl();
            $links[] = $url ? Html::a($label, $url) : $label;
        }

        if ($elements['more'] > 0) {
            $links[] = Html::tag('span', Craft::t('cleanair', '+{count} more', ['count' => $elements['more']]), ['class' => 'light']);
        }

        return implode(', ', $links);
    }

    /**
     * @return array{elements: ElementInterface[], more: int}|null Null when the value isn't a
     *         set of elements at all.
     */
    private function relatedElements(mixed $value, int $limit): ?array
    {
        try {
            if ($value instanceof ElementQueryInterface) {
                $total = (int)$value->count();
                $elements = $value->limit($limit)->all();
                return ['elements' => $elements, 'more' => max(0, $total - count($elements))];
            }

            if (is_array($value)) {
                $elements = array_filter($value, fn($item) => $item instanceof ElementInterface);
                return [
                    'elements' => array_slice($elements, 0, $limit),
                    'more' => max(0, count($elements) - $limit),
                ];
            }

            if (is_iterable($value)) {
                $elements = [];
                foreach ($value as $item) {
                    if ($item instanceof ElementInterface) {
                        $elements[] = $item;
                    }
                }
                return [
                    'elements' => array_slice($elements, 0, $limit),
                    'more' => max(0, count($elements) - $limit),
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === null ? '' : ($value ? '1' : '0');
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return '';
    }
}
