<?php

namespace justinholtweb\cleanair\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use justinholtweb\cleanair\models\FilterFieldValue;
use justinholtweb\cleanair\Plugin;
use yii\db\Schema;

/**
 * A field that points at a saved Clean Air filter.
 *
 * Put one on a landing page entry type and the template asks the field for its elements. The
 * editor picks "Featured, in stock, this month" from a menu; nobody writes a query, and
 * changing what that means is a content edit rather than a deploy.
 *
 * The field stores the filter's handle, not its ID, so a filter can be rebuilt or reimported
 * without every entry pointing at it going blank.
 */
class FilterField extends Field
{
    /** @var string|null Restrict the menu to filters for one element type. */
    public ?string $limitToElementType = null;

    public static function displayName(): string
    {
        return Craft::t('cleanair', 'Clean Air Filter');
    }

    public static function icon(): string
    {
        return 'filter';
    }

    public static function dbType(): string
    {
        return Schema::TYPE_STRING;
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|null', FilterFieldValue::class);
    }

    public function getSettingsHtml(): ?string
    {
        $options = [['label' => Craft::t('cleanair', 'Any element type'), 'value' => '']];

        foreach (Plugin::getInstance()->types->all() as $type) {
            if ($type->isAvailable()) {
                $options[] = ['label' => $type->label(), 'value' => $type->elementType()];
            }
        }

        return Cp::selectFieldHtml([
            'label' => Craft::t('cleanair', 'Limit to element type'),
            'instructions' => Craft::t('cleanair', 'Only offer filters that return this kind of element.'),
            'id' => 'limitToElementType',
            'name' => 'limitToElementType',
            'value' => $this->limitToElementType,
            'options' => $options,
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $filters = Plugin::getInstance()->savedFilters->all(null, $this->limitToElementType ?: null);

        if (!$filters) {
            return Html::tag('p', Craft::t('cleanair', 'No saved filters yet. Build one in Clean Air and save it, and it’ll appear here.'), ['class' => 'light']);
        }

        $options = [['label' => Craft::t('cleanair', 'None'), 'value' => '']];

        foreach ($filters as $filter) {
            $options[] = ['label' => $filter->name, 'value' => $filter->handle];
        }

        return Cp::selectHtml([
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $value instanceof FilterFieldValue ? $value->handle : (string)$value,
            'options' => $options,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof FilterFieldValue) {
            return $value;
        }

        $handle = is_string($value) ? trim($value) : '';

        return $handle !== '' ? new FilterFieldValue(['handle' => $handle]) : null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof FilterFieldValue) {
            return $value->handle;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
