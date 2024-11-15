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
./craft hyper/migrate/link-field
```

## Content Migration
Next, we'll migrate your content. Click the **Migrate Content** button to begin the migration process. The next screen will show you the result of the migration and what errors or exceptions were encountered.

This step can be re-run on any environment (like staging and production) safely, as Hyper will detect any content that's already been migrated over and skip it.

We **strongly** recommend you run this migration locally first, to ensure the migration runs as expected. You'll need to re-run this on other environments, but it's a good idea to check your content migrates correctly first.

You can also trigger this via a console command:

```shell
./craft hyper/migrate/link-content
```