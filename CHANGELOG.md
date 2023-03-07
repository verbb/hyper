# Changelog

## 1.1.1 - 2023-03-07

### Fixed
- Fix an issue registering link types when Hyper isn’t fully initialized.
- Fix an error with element caches for Asset links.

## 1.1.0 - 2023-03-05

### Added
- Add Commerce Product and Variant link types.
- Add `hyper/migrate/typed-link-field` console command for migration.
- Add `hyper/migrate/typed-link-content` console command for migration.
- Add `hyper/migrate/linkit-field` console command for migration.
- Add `hyper/migrate/linkit-content` console command for migration.
- Add `hyper/migrate/link-field` console command for migration.
- Add `hyper/migrate/link-content` console command for migration.
- Add `embedDetectorsSettings` to pass to embed settings. (thanks @kylecotter).
- Add `embedHeaders` plugin setting to provide settings for Embed link fetching.
- Add `embedClientSettings` plugin setting to provide settings for Embed link fetching.

### Changed
- Improve third-party link field content migration. You can now run the migration for the field and content separately and safely multiple times, and per-environment.
- Update multi-link fields to not show a link type dropdown when only one link type is available.

### Fixed
- Fix select fields not working for link blocks when re-ordered for Craft 4.4+.
- Fix Redactor not working correctly for link blocks when re-ordering.
- Fix an visual overflow issue for link blocks.
- Fix asset link types not working correctly to select assets.
- Fix multi-link fields not allowing all links to be removed.
- Fix a infinite loop issue when link types contain custom fields.
- Fix an error when Hyper hasn’t been fully initialised yet, setting link type objects.
- Fix an issue where a disabled link type could be chosen as the default for the field.

## 1.0.5.2 - 2023-03-01

### Fixed
- Fix settings icon not appearing for multi-link fields.
- Fix a potential error with field layout config when saving fields.

## 1.0.5.1 - 2023-03-01

### Fixed
- Fix settings icon not appearing for multi-link fields.

## 1.0.5 - 2023-03-01

### Added
- Add error text when unable to render the link’s HTML for general errors.
- Add error text when unable to render the link’s HTML due to field layout issues.

### Fixed
- Fix an error with invalid field layout data..
- Fix multi-link fields with a single tab for linktype settings throwing an error.
- Fix project config change inconsistencies for link types.
- Fix element cache for element links not working correctly for multi-site installs.
- Fix incorrect `valueType()` for field.
- Fix an error when deleting a Matrix or Super Table block type throwing an error with Hyper fields.
- Fix link type field layouts not persisting once edited.
- Fix link types not saving a custom order in the field settings.
- Fix an error when running project config rebuild.

## 1.0.4 - 2023-02-21

### Fixed
- Fix classes not applying with `getLink({ class: ‘…’ })`.
- Fix a migration issue for Vizy fields containing Super Table/Matrix fields with links.

## 1.0.3 - 2023-02-18

### Fixed
- Fix an error migrating Typed Link fields for element-based links with cache data.
- Fix an error when migrating Typed Link fields, when multiple ones are in a Matrix field.
- Fix a visual gap for new link button for multi-link fields.
- Fix an error when rendering fields that used to have an element for the link value, not being `null`.

## 1.0.2 - 2023-02-16

### Added
- Add `asString` as a param to `Link::getLinkAttributes()`.

### Fixed
- Fix custom link text not overriding for Element or Site link types.

## 1.0.1 - 2023-02-16

### Added
- Add more logging more failed content table migrations.
- Add UID to field migration output for fields.

### Fixed
- Fix an unhandled error when migrating, where a corresponding Hyper link type cannot be found.
- Fix a potential error when migrating fields.
- Fix an error when migrating empty field content.
- Fix an error with field settings not having their enabled link type state set correctly.
- Fix a validation error when saving element drafts.

## 1.0.0 - 2023-02-14

### Added
- Initial release
