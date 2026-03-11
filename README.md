# Hyper to Link

Hyper to Link is a Craft CMS plugin for migrating Verbb Hyper fields to Craft's native Link field with an explicit, reviewable CLI workflow.

It is designed for teams that want to move off Hyper without a black-box migration step. You can audit current usage, migrate field configuration, migrate stored content, and keep written reports for every run.

## Requirements

- PHP 8.2+
- Craft CMS 5.3+
- Verbb Hyper must remain installed until both migration phases are complete
- Recommended: Craft 5.6+ if you want the full native Link field feature set

## Installation

Install the plugin with Composer:

```bash
composer require lm2k/craft-hyper-to-link
php craft plugin/install hyper-to-link
```

If you use DDEV:

```bash
ddev composer require lm2k/craft-hyper-to-link
ddev craft plugin/install hyper-to-link
```

## What It Does

- Audits Hyper field usage before any destructive changes are made
- Migrates Hyper field definitions to native Link field definitions
- Migrates stored element content in a separate step
- Supports dry runs, `--force`, backups, resumability, and written reports
- Leaves unsupported cases visible in reports instead of silently coercing data

## Quick Start

Run the migration in this order:

```bash
php craft hyper-to-link/migrate/audit --dry-run=1
php craft hyper-to-link/migrate/fields --dry-run=1
php craft hyper-to-link/migrate/fields --force=1
php craft project-config/apply
php craft hyper-to-link/migrate/content --dry-run=1 --create-backup=1
php craft hyper-to-link/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft hyper-to-link/migrate/rollback-info
```

Single-field migrations are also supported:

```bash
php craft hyper-to-link/migrate/fields --field=ctaLink --dry-run=1
php craft hyper-to-link/migrate/content --field=ctaLink --force=1 --create-backup=1
```

## Supported Mapping

Fully supported:

- URL -> URL
- Entry -> Entry
- Asset -> Asset
- Category -> Category
- Email -> Email
- Phone -> Phone
- SMS -> SMS

Partially supported:

- label/text
- target/new tab
- URL suffix
- title
- class
- id
- rel

Unsupported or lossy:

- Hyper fields allowing multiple links
- custom Hyper link types from plugins or custom code
- custom field layouts on Hyper link types
- embed-only data
- user, site, or plugin-specific link types without a native Link equivalent

Unsupported values are skipped and reported. They are not silently coerced.

## Reports and Backups

Each run writes:

- a JSON report
- a log report
- optional per-element backup JSON payloads when `--create-backup=1`

Output is written to:

```text
storage/runtime/hyper-to-link/
storage/runtime/hyper-to-link/backups/
```

The plugin also records per-element migration state in `{{%hypertolink_migrations}}` so content migration can be resumed safely after interruptions.

## Template Impact

See [docs/TEMPLATE-IMPACT.md](docs/TEMPLATE-IMPACT.md) for template changes to make after moving from Hyper values to native Link values.

## Support

Report bugs or migration edge cases here:

- https://github.com/LuukRM2000/hyper-to-native-migration/issues
