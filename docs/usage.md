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

**Groups** (Pro) put conditions together under their own `all`/`any`. See
[Condition groups](#condition-groups).

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

To have Clean Air email a saved filter on a schedule rather than writing cron and mail plumbing
yourself, see [Scheduled reports](#scheduled-reports).

```sh
craft cleanair/reports/send                               # send every report that's due (cron)
craft cleanair/reports/send --report=stale-drafts --force # send one now, whatever the schedule
craft cleanair/reports/status                             # last run, last sent, next due
```

`cleanair/reports/send` exits 0 for every outcome that isn't a fault — not due, already sent,
nothing to send — so cron stays quiet. It exits 78 when a report has nobody it may go to, or its
owner can no longer run it, and 1 when a send failed (the next run tries again).

## Scheduled reports

*Pro only.*

On **Saved filters**, press **Email…** beside a filter you can edit. You need the *Email saved
filters on a schedule* permission as well as *Save filters* and *Export results*.

| Setting | |
| --- | --- |
| How often | Daily, weekly (on a weekday) or monthly (on a day, 1–28) |
| At | An hour, in the system time zone |
| Send to | Control panel users |
| Format | Any export format your edition has |
| Send it | Only when the filter matches something; only when it matches something the last report didn't; or every time |
| Delivery | Attach the file, or put a copy on each person's Exports screen and link to it |

**Who it runs as.** A report runs with the permissions of whoever last saved its schedule. Saving
it is saying "run this as me". If that person loses access to the filter's element type or source,
or to Clean Air, the report stops and its status says why; someone with access has to save it again.

**Who it goes to.** Control panel users who could run the filter and export it themselves — the
schedule screen refuses anyone else, with the reason, and each recipient is checked again every
time it runs. A report never tells anyone something they couldn't find out in Clean Air.

**Personal data.** Users, addresses and orders — and element types Clean Air knows nothing about —
are never attached to an email and never listed in one. Each recipient gets their own copy on their
**Exports** screen, behind a login. Other types are attached up to `reportAttachmentLimit`
([Configuration](configuration#scheduled-reports)) and linked above it. Copies are pruned with every
other export.

**What's new.** Each report remembers which elements it sent last time, so the email can say how
many are new and list the new ones first, and "only when it matches something the last report
didn't" means exactly that. Past 50,000 results it stops comparing, and says so.

**When it goes.** Once per period — a day, an ISO week or a month — at the first run of
`cleanair/reports/send` on or after the hour. A run that is missed still sends later in the same
period. A schedule saved after this period's hour waits for the next period, so saving "weekly on
Monday" on a Wednesday doesn't send straight away. If a send fails, the period is handed back and
the next run tries again.

**Without cron**, the end of a web request queues any report that's due, at most every five minutes
(`reportWebTrigger`). Cron is more punctual on a quiet site.

**Send me a test now** sends what the report would say to you alone, run with your permissions and
marked as a test. It doesn't count as this period's report and doesn't change what the next one
calls new. One test every 30 seconds.

**Changing it in code.** `Reports::EVENT_BEFORE_SEND` fires before every report, scheduled or test,
with the report, the saved filter, the recipients, the subject and the template variables. Change
any of them — anyone added is still checked — or set `$event->isValid = false` to stop it. A
cancelled scheduled report keeps its marker, so the next run asks again, and nothing has been
exported yet when the event fires.

```php
use justinholtweb\cleanair\events\ReportEvent;
use justinholtweb\cleanair\services\Reports;

Event::on(Reports::class, Reports::EVENT_BEFORE_SEND, function(ReportEvent $event) {
    $event->subject = '[Weekly] ' . $event->subject;
});
```

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

Carrying over CP Filters' own settings changes Clean Air's settings, which live in project config,
so that option is only offered to admins, and only where `allowAdminChanges` is on. Anyone else with
the import permission can still import the filters themselves.

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

## Condition groups

*Pro only.*

**Add a group** puts a box in the filter with its own `all of these` / `any of these`. Groups go one
level deep: a group holds conditions, not other groups. *Section is Products* AND (*Image is empty*
OR *Price is empty*) is one filter:

- match **all conditions**
  - Section is Products
  - group, **any of these**: Image is empty · Price is empty

It works the other way round too — `match any` at the top, with an `all` group as one of the
alternatives.

An `all` group inside an `all` filter is the same as writing its conditions at the top. An `any`
group is evaluated like `match any` — one query per condition, IDs unioned — and intersected with
everything else, so `anyMatchIdLimit` applies per group. When a group hits it, the warning names the
group.

A filter with groups that is opened on Lite (after a downgrade, say) matches nothing and says why,
rather than quietly ignoring the groups and returning more than it should.

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
