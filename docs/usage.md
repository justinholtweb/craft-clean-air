---
title: Usage
slug: usage
order: 30
summary: Building filters, operators, saved filters, exports, Twig, console, and the CP Filters importer.
---

## Building a filter

A filter is four things: an element type, a source, a set of conditions, and how those conditions
combine.

**Element type** is the row of tabs. Entries, assets, categories, tags and users are in both
editions. Addresses, Commerce orders, products and variants, and any other element type on the site
are Pro.

**Source** narrows the type — a section or entry type for entries, a volume for assets, a group for
categories, tags and users, a product type for Commerce. *All sources* searches everything you have
permission to see. Addresses have no source, because Craft has no address index at all.

**Conditions** are a field, an operator, and whatever the operator needs. Add as many as you like.

**Match** is `all conditions` or `any condition`. CP Filters could only ever AND. See
[Match any](#match-any) below for what OR costs.

## Operators that fit the value

Clean Air does not ask what class a field is. It asks what shape its value is, and the operators
follow from that — which is why a plain text field, a URL field, an email field, a CKEditor body, a
variant SKU and an address line all get the same operators without any of them having been listed
anywhere.

| Value shape | Operators |
| --- | --- |
| Text | contains, doesn't contain, starts with, ends with, is, is not, is one of, is none of, is empty, is not empty |
| Number / money | is, is not, greater than, at least, less than, at most, between, empty, not empty |
| Date | is on, is before, is after, is between, is in the last N days/weeks/months, is in the next N, empty, not empty |
| Lightswitch | is on, is off |
| Dropdown / radio | is, is not, is one of, is none of, empty, not empty |
| Checkboxes / multi-select | contains, doesn't contain, empty, not empty |
| Relations | is assigned, is not assigned, empty, not empty, has at least N, has at most N |
| Matrix | empty, not empty, has at least N, has at most N |

An unrecognised field type does not disappear from the menu — it gets the text operators, which work
on whatever is in the content column. To place it properly, use `additionalFieldTypes` in
[Configuration](configuration), or the `EVENT_DEFINE_FIELD_DEFINITION` event.

## Statuses

Filters run with **no status constraint** by default. Craft's own indexes default to *live*, which
is exactly wrong for a tool you reach for when something has gone missing. Drafts, revisions and
trashed elements are each a separate toggle.

## Results

Choose the columns, and sort by any of them. Relations render as links and dates as dates, and the
export writes the same values.

Every result set has a URL that reproduces it. **Link to these results** copies it — paste it into a
ticket.

## Saved filters

Save a filter and give it a name. Keep it private, or share it with everyone who can use Clean Air.

Seeing a shared filter and being able to rewrite it are separate permissions, so the report your
team relies on does not quietly change under them.

Pin a filter to put it in the sidebar.

Lite keeps ten saved filters per person. Pro is unlimited and adds sharing.

## Exports

CSV in both editions; TSV, JSON and XML in Pro. Exports are streamed in batches, so a 250,000-row
export costs about the same memory as a fifty-row one.

Anything over `queueExportThreshold` goes to the queue and waits for you on the **Exports** screen.
Files are pruned after `exportRetentionDays`, because an export is a snapshot of real content and
often of personal data.

## From a template

*Pro only.*

```twig
{% set featured = craft.cleanair.filter('featured-in-stock') %}
{% for product in featured.limit(8).all() %}
    {{ product.title }}
{% endfor %}
```

`craft.cleanair.filter()` takes a saved filter's handle and hands back an element query, so
everything you already know about element queries still applies.

Or put a **Clean Air Filter** field on an entry, let an editor pick the filter, and ask the field for
its elements:

```twig
{% for entry in entry.productList.all(8) %}
```

The filter's conditions can then change without a deploy.

## From the command line

*Pro only.*

```sh
craft cleanair/filters                        # list saved filters
craft cleanair/filters/count stale-drafts     # just the number
craft cleanair/filters/run stale-drafts       # run one and print what it matched
craft cleanair/filters/export stale-drafts --format=csv --path=/tmp/report.csv
craft cleanair/exports/prune                  # housekeeping
```

Which is how a saved filter becomes a scheduled report.

## Importing from CP Filters

*Both editions.*

Clean Air reads CP Filters' `cpfilters_savedfilters` table directly. The old plugin does not need to
be installed, or even installable — which matters, because it cannot be installed on Craft 5.

Go to **Clean Air → Import**. Nothing is written until you say so, and the preview tells you, per
filter, exactly what it will become and what it lost on the way.

```sh
craft cleanair/import          # preview
craft cleanair/import/run      # import
```

The mapping is mostly one-to-one. Three places it has to think:

- **Sources.** CP Filters stored bare IDs. Entry type, volume, group and product type IDs all
  survive the Craft 4 → 5 upgrade, so those land exactly. One that no longer exists is reported, and
  the filter runs across every source instead.
- **Ambiguous operators.** `is greater than` meant one thing on a number and another on a date.
  Clean Air resolves the field first and picks the operator that matches its kind, so a date filter
  becomes `is after` rather than a string comparison that matches nothing.
- **Fields that have gone.** A condition naming a field that no longer exists is reported against the
  filter rather than silently dropped — a filter that quietly loses a condition returns *more* rows
  than it should, which is the dangerous direction.

CP Filters' settings come across too, from project config or from a `config/cpfilters.php` file. Its
`additionalFieldTypes` format is understood as-is.

Your CP Filters data is never modified. Import, compare the two, and uninstall the old plugin when
you are happy.

## Match any

Two parameters on one element query always AND — that is how Craft works, and there is no parameter
syntax for OR across different fields. So a `match any` filter runs each condition as its own query
and unions the IDs. It is correct, it works with every field type including relations, and it costs
one query per condition.

There is a configurable ceiling (`anyMatchIdLimit`, 50,000 by default); results past it are
truncated and the filter says so rather than pretending. That is why OR is a deliberate mode rather
than the default.

## Extending it

```php
use justinholtweb\cleanair\services\Types;
use justinholtweb\cleanair\events\RegisterTypesEvent;

Event::on(Types::class, Types::EVENT_REGISTER_TYPES, function(RegisterTypesEvent $event) {
    $event->types['widgets'] = new MyWidgetType();
});
```

`Types::EVENT_REGISTER_TYPES` adds or replaces an element type driver.
`Fields::EVENT_DEFINE_FIELD_DEFINITION` fires per custom field, to correct the value shape Clean Air
inferred or to hide a field from the builder entirely.
