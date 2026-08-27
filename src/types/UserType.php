<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use justinholtweb\cleanair\models\Source;

/**
 * Users. Sources are user groups, but they're optional — users share one field layout, so
 * the field list doesn't depend on the group.
 */
class UserType extends BaseType
{
    public function key(): string
    {
        return 'users';
    }

    public function elementType(): string
    {
        return User::class;
    }

    public function requiresSource(): bool
    {
        return false;
    }

    public function sources(): array
    {
        $sources = [];
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $sources[] = Source::make('group', $group->id, $group->name, $group->uid, Craft::t('cleanair', 'User groups'));
        }
        return $sources;
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
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $groupOptions[(string)$group->id] = $group->name;
        }

        return [
            $this->text('username', Craft::t('app', 'Username')),
            $this->text('email', Craft::t('app', 'Email')),
            $this->text('fullName', Craft::t('app', 'Full Name')),
            $this->text('firstName', Craft::t('app', 'First Name')),
            $this->text('lastName', Craft::t('app', 'Last Name')),
            $this->options('groupId', Craft::t('app', 'Group'), $groupOptions),
            $this->boolean('admin', Craft::t('app', 'Admin')),
            $this->date('lastLoginDate', Craft::t('app', 'Last Login')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'username', 'fullName', 'email', 'status', 'lastLoginDate'];
    }

    public function canView(?User $user): bool
    {
        return $user !== null && ($user->admin || $user->can('viewUsers'));
    }
}
