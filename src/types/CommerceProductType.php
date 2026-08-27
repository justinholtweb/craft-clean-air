<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Craft Commerce products. Sources are product types.
 */
class CommerceProductType extends CommerceType
{
    public function key(): string
    {
        return 'products';
    }

    public function elementType(): string
    {
        return 'craft\\commerce\\elements\\Product';
    }

    public function label(): string
    {
        return Craft::t('cleanair', 'Products');
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
                    return [$productType->getFieldLayout()];
                }
            }
            return [];
        }

        return array_map(fn($productType) => $productType->getFieldLayout(), $productTypes);
    }

    public function applySource(ElementQuery $query, ?string $sourceKey): void
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
            $this->text('slug', Craft::t('app', 'Slug')),
            $this->text('uri', Craft::t('app', 'URI')),
            $this->date('postDate', Craft::t('app', 'Post Date')),
            $this->date('expiryDate', Craft::t('app', 'Expiry Date')),
            $this->options('typeId', Craft::t('commerce', 'Product Type'), $typeOptions),
            $this->money('defaultPrice', Craft::t('commerce', 'Price')),
            $this->text('defaultSku', Craft::t('commerce', 'SKU')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'status', 'defaultSku', 'defaultPrice', 'dateUpdated'];
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($user->admin || $user->can('commerce-manageProducts'));
    }
}
