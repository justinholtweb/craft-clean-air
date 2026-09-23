---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Missing fields and sources, results that look wrong, slow OR filters, and exports that never arrive.
---

## A field isn't in the list

The field list is built from the field layouts the **chosen source** actually uses. If you are
filtering *All sources*, you get the attributes every element of that type has, plus the custom
fields shared across them — pick a specific section or volume and the list gets longer, not shorter.

If the field is in the layout and still missing, it has been hidden deliberately through the
`Fields::EVENT_DEFINE_FIELD_DEFINITION` event. Check your own modules.

## A field is there but has the wrong operators

Clean Air infers the shape of a field's value. A third-party field type it does not recognise falls
back to the text operators, which work on whatever is in the content column but will not offer, say,
`is between` on something that is really a number.

Name it in `additionalFieldTypes`, or correct it from the event. See
[Configuration](configuration).

## A source isn't in the list

Three things remove a source:

1. **`filterableSources`** restricts that element type to a named list.
2. **Permissions.** Clean Air never widens access. A source you cannot see in Craft is not offered
   here.
3. **`showEmptySources`** set to `false` hides sources with nothing in them.

A source hand-edited into a URL is dropped rather than honoured, so an edited address falls back to
*All sources* instead of reaching past your permissions.

## The result count looks too high

Check the **Match** setting first. `any condition` ORs, and an OR of three conditions will usually
return more than any one of them.

Then check the status toggles. Clean Air runs with **no status constraint** by default, so disabled,
pending and expired elements are included unless you say otherwise. Craft's own indexes default to
*live*, which is why the same question can give two different numbers in two places.

## The result count looks too low

A condition on a field that no longer resolves is **reported**, not dropped — if you imported from
CP Filters, read the notes on the import screen. Everything else being equal, a filter returning
fewer rows than you expect usually means an extra condition is ANDed in that you had forgotten
about, or a source is narrower than you thought.

## A "match any" filter is slow, or says it truncated results

Craft has no parameter syntax for OR across different fields, so `match any` runs one query per
condition and unions the IDs. Three conditions is three queries.

Past `anyMatchIdLimit` (50,000 by default) the ID set is truncated and the filter says so. Raise it
if you have the memory, or narrow the source. Turning a filter back into `all conditions` is one
query again.

## An export never arrives

Counts above `queueExportThreshold` are handed to the queue. If the queue is not running, the export
sits at **Queued** forever. Check **Utilities → Queue Manager**, and check that your queue runner is
actually running — `queue/listen` or a cron entry.

Queued exports are Pro. On Lite, exports always run inline, which means a very large one can hit
PHP's execution limit instead. Lower `maxExportRows`, or narrow the filter.

## An export downloaded but the accents are wrong

That is Excel, not Clean Air. `csvBom` writes a UTF-8 byte order mark, which is what makes Excel
read the encoding correctly; it is on by default. If something downstream chokes on the BOM instead,
turn it off.

## The import screen says there's nothing to import

Clean Air looks for a table called `cpfilters_savedfilters`. If CP Filters was uninstalled properly,
its migration dropped that table and the filters are gone — restore a database backup from before
the uninstall.

Filters CP Filters had soft-deleted are hidden by default. Tick **Include filters deleted in CP
Filters** to see them.

## Logs

Clean Air logs under the `cleanair` category, at `logLevel` (`warning` by default). Set it to `debug`
in `config/cleanair.php` to see the queries a filter produces.

A broken field will not take the results table down with it — values are read defensively, because a
results table is exactly where a half-migrated field surfaces. If a column is empty where you expect
a value, the log is where the reason is.
