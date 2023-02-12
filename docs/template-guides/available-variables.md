# Available Variables
The following are common methods you will want to call in your front end templates:

### `craft.socialFeed.getAllFeeds()`
Returns a collection of [Feed](docs:developers/feed) objects.

### `craft.socialFeed.getAllEnabledFeeds()`
Returns a collection of enabled [Feed](docs:developers/feed) objects.

### `craft.socialFeed.getFeedById(id)`
Returns a [Feed](docs:developers/feed) object by its ID.

### `craft.socialFeed.getFeedByHandle(handle)`
Returns a [Feed](docs:developers/feed) object by its handle.

### `craft.socialFeed.getAllSources()`
Returns a collection of [Source](docs:developers/source) objects.

### `craft.socialFeed.getAllEnabledSources()`
Returns a collection of enabled [Source](docs:developers/source) objects.

### `craft.socialFeed.getAllConfiguredSources()`
Returns a collection of configured [Source](docs:developers/source) objects.

### `craft.socialFeed.getSourceById(id)`
Returns a [Source](docs:developers/source) object by its ID.

### `craft.socialFeed.getSourceByHandle(handle)`
Returns a [Source](docs:developers/source) object by its handle.

### `craft.socialFeed.getPosts(feedHandle, options)`
Returns a collection of [Post](docs:developers/post) objects for the provided [Feed](docs:developers/feed) handle.

### `craft.socialFeed.renderPosts(feedHandle, options)`
Returns the HTML of rendered [Post](docs:developers/post) objects for the provided [Feed](docs:developers/feed) handle.
