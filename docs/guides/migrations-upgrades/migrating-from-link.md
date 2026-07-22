# Migrating from Link
If your existing site has links from [Link](https://github.com/flipboxfactory/craft-link), it can be easily migrated over to Hyper.

To migrate your link fields and content, install Hyper, and navigate to **Hyper** → **Settings** → **Migrations** → **Link**. You'll need to have Link installed and enabled for this setting to appear.

Hyper's migrations will automatically take a database backup before the migration begins. If you encounter any errors during the migration, you **must** restore the backup before the migration, before running again.

:::warning
Because the migration needs to modify the content of your elements, this will be a **permanent** modification of your fields and field content. You will be unable to revert back to Link, without restoring your database from a backup.
:::

## Migration Process
The migration consists of two parts; 1. Migrating your field to Hyper and 2. Migrating the content of elements (entries, etc) to a Hyper Link model.

Because content is stored per-environment, we'll need to re-run any content migrations on each environment. For example, migrating content locally will not change any content on your staging or production installs. Migrated fields will, however due to them being store in Project Config.

## Field Migration
To begin the field migration, you must be on an environment where `allowAdminChanges` is set to `true`.

Click the **Migrate Fields** button to begin the migration process. The next screen will show you the result of the migration and what errors or exceptions were encountered.

You will only need to do this once, as the field changes are store in Project Config.

You can also trigger this via a console command:

```shell
./craft hyper/migrate/link --step=field
```

## Content Migration
Next, we'll migrate your content. Click the **Migrate Content** button to begin the migration process. The next screen will show you the result of the migration and what errors or exceptions were encountered.

This step can be re-run on any environment (like staging and production) safely, as Hyper will detect any content that's already been migrated over and skip it.

We **strongly** recommend you run this migration locally first, to ensure the migration runs as expected. You'll need to re-run this on other environments, but it's a good idea to check your content migrates correctly first.

You can also trigger this via a console command:

```shell
./craft hyper/migrate/link --step=content
```

To run both steps in one pass, omit `--step` (it defaults to `all`):

```shell
./craft hyper/migrate/link
```

:::tip
The old `hyper/migrate/link-field` and `hyper/migrate/link-content` commands still work but are deprecated in favour of the `--step` form above.
:::

## Nested Matrix / Super Table fields

Hyper migrates **all** Hyper fields, including those nested under Matrix or Super Table (non-`global` field context). Field migration writes a `migrationData` map (flipbox type identifier → Hyper link type) into each field’s settings; content migration uses that map to resolve each link.

If you see `Unable to convert…` for nested owners:

1. Confirm the nested field’s settings still include `migrationData` (re-run **Migrate Fields** if the map is empty).
2. Re-run **Migrate Content** — convert failures now log the flipbox `identifier`, content keys, and available `migrationData` keys.
3. When `migrationData` is missing a key, Hyper falls back to inferring Url / Email / Entry from the stored content shape.

Content migration is safe to re-run; already-converted Hyper collections are skipped.
