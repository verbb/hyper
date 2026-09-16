# Migration Tools

Use the console options below when running a source conversion. [Events](/developers/events) demonstrates a complete type-mapping listener for a bootstrapped module.

## Console Options

Run commands from the Craft project directory. For example, `php craft hyper/migrate/typed-link --step=content --dry-run=1` previews the Typed Link content step. The source-specific User Guides explain the required stages.

| Option | Behaviour |
| --- | --- |
| `--step` | Select a source’s `field` or `content` step, or `all` for its full sequence. Defaults to `all`. Typed Link also exposes a `legacy` step. Entrification has its own action without a field/content sequence. |
| `--dry-run=1` | Preview supported content transformations without saving them. This is not a dry run of field-definition changes; select `--step=content` for a content preview. |
| `--sync-relations=1` | Synchronise Hyper’s relation index after content conversion. Enabled by default; `0` disables it. |
| `--create-backup=1` | Create a database backup before a write operation. Defaults to the `backupOnMigrate` setting; `0` disables it. |

Source action names are `typed-link`, `linkit`, `link`, `craft-link` and `oembed`. The category-to-entry action is `entrify-categories`.

## Type Mapping

`EVENT_MODIFY_LINK_TYPE` supplies a `verbb\hyper\events\ModifyMigrationLinkEvent`. Read `oldClass` to identify the source type and set `newClass` to the destination Hyper class. Source identifiers may be strings such as `entry` rather than PHP class names.

| Source | Field Migration | Content Migration |
| --- | --- | --- |
| Typed Link | `MigrateTypedLinkField` | `MigrateTypedLinkContent` |
| Linkit | `MigrateLinkitField` | `MigrateLinkitContent` |
| Craft Link | `MigrateCraftLinkField` | `MigrateCraftLinkContent` |

These classes are in the `verbb\hyper\migrations` namespace. Register on both field and content classes when both stages need the mapping. For Typed Link’s legacy field step, register on `MigrateTypedLinkFieldLegacy` where the source requires it.

This event selects the target type. It does not provide a general transformation of every field in the source payload. Use the [Content API](/developers/managing-embedded-content) when you need an explicit raw-value conversion.
