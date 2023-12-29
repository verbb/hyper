# Configuration
Create a `hyper.php` file under your `/config` directory with the following options available to you. You can also use multi-environment options to change these per environment.

The below shows the defaults already used by Hyper, so you don't need to add these options unless you want to modify the values.

```php
<?php

return [
    '*' => [
        'resolveHiResEmbedImage' => false,
        'embedClientSettings' => [],
        'embedHeaders' => [],
        'embedDetectorsSettings' => [],
        'embedAllowedDomains' => [],
    ],
];
```

## Configuration options
- `resolveHiResEmbedImage` - Whether the Embed field should determine the most hi-resolution image available. Do note that there's performance implications for this, as it requires fetching every available image for the embed data and comparing them.
- `embedClientSettings` - Define any [settings](https://github.com/oscarotero/Embed#settings) to pass to the Curl Client for Embed links.
- `embedHeaders` - Define any [headers](https://github.com/oscarotero/Embed#settings) to pass to the Curl Client for Embed links.
- `embedDetectorsSettings` - Define any [settings](https://github.com/oscarotero/Embed#settings) to pass to the detectors for Embed links.
- `embedAllowedDomains` - Define any allowed domain names for Embed links. Any embed links that are added _not_ in this list will fail to be saved. Leave empty to allow any domain. Include just the TLD with no `http://`, `https://` or `www`.

## Control Panel
You can also manage configuration settings through the Control Panel by visiting Settings → Hyper.
