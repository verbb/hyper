# Changelog

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
