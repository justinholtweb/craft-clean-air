<?php

namespace justinholtweb\cleanair\services;

use Craft;
use craft\elements\User;
use justinholtweb\cleanair\models\Edition;
use justinholtweb\cleanair\models\SavedFilter;
use justinholtweb\cleanair\Plugin;
use yii\base\Component;

/**
 * Who may do what.
 *
 * Clean Air can read across a whole site at once, which makes it exactly the sort of tool
 * that needs its own permissions rather than riding on "has control panel access". Note that
 * these only ever *narrow*: every query still goes through Craft, so a user who can't see a
 * section can't see its entries here either, whatever these say.
 */
class Permissions extends Component
{
    public const VIEW = 'cleanair:view';
    public const EXPORT = 'cleanair:export';
    public const SAVE = 'cleanair:save';
    public const SHARE = 'cleanair:share';
    public const MANAGE_SHARED = 'cleanair:manageShared';
    public const IMPORT = 'cleanair:import';

    /** Per-element-type permission, e.g. `cleanair:type:entries`. */
    public static function typePermission(string $typeKey): string
    {
        return "cleanair:type:$typeKey";
    }

    private function user(?User $user): ?User
    {
        return $user ?? Craft::$app->getUser()->getIdentity();
    }

    public function canView(?User $user = null): bool
    {
        $user = $this->user($user);
        return $user !== null && ($user->admin || $user->can(self::VIEW));
    }

    /**
     * Per-type permissions are opt-out: granting Clean Air access grants every type the user
     * could already see, and a nested permission is only checked when it has been used to
     * revoke one. Otherwise every install would need each type ticked before the plugin did
     * anything, which is a support ticket waiting to happen.
     */
    public function canFilterType(?User $user, string $typeKey): bool
    {
        $user = $this->user($user);

        if ($user === null) {
            return false;
        }

        if ($user->admin) {
            return true;
        }

        if (!$user->can(self::VIEW)) {
            return false;
        }

        $permission = self::typePermission($typeKey);

        // If nobody has ever been granted or denied this specific type, don't gate on it.
        return $user->can($permission) || !$this->typePermissionInUse($permission);
    }

    /** @var array<string,bool> */
    private array $_typePermissionInUse = [];

    private function typePermissionInUse(string $permission): bool
    {
        if (isset($this->_typePermissionInUse[$permission])) {
            return $this->_typePermissionInUse[$permission];
        }

        return $this->_typePermissionInUse[$permission] = $this->probeTypePermission($permission);
    }

    private function probeTypePermission(string $permission): bool
    {
        $permissionsService = Craft::$app->getUserPermissions();

        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $granted = array_map('strtolower', $permissionsService->getPermissionsByGroupId($group->id));
            if (in_array(strtolower($permission), $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public function canExport(?User $user = null): bool
    {
        $user = $this->user($user);
        return $user !== null && ($user->admin || $user->can(self::EXPORT));
    }

    public function canSave(?User $user = null): bool
    {
        $user = $this->user($user);
        return $user !== null && ($user->admin || $user->can(self::SAVE));
    }

    public function canShare(?User $user = null): bool
    {
        $user = $this->user($user);

        if (!Edition::allowsSharing(Plugin::getInstance()->isPro())) {
            return false;
        }

        return $user !== null && ($user->admin || $user->can(self::SHARE));
    }

    public function canImport(?User $user = null): bool
    {
        $user = $this->user($user);
        return $user !== null && ($user->admin || $user->can(self::IMPORT));
    }

    /** Whether a saved filter is visible to someone. */
    public function canReadFilter(SavedFilter $filter, ?User $user = null): bool
    {
        $user = $this->user($user);

        if ($user === null) {
            return false;
        }

        if ($user->admin || $filter->userId === $user->id) {
            return true;
        }

        return $filter->isShared();
    }

    /**
     * Whether someone may change or delete a saved filter. Shared filters belong to their
     * author; editing someone else's needs the manage permission, so a shared filter can't be
     * quietly rewritten under the people relying on it.
     */
    public function canWriteFilter(SavedFilter $filter, ?User $user = null): bool
    {
        $user = $this->user($user);

        if ($user === null) {
            return false;
        }

        if ($user->admin) {
            return true;
        }

        if ($filter->userId === $user->id) {
            return $this->canSave($user);
        }

        return $filter->isShared() && $user->can(self::MANAGE_SHARED);
    }
}
