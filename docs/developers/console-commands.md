# Console Commands

Hyper includes console commands you can run from your Craft project’s directory.

## Migrations

These commands convert fields and content from other link plugins into Hyper fields. Run the `field` step before the `content` step, or leave out `--step` to run both in order. Each source’s User Guide walks through a complete conversion, and [Events](/developers/events#migration-events) explains how to map a custom source link type.

::: reference
### `hyper/migrate/typed-link`

Converts Typed Link fields and their content to Hyper. Follow [the Typed Link guide](/guides/migrations-upgrades/migrating-from-typed-link) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--step` | The step to run, either `field`, `content` or `all` for the full sequence. Typed Link also has a `legacy` step that updates fields from older Typed Link versions before the `field` step. | `all` |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/typed-link --step=field
php craft hyper/migrate/typed-link --step=content --dry-run=1
php craft hyper/migrate/typed-link
```
:::

::: reference
### `hyper/migrate/linkit`

Converts Linkit fields and their content to Hyper. Follow [the Linkit guide](/guides/migrations-upgrades/migrating-from-linkit) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--step` | The step to run, either `field`, `content` or `all` for the full sequence. | `all` |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/linkit --step=field
php craft hyper/migrate/linkit --step=content --dry-run=1
php craft hyper/migrate/linkit
```
:::

::: reference
### `hyper/migrate/link`

Converts Flipbox Link fields and their content to Hyper. Follow [the Flipbox Link guide](/guides/migrations-upgrades/migrating-from-link) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--step` | The step to run, either `field`, `content` or `all` for the full sequence. | `all` |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/link --step=field
php craft hyper/migrate/link --step=content --dry-run=1
php craft hyper/migrate/link
```
:::

::: reference
### `hyper/migrate/craft-link`

Converts Craft’s own Link field fields and their content to Hyper. Follow [the Craft’s own Link field guide](/guides/migrations-upgrades/migrating-from-craft-link) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--step` | The step to run, either `field`, `content` or `all` for the full sequence. | `all` |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/craft-link --step=field
php craft hyper/migrate/craft-link --step=content --dry-run=1
php craft hyper/migrate/craft-link
```
:::

::: reference
### `hyper/migrate/oembed`

Converts oEmbed fields and their content to Hyper. Follow [the oEmbed guide](/guides/migrations-upgrades/migrating-from-oembed) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--step` | The step to run, either `field`, `content` or `all` for the full sequence. | `all` |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/oembed --step=field
php craft hyper/migrate/oembed --step=content --dry-run=1
php craft hyper/migrate/oembed
```
:::

::: reference
### `hyper/migrate/entrify-categories`

Updates Category links to Entry links with the same element IDs, for sites that have converted their categories to entries with Craft’s entrification. Follow [the entrification guide](/guides/migrations-upgrades/converting-category-links-to-entry-links) for the full process.

| Option | Description | Default |
| --- | --- | --- |
| `--dry-run` | Set to `1` to preview the conversion without saving it. | `0` |
| `--sync-relations` | Set to `0` to skip rebuilding Hyper’s relation rows after converting content. | `1` |
| `--create-backup` | Set to `1` to back up the database before writing, or `0` to skip the backup. | The `backupOnMigrate` setting |

```shell
php craft hyper/migrate/entrify-categories --dry-run=1
php craft hyper/migrate/entrify-categories
```
:::

## Content

These commands work on content already stored in a Hyper field. Both are dry runs unless you pass `--dry-run=0`.

::: reference
### `hyper/content/modify`

Runs every instance of a Hyper field through Hyper’s content API without changing its links, and reports how many owners matched and how many would be re-saved. With `--dry-run=0`, Hyper re-saves the stored link data in its current format and rebuilds the relation rows for owners that changed.

| Option | Description | Default |
| --- | --- | --- |
| `--field` | The handle of the Hyper field to process. Required. | |
| `--dry-run` | Set to `0` to write changes. By default the command only reports what it would change. | `1` |
| `--include-nested` | Set to `0` to skip instances of the field inside Matrix, Neo, Super Table and Vizy fields. | `1` |
| `--element-ids` | A comma-separated list of owner element IDs to limit the command to. | All owners |
| `--content-contains` | Only process owners whose stored link data contains this text. | |

```shell
php craft hyper/content/modify --field=navLinks
php craft hyper/content/modify --field=navLinks --element-ids=12,48 --dry-run=0
```
:::

::: reference
### `hyper/content/sync-relations`

Rebuilds Hyper’s relation rows for a field from the link data saved on each owner, without changing that data. Run it when reverse relations or eager-loaded links don’t match the saved links.

| Option | Description | Default |
| --- | --- | --- |
| `--field` | The handle of the Hyper field to process. Required. | |
| `--dry-run` | Set to `0` to write changes. By default the command only reports what it would change. | `1` |
| `--include-nested` | Set to `0` to skip instances of the field inside Matrix, Neo, Super Table and Vizy fields. | `1` |
| `--element-ids` | A comma-separated list of owner element IDs to limit the command to. | All owners |
| `--content-contains` | Only process owners whose stored link data contains this text. | |

```shell
php craft hyper/content/sync-relations --field=navLinks
php craft hyper/content/sync-relations --field=navLinks --dry-run=0
```
:::

## Typed Link Legacy Fields

::: reference
### `hyper/typed-link-legacy`

Updates the settings of fields from older Typed Link versions to the current Typed Link format. `hyper/migrate/typed-link` runs this as its `legacy` step, so you only need it on its own when updating those fields separately.

```shell
php craft hyper/typed-link-legacy
```
:::
