<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Categories. Sources are category groups.
 */
class CategoryType extends BaseType
{
    public function key(): string
    {
        return 'categories';
    }

    public function elementType(): string
    {
        return Category::class;
    }

    public function sources(): array
    {
        $sources = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $sources[] = Source::make('group', $group->id, $group->name, $group->uid, Craft::t('cleanair', 'Category groups'));
        }
        return $sources;
    }

    public function fieldLayouts(?string $sourceKey): array
    {
        [$type, $id] = Source::parse($sourceKey);
        $categoriesService = Craft::$app->getCategories();

        if ($type === 'group' && $id) {
            $group = $categoriesService->getGroupById($id);
            return $group ? [$group->getFieldLayout()] : [];
        }

        return array_map(fn($group) => $group->getFieldLayout(), $categoriesService->getAllGroups());
    }

    public function applySource(ElementQuery $query, ?string $sourceKey): void
    {
        [$type, $id] = Source::parse($sourceKey);
        if ($type === 'group' && $id) {
            $query->groupId = $id;
        }
    }

    public function nativeAttributes(): array
    {
        $groupOptions = [];
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $groupOptions[(string)$group->id] = $group->name;
        }

        return [
            $this->text('slug', Craft::t('app', 'Slug')),
            $this->text('uri', Craft::t('app', 'URI')),
            $this->options('groupId', Craft::t('app', 'Group'), $groupOptions),
            $this->number('level', Craft::t('app', 'Level')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'slug', 'groupId', 'dateUpdated'];
    }

    public function canView(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($user->can("viewCategories:$group->uid")) {
                return true;
            }
        }
        return false;
    }

    public function canViewSource(?User $user, Source $source): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        $group = $source->id ? Craft::$app->getCategories()->getGroupById($source->id) : null;
        return $group !== null && $user->can("viewCategories:$group->uid");
    }
}
