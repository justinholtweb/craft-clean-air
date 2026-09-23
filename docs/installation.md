---
title: Installation
slug: installation
order: 10
summary: Requirements, install, editions, and running your first filter.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Craft Commerce is optional. If it is installed, orders, products and variants appear alongside
everything else.

## Install

```sh
composer require justinholtweb/craft-cleanair
php craft plugin/install cleanair
```

Clean Air adds a **Clean Air** item to the control panel navigation, with **Saved filters**,
**Exports** and **Import** beneath it.

## Editions

Clean Air has two editions. Lite is free and is not a trial.

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
| Import from CP Filters | ● | ● |

Pro is **$99**, with a **$79/year** renewal. The importer is in both editions — a migration path
should not be behind a paywall.

Switch editions in **Settings → Plugins → Clean Air**.

## Your first filter

1. Go to **Clean Air**.
2. Pick an element type from the tabs along the top. Entries is selected by default.
3. Pick a **Source** — a section, a volume, a group, a product type. Leave it on *All sources* to
   search everything you have permission to see.
4. Press **Add a condition**, choose a field, and choose an operator. The operators offered are the
   ones that make sense for that field's value.
5. Press **Run filter**.

The count beside the button updates before you run anything, so you can tell whether a condition is
doing what you expected without waiting for a full result set.

## Permissions

Clean Air installs with its own permissions, all off by default except for admins.

| Permission | What it allows |
| --- | --- |
| Use Clean Air | Access to the tool |
| Filter *(type)* | One nested permission per element type |
| Export results | Writing and downloading exports |
| Save filters | Keeping filters |
| — Share filters with everyone | Making a filter visible to others |
| — Edit and delete other people's shared filters | Managing shared filters |
| Import filters from CP Filters | Running the importer |

Clean Air never widens anybody's access. Every query goes through Craft, so a user who cannot see a
section cannot see its entries here either, whatever these permissions say.

## Coming from CP Filters

If the site previously ran CP Filters, its saved filters are still in the database and Clean Air can
read them. CP Filters does not need to be installed — it cannot be, on Craft 5.

Go to **Clean Air → Import**. See [Usage](usage) for what the importer does and does not carry
across.
