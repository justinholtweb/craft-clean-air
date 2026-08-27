<p align="center"><img src="src/icon.svg" width="96" alt="Clean Air"></p>

<h1 align="center">Clean Air</h1>

<p align="center">Advanced control panel filtering for every element type in Craft 5 — and a
way out of CP Filters.</p>

---

Craft's element indexes are good at showing you a section. They are not good at answering a
question. *Which entries in these four sections have no hero image, were last touched before
March, and aren't linked from anywhere?* You can write that as a query in a template in about
fifteen minutes. Clean Air lets you ask it in about fifteen seconds, look at the answer, save
the question, and send the results to somebody as a spreadsheet.

CP Filters did this for Craft 3 and 4. It was discontinued in April 2024 and never made the
jump to Craft 5, which left a lot of sites with a shelf of saved filters they can't read any
more. **Clean Air imports them.**

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

## Installation

```sh
composer require justinholtweb/craft-cleanair
php craft plugin/install cleanair
```

## What it does

### Filter anything

Pick an element type, pick a source, and stack conditions.

| Element type | Sources |
| --- | --- |
| Entries | Sections and entry types, including nested (Matrix) entry types |
| Assets | Volumes |
| Categories | Category groups |
| Tags | Tag groups |
| Users | User groups |
| Addresses | — (Craft has no address index at all) |
| Commerce orders | — |
| Commerce products | Product types |
| Commerce variants | Product types |
| Anything else | Any other element type on the site, through a generic driver |

Conditions match **all** at once, or **any** of them — CP Filters could only ever AND.

### Operators that fit the value

Clean Air doesn't ask what class a field is. It asks what shape its value is, and the
operators follow from that. Which means a plain text field, a URL field, an email field, a
CKEditor field, a variant SKU and an address line all get the same nine operators without any
of them having been listed anywhere.

| Value shape | Operators |
| --- | --- |
| Text | contains, doesn't contain, starts with, ends with, is, is not, is one of, is none of, is empty, is not empty |
| Number / money | is, is not, greater than, at least, less than, at most, between, empty, not empty |
| Date | is on, is before, is after, is between, **is in the last N days/weeks/months**, is in the next N, empty, not empty |
| Lightswitch | is on, is off |
| Dropdown / radio | is, is not, is one of, is none of, empty, not empty |
| Checkboxes / multi-select | contains, doesn't contain, empty, not empty |
| Relations | is assigned, **is not assigned**, empty, not empty, **has at least N**, **has at most N** |
| Matrix | empty, not empty, has at least N, has at most N |

The bold ones are the questions CP Filters couldn't ask.

An unrecognised field type doesn't disappear from the menu — it gets the text operators,
which work on whatever is in the content column. Point Clean Air at a field type properly with
`additionalFieldTypes` in the config, or with the `EVENT_DEFINE_FIELD_DEFINITION` event.

### Statuses that don't lie

Filters run with **no status constraint** by default. Craft's indexes default to "live", which
is exactly wrong for a tool you reach for when something has gone missing. Drafts, revisions
and trashed elements are each an explicit toggle.

### Results you can use

Choose the columns. Sort by any of them. The table shows relations as links and dates as
dates, and the export writes the same values — CP Filters showed you a rendered preview on
screen and dumped raw serialised arrays into the CSV.

Every result set has a URL that reproduces it. Paste it into a ticket.

### Saved and shared filters

Save a filter and give it a name. Keep it private, or share it with everyone who can use Clean
Air. Seeing a shared filter and being able to rewrite it are separate permissions, so the
report your team relies on doesn't quietly change under them.

Pin a filter to put it in the sidebar.

### Exports

CSV, TSV, JSON and XML. Streamed in batches, so a 250,000-row export costs the same memory as a
50-row one. Anything over a threshold goes to the queue and waits for you on the Exports
screen. Files are pruned after a set number of days, because an export is a snapshot of real
content and often of personal data.

### From the command line

```sh
craft cleanair/filters                        # list saved filters
craft cleanair/filters/run stale-drafts       # run one and print what it matched
craft cleanair/filters/export stale-drafts --format=csv --path=/tmp/report.csv
craft cleanair/exports/prune                  # housekeeping
```

Which is how a saved filter becomes a scheduled report.

### From a template

```twig
{% set featured = craft.cleanair.filter('featured-in-stock') %}
{% for product in featured.limit(8).all() %}
    {{ product.title }}
{% endfor %}
```

Or put a **Clean Air Filter** field on an entry, let an editor pick the filter, and ask the
field for its elements:

```twig
{% for entry in entry.productList.all(8) %}
```

The filter's conditions can then change without a deploy.

*Console commands, the Twig variable and the field type are Pro features.*

## Importing from CP Filters

Clean Air reads CP Filters' `cpfilters_savedfilters` table directly. The old plugin does not
need to be installed, or even installable — which matters, because it can't be installed on
Craft 5.

Go to **Clean Air → Import**. Nothing is written until you say so, and the preview tells you,
per filter, exactly what it will become and what it lost on the way.

Or:

```sh
craft cleanair/import          # preview
craft cleanair/import/run      # import
```

The mapping is mostly one-to-one. Three places it has to think:

- **Sources.** CP Filters stored bare IDs. Entry type, volume, group and product type IDs all
  survive the Craft 4 → 5 upgrade, so those land exactly. One that no longer exists is
  reported, and the filter runs across every source instead.
- **Ambiguous operators.** `is greater than` meant one thing on a number and another on a
  date. Clean Air resolves the field first and picks the operator that matches its kind, so a
  date filter becomes `is after` rather than a string comparison that matches nothing.
- **Fields that have gone.** A condition naming a field that no longer exists is reported
  against the filter rather than silently dropped — a filter that quietly loses a condition
  returns *more* rows than it should, which is the dangerous direction.

CP Filters' settings come across too, from project config or from a `config/cpfilters.php`
file. Its `additionalFieldTypes` format is understood as-is.

Your CP Filters data is never modified. Import, compare the two, and uninstall the old plugin
when you're happy.

## Permissions

| Permission | What it allows |
| --- | --- |
| Use Clean Air | Access to the tool |
| Filter *(type)* | One nested permission per element type |
| Export results | Writing and downloading exports |
| Save filters | Keeping filters |
| — Share filters with everyone | Making a filter visible to others |
| — Edit and delete other people's shared filters | Managing shared filters |
| Import filters from CP Filters | Running the importer |

Clean Air never widens anybody's access. Every query goes through Craft, so a user who can't
see a section can't see its entries here either, whatever these permissions say. Sources the
user has no permission for are removed from the menus, and a source hand-edited into a URL is
dropped rather than honoured.

## Settings

Everything is editable in the control panel and overridable from `config/cleanair.php`.
See [`src/config.php`](src/config.php) for the annotated list.

The one worth knowing about: **filterable sources**. Restrict which sections, volumes and
groups each element type may filter. CP Filters had this too, but only in a config file, which
meant an editor could never widen their own view without a deploy.

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Entries, assets, categories, tags, users | ● | ● |
| Addresses, Commerce, custom element types | | ● |
| All operators | ● | ● |
| Match **all** conditions | ● | ● |
| Match **any** condition | | ● |
| Saved filters | 10 per person | Unlimited |
| Shared filters | | ● |
| Column chooser | | ● |
| CSV export | ● | ● |
| TSV, JSON, XML export | | ● |
| Queued exports | | ● |
| Console commands | | ● |
| `craft.cleanair` in templates | | ● |
| Clean Air Filter field | | ● |
| **Import from CP Filters** | ● | ● |

The importer is in both editions. A migration path shouldn't be behind a paywall.

## Extending it

```php
use justinholtweb\cleanair\services\Types;
use justinholtweb\cleanair\events\RegisterTypesEvent;

Event::on(Types::class, Types::EVENT_REGISTER_TYPES, function(RegisterTypesEvent $event) {
    $event->types['widgets'] = new MyWidgetType();
});
```

`Types::EVENT_REGISTER_TYPES` adds or replaces an element type driver.
`Fields::EVENT_DEFINE_FIELD_DEFINITION` fires per custom field, to correct the value shape
Clean Air inferred or to hide a field from the builder entirely.

## A note on “match any”

Two parameters on one element query always AND — that's how Craft works, and there is no
parameter syntax for OR across different fields. So a `match any` filter runs each condition
as its own query and unions the IDs. It's correct, it works with every field type including
relations, and it costs one query per condition. There's a configurable ceiling
(`anyMatchIdLimit`, 50,000 by default); results past it are truncated and the filter says so
rather than pretending.

That's why it's a deliberate mode rather than the default.

## Licence

Commercial. See [LICENSE.md](LICENSE.md).
