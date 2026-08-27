# Release Notes for Clean Air

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
