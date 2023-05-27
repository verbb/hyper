# Available Variables
The following methods are available to call in your Twig templates:

### `craft.hyper.getRelatedElements(params)`
Returns an ElementQuery for elements that are related to a provided Hyper field.

```twig
{% set relatedElements = craft.hyper.getRelatedElements({
  relatedTo: {
      targetElement: entry,
      field: 'myHyperField',
  },
  ownerSite: 'siteHandle',
  elementType: 'craft\\elements\\Entry',
  criteria: {
      id: 'not 123',
      section: 'someSection',
  }
}).all() %}
```
