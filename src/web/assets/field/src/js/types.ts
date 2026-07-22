export type LinkInstance = Record<string, unknown> & {
    id: string;
    handle: string;
    /** Durable content identity. */
    uid?: string;
    isNew?: boolean;
    newWindow?: boolean;
    fields?: Record<string, unknown>;
    customAttributes?: Record<string, unknown>;
};

export type HyperBulkMode = 'elements' | 'text';

export type HyperBulkConfig = {
    /**
     * How the Bulk Add dialog collects values for this type: `text` shows a one-per-line
     * textarea; `elements` mounts Craft's native element select (chips) rendered server-side via
     * hyper/fields/bulk-element-select, so source/criteria/condition stay on the server.
     */
    mode: HyperBulkMode;
};

export type HyperLinkTypeConfig = {
    handle: string;
    label: string;
    tabCount?: number;
    /** Registry FQCN — used for clipboard paste matching. */
    type?: string;
    /** Present when this type opts into bulk creation on a bulk-enabled multi-link field. */
    bulk?: HyperBulkConfig;
};

/** Server-rendered, fully-populated block returned by hyper/fields/create-links. */
export type HyperSeededBlock = {
    handle: string;
    typeHandle?: string;
    label?: string;
    tabCount?: number;
    tabLabels?: string[];
    showHeaderNewWindow?: boolean;
    newWindow?: boolean;
    /** HTML/JS still carry the __LINK_ID__ placeholder — the client mints a unique id. */
    html: string;
    js?: string;
    serialized?: Record<string, unknown>;
};

export type HyperInputSettings = {
    fieldId: number | string | null;
    handle: string;
    /** Owner element site id — Advanced-tab Entries pickers use this. */
    siteId?: number | null;
    defaultLinkType: string;
    defaultNewWindow?: boolean;
    newWindow?: boolean;
    multipleLinks?: boolean;
    minLinks?: number;
    maxLinks?: number;
    /** Resolved gate for the "Bulk Add" action (multi-link + enabled + a bulk-capable type). */
    enableBulkAdd?: boolean;
    /** CP presentation: blocks | card. Legacy expanded/compact/inline → blocks. */
    viewMode?: string;
    isStatic?: boolean;
    placeholderKey: string;
    linkTypes: HyperLinkTypeConfig[];
};

export type HyperInputConfig = {
    initialValue: LinkInstance[];
    settings: HyperInputSettings;
};

export type LinkTypeTemplate = {
    handle: string;
    label: string;
    tabCount?: number;
    html: string;
    js?: string;
};

export type RegisteredLinkType = {
    label: string;
    value: string;
};

export type HyperSettingsConfig = {
    fieldId: number | string | null;
    registeredLinkTypes: RegisteredLinkType[];
    namespacedName: string;
    namespacedId: string;
    linkTypeTemplates: Array<{
        type: string;
        displayName?: string;
        htmlTemplate?: string;
        jsTemplate?: string;
        layoutConfig?: string;
        layoutUid?: string;
    }>;
};
