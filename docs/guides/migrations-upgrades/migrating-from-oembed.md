# Migrating from oEmbed
Migrate [wrav/oembed](https://plugins.craftcms.com/oembed) fields to Hyper’s **Embed** link type.

## Control Panel
1. Backup your database and project config.
2. With `allowAdminChanges` enabled, open **Hyper → Settings → Migrations → oEmbed**.
3. Run **Migrate Fields**, deploy project config, then run **Migrate Content** on each environment.

## Console

```bash
php craft hyper/migrate/oembed --step=field
php craft hyper/migrate/oembed --step=content
php craft hyper/migrate/oembed --step=content --dry-run=1
```

## What gets mapped

| oEmbed | Hyper |
| --- | --- |
| Field type | Hyper field with Embed enabled (other types disabled) |
| Stored URL / `{ url: … }` | Embed `linkValue` (metadata fetched when possible) |

## Embedded Assets
[Spicy Web Embedded Assets](https://plugins.craftcms.com/embeddedassets) does not ship a separate field type — embeds live as Craft **Assets**. Prefer Hyper’s **Asset** link type for those volumes, or re-author as **Embed** URLs. There is no automatic Assets→Embed field swap (mixed image libraries are common).
