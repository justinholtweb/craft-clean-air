---
title: FAQ
slug: faq
order: 50
summary: Editions, CP Filters, permissions, performance, and what Clean Air does not do.
---

## Is Clean Air free?

Lite is, and it is not a trial. Entries, assets, categories, tags and users, every operator, `match
all`, ten saved filters per person, CSV export, and the CP Filters importer.

Pro is **$99** with a **$79/year** renewal. It adds addresses, Commerce and custom element types,
`match any`, unlimited and shared filters, the column chooser, TSV/JSON/XML export, queued exports,
console commands, `craft.cleanair` in templates, and the Clean Air Filter field.

## Why is the importer in the free edition?

Because a migration path should not be behind a paywall. If CP Filters left you with a shelf of
saved filters you cannot read, getting them back should not cost anything.

## Do I need CP Filters installed to import from it?

No — and you could not if you wanted to, because CP Filters cannot be installed on Craft 5. Clean Air
reads its `cpfilters_savedfilters` table directly.

## Will importing change my CP Filters data?

No. Nothing is written to CP Filters' table, ever. Import, compare the two, and uninstall the old
plugin when you are happy.

## What happens to conditions Clean Air can't carry across?

They are named on the import preview, against the filter they came from, before anything is written.
A filter that quietly loses a condition returns *more* rows than it should, which is the dangerous
direction — so nothing is dropped silently.

## Can Clean Air show somebody content they shouldn't see?

No. Every query goes through Craft, so a user who cannot see a section cannot see its entries here
either, whatever Clean Air's own permissions say. Sources the user has no permission for are removed
from the menus, and a source hand-edited into a URL is dropped rather than honoured.

Clean Air's permissions can only narrow access, never widen it.

## Does it work with my third-party field type?

Probably, without being told anything. Clean Air infers the shape of a field's *value* rather than
matching on class, so most fields land in the right place. One that does not falls back to the text
operators, and can be placed properly with `additionalFieldTypes` or the
`EVENT_DEFINE_FIELD_DEFINITION` event.

## Does it work with Matrix?

Yes. Nested entry types appear as sources in their own right, and a Matrix field on the parent gets
`empty`, `not empty`, `has at least N` and `has at most N`.

## Why is "match any" a separate mode instead of the default?

Two parameters on one element query always AND — that is how Craft works, and there is no parameter
syntax for OR across different fields. So `match any` runs each condition as its own query and unions
the IDs: correct, works with every field type, and costs one query per condition. It has a
configurable ceiling, past which it truncates and says so.

It is a real cost, so it is a deliberate choice rather than something you get by accident.

## How large a result set can it export?

Exports are streamed in batches, so a 250,000-row export costs about the same memory as a fifty-row
one. Anything above `queueExportThreshold` goes to the queue. `maxExportRows` sets a hard cap if you
want one.

## Can I run a saved filter on a schedule?

Yes, on Pro:

```sh
craft cleanair/filters/export stale-drafts --format=csv --path=/tmp/report.csv
```

Put that in cron and a saved filter is a scheduled report.

## Does Clean Air edit content?

No. It is a read tool. It finds elements and hands you the list, a URL, or a file — the editing
happens in Craft, through the Edit links in the results table.

## Does it replace Craft's element indexes?

No, and it is not trying to. Craft's indexes are good at showing you a section. Clean Air is for the
questions they cannot answer — which entries are missing something, which assets nobody has given
alt text, which users have never signed in.
