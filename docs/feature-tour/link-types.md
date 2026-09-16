# Link Types

Choose link types according to the destinations editors need. A contact button might accept Email and Phone, while a related-content list might accept Entry and URL. Enable those types in the field’s Custom settings or its shared [Link Type Config](/feature-tour/link-type-configs).

Each type can have its own label and field layout. You can add several instances of an element type when they need different sources, such as separate News and Product entry choices.

## Entry, Category, and Asset

These types let editors choose existing Craft content. Use Entry for pages, Category for category pages, and Asset for downloadable files or other assets with URLs. Restrict their sources to make the available choices relevant to the field’s purpose.

Hyper resolves their destinations from the selected elements. See [Element Links](/feature-tour/element-links) for title fallbacks, unavailable targets and template access.

## URL

Use URL for an address the editor enters, including absolute URLs, relative addresses and page fragments. A **Default Link Value** can prefill the address for new links. Enable **Fixed Link Value** with a default when editors should keep that destination while editing other link information.

A URL can contain a fragment such as `#contact`; the destination page must have a matching element ID. For fragments appended to a selected entry’s URL, use [URL Suffix](/feature-tour/rendering-links#url-fragments-anchors).

## Email

Use Email for an email address. Hyper prefixes it with `mailto:` when rendering. Enter the address alone in the Link field; subject or body parameters are not part of an email address.

To prefill a subject, put `?subject=Hello` in **URL Suffix**. For a full `mailto:` address with several parameters, use Custom. Follow the rendered link to check that your mail application receives the intended address and subject.

## Phone

Use Phone for a telephone number. Hyper prefixes it with `tel:` so supported devices can open their calling application. Enter the number in the link and use Link Text when the visible label should be different, such as “Call our team”.

## Custom

Use Custom for an address that needs different validation from the URL input, such as a complete `mailto:` URI. Both types still follow the shared rendering policy. Application schemes such as `slack:` require an entry in [allowedUriSchemes](/get-started/configuration#additional-uri-schemes).

## Embed

Use Embed when you want information or embeddable content from a remote URL, such as a video’s title, thumbnail and player. Enter an absolute HTTP or HTTPS URL and check the preview before saving. The provider determines which metadata is available.

You can restrict its **Allowed Domains** to suitable providers. See [Embed Domains](/get-started/configuration#embed-domains) for secondary hosts and [Rendering Embed Links](/feature-tour/rendering-links#embed-links) for output with an anchor fallback.

## Passive

Use Passive for a label without a destination, such as a heading in a resource list. It can contain Link Text and custom fields, but has no URL. Render its text separately instead of calling `getLink()`; the [multiple-link example](/feature-tour/rendering-links#multiple-links) includes that case.

## Site

Use Site to choose one of Craft’s configured sites as the destination. This is useful for a list of your site’s regional or language editions. Check that the selected site has the intended base URL.

## User

Use User when your template needs a selected Craft user. Users do not inherently have frontend URLs, so selecting a user alone does not create a profile-page route. Obtain the user with `getElement()`, check that it exists, and use your site’s profile URL convention when rendering it.

## Destinations from Other Plugins

Additional types are available when their required plugins are installed and enabled:

- Calendar Event selects a Calendar event.
- Form selects a Formie form.
- Product and Variant select Craft Commerce content.
- Shopify Product selects content from Craft’s Shopify integration.

A selectable element still needs a URL or your own template behaviour to become a useful frontend link. For example, selecting a Formie form can let your template access that form, but does not create a form page automatically.

For PHP class names, see [Built-in Classes](/reference/link#built-in-classes). To add destination behaviour, follow [Creating Link Types](/developers/creating-link-types).
