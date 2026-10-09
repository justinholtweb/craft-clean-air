# Release Notes for Clean Air

## 5.1.0 — 2026-10-09
### Added

- Scheduled email reports (Pro). Any saved filter can be emailed daily, weekly or monthly — press
  **Email…** on the Saved filters screen — to control panel users, in any export format, every time
  or only when it matches something (or something new since the last report). Each report runs with
  the permissions of whoever saved its schedule, goes only to people who could run and export the
  filter themselves, and never attaches or lists users, addresses or orders: those land on each
  recipient's Exports screen instead.
- `craft cleanair/reports/send` (for cron; each report sends once per period however often it runs)
  and `craft cleanair/reports/status`. Without cron, web requests queue due reports at most every
  five minutes (`reportWebTrigger`).
- **Send me a test now** on a report's schedule screen.
- The *Email saved filters on a schedule* permission, the `reportWebTrigger` and
  `reportAttachmentLimit` settings, and `Reports::EVENT_BEFORE_SEND`.
- Condition groups (Pro): a group of conditions with its own all/any, one level deep, so "in
  Products AND (no image OR no price)" is one filter. The `match any` ceiling applies per group and
  the warning names the group that hit it.

### Fixed

- Date conditions (**is on**, **is before**, **is after**, **is between**, **in the last/next…**)
  converted their dates to UTC twice, shifting them by the site's distance from UTC: on a site
  west of UTC, "is on 7 October" ran from 7am on the 7th to 7am on the 8th, missing everything
  earlier in the day and catching the next morning's. They now cover the site's own day and time
  exactly.
- Two conditions on the same field or attribute no longer replace each other. Each one set the
  same query parameter, so the last won: "Price is greater than 10" and "Price is less than 50"
  ran as "Price is less than 50". They now both hold — at the top level, inside a group, and across
  a group and the top level — whether they're numbers, dates, text, options, lightswitches,
  relations, statuses or IDs. The same fault let a **Section** or **Entry Type** condition replace
  the section the filter was built on, and a **Status** condition replace the filter's own status.
- A condition on a section, entry type, group or volume whose every chosen option has since been
  deleted matches nothing. Tags' **Group** condition matched every tag instead, because Craft drops
  IDs that no longer exist and then filters on none.

## 5.0.1 — 2026-09-28

### Security

- Fixed a vulnerability where *All sources* let a user filter elements in sources they don't have
  permission to view, such as every section when they can only see one. *All sources* is now only
  offered, and only accepted, when the user can view every source of the element type.
- Fixed a vulnerability where the count, export and save actions accepted any element type and
  source in the posted filter. A user with the export permission but no access to users could export
  every account's email address. These actions now check the element type and source the same way
  the filter builder does.
- Carrying over CP Filters' settings during an import now requires an admin and `allowAdminChanges`,
  because it rewrites Clean Air's settings in project config.

### Fixed

- Fixed console and export error messages that printed an empty value in place of the filter handle
  or format name.

### Changed

- Added PHPStan (level 4) and ECS configuration, with `composer phpstan`, `composer check-cs` and
  `composer fix-cs`.

## 5.0.0 — Unreleased

Initial release.

### Filtering
- Filter entries, assets, categories, tags, users, addresses, Commerce orders, products and
  variants — plus any other element type on the site, through a generic driver.
- Stack any number of conditions, matching **all** of them or **any** of them.
- Operators by value shape rather than field class: text, number, money, date, boolean,
  single- and multi-option, relation and nested-entry fields each get the operators that make
  sense for them, including `is one of`, `is between`, `is in the last 30 days`,
  `is not assigned` and `has at least N related`.
- Filters run with no status constraint by default, so disabled, expired and pending elements
  show up — which is normally why you went looking.
- Optionally include drafts, revisions and trashed elements.
- Results are unrestricted by Craft's element index limitations: sortable, paginated, with a
  chosen set of columns.

### Saved filters
- Save a filter, name it, and give it a handle.
- Share a filter with everyone, or keep it private. Editing someone else's shared filter is a
  separate permission from seeing it.
- Pin a filter, duplicate one, or run one from a link.
- Every result set has a URL that reproduces it.

### Exports
- CSV, TSV, JSON and XML.
- Choose the columns; what you see on screen is what lands in the file.
- Streamed in batches, so an export of a quarter of a million rows costs the same memory as
  fifty.
- Large exports go to the queue and are collected from the Exports screen afterwards.
- Export files are pruned on a schedule.

### Console
- `cleanair/filters` — list, run, export and count saved filters. Cron-friendly.
- `cleanair/exports/prune` — housekeeping.

### Templates
- `craft.cleanair.filter('handle')` returns a live element query for a saved filter.
- A **Clean Air Filter** field points an entry at a saved filter.

### Importing from CP Filters
- Reads CP Filters' own table directly — the old plugin doesn't need to be installed.
- Previews every filter before anything is written, saying exactly what each one will become
  and what it lost.
- Maps CP Filters' operators onto Clean Air's, resolving the ambiguous ones (`is greater than`
  on a date becomes `is after`) against the field's actual type.
- Carries CP Filters' settings across, from project config or a `config/cpfilters.php` file.
- Also available as `craft cleanair/import/run`.
