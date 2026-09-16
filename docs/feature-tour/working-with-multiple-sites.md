# Working with Multiple Sites

Hyper works with Craft’s field translation settings. These determine which sites share a field value and which can edit it separately. For a multilingual site, you might want the same related links in each language while allowing editors to translate their labels.

Before configuring Hyper, make sure the owning entry’s section is available in the relevant sites. Linking to a translated entry also requires that destination to be available in the intended site.

## Choose How the Field Is Translated

Open the Hyper field in **Settings → Fields** and choose its translation method. Use **Not translatable** when the field should share its value. Use **Translate for each site** when editors need to enter site-specific values.

A multi-link Hyper field on an entry has an additional behaviour with **Translate for each site**: editing and saving that field synchronises the link list’s structure to the entry’s other sites. Added, removed and reordered links are reflected there too. Labels already translated for matching links are retained, including a deliberately blank label. Existing custom-field content for matching links is retained when present; other link attributes follow the source link.

This is useful for a shared related-resources list, but it does not provide independently arranged lists for each language. Saving an unrelated field does not trigger this structural synchronisation. Single-link fields follow Craft’s translation and propagation behaviour without the multi-link structure merge.

## Select a Destination Site

Element link types can expose a site menu in their selector. When enabled, this lets an editor choose the site of the linked element. Hyper uses the recorded site when resolving that destination.

Custom relation fields inside the link use the owning entry's site unless the custom field explicitly selects another target site. Choosing a different site for the link's main destination does not change those custom fields. For example, a French entry can link to an English article while a related Entry field inside that link still resolves French content.

During propagation to another site, Hyper attempts to resolve localised element links in that destination site. If it finds an eligible translated element, it uses that element and site. If no eligible translation exists, it retains the source reference; it does not create or publish the missing translation. Check links on each site when the same destination is not published everywhere.

A URL link remains the address the editor entered. Hyper cannot infer a translated equivalent of an arbitrary external or relative URL.

## Check a Translated List

On a development site with two enabled languages, add two Entry links to a multi-link Hyper field using **Translate for each site**, then save the entry. Switch to the other language and enter translated Link Text for both links.

Return to the first language, reorder the links and save. Open the second language again: the order should match, while its labels stay with their corresponding links. View the frontend pages in both languages and follow the links to confirm the destination sites and URLs.

If a destination is missing or unavailable, check its publication status and site availability before changing the link. [Element Links](/feature-tour/element-links) explains nullable element access, and [Reverse Relations](/feature-tour/reverse-relations#work-with-multiple-sites) explains how site selection affects incoming-link queries.
