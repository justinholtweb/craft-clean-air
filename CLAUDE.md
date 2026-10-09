# Clean Air — Craft CMS 5 Plugin

Advanced control-panel filtering for Craft 5, and a way off CP Filters. Package
`justinholtweb/craft-cleanair`, handle `cleanair`, namespace `justinholtweb\cleanair`. Lite + Pro.
The README and `docs/` describe the product; this file is for working on it.

## Where authorization lives

- **`services\Types` decides what a user may filter.** `available()` is the element types they can
  see; `sources()` the sources within one; `isAllowedSource()` whether a source — or *All sources*,
  passed as null — is usable. *All sources* adds no source condition to the query, so it is only
  allowed when the settings restrict nothing **and** the user can view every source of the type.
- **The CP controllers enforce it, not `Query::build()`.** `build()` is also used by the Twig
  variable and the filter field — trusted template code, often with nobody signed in — and by queued
  exports, which run without a user. Putting a user check in the query builder would break all
  three. `FiltersController::authorizedFilter()` is the one gate for count, export and save; the
  builder screen (`actionIndex`) checks the same things and falls back to an allowed source.
- **Settings are project config.** Anything that writes them (the importer's "carry over CP Filters'
  settings") needs an admin with `allowAdminChanges` on.

## Scheduled reports (Theme 7 port)

Ported from Yarn's findings digest (craft-yarn `CLAUDE.md`, "Scheduled digests (reference for the
family)"). Same rules — period keys (`Y-m-d`, ISO `o-\WW`, `Y-m`), claim with an atomic
`UPDATE … WHERE period = :old`, release on failure or cancel, "new" = current IDs minus `seen`,
cron + `EVENT_AFTER_REQUEST` fallback (never `Gc::EVENT_RUN`), one `helpers\Mailer`, a test send
that never touches the marker. What differs:

- **One marker row per report**, not one per plugin: `cleanair_reports` holds each saved filter's
  schedule *and* its marker (`period`, `seen`, `last*`). `filterId` is unique and cascades.
- **Runs as its owner.** `Query::build()` checks nothing (see above), so `Reports::canRun()` is the
  gate: active account, view + export, can read the saved filter, type in `available($user)`,
  `isAllowedSource($type, $source, $user)`. Asked of the owner and of every recipient on every run,
  and again after `EVENT_BEFORE_SEND`. The owner is whoever last saved the schedule (the controller
  sets `ownerId`, never the form).
- **Personal data** (`BaseType::containsPersonalData()`: users, addresses, orders, generic types)
  is never attached or listed; each recipient gets `Exports::copyFor()` — their own record + file,
  because downloads are owner-only.
- `seen` is a JSON ID list, or `"*"` past `SEEN_LIMIT` (then "new" is unknown, not "nothing").
- The test send goes to the person pressing it, run as them — not to the list.
- A schedule saved after this period's hour starts with this period claimed (`initialPeriod()`).

## Condition groups

`Criterion` with `type = group` holds `matchMode` + child criteria, one level deep (a nested group
is dropped on read). Plain conditions serialise without a `type` key, so old saved filters read
back unchanged. In `Query`: an `all` group applies its children to the query; an `any` group is a
capped ID union intersected with `andWhere(['elements.id' => …])` — **not `id()`**, which a second
group would overwrite. On Lite a filter with groups matches nothing (ignoring them would widen it).
Builder inputs are named by each row's `data-name` prefix; `criterion-row` accepts a `name` but only
in the two shapes the regex allows.

## Traps

- **A curly quote straight after a variable in a double-quoted string is part of the variable's
  name** — PHP allows bytes above 0x7F in identifiers — so `"… “$handle”."` reads `$handle”` and
  prints nothing. Brace it: `“{$handle}”`. PHPStan reports it as an undefined variable.
- **ECS's ordered-imports fixer breaks a file with a `use` statement below code.** It swapped a
  mid-file `use` into the top block and moved `use …\Plugin;` below its first use. Keep every `use`
  at the top of `tests/integration/*.php`.
- **`craftcms/phpstan` does not pull in PHPStan itself**; `phpstan/phpstan` has to be required too.
  (The same is true of every sibling plugin that copied the family's `phpstan.neon`.)
- **Never hand a date *param* a `Db::prepareDateForDb()` string.** Craft's date params (custom
  fields, native attributes and `Db::parseDateParam()` on a column alike) read a bare
  `Y-m-d H:i:s` as system time and convert it to UTC themselves, so a pre-converted bound is
  shifted twice. `Operators::dateParam()` formats with `DATE_ATOM`, which carries its own offset.
  The fixture-relative date checks only failed in the hours before the offset (00:00–07:00 in LA).
- **A query parameter holds one value.** Never assign one blind: `Query::applyParam()` reads what's
  there first (an earlier condition, or the source — a Section condition on a section source) and
  either joins the two as one flat `['and', …]` list (Craft's parsers don't recurse, so only
  operator-prefixed scalars join) or ANDs the new one in as an `elements_sites.id IN (subquery)`
  built with `prepareSubquery()`. Status conditions do the same against the filter's status.
- **Craft drops option IDs that don't resolve, then some queries filter on nothing** (`TagQuery`'s
  `groupId`). `Operators::build()` keeps only the native options that still exist for "is"/"is one
  of", and aborts when none do.
- **Element query source params are subclass properties.** Set them with
  `Craft::configure($query, ['sectionId' => $id])`, which PHPStan accepts on a base `ElementQuery`.

## Testing

No local PHP on this Mac. Everything runs in the plugin-testing container, where this repo is mounted
at `/var/www/craft-cleanair` (and symlinked into the harness's vendor directory — check with
`ls -la ~/Sites/plugin-testing/vendor/justinholtweb | grep cleanair`).

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/checks.php   # 306 + 3 skipped
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/trust.php    # 5, a limited editor over HTTP
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/reports.php  # 49, scheduled reports
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/reports-http.php # 17, schedule endpoints + group markup
docker exec -w /var/www/craft-cleanair ddev-plugin-testing-web bash -c 'vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
```

`trust.php` signs in as an editor who can see one section and no users, and pairs every refusal with
something the same user *is* allowed, so a pass means "refused", not "broken". Its cleanup deletes
exports through `Exports::delete()` so the file goes too — an export that got through would be a CSV
of personal data left in `storage/runtime/cleanair-exports`.
