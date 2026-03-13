# Link Migrator

Link Migrator is a CLI-first Craft CMS plugin for migrating Verbb Hyper fields to Craft's native Link field.

This plugin is independent and unaffiliated. Verbb Hyper is a plugin by Verbb.

## What This Plugin Does

- Audits Hyper fields before anything is changed
- Converts supported Hyper field settings into native Craft Link field settings
- Migrates existing element content in a separate step
- Writes JSON and log reports for every run
- Optionally writes per-element backup payloads before content changes
- Tracks migration state so content migration can resume safely
- Scans your templates and modules for common Hyper-to-Link API mismatches

## Requirements

- PHP 8.2+
- Craft CMS 5.3+
- Verbb Hyper must remain installed until field migration and content migration are both complete
- Recommended: Craft 5.6+ if you want the fuller native Link advanced field set

## Installation

Once published, you will be able to install Link Migrator from Craft's in-app Plugin Store or via Composer.

Install from Composer:

```bash
composer require lm2k/craft-link-migrator
php craft plugin/install link-migrator
```

## Recommended Workflow

For most projects, use the orchestration command:

```bash
php craft link-migrator/migrate/all --dry-run=1 --create-backup=1
php craft link-migrator/migrate/all --force=1 --create-backup=1 --batch-size=100
```

`migrate/all` runs:

1. `audit`
2. `fields`
3. `content`

Notes:

- In dry-run mode, no changes are written.
- In write mode, the command refuses to run unless `--force=1` is provided.
- Field migration still writes project config files that you can review and apply separately if your workflow requires it.
- `--apply-project-config=0` only suppresses the reminder message; `migrate/all` no longer tries to run `project-config/apply` inline because that conflicts with Craft's config lock during the same process.
- `--batch-size=100` means the content migration processes 100 elements at a time.

## Manual Workflow

If you want to inspect every stage yourself, run:

```bash
php craft link-migrator/migrate/audit --dry-run=1
php craft link-migrator/migrate/fields --dry-run=1
php craft link-migrator/migrate/fields --force=1
php craft project-config/apply
php craft link-migrator/migrate/content --dry-run=1 --create-backup=1
php craft link-migrator/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/rollback-info
```

A single-field run is also supported:

```bash
php craft link-migrator/migrate/fields --field=ctaLink --dry-run=1
php craft link-migrator/migrate/content --field=ctaLink --force=1 --create-backup=1
```

## Commands

### `link-migrator/migrate/all`

Runs audit, field migration, and content migration in sequence.

Common options:

- `--dry-run=1`
- `--force=1`
- `--field=handle`
- `--create-backup=1`
- `--batch-size=100`
- `--apply-project-config=0`
- `--verbose=1`

### `link-migrator/migrate/audit`

Builds an audit of Hyper fields, supported mappings, unsupported cases, code references, and mismatch candidates.

Useful when:

- you want to know which Hyper fields are migratable
- you want to see unsupported link types before changing anything
- you want a machine-readable report of the current state

### `link-migrator/migrate/fields`

Migrates supported Hyper field definitions to Craft Link field definitions.

Important:

- non-dry runs require `--force=1`
- unsupported fields are skipped
- this changes field configuration, not content

### `link-migrator/migrate/content`

Migrates existing content values into `craft\fields\data\LinkData`.

Important:

- non-dry runs require `--force=1`
- content writes are resumable
- already migrated element/site pairs are skipped on later runs
- optional backups are written before content is changed
- if you want to run `php craft project-config/apply`, do it as a separate command after the migration run

### `link-migrator/migrate/mismatches`

Scans templates, modules, `src`, and config for common Hyper-only API usage that usually breaks after migration.

Examples it flags:

- `.text`
- `.linkText`
- `linkValue`
- `getLink()`
- `getElement()`
- `hasElement()`
- `getHtml()`
- `getData()`
- Hyper class-name type checks such as `verbb\hyper\links\Entry`

This command exits non-zero if mismatches are found, which makes it useful in CI or migration checklists.

### `link-migrator/migrate/rollback-info`

Shows informational summaries from the plugin's migration state table:

- migrated counts
- skipped counts
- warning counts
- backup counts
- last update time

It does not automatically roll anything back.

## Supported Mappings

Fully supported link types:

- URL -> URL
- Entry -> Entry
- Asset -> Asset
- Category -> Category
- Email -> Email
- Phone -> Phone
- SMS -> SMS

Migrated advanced attributes:

- label/text
- target/new tab
- URL suffix
- title
- class
- id
- rel

Field configuration defaults:

- the native Link field label field is enabled by default

Partially supported or lossy cases:

- custom field layouts on Hyper link types are not migrated
- Hyper fields with broad link-type allowances should be checked after migration
- custom link field data is preserved in backups, not converted into native Link data
- custom or unsupported Hyper link types are downgraded to native URL links when a scalar URL-like value is available

Unsupported cases:

- Hyper fields allowing multiple links
- embed-only data
- user/site/plugin-specific link types without a native Link equivalent

Unsupported values are skipped and reported. They are not silently coerced.

## What Gets Persisted

### Reports

Every run writes:

- a JSON report
- a log report

Stored in:

```text
storage/runtime/link-migrator/
```

### Optional backups

When `--create-backup=1` is used during content migration, per-element backup payloads are written to:

```text
storage/runtime/link-migrator/backups/
```

### Migration state

The plugin stores per-element migration state in:

```text
{{%linkmigrator_migrations}}
```

This is what allows content migration to skip already migrated element/site pairs and resume safely after interruptions.

## Template and API Differences You Must Review

Hyper and Craft's native Link field are not API-identical, even when the content migration succeeds.

Common breakpoints:

- Hyper `.text` or `.linkText` usually becomes LinkData `.label`
- Hyper `linkValue` becomes LinkData `value` or `url`, depending on what your template really needs
- Hyper `getElement()` and `hasElement()` become checks against `.element`
- Hyper class-name type checks become short Craft type handles like `entry`, `asset`, or `url`
- Hyper-only helpers like `getHtml()` and `getData()` do not exist on native Link values
- Hyper GraphQL output shape differs from Craft Link GraphQL output

Read [docs/TEMPLATE-IMPACT.md](docs/TEMPLATE-IMPACT.md) before running the content migration in production.

## Safety Notes

- Back up the database and project config before any non-dry run
- Keep Hyper installed until reports are clean and templates are updated
- Run content migration separately in each environment because content is environment-specific
- Treat `migrate/mismatches` as a guide, not a proof that every template issue has been found
- `rollback-info` is informational only; it is not an automatic restore command

## Typical Example

Dry run everything first:

```bash
php craft link-migrator/migrate/mismatches
php craft link-migrator/migrate/all --dry-run=1 --create-backup=1
```

Then perform the real migration:

```bash
php craft link-migrator/migrate/all --force=1 --create-backup=1 --batch-size=100
php craft link-migrator/migrate/rollback-info
```

## Support

Report bugs and migration edge cases here:

- https://github.com/LuukRM2000/hyper-to-native-migration/issues
