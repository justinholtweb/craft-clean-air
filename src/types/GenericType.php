<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\helpers\StringHelper;

/**
 * The fallback driver, used for any element type Clean Air has no purpose-built driver for —
 * a third-party plugin's element, or one Craft adds after this release.
 *
 * It offers the element type's single field layout and the common attributes. That's enough
 * to be useful without knowing anything about the element, and a plugin that wants more can
 * register its own driver through Plugin::EVENT_REGISTER_TYPES.
 */
class GenericType extends BaseType
{
    /** @var class-string<ElementInterface> */
    public string $class;

    public function key(): string
    {
        return StringHelper::toKebabCase(str_replace('\\', '-', $this->class));
    }

    public function elementType(): string
    {
        return $this->class;
    }

    public function requiresSource(): bool
    {
        return false;
    }

    public function canView(?User $user): bool
    {
        return $user !== null;
    }

    public function label(): string
    {
        /** @var class-string<ElementInterface> $class */
        $class = $this->class;
        return Craft::t('cleanair', '{type}', ['type' => $class::pluralDisplayName()]);
    }
}
