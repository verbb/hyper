# Configuration
Create a `hyper.php` file under your `/config` directory with the following options available to you. You can also use multi-environment options to change these per environment.

The below shows the defaults already used by Hyper, so you don't need to add these options unless you want to modify the values.

```php
<?php

return [
    '*' => [
        'embedClientSettings' => [],
        'embedHeaders' => [],
        'embedDetectorsSettings' => [],
    ],
];
```

## Configuration options
- `embedClientSettings` - Define any [settings](https://github.com/oscarotero/Embed#settings) to pass to the Curl Client for Embed links.
- `embedHeaders` - Define any [headers](https://github.com/oscarotero/Embed#settings) to pass to the Curl Client for Embed links.
- `embedDetectorsSettings` - Define any [settings](https://github.com/oscarotero/Embed#settings) to pass to the detectors for Embed links.

## Control Panel
You can also manage configuration settings through the Control Panel by visiting Settings → Hyper.
