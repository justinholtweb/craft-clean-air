<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Entries.
 *
 * Sources are offered twice over: by section, and by entry type. Entry types are the useful
 * unit — they are what owns a field layout — but "everything in the News section" is how
 * people actually think, and in Craft 5 an entry type can be shared across sections or live
 * only inside a Matrix field with no section at all.
 *
 * CP Filters keyed its saved filters on entry type IDs, which survive the Craft 4 → 5
 * upgrade, so `entryType:<id>` is what its filters import onto.
 */
class EntryType extends BaseType
{
    public function key(): string
    {
        return 'entries';
    }

    public function elementType(): string
    {
        return Entry::class;
    }

    public function sources(): array
    {
        $entriesService = Craft::$app->getEntries();
        $sources = [];

        foreach ($entriesService->getAllSections() as $section) {
            $sources[] = Source::make('section', $section->id, $section->name, $section->uid, Craft::t('cleanair', 'Sections'));
        }

        // Which sections each entry type belongs to, so the picker can say where a type is
        // used — several sections can share one, and nested types belong to none.
        $sectionsByTypeId = [];
        foreach ($entriesService->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $sectionsByTypeId[$entryType->id][] = $section->name;
            }
        }

        foreach ($entriesService->getAllEntryTypes() as $entryType) {
            $hint = isset($sectionsByTypeId[$entryType->id])
                ? implode(', ', $sectionsByTypeId[$entryType->id])
                : Craft::t('cleanair', 'Nested');
            $sources[] = Source::make(
                'entryType',
                $entryType->id,
                $entryType->name,
                $entryType->uid,
                Craft::t('cleanair', 'Entry types'),
                $hint,
            );
        }

        return $sources;
    }

    public function fieldLayouts(?string $sourceKey): array
    {
        [$type, $id] = Source::parse($sourceKey);
        $entriesService = Craft::$app->getEntries();

        if ($type === 'entryType' && $id) {
            $entryType = $entriesService->getEntryTypeById($id);
            return $entryType ? [$entryType->getFieldLayout()] : [];
        }

        if ($type === 'section' && $id) {
            $section = $entriesService->getSectionById($id);
            if (!$section) {
                return [];
            }
            return array_map(fn($entryType) => $entryType->getFieldLayout(), $section->getEntryTypes());
        }

        // No source: every entry type's layout, so a site-wide filter can still name a field.
        return array_map(fn($entryType) => $entryType->getFieldLayout(), $entriesService->getAllEntryTypes());
    }

    public function applySource(ElementQuery $query, ?string $sourceKey): void
    {
        [$type, $id] = Source::parse($sourceKey);
        if ($type === 'entryType' && $id) {
            $query->typeId = $id;
        } elseif ($type === 'section' && $id) {
            $query->sectionId = $id;
        }
    }

    public function nativeAttributes(): array
    {
        $sectionOptions = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sectionOptions[(string)$section->id] = $section->name;
        }

        $typeOptions = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $typeOptions[(string)$entryType->id] = $entryType->name;
        }

        return [
            $this->text('slug', Craft::t('app', 'Slug')),
            $this->text('uri', Craft::t('app', 'URI')),
            $this->date('postDate', Craft::t('app', 'Post Date')),
            $this->date('expiryDate', Craft::t('app', 'Expiry Date')),
            $this->relation('authorId', Craft::t('app', 'Author'), User::class),
            $this->options('sectionId', Craft::t('app', 'Section'), $sectionOptions),
            $this->options('typeId', Craft::t('app', 'Entry Type'), $typeOptions),
            $this->number('level', Craft::t('app', 'Level')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'status', 'sectionId', 'postDate', 'dateUpdated'];
    }

    public function canView(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("viewEntries:$section->uid")) {
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

        $entriesService = Craft::$app->getEntries();

        if ($source->type === 'section') {
            $section = $source->id ? $entriesService->getSectionById($source->id) : null;
            return $section !== null && $user->can("viewEntries:$section->uid");
        }

        if ($source->type === 'entryType') {
            // An entry type is visible if any section using it is. Nested types have no
            // section of their own, so they fall back to "can see entries at all" — their
            // owners' permissions still apply to the elements themselves.
            $used = false;
            foreach ($entriesService->getAllSections() as $section) {
                foreach ($section->getEntryTypes() as $entryType) {
                    if ($entryType->id === $source->id) {
                        $used = true;
                        if ($user->can("viewEntries:$section->uid")) {
                            return true;
                        }
                    }
                }
            }
            return !$used && $this->canView($user);
        }

        return $this->canView($user);
    }
}
