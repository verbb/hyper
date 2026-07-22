# Migrating from Craft Link
Craft 5.3+ ships a native [Link](https://craftcms.com/docs/5.x/reference/field-types/link.html) field. Hyper can convert those fields and their content.

## Control Panel
1. Backup your database and project config.
2. In an environment with `allowAdminChanges` enabled, open **Hyper → Settings → Migrations → Craft Link**.
3. Run **Migrate Fields**, then deploy the resulting project config.
4. On each environment, run **Migrate Content** (re-runnable). Content migration syncs `hyper_links` relations by default.

## Console

```bash
php craft hyper/migrate/craft-link --step=field
php craft hyper/migrate/craft-link --step=content

# Preview content conversion without writing
php craft hyper/migrate/craft-link --step=content --dry-run=1
```

## What gets mapped

| Craft type | Hyper type |
| --- | --- |
| Entry / Asset / Category | Same |
| URL | URL |
| Email | Email |
| Phone / Tel | Phone |
| SMS | URL (`sms:` values preserved) |
| Product (when present) | Product |

Label, URL suffix, title, classes, aria-label, and `_blank` target map onto Hyper’s native link fields when those advanced options were enabled on the Craft field.

## Notes

- Element link values stored as `{craft\elements\Entry:123@1}` are parsed into Hyper element IDs + site.
- Unknown Craft link types can be remapped with `PluginMigration::EVENT_MODIFY_LINK_TYPE` (same event as Linkit / Typed Link).
- Source Craft Link fields are converted in place to Hyper fields (type + settings). Content JSON is rewritten on the content step.
