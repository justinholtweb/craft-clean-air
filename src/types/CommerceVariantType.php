<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Craft Commerce variants.
 *
 * Commerce 5 moved SKU, dimensions and price out of the variant table and onto the shared
 * purchasable tables, both of which the variant query already joins — so those columns are
 * filterable here without Clean Air adding a join of its own.
 *
 * Variants are where stock questions live, and "every variant under 5 in stock across the
 * whole store" is not a report Commerce ships.
 */
class CommerceVariantType extends CommerceType
{
    public function key(): string
    {
        return 'variants';
    }

    public function elementType(): string
    {
        return 'craft\\commerce\\elements\\Variant';
    }

    public function label(): string
    {
        return Craft::t('cleanair', 'Variants');
    }

    public function requiresSource(): bool
    {
        return false;
    }

    public function sources(): array
    {
        $sources = [];
        foreach ($this->productTypes() as $productType) {
            $sources[] = Source::make('productType', $productType->id, $productType->name, $productType->uid, Craft::t('cleanair', 'Product types'));
        }
        return $sources;
    }

    public function fieldLayouts(?string $sourceKey): array
    {
        [$type, $id] = Source::parse($sourceKey);
        $productTypes = $this->productTypes();

        if ($type === 'productType' && $id) {
            foreach ($productTypes as $productType) {
                if ($productType->id === $id) {
                    return [$productType->getVariantFieldLayout()];
                }
            }
            return [];
        }

        return array_map(fn($productType) => $productType->getVariantFieldLayout(), $productTypes);
    }

    public function applySource(\craft\elements\db\ElementQuery $query, ?string $sourceKey): void
    {
        [$type, $id] = Source::parse($sourceKey);
        if ($type === 'productType' && $id) {
            $query->typeId = $id;
        }
    }

    public function nativeAttributes(): array
    {
        $typeOptions = [];
        foreach ($this->productTypes() as $productType) {
            $typeOptions[(string)$productType->id] = $productType->name;
        }

        return [
            $this->text('sku', Craft::t('commerce', 'SKU'), 'commerce_purchasables.sku'),
            $this->money('price', Craft::t('commerce', 'Price'), 'purchasables_stores.basePrice'),
            $this->number('weight', Craft::t('commerce', 'Weight'), 'commerce_purchasables.weight'),
            $this->number('width', Craft::t('commerce', 'Width'), 'commerce_purchasables.width'),
            $this->number('height', Craft::t('commerce', 'Height'), 'commerce_purchasables.height'),
            $this->number('length', Craft::t('commerce', 'Length'), 'commerce_purchasables.length'),
            $this->boolean('availableForPurchase', Craft::t('commerce', 'Available for purchase'), 'purchasables_stores.availableForPurchase'),
            $this->boolean('freeShipping', Craft::t('commerce', 'Free Shipping'), 'purchasables_stores.freeShipping'),
            $this->boolean('isDefault', Craft::t('commerce', 'Default Variant')),
            $this->options('typeId', Craft::t('commerce', 'Product Type'), $typeOptions),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'sku', 'price', 'status'];
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($user->admin || $user->can('commerce-manageProducts'));
    }
}
