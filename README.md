# Hyper to Link

Console-first Craft CMS plugin for migrating Verbb Hyper fields to Craft's native Link field in two explicit steps:

1. field/config migration
2. content migration

The plugin is intentionally boring:

- no CP UI
- no abstract migration platform
- no automatic Hyper uninstall
- strict unsupported-case reporting
- dry runs, `--force`, backups, resumability, and written reports

## Requirements

- PHP 8.2+
- Craft CMS 5.3+
- Verbb Hyper must stay installed during the migration
- Recommended: Craft 5.6+ if you want all native Link advanced fields available

## Install

Add the plugin as a path repository or package, then install it:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "plugins/hyper-to-link"
    }
  ],
  "require": {
    "lm2k/craft-hyper-to-link": "*"
  }
}
```

Then run:

```bash
composer install
php craft plugin/install hyper-to-link
```

## Commands

```bash
php craft hyper-to-link/migrate/audit --dry-run=1
php craft hyper-to-link/migrate/fields --dry-run=1
php craft hyper-to-link/migrate/fields --force=1
php craft project-config/apply
php craft hyper-to-link/migrate/content --dry-run=1 --create-backup=1
php craft hyper-to-link/migrate/content --force=1 --create-backup=1 --batch-size=100
php craft hyper-to-link/migrate/rollback-info
```

Single field:

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
- custom Hyper link types from plugins/custom code
- custom field layouts on Hyper link types
- embed-only data
- user/site/plugin-specific link types without a native Link equivalent

Unsupported values are skipped and reported. They are not silently coerced.

## Reports and Backups

Each run writes:

- a JSON report
- a log report
- optional per-element backup JSON payloads when `--create-backup=1`

Location:

```text
storage/runtime/hyper-to-link/
storage/runtime/hyper-to-link/backups/
```

The plugin also records per-element migration state in `{{%hypertolink_migrations}}` so content migration can be resumed safely.

## Template Impact

See [docs/TEMPLATE-IMPACT.md](docs/TEMPLATE-IMPACT.md).
