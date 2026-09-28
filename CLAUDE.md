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

## Traps

- **A curly quote straight after a variable in a double-quoted string is part of the variable's
  name** — PHP allows bytes above 0x7F in identifiers — so `"… “$handle”."` reads `$handle”` and
  prints nothing. Brace it: `“{$handle}”`. PHPStan reports it as an undefined variable.
- **ECS's ordered-imports fixer breaks a file with a `use` statement below code.** It swapped a
  mid-file `use` into the top block and moved `use …\Plugin;` below its first use. Keep every `use`
  at the top of `tests/integration/*.php`.
- **`craftcms/phpstan` does not pull in PHPStan itself**; `phpstan/phpstan` has to be required too.
  (The same is true of every sibling plugin that copied the family's `phpstan.neon`.)
- **Element query source params are subclass properties.** Set them with
  `Craft::configure($query, ['sectionId' => $id])`, which PHPStan accepts on a base `ElementQuery`.

## Testing

No local PHP on this Mac. Everything runs in the plugin-testing container, where this repo is mounted
at `/var/www/craft-cleanair` (and symlinked into the harness's vendor directory — check with
`ls -la ~/Sites/plugin-testing/vendor/justinholtweb | grep cleanair`).

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/checks.php   # 213 + 3 skipped
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-cleanair/tests/integration/trust.php    # 5, a limited editor over HTTP
docker exec -w /var/www/craft-cleanair ddev-plugin-testing-web bash -c 'vendor/bin/phpunit && vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check'
```

`trust.php` signs in as an editor who can see one section and no users, and pairs every refusal with
something the same user *is* allowed, so a pass means "refused", not "broken". Its cleanup deletes
exports through `Exports::delete()` so the file goes too — an export that got through would be a CSV
of personal data left in `storage/runtime/cleanair-exports`.
