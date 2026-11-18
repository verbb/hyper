# Working with Multiple Sites

Hyper works with Craft’s field translation settings. These determine which sites share a field value and which can edit it separately. For a multilingual site, you might want the same related links in each language while allowing editors to translate their labels.

Before configuring Hyper, make sure the owning entry’s section is available in the relevant sites. Linking to a translated entry also requires that destination to be available in the intended site.

## Choose How the Field Is Translated

Open the Hyper field in **Settings → Fields** and choose its translation method. Use **Not translatable** when the field should share its value. Use **Translate for each site** when editors need to enter site-specific values.

With **Translate for each site**, a new entry copies its links to the entry’s other sites when it’s first saved. After that, each site keeps its own list, so editors can add, remove, reorder and change links on one site without affecting the others.

## Sync Links Across Sites

Turn on **Sync Links Across Sites** for a multi-link field using **Translate for each site** when every site should show the same list with translated labels, such as a shared navigation. Editing and saving the field on one site then adds, removes and reorders the matching links on the entry’s other sites.

Labels already translated for matching links are retained, including a deliberately blank label. Links match when one was copied from the other, so a link added separately on each site doesn't match, even with the same destination, and takes the edited site’s link text. Links from Hyper 2 never match across sites, so for upgraded content the first sync replaces the other sites’ lists and their translated link text with the edited site’s. Re-enter the translations after that first sync. Existing custom-field content for matching links is retained when present. Other link attributes, including URLs, follow the site you edited, so a site-specific URL is replaced the next time another site’s list is saved. Leave the setting off when sites need different lists or destinations.

Syncing works in both directions, so the most recently saved site wins. If a translator edits the French list, the English list follows it. Turning the setting on for a field with existing content has no immediate effect, but the next save of that field on any site brings the entry’s other sites into line with it, removing links that only exist on another site.

Saving an unrelated field does not sync the list. The setting only applies to entries, and has no effect on single-link fields or other translation methods. Inside a Matrix field, it syncs a nested entry’s links when the Matrix field saves its entries to all of the owner’s sites. Syncing applies to every save that changes the field, including imports and GraphQL mutations, as well as the control panel.

## Select a Destination Site

Element link types can expose a site menu in their selector. When enabled, this lets an editor choose the site of the linked element. Hyper uses the recorded site when resolving that destination.

Custom relation fields inside the link use the owning entry's site unless the custom field explicitly selects another target site. Choosing a different site for the link's main destination does not change those custom fields. For example, a French entry can link to an English article while a related Entry field inside that link still resolves French content.

During propagation to another site, Hyper attempts to resolve localised element links in that destination site. If it finds an eligible translated element, it uses that element and site. If no eligible translation exists, it retains the source reference; it does not create or publish the missing translation. Check links on each site when the same destination is not published everywhere.

A URL link remains the address the editor entered. Hyper cannot infer a translated equivalent of an arbitrary external or relative URL.

## Check a Translated List

On a development site with two enabled languages, add two Entry links to a multi-link Hyper field using **Translate for each site**, then save the entry. Switch to the other language and enter translated Link Text for both links.

If the field has **Sync Links Across Sites** turned on, return to the first language, reorder the links and save. Open the second language again. The order should match, while its labels stay with their corresponding links. View the frontend pages in both languages and follow the links to confirm the destination sites and URLs.

If a destination is missing or unavailable, check its publication status and site availability before changing the link. [Element Links](/feature-tour/element-links) explains nullable element access, and [Reverse Relations](/feature-tour/reverse-relations#work-with-multiple-sites) explains how site selection affects incoming-link queries.
