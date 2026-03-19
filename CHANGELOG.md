# Changelog

## 1.0.1 - 2026-03-12

### Changed

- Persist migrated field metadata into Craft project config so content migration can recover migrated Link fields without relying on runtime reports.
- Wrap content writes and migration-state writes in a shared database transaction.
- Tighten unsupported Hyper type fallback so only URL-like values are downgraded to native URL links.
- Preserve explicit target strings instead of coercing every truthy target to `_blank`.
- Reconcile legacy `hypertolink` migration state tables with the current `linkmigrator` table name during install/upgrade.

## 1.0.0 - 2026-03-11

### Added

- Initial public release of Link Migrator for Craft CMS 5.
- CLI audit, field migration, content migration, and rollback-info commands.
- Dry-run support, resumable migration state tracking, JSON/log reports, and optional per-element backups.
- Template migration guidance for projects moving from Verbb Hyper to native Link fields.
