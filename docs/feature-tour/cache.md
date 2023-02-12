# Cache
Element link types link to an element, which uses the Title and URL of that element to generate a link. Generating an element query to fetch this data is costly, especially if you have lots of Hyper links on a page.

We employ a layer of cache to prevent fetching the element itself unless required. When you create an element-based link, Hyper will store the URL and Title of the linked element in a database cache.

## How it Works
Whenever an element is saved, and has been used in a Hyper field, we'll cache the URL and Title of the linked element (for each site enabled). That way, your links will always be up to date.

In addition, when rendering a Twig page, Hyper will find all Hyper fields used on that page and preload the cache for every potential element used in each Hyper field. This allows us to execute a single query to fetch the cached items, rather than a query for every instance of a Hyper field being output in your templates. This provides significant performance improvements over other solutions.
