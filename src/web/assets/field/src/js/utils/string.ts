export const getId = (prefix = ''): string => `${prefix}${Craft.randomString(10)}`;

export const namespaceString = (ns: string, name: string): string => {
    const normalized = name.replace(/]/g, '').split('[').join('][');

    return `${ns}[${normalized}]`;
};

export const getName = (namespacedName: string, name: string): string => {
    // Field settings: `fields[handle][__PREFIX__]` → nest under `fields[handle]`.
    // Link Type Config (no Craft input namespace): bare `__PREFIX__` → use `name` as-is.
    const base = namespacedName
        .replace('[__PREFIX__]', '')
        .replace(/__PREFIX__/g, '');

    if (!base) {
        return name;
    }

    return namespaceString(base, name);
};

export const parseLinkTypeHtml = (html: string | undefined, handle: string): string => {
    if (typeof html !== 'string') {
        return '';
    }

    return html.replace(/__LINK_TYPE__/g, handle);
};

export const parseLinkIdHtml = (html: string | undefined, linkId: string): string => {
    if (typeof html !== 'string') {
        return '';
    }

    return html.replace(/__LINK_ID__/g, linkId);
};

export const decodeHtmlEntities = (html: string): string => {
    return html.replace(/&#(\d+);/g, (_match, dec) => String.fromCharCode(Number(dec)));
};
