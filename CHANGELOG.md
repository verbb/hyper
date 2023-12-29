# Changelog

## 1.1.19 - 2023-12-20

### Added
- Add validation support for Embed fields, when supplied with an invalid URL.
- Add `imageWidth` and `imageHeight` to embed data for Embed fields, when `resolveHiResEmbedImage` is enabled.

### Fixed
- Fix an error when fetching an invalid URL for Embed links.

## 1.1.18 - 2023-12-08

### Added
- Add “allowed domains” plugin setting, to control what domains are allowed to be used in Embed link types.
- Add `resolveHiResEmbedImage` for Embed fields to resolve hi-resolution images for embed data.
- Add “Embed Preview” field layout UI element.
- Add `linkValue` to GraphQL queries for Hyper fields for the raw value of the link (useful for embed link types).
- Add extra logging when unable to render a Hyper field block.
- Add error logging to Embed link types.

### Changed
- Improve Embed link type performance when saving elements.
- Embed link types now fetch the most hi-res image available.
- Embed links now include the `description` for the link’s `title` attribute, if “Link Title” field is enabled.

### Fixed
- Fix Embed link type fetching extra times when the link’s URL hasn’t changed.
- Fix support for Embed v3 for Embed links, when other plugins (`spicyweb/craft-embedded-assets`) still use it.
- Fix an error when deleting link blocks.
- Fix Assets fields rendering the “Upload files” button multiple times when re-rendering.
- Fix styles when dragging link blocks.

## 1.1.17 - 2023-11-25

### Added
- Add blocktype errors for Matrix and Super Table fields when migrating from Typed Link fields.

### Fixed
- Fix an error when invalid `linkValue` was set for element link types.
- Fix an error with linkField `autofocus` being incorrectly set.
- Fix an issue with setting custom `linkText` when rendering fields. `text` should now be used.

### Deprecated
- Deprecate setting `linkText` when calling `getLink()`. Use `text` instead.

## 1.1.16 - 2023-10-26

### Fixed
- Fix an error when deleting a link block.
- Fix an error for non-multiple links and some fields in the slide-out editor.

## 1.1.15 - 2023-10-12

### Fixed
- Fix missing templates for Shopify Product link types.

## 1.1.14 - 2023-10-03

### Fixed
- Fix some visual inconsistencies with link blocks.
- Fix an error for nested Hyper fields.
- Fix save button for plugin settings.
- Fix an error when re-ordering link blocks.

## 1.1.13 - 2023-09-25

### Fixed
- Fix missing translations.
- Fix link block background clip.
- Fix field handles not showing for link attributes and custom fields.
- Fix an issue when re-ordering multiple links.

## 1.1.12 - 2023-09-15

### Added
- Add support for Shopify Product links.

### Changed
- Update cache after slug/uri changes. (thanks @nateiler).

### Fixed
- Fix an error for some field setups and failing linktypes.
- Fix fields in the settings slideout not saving correctly in some cases.
- Fix a visual bug when dragging multiple Hyper link blocks in the element slideout.
- Fix multi-link “Add link” buttons not working correctly for nested Hyper fields.
- Fix field not initializing correctly in Super Table or Matrix field settings.
- Fix being unable to pick Variants for Variant link type.

## 1.1.11 - 2023-08-10

### Added
- Add `Link::checkElementUri()`.

### Fixed
- Fix some fields not having their JS initialized when used in the link type “Content” tab.
- Fix an error with Matrix (and some other fields) caused by incorrect Linktype validation calls when saving a Hyper field.
- Fix incorrect results when trying to eager-load Hyper fields.
- Fix an error when Hyper fields are initialized too early, before Craft and Hyper are ready.
- Fix lightswitch UI on Craft 4.4.16+.
- Fix “fresh” check for blocks, affecting some defaults for some fields (Button Box) saving over content.

## 1.1.10 - 2023-07-21

### Added
- Add support for `rel` in custom attributes field value, when also enabling “new window”.
- Add extra debugging message for Linkit migration when link types cannot be migrated.
- Add support for migrating Product link type for LinkIt.

### Changed
- The Link type dropdown now shows as disabled if only a single link type.

### Fixed
- Fix nested Hyper fields not working correctly.
- Fix an error where checking if Craft was initialized too early results in empty Hyper fields.

## 1.1.9 - 2023-07-11

### Added
- Add French translations. (thanks @scandella).
- Add custom link type checks to migrations.
- Add cache utility to clear caches for element links.

### Changed
- Update link field migrations to disable any link types that weren’t present in the respective original link plugin (e.g. Embed).
- Field settings now no longer open the settings for the first link type automatically.

### Fixed
- Fix LinkIt migration for social media URLs (Facebook, Twitter, etc), not migrating correctly.
- Fix translations for some strings.
- Fix an error when Hyper fields are initialized too early, before Craft and Hyper are ready.
- Fix default new window setting for multiple link version not working correctly. (thanks @JeroenOnstuimig).

## 1.1.8 - 2023-06-25

### Added
- Add link types to GraphQL queries.
- Add `Link::isElement()`.

### Changed
- Element links now only return an element value when the linked-to element is enabled.

### Fixed
- Fix GraphQL queries containing `isElement` and `isEmpty`.
- Fix `LinkInterface` not being registered properly as a GraphQL interface.
- Fix some field layout elements not allowing custom instructions text.
- Fix an error when uninstalling third-party elements that register a link type.

## 1.1.7 - 2023-05-27

### Fixed
- Fix Feed Me support for older Feed Me versions (pre 5.1.1.1).

## 1.1.6 - 2023-05-17

### Fixed
- Fix a migration error with Typed Link fields in Vizy fields.
- Fix an issue where Hyper fields weren’t working in Vizy fields.

## 1.1.5 - 2023-05-11

### Added
- Add compatibility with Vizy 2.1.x.

### Changed
- Allow `text` and `linkText` options to override link text for `getLink()` calls.
- Link attributes defined in the field settings can now be overridden in templates.

### Fixed
- Fix an error in some cases with an empty element select modal.
- Fix place link type settings not saving correctly.
- Fix an issue using Smith to clone Matrix fields containing Hyper fields.
- Fix classes define in templates not merging with field settings classes.

## 1.1.4 - 2023-04-07

### Added
- `LinkCollection` now implements `ArrayAccess` to allow index-access for Hyper field values.

### Fixed
- Fix some HTML characters being stripped incorrectly due to LitEmoji processing.
- Fix HTML characters not being encoded/decoded correctly for field values.

## 1.1.3 - 2023-04-04

### Fixed
- Fix empty link states for Email and Phone links.
- Fix an error when creating the field cache for new fields.
- Increase z-index for tooltips when in live preview or editor slide-out.

## 1.1.2 - 2023-03-14

### Fixed
- Fix an error with Commerce Product/Variant link types.

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
