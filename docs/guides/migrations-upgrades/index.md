# Migrations & Upgrades

Moving to Hyper from other link plugins.

Hyper’s migration shell matches Navigation / Formie: a **source registry**, structured log lines, CP pages under **Settings → Migrations**, and console commands of the form `php craft hyper/migrate/{source}`.

Field migration (project config) and content migration (per-environment JSON) remain separate steps.

##### [Migrating from Craft Link](/guides/migrations-upgrades/migrating-from-craft-link)

Migrate Craft 5.3+ native Link fields and content to Hyper.

##### [Migrating from oEmbed](/guides/migrations-upgrades/migrating-from-oembed)

Migrate wrav/oembed fields to Hyper Embed links.

##### [Entrification](/guides/migrations-upgrades/entrification)

Remap Hyper Category links to Entry links after Craft entrification.

##### [Migrating from Link](/guides/migrations-upgrades/migrating-from-link)

Migrate link fields and content from Flipbox Link to Hyper.

##### [Migrating from Linkit](/guides/migrations-upgrades/migrating-from-linkit)

Migrate link fields and content from Linkit to Hyper.

##### [Migrating from Typed Link](/guides/migrations-upgrades/migrating-from-typed-link)

Migrate link fields and content from Typed Link to Hyper.

## Console

```bash
# Preferred — source + step
php craft hyper/migrate/craft-link --step=field
php craft hyper/migrate/craft-link --step=content
php craft hyper/migrate/typed-link --step=all
php craft hyper/migrate/oembed --step=content --dry-run=1
php craft hyper/migrate/entrify-categories
php craft hyper/migrate/entrify-categories --dry-run=1

# Options
# --create-backup=1|0
# --step=legacy|field|content|all
# --dry-run=1          (content steps; skips DB writes)
# --sync-relations=1|0 (default 1; writes hyper_links after content migrate)
```

Legacy commands such as `hyper/migrate/typed-link-field` still work.
