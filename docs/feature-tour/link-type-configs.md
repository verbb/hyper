# Link Type Configs

Use a Link Type Config when several Hyper fields should offer the same destinations and link fields. For example, a site might use the same URL and Entry options for a page’s main button and its footer links. Updating their shared config keeps those choices consistent.

A field can instead select **Custom** to manage its own link types. Choose this when changing one field should not affect others.

## Create a Shared Config

Open Hyper’s settings and select **Link Type Configs**, then **New Link Type Config**. You need permission to manage Craft settings in an environment that allows administrative changes.

Enter `Page Links` as the **Name** and `pageLinks` as the **Handle**. The name identifies the config in the control panel; the handle identifies it in code. In **Link Types**, enable URL and Entry and disable the types this set of fields does not need.

Select Entry to configure its **Sources**. If these links should point only to News entries, select that section. Use **Link Fields** to arrange the link’s fields and tabs, then save the config.

## Apply It to a Field

Open **Settings → Fields**, select your Hyper field and choose **Page Links** under **Link Type Config**. Choose an enabled **Default Link Type**, then save the field. Repeat for the other fields that should share these options.

Edit an entry containing one of those fields and add a link. You should see URL and Entry as the available types. Selecting Entry should offer the sources you chose. The field still controls whether it accepts one or several links independently of the shared config.

## Change Shared Settings

Edit the config from **Link Type Configs** to change the labels, enabled types or layouts for every field using it. These settings are stored in Craft’s Project Config, so deploy them through your site’s normal configuration workflow.

Before disabling a type or removing a custom field, check existing content that uses it. An unavailable link type cannot render normally, even though Hyper retains its stored content. Restore the type or choose a supported destination in the editor to make the link usable again.

## Use Settings for One Field

Select **Custom** in a Hyper field’s **Link Type Config** setting to edit that field’s own link types. Hyper copies the selected config’s link types as a starting point. Changes then apply to that field alone.

New Hyper fields use the **Default** config. You can edit its name, link types and layouts, but its `default` handle is reserved and it cannot be deleted. Other configs can be deleted only when no fields use them; move those fields to another config or Custom first.

For creating fields in PHP, see [Creating Hyper Fields Programmatically](/guides/developers/creating-hyper-fields-programmatically). [Link Type Config](/reference/link-type-config) documents the corresponding service and stored settings.
