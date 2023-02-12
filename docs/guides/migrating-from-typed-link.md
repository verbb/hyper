# Migrating from Typed Link
If your existing site has links from [Typed Link](https://github.com/sebastian-lenz/craft-linkfield), it can be easily migrated over to Hyper.

To migrate your link fields and content, install Hyper, and navigate to **Hyper** → **Settings** → **Migrations** → **Typed Link**. You'll need to have Typed Link installed and enabled for this setting to appear.

Click the **Migrate Fields** button to begin the migration process. The next screen will show you the result of the migration and what errors or exceptions were encountered.

:::warning
Because the migration needs to modify the content of your elements, this will be a **permanent** modification of your fields and field content. You will be unable to revert back to Typed Link, without restoring your database from a backup.
:::

## Content Migration
The migration consists of two parts; 1. Migrating your field to Hyper and 2. Migrating the content of elements (entries, etc) to a Hyper Link model.

To achieve this, the migration will **permanently** modify your fields, and the field content for elements. This allows us to provide you with a streamlined and in-place migration without having to create new fields, or go through every occurrence of the field in entries, etc and update link content. All that's required on your end will be updating your Twig templates to work with Hyper.

Hyper's migrations will automatically take a database backup before the migration begins. If you encounter any errors during the migration, you **must** restore the backup before the migration, before running again.