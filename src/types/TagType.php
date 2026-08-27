<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Tags. Sources are tag groups.
 *
 * Craft gives tags no element index of their own worth the name, so filtering them —
 * "which tags in this group are used by nothing?" — is one of the places Clean Air earns
 * its keep.
 */
class TagType extends BaseType
{
    public function key(): string
    {
        return 'tags';
    }

    public function elementType(): string
    {
        return Tag::class;
    }

    public function sources(): array
    {
        $sources = [];
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $sources[] = Source::make('group', $group->id, $group->name, $group->uid, Craft::t('cleanair', 'Tag groups'));
        }
        return $sources;
    }

    public function fieldLayouts(?string $sourceKey): array
    {
        [$type, $id] = Source::parse($sourceKey);
        $tagsService = Craft::$app->getTags();

        if ($type === 'group' && $id) {
            $group = $tagsService->getTagGroupById($id);
            return $group ? [$group->getFieldLayout()] : [];
        }

        return array_map(fn($group) => $group->getFieldLayout(), $tagsService->getAllTagGroups());
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
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $groupOptions[(string)$group->id] = $group->name;
        }

        return [
            $this->options('groupId', Craft::t('app', 'Group'), $groupOptions),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'groupId', 'dateCreated'];
    }

    public function canView(?User $user): bool
    {
        // Craft has no per-tag-group permission; editing tags is bundled with the elements
        // that use them, so anyone with control panel access may look.
        return $user !== null;
    }
}
