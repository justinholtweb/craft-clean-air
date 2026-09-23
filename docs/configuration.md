---
title: Configuration
slug: configuration
order: 20
summary: Filterable sources, unknown field types, result and export settings.
---

Everything is editable in **Settings → Plugins → Clean Air**, and everything can be overridden from
`config/cleanair.php`. Where both are set, the config file wins — the usual Craft arrangement, and
it means a value can be environment-specific.

```sh
cp vendor/justinholtweb/craft-cleanair/src/config.php config/cleanair.php
```

## Filterable sources

The setting worth knowing about. It restricts which sections, volumes and groups each element type
may filter.

```php
'filterableSources' => [
    'entries' => ['section:1', 'entryType:4'],
    'assets'  => ['volume:2'],
],
```

An empty array for a type — the default — means every source the user already has permission to
see. Clean Air never widens access: this narrows what is on offer, it cannot grant anything.

CP Filters had the same idea as `filterableEntryTypeIds` and friends, but only in a config file,
which meant an editor could never widen their own view without a deploy. This one is editable in
the control panel as well. The importer folds CP Filters' version into it.

## Element types

```php
'disabledElementTypes' => [],
'includeCommerce' => null,
```

`disabledElementTypes` takes element type classes Clean Air will not offer at all.
`includeCommerce` left at `null` auto-detects Craft Commerce, which is what you want.

## Teaching it a field type

Clean Air infers the shape of a field's value rather than matching on class, so most third-party
fields work without being told about. One that does not can be named:

```php
'additionalFieldTypes' => [
    'modules\\fields\\Rating' => FieldDefinition::KIND_NUMBER,
],
```

The kinds are `text`, `number`, `money`, `date`, `boolean`, `options`, `multiOptions`, `relation`,
`status` and `nested`.

CP Filters' own format — a class mapped to a list of filter labels — is also accepted, so an
existing `config/cpfilters.php` can be pasted in unchanged.

For anything that needs logic rather than a constant, use the
`Fields::EVENT_DEFINE_FIELD_DEFINITION` event. See [Usage](usage).

## Results

```php
'resultsPerPage' => 50,
'anyMatchIdLimit' => 50000,
'shareableUrls' => true,
'defaultColumns' => [],
```

`defaultColumns` sets the starting column set per element type key:

```php
'defaultColumns' => ['entries' => ['id', 'title', 'status', 'postDate']],
```

`anyMatchIdLimit` is the ceiling on the ID set collected for a **match any** filter. Because Craft
has no OR syntax across different fields, an OR filter runs one query per condition and unions the
IDs; unbounded, that is a way to run out of memory on a large site. Past the ceiling, results are
truncated and the interface says so rather than pretending.

`shareableUrls` set to `false` stops Clean Air handing out the **Link to these results** address, so
a filter's criteria never end up in browser history. The builder posts its form either way, so
normal use does not put criteria in the URL; this turns off the one feature that deliberately does.
It is not an access control — a hand-built URL still runs, subject to the same permissions as
everything else.

`showEmptySources` set to `false` hides sources that contain no elements from the Source menu.

## Exports

```php
'queueExportThreshold' => 2000,
'maxExportRows' => 0,
'exportBatchSize' => 100,
'exportRetentionDays' => 7,
'csvDelimiter' => ',',
'csvBom' => true,
```

Result counts above `queueExportThreshold` are exported on the queue instead of in the request. `0`
always exports inline. This is a Pro setting — Lite always exports inline.

`maxExportRows` is a hard cap on rows a single export will write; `0` means no cap.

`exportRetentionDays` is how long a finished export file is kept. `0` keeps them forever, which is
rarely what anyone means — an export is a snapshot of real content, and often of personal data.
Pruning runs from `craft cleanair/exports/prune`.

`csvBom` writes a UTF-8 byte order mark, which is what makes Excel open accented characters
correctly.

## Logging

```php
'logLevel' => 'warning',
```

`debug`, `info`, `warning` or `error`. Clean Air logs to Craft's own log, under the `cleanair`
category.
