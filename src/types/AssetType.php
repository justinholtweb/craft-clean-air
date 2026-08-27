<?php

namespace justinholtweb\cleanair\types;

use Craft;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\User;
use craft\helpers\Assets;
use justinholtweb\cleanair\models\Source;

/**
 * Assets. Sources are volumes.
 *
 * The interesting filters here are the ones the native index can't do: files over a size,
 * images under a dimension, anything missing alt text, everything uploaded by a person who
 * has since left.
 */
class AssetType extends BaseType
{
    public function key(): string
    {
        return 'assets';
    }

    public function elementType(): string
    {
        return Asset::class;
    }

    public function sources(): array
    {
        $sources = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $sources[] = Source::make('volume', $volume->id, $volume->name, $volume->uid, Craft::t('cleanair', 'Volumes'));
        }
        return $sources;
    }

    public function fieldLayouts(?string $sourceKey): array
    {
        [$type, $id] = Source::parse($sourceKey);
        $volumesService = Craft::$app->getVolumes();

        if ($type === 'volume' && $id) {
            $volume = $volumesService->getVolumeById($id);
            return $volume ? [$volume->getFieldLayout()] : [];
        }

        return array_map(fn($volume) => $volume->getFieldLayout(), $volumesService->getAllVolumes());
    }

    public function applySource(ElementQuery $query, ?string $sourceKey): void
    {
        [$type, $id] = Source::parse($sourceKey);
        if ($type === 'volume' && $id) {
            $query->volumeId = $id;
        }
    }

    public function nativeAttributes(): array
    {
        $volumeOptions = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumeOptions[(string)$volume->id] = $volume->name;
        }

        $kindOptions = [];
        foreach (Assets::getAllowedFileKinds() as $kind => $config) {
            $kindOptions[$kind] = $config['label'] ?? ucfirst($kind);
        }

        return [
            $this->text('filename', Craft::t('app', 'Filename')),
            $this->options('kind', Craft::t('app', 'File Kind'), $kindOptions),
            $this->number('size', Craft::t('app', 'File Size') . ' (' . Craft::t('cleanair', 'bytes') . ')'),
            $this->number('width', Craft::t('app', 'Width')),
            $this->number('height', Craft::t('app', 'Height')),
            $this->options('volumeId', Craft::t('app', 'Volume'), $volumeOptions),
            $this->relation('uploaderId', Craft::t('app', 'Uploaded By'), User::class),
            $this->boolean('hasAlt', Craft::t('app', 'Has Alternative Text')),
            $this->text('alt', Craft::t('app', 'Alternative Text'), 'assets.alt'),
            $this->date('dateModified', Craft::t('app', 'File Modified Date')),
        ];
    }

    public function defaultColumns(): array
    {
        return ['id', 'title', 'filename', 'kind', 'size', 'dateUpdated'];
    }

    public function canView(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user->can("viewAssets:$volume->uid")) {
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
        $volume = $source->id ? Craft::$app->getVolumes()->getVolumeById($source->id) : null;
        return $volume !== null && $user->can("viewAssets:$volume->uid");
    }
}
