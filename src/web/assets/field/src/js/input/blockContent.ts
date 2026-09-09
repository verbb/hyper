/**
 * Portal → model merge helpers (layer 1 → layer 2).
 * See `.cursor/docs/hyper-cp-field-architecture.md`.
 */
import { merge } from 'lodash-es';

import type { LinkInstance } from '../types';
import { normalizePortalBlockContent } from './serialize';

/** Native attrs shared across link types — keep these when the type select remounts. */
const COMPATIBLE_LINK_ATTRS = [
    'linkText',
    'linkTitle',
    'ariaLabel',
    'classes',
    'urlSuffix',
    'customAttributes',
    'newWindow',
] as const;

// Portal link-type fields render under PHP namespace `hyperData[{linkId}]`, then Craft's
// `namespaceInputs('fields')` pass wraps them as `fields[hyperData][{linkId}][...]`.
const getHyperDataRoot = (content: Record<string, unknown>): Record<string, unknown> | undefined => {
    const fields = content.fields;

    if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
        const hyperData = (fields as Record<string, unknown>).hyperData;

        if (hyperData && typeof hyperData === 'object') {
            return hyperData as Record<string, unknown>;
        }
    }

    const hyperData = content.hyperData;

    if (hyperData && typeof hyperData === 'object') {
        return hyperData as Record<string, unknown>;
    }

    return undefined;
};

/**
 * Snapshot attrs that survive a link-type switch. Type-specific `linkValue` / `fields` are dropped.
 */
export function pickCompatibleLinkAttrs(link: LinkInstance): Partial<LinkInstance> {
    const preserved: Partial<LinkInstance> = {};

    for (const key of COMPATIBLE_LINK_ATTRS) {
        const value = link[key];

        if (value === undefined || value === null || value === '') {
            continue;
        }

        if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value as object).length === 0) {
            continue;
        }

        (preserved as Record<string, unknown>)[key] = value;
    }

    return preserved;
}

/**
 * Write preserved native attrs back into blank type-template inputs after a type switch.
 * Empty template inputs would otherwise wipe prior values on the next portal merge.
 */
export function applyPreservedAttrsToPortal(
    portalEl: HTMLElement,
    preserved: Partial<LinkInstance>,
): void {
    for (const [key, value] of Object.entries(preserved)) {
        if (value === undefined || value === null) {
            continue;
        }

        if (key === 'customAttributes' && typeof value === 'object') {
            // Complex widget — model still carries the value via merge if we patch after restore;
            // scalar native fields are restored into inputs below.
            continue;
        }

        // Craft lightswitch (New Window layout field) — toggle class + hidden input.
        if (typeof value === 'boolean') {
            setPortalLightswitch(portalEl, key, value);
            continue;
        }

        if (typeof value !== 'string' && typeof value !== 'number') {
            continue;
        }

        const input = portalEl.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
            `input[name$="[${key}]"], textarea[name$="[${key}]"], select[name$="[${key}]"]`,
        );

        if (input) {
            input.value = String(value);
        }
    }
}

/** Apply on/off state to a Craft lightswitch inside the portal (e.g. New Window layout field). */
export function setPortalLightswitch(portalEl: HTMLElement, attribute: string, on: boolean): void {
    const input = portalEl.querySelector<HTMLInputElement>(
        `button.lightswitch input[type="hidden"][name$="[${attribute}]"], .lightswitch input[type="hidden"][name$="[${attribute}]"]`,
    );

    if (!input) {
        return;
    }

    const switchEl = input.closest('button.lightswitch, .lightswitch');
    const onValue = switchEl?.getAttribute('data-value') || '1';

    input.value = on ? onValue : '';

    if (switchEl instanceof HTMLElement) {
        switchEl.classList.toggle('on', on);
        switchEl.setAttribute('aria-checked', on ? 'true' : 'false');
    }
}

export function mergeLinkWithBlockContent(
    link: LinkInstance,
    blockEl: HTMLElement | null,
): LinkInstance {
    if (!blockEl || link.id === undefined || link.id === null) {
        return link;
    }

    const portalEl = blockEl.querySelector('[data-hyper-portal]');

    if (!(portalEl instanceof HTMLElement)) {
        return link;
    }

    const postData = Garnish.getPostData(portalEl);
    const content = Craft.expandPostArray(postData) as Record<string, unknown>;
    const linkId = String(link.id);
    const hyperDataRoot = getHyperDataRoot(content);
    const rawBlockContent = { ...(hyperDataRoot?.[linkId] as Record<string, unknown> | undefined ?? {}) };

    delete rawBlockContent.handle;
    delete rawBlockContent.id;

    // Lightswitch posts '' when off — coerce so false persists (not dropped as empty).
    if ('newWindow' in rawBlockContent) {
        const value = rawBlockContent.newWindow;
        rawBlockContent.newWindow = value === true || value === 1 || value === '1';
    }

    const blockContent = normalizePortalBlockContent(rawBlockContent, link);

    // Replace semantics for portal-owned keys. Lodash deep-merge retains cleared
    // custom fields and trailing array entries (Astra H3-A01).
    const next: LinkInstance = { ...link, ...blockContent } as LinkInstance;

    if ('fields' in blockContent) {
        next.fields = (blockContent.fields ?? {}) as LinkInstance['fields'];
    }

    if ('customAttributes' in blockContent) {
        next.customAttributes = blockContent.customAttributes as LinkInstance['customAttributes'];
    }

    return next;
}

export function mergeLinksWithBlockContent(
    container: HTMLElement,
    reference: LinkInstance[] = [],
): LinkInstance[] {
    const links: LinkInstance[] = [];

    container.querySelectorAll('[data-hyper-link]').forEach((blockEl, index) => {
        if (!(blockEl instanceof HTMLElement)) {
            return;
        }

        const meta = readLinkMeta(blockEl);
        const refLink = reference.find((item) => String(item.id) === String(meta.id))
            ?? reference[index]
            ?? null;
        const baseLink = refLink
            ? merge({}, refLink, meta) as LinkInstance
            : meta;

        // Header controls (new window, type handle) live outside the hyperData portal namespace.
        links.push(merge({}, mergeLinkWithBlockContent(baseLink, blockEl), meta) as LinkInstance);
    });

    return links;
}

/** Header icon is the source of truth only while visible; otherwise portal lightswitch wins. */
function isHeaderNewWindowVisible(toggle: HTMLElement): boolean {
    return !toggle.hasAttribute('hidden') && toggle.getAttribute('aria-hidden') !== 'true';
}

export function readLinkMeta(blockEl: HTMLElement): LinkInstance {
    const toggle = blockEl.querySelector('[data-hyper-new-window-switch]');

    const meta: LinkInstance = {
        id: blockEl.dataset.linkId ?? '',
        handle: blockEl.dataset.linkHandle ?? '',
    };

    // Omit newWindow when the header icon is hidden so portal merge is not overwritten with false.
    if (toggle instanceof HTMLElement && isHeaderNewWindowVisible(toggle)) {
        meta.newWindow = toggle.classList.contains('is-active')
            || toggle.getAttribute('aria-pressed') === 'true'
            || toggle.classList.contains('on');
    }

    return meta;
}
