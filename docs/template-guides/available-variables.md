# Available Variables
The following are common methods you will want to call in your front end templates:

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
