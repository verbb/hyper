# Configuration

You can customise Hyper’s settings using a PHP configuration file. This is optional: each setting has a default, so you only need to include the values you want to change.

To override a setting, create `hyper.php` in your Craft project’s `/config` directory and return an array of setting names and values. For example, the following enables higher-resolution embed image selection:

```php
<?php

return [
    'resolveHiResEmbedImage' => true,
];
```

All other settings keep their defaults. Add any further settings you want to change to the same array. The options below explain the available settings and their defaults.

## Configuration Options

::: reference
### `backupOnMigrate`

**Type:** `bool` · **Default:** `true`

Whether migration utilities create a database backup before making changes. Leave this enabled unless you are deliberately managing the backup separately.
:::

::: reference
### `resolveHiResEmbedImage`

**Type:** `bool` · **Default:** `false`

Compare candidate embed images to choose a higher-resolution image. This requires additional requests and can make fetching metadata slower. YouTube’s `hqdefault` thumbnail is checked for an available `maxresdefault` alternative independently of this setting.
:::

::: reference
### `embedClientSettings`

**Type:** `array` · **Default:** `[]`

Set the timeout for an individual embed request. Hyper uses 10 seconds when no timeout is supplied and clamps a supplied value to 1–10 seconds. Connection checks and overall request limits remain enforced; see [Embed Requests](#embed-requests).

For example, to limit each request to five seconds, use this override in `config/hyper.php`:

```php
<?php

return [
    'embedClientSettings' => [
        'timeout' => 5,
    ],
];
```
:::

::: reference
### `embedHeaders`

**Type:** `array` · **Default:** `[]`

HTTP headers to send with embed requests. The empty default adds no custom headers. Use this when the service you are fetching requires a particular request header.
:::

::: reference
### `embedDetectorsSettings`

**Type:** `array` · **Default:** `[]`

Settings passed to Embed’s metadata detectors. Leave this empty to use their normal behaviour. See the [Embed library documentation](https://github.com/oscarotero/Embed#settings) for detector options.
:::

::: reference
### `embedAllowedDomains`

**Type:** `array` · **Default:** `[]`

Domains allowed for embed pages and their secondary requests. An empty list allows public hosts. A non-empty Allowed Domains list on an individual Embed link type replaces this global list.

See [Embed Domains](#embed-domains) for a complete example and an explanation of secondary image and metadata hosts.
:::

::: reference
### `allowedUriSchemes`

**Type:** `array` · **Default:** `[]`

Extra URI schemes permitted in links, such as `slack` or `ftp`. The built-in schemes are `http`, `https`, `mailto`, `tel` and `sms`; fragment-only and relative URLs are also permitted. You do not need to repeat the built-in schemes when adding an extra one.

The schemes `javascript`, `data` and `vbscript` are always blocked. See [Additional URI Schemes](#additional-uri-schemes) for how this applies to Custom links.
:::

## Embed Domains

For a video-only field, set **Allowed Domains** on its Embed link type. If the type’s list is empty, Hyper uses `embedAllowedDomains` from this file instead. Enter domains without a scheme or path. A domain such as `youtube.com` also permits its subdomains, but not unrelated hosts whose names contain that string.

Embed pages may fetch metadata and images from other hosts. Include the provider’s required secondary domains, such as `ytimg.com` for YouTube thumbnails. For example, a `config/hyper.php` file allowing these YouTube domains would contain:

```php
<?php

return [
    'embedAllowedDomains' => ['youtube.com', 'youtu.be', 'ytimg.com'],
];
```

Try a representative URL in the editor and check both its preview and saved metadata. If a redirect or image host is blocked, review the required host and add it to the applicable list. Private network addresses are not permitted even when a domain is listed.

## Embed Requests

Hyper verifies TLS and checks the destination of each page, redirect, metadata and image request. Client settings cannot turn these checks off. Requests are limited to five redirects, 20 requests, 2 MiB per response and 8 MiB in total, with a 30-second transport budget.

These limits mean a provider requiring many requests or a slow response can fail to return complete embed metadata. Check the editor’s response with the actual provider URL before relying on an embed in your templates.

## Additional URI Schemes

If your site links into an application using a scheme such as `slack:`, add it to `allowedUriSchemes`. This applies to rendered Custom links as well as URL links; choosing Custom does not bypass the shared policy.

## Control Panel

Open Hyper’s settings to manage [Link Type Configs](/feature-tour/link-type-configs) and access migration tools. The General Settings page does not provide controls for the PHP options above; edit `config/hyper.php` to change those values.

[Custom Fields Inside Links](/feature-tour/custom-fields-inside-links) covers link layouts, nested Matrix fields and asset uploads.
