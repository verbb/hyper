# Entrification (Category → Entry)
After Craft’s [entrification](https://craftcms.com/blog/entrification) converts category groups to sections, Hyper **Category** links still store the Category link type. Remap them to **Entry** links.

Craft’s `entrify/categories` keeps the **same element ID** (`entries.id = categories.id`), so Hyper only flips the link type/handle — no ID mapping file.

## Prerequisites
1. Run Craft’s `php craft entrify/categories` (or equivalent) first.
2. Ensure each Hyper field has an enabled **Entry** link type (Category type can stay disabled afterward).

## Console

```bash
php craft hyper/migrate/entrify-categories
php craft hyper/migrate/entrify-categories --dry-run=1
```

Shared options: `--create-backup`, `--dry-run`, `--sync-relations` (default on).
