<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\elements\User;
use justinholtweb\cleanair\events\RegisterTypesEvent;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\Source;
use justinholtweb\cleanair\Plugin;
use justinholtweb\cleanair\types\AddressType;
use justinholtweb\cleanair\types\AssetType;
use justinholtweb\cleanair\types\BaseType;
use justinholtweb\cleanair\types\CategoryType;
use justinholtweb\cleanair\types\CommerceOrderType;
use justinholtweb\cleanair\types\CommerceProductType;
use justinholtweb\cleanair\types\CommerceVariantType;
use justinholtweb\cleanair\types\EntryType;
use justinholtweb\cleanair\types\GenericType;
use justinholtweb\cleanair\types\TagType;
use justinholtweb\cleanair\types\UserType;
use yii\base\Component;

/**
 * The register of element types Clean Air can filter.
 *
 * Purpose-built drivers first, then a generic driver for every other element type Craft
 * knows about — so a plugin that adds an element type gets basic filtering and export for
 * free, and can register a better driver if it wants one.
 */
class Types extends Component
{
    /**
     * @event RegisterTypesEvent Fired when the drivers are assembled, for plugins that want
     *        to add or replace one.
     */
    public const EVENT_REGISTER_TYPES = 'registerTypes';

    /**
     * Element types the generic driver skips. Not because they can't be filtered, but
     * because a list of them answers no question anybody has.
     */
    private const GENERIC_DENYLIST = [
        'craft\\elements\\GlobalSet',
        'craft\\elements\\MatrixBlock',
        'craft\\commerce\\elements\\Donation',
    ];

    /** @var BaseType[]|null */
    private ?array $_types = null;

    /**
     * Every driver, available or not, keyed by type key.
     *
     * @return BaseType[]
     */
    public function all(): array
    {
        if ($this->_types !== null) {
            return $this->_types;
        }

        $types = [
            new EntryType(),
            new AssetType(),
            new CategoryType(),
            new TagType(),
            new UserType(),
            new AddressType(),
            new CommerceOrderType(),
            new CommerceProductType(),
            new CommerceVariantType(),
        ];

        $keyed = [];
        foreach ($types as $type) {
            $keyed[$type->key()] = $type;
        }

        foreach ($this->genericTypes($keyed) as $type) {
            $keyed[$type->key()] = $type;
        }

        $event = new RegisterTypesEvent(['types' => $keyed]);
        $this->trigger(self::EVENT_REGISTER_TYPES, $event);

        return $this->_types = $event->types;
    }

    /**
     * @param BaseType[] $covered
     * @return BaseType[]
     */
    private function genericTypes(array $covered): array
    {
        $coveredClasses = array_map(fn(BaseType $type) => $type->elementType(), $covered);
        $generic = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $class) {
            if (in_array($class, $coveredClasses, true) || in_array($class, self::GENERIC_DENYLIST, true)) {
                continue;
            }
            $generic[] = new GenericType(['class' => $class]);
        }

        return $generic;
    }

    /**
     * Drivers this install can actually use: the element type exists, the settings haven't
     * turned it off, the edition allows it, and the user is allowed to look.
     *
     * @return BaseType[]
     */
    public function available(?User $user = null): array
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        $settings = Plugin::getInstance()->getSettings();
        $isPro = Plugin::getInstance()->isPro();

        $available = [];
        foreach ($this->all() as $key => $type) {
            if (!$type->isAvailable()) {
                continue;
            }
            if (in_array($type->elementType(), $settings->disabledElementTypes, true)) {
                continue;
            }
            if (!Edition::allowsTypeKey($isPro, $key)) {
                continue;
            }
            if (!$type->canView($user)) {
                continue;
            }
            if (!Plugin::getInstance()->permissions->canFilterType($user, $key)) {
                continue;
            }
            $available[$key] = $type;
        }

        return $available;
    }

    public function forKey(?string $key): ?BaseType
    {
        if ($key === null || $key === '') {
            return null;
        }
        return $this->all()[$key] ?? null;
    }

    public function forElementType(string $class): ?BaseType
    {
        foreach ($this->all() as $type) {
            if ($type->elementType() === $class) {
                return $type;
            }
        }
        return null;
    }

    /**
     * The sources a user may filter within a type, after both Clean Air's settings and
     * Craft's own permissions have had their say.
     *
     * @return Source[]
     */
    public function sources(BaseType $type, ?User $user = null): array
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        $allowed = Plugin::getInstance()->getSettings()->allowedSources($type->key());

        $sources = [];
        foreach ($type->sources() as $source) {
            if ($allowed && !in_array($source->key, $allowed, true)) {
                continue;
            }
            if (!$type->canViewSource($user, $source)) {
                continue;
            }
            $sources[] = $source;
        }

        return $sources;
    }

    /** Whether a source key is one the user is allowed to use for this type. */
    public function isAllowedSource(BaseType $type, ?string $sourceKey, ?User $user = null): bool
    {
        if ($sourceKey === null || $sourceKey === '') {
            // "All sources" is only allowed when no restriction is in force — otherwise it
            // would be a way around the restriction.
            return !Plugin::getInstance()->getSettings()->allowedSources($type->key());
        }

        foreach ($this->sources($type, $user) as $source) {
            if ($source->key === $sourceKey) {
                return true;
            }
        }

        return false;
    }
}
