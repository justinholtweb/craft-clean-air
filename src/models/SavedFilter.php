<?php

namespace justinholtweb\cleanair\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\UrlHelper;
use DateTime;

/**
 * A filter someone kept.
 *
 * CP Filters made these Craft elements so they could have an element index and a field type.
 * Clean Air keeps them as plain records: they are configuration, not content, and making
 * them elements meant they picked up sites, statuses, revisions and a content row they never
 * used. The field type works off the handle instead.
 */
class SavedFilter extends Model
{
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_SHARED = 'shared';

    public ?int $id = null;
    public ?string $uid = null;

    public string $name = '';

    /** @var string A stable slug, so templates and console commands can name a filter. */
    public string $handle = '';

    public string $elementType = '';
    public ?string $source = null;

    /** @var FilterSet The filter itself. */
    public FilterSet $filter;

    /** @var int|null The author. Null means the filter outlived its author's account. */
    public ?int $userId = null;

    public string $visibility = self::VISIBILITY_PRIVATE;

    /** Whether it shows in Clean Air's sidebar. */
    public bool $pinned = false;

    public int $sortOrder = 0;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?DateTime $dateLastRun = null;

    private ?User $_user = null;
    private bool $_userLoaded = false;

    public function init(): void
    {
        parent::init();
        if (!isset($this->filter)) {
            $this->filter = new FilterSet();
        }
    }

    public function rules(): array
    {
        return [
            [['name', 'handle', 'elementType'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [['handle'], 'match', 'pattern' => '/^[a-z0-9][a-z0-9\-]*$/', 'message' => Craft::t('cleanair', 'Handles may contain lowercase letters, numbers and hyphens.')],
            [['visibility'], 'in', 'range' => [self::VISIBILITY_PRIVATE, self::VISIBILITY_SHARED]],
        ];
    }

    public function isShared(): bool
    {
        return $this->visibility === self::VISIBILITY_SHARED;
    }

    public function getUser(): ?User
    {
        if (!$this->_userLoaded) {
            $this->_userLoaded = true;
            $this->_user = $this->userId ? Craft::$app->getUsers()->getUserById($this->userId) : null;
        }
        return $this->_user;
    }

    /** The control panel URL that runs this filter. */
    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('cleanair/saved/' . ($this->id ?? 0));
    }
}
