# Changelog

## 1.3.2 - 2024-10-09

### Added
- Typed Link field migrations now include an “Update Legacy Fields” button as a first-step in the migration process to fix incorrect Typed Link fields

### Changed
- Typed Link Field Content Migration now prioritize `linkedSiteId` over `siteId` to support cross site relations. (thanks @internetztube).

### Fixed
- Fix link types from initializing too early.

## 1.3.1 - 2024-09-17

### Fixed
- Add back handling for when Hyper is initialised before Craft is ready, but log exception and stack trace.

## 1.3.0 - 2024-09-14

### Added
- Add a more descriptive warning when Hyper is initialized before Craft is.
- Link types now can specify any required plugins via `getRequiredPlugins()`.

### Changed
- Improve handling of link types that are invalid (removed or unavailable).
- Hyper will now throw a fatal error if an element query is made before Craft is initialized (as per Craft best practices).

## 1.2.2 - 2024-09-07

### Added
- Add extra error logging for Matrix and Super Table fields for Typed Link field migration.
- Add “safe” attributes for various link properties to allow `setAttributes` correctly.
- Add `linkUri` to the GraphQL `LinkInterface` class.

## 1.2.1 - 2024-08-08

### Fixed
- Fix a compatibility issue with Craft 4.11.x.

## 1.2.0 - 2024-07-21

### Added
- Add `Link::linkUri`.
- Add “Create backup” checkbox for migrations.
- Add `create-backup` console command option for migrations.
- Add missing descriptions for console commands.

### Changed
- Change element-based links properly checking if their linked-to element is “empty”. For example, linking to a disabled element will now make `isEmpty` return true.
- Improve GraphQL Schema generation performance. (thanks @markhuot).
- Handle migration of anchor-only URLs for Typed Link fields.

### Fixed
- Fix Typed Link migration for Site link types.
- Fix parsing emoji's in link URLs. (thanks @sfsmfc).

## 1.1.32 - 2024-06-15

### Added
- Add `Link::lowerDisplayName`.

### Changed
- Change `Link::lowerDisplayName` to `Link::lowerClassDisplayName`.
- Change `Link::displayNameSlug` to `Link::classDisplayNameSlug`.

### Fixed
- Fix an error where min/max links are validated for non-multi link fields.
- Fix an error for not handling invalid link content correctly.
- Fix some JS not initializing for some fields when switching link types.

## 1.1.31 - 2024-05-31

### Changed
- Update non-English translations.
- Update English translations.
- Improve instructions text contrast for sub-fields.

### Fixed
- Fix link text returning a value when an invalid link.
- Fix an error when determining the link text automatically when the `linkText` field is excluded from the link type.
- Fix link type placeholder not being able to be overwritten.
- Fix Link Type labels not being translated.
- Fix link type translations.

## 1.1.30 - 2024-05-18

### Changed
- Hyper link’s `text` now uses the placeholder for the field if defined, otherwise falling back to “Read more”.

## 1.1.29 - 2024-05-11

### Added
- Add console migration for Typed Link fields (legacy) for older Typed Link installs.

### Changed
- Update French translations.
- Changed the default text for links to `Read more` when text cannot be resolved. This improves URL-based links not relying on text to be defined to render the link at all.

### Fixed
- Fix an error when setting the element cache. (thanks @boboldehampsink).
- Fix an error where custom text set on a link is retained next time the same link is rendered (without custom text).

## 1.1.28 - 2024-04-29

### Changed
- Update non-English translations.
- Update English translations.

### Fixed
- Fix an error with custom fields retaining values after initial creation.
- Fix other link plugin migrations where owner fields like Matrix and Super Table were incorrectly referenced.
- Fix `Link::getText()` not using a defined fallback text value correctly.
- Fix programmatically creating links not working for some link types.

## 1.1.27 - 2024-04-10

### Added
- Add `Link::getLinkType()` to return the `LinkInterface` for the link’s type.

### Changed
- New element-based links on a multi-site now propagate the linked-to element for the same owner site.

### Fixed
- Fix link type dropdown styling on Safari.
- Fix field changes being triggered when no changes had been made.

## 1.1.26 - 2024-03-29

### Added
- Add Formie forms as a link type.

### Fixed
- Fix an error when limiting link types and removing the current link type of a link.
- Fix Shopify Product link type label.
- Fix Typed Link migration not including suffixes for element link types.
- Fix an error where other field content would be cleared when Hyper field content was initialized at the same time.
- Fix Typed Link migration not including suffixes for element link types.

## 1.1.25 - 2024-03-23

### Fixed
- Fix validation not working correctly for link blocks.

## 1.1.24 - 2024-03-22

### Fixed
- Fix an error when creating new link blocks, not respecting the default link type.

## 1.1.23 - 2024-03-22

### Added
- Add field migration note for when no fields are found to migrate.
- Add counter to field migration utility to make it easier to troubleshoot any failed fields.

### Changed
- Link blocks no longer show a border around the block for single links, when the header is hidden.
- Link block headers now hide the header when not required.

### Fixed
- Fix an error if an existing link block is set to a type that’s no longer in its allowed types.
- Fix an error with Embed links with image processing.
- Fix an error when rendering Commerce Variant links.
- Fix UI overflow for field.
- Fix Typed Link migration for multi-site installs.

## 1.1.22 - 2024-03-18

### Added
- Add `backupOnMigrate` plugin setting to control whether backup are performed when migrating from another link plugin.

## 1.1.21 - 2024-03-04

### Added
- Add the ability to set the column type for Hyper fields.

### Changed
- Embed link types now create `<iframe>` elements when embed responses don’t contain them.

### Fixed
- Fix custom attributes not saving correctly.
- Fix Selectize fields not working properly when re-ordering link blocks which Hyper was contained in a Matrix/Super Table field.

## 1.1.20 - 2024-01-30

### Added
- Add the ability to make the `linkValue` a required field.

### Fixed
- Fix element-based link types not validating correctly when a non-uri element is selected.
- Fix being unable to select User elements for User link type.
- Fix when making changes to a link, switching between link types would not retain any changes.

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
