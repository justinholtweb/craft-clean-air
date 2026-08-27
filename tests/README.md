# Tests

## Unit

Plain PHP, no Craft application and no database. What they cover is the part of Clean Air
that is deliberately pure: the filter language — turning a criterion into a query parameter —
and where the Lite/Pro line falls.

```sh
composer install
composer test
```

## Integration

The rest needs a real Craft. `tests/integration/checks.php` builds its own content — six
fields, an entry type, a section, four entries with deliberately awkward values — runs the
whole plugin against it, and tears it back down. It depends on nothing that happens to be in
the install already, so it gives the same answer anywhere.

Run it from the Craft installation, not from the plugin:

```sh
ddev exec php /var/www/craft-cleanair/tests/integration/checks.php
ddev exec php /var/www/craft-cleanair/tests/integration/checks.php --keep   # leave the content behind
```

It covers the element type registry, field discovery and kind mapping, every operator against
known data, statuses and sources, match-all and match-any, columns and their rendering, paging
and batched iteration, all four export formats, saved filters, the edition boundaries, the CP
Filters importer (against a table it stands up itself, shaped exactly as CP Filters left it),
the Twig variable and the filter field, and that every template compiles and renders.

Three checks are reported as **skipped** rather than run: Craft's own date picker and element
selector macros call methods that only exist on a web request. Those are covered by loading
the real control panel pages instead.

### The control panel

The console suite can't exercise the pages themselves. To check those, load them as an admin
and look for a 200 with no exception in the body:

```
/admin/cleanair
/admin/cleanair/type/<typeKey>
/admin/cleanair/saved
/admin/cleanair/exports
/admin/cleanair/import
/admin/settings/plugins/cleanair
```

and the endpoints the builder uses: `cleanair/filters/criterion-row`,
`cleanair/filters/count`, `cleanair/filters/export`, `cleanair/filters/save`.

### A note on running console scripts as root

`docker exec` runs as root; `ddev exec` runs as the web user. A bare script that writes project
config as root leaves YAML the web server can't rewrite, and the next control panel request
throws. Use `ddev exec`.

Relatedly: a bare console script buffers its project config writes. If it exits before
`saveModifiedConfigData()`, the database has rows project config has never heard of — and the
next run is told a field handle is taken by a field it cannot see. The suite flushes as it goes
and cleans up both halves on the way in.
