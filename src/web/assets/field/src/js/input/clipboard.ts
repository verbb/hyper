/**
 * Neo-style link clipboard — localStorage JSON, not same-page Move to….
 */

import type { LinkInstance } from '../types';

export const HYPER_LINK_CLIPBOARD_KEY = 'hyper:linkClipboard';

export const HYPER_LINK_CLIPBOARD_VERSION = 1;

export type HyperLinkClipboardPayload = {
    version: number;
    /** Source link type FQCN for paste matching. */
    type: string;
    /** Optional source handle (debug / same-handle preference). */
    linkTypeHandle?: string;
    /** Durable content uid — preserved across cut/paste. */
    uid?: string;
    /** Content without CP-only keys (id, isNew, handle). */
    link: Record<string, unknown>;
    copiedAt: number;
};

const CP_ONLY_KEYS = new Set(['id', 'isNew', 'html', 'js', 'type']);

export function buildClipboardPayload(
    link: LinkInstance,
    typeFqcn: string,
): HyperLinkClipboardPayload {
    const linkCopy: Record<string, unknown> = {};

    Object.entries(link).forEach(([key, value]) => {
        if (CP_ONLY_KEYS.has(key) || key === 'handle') {
            return;
        }

        if (value === undefined || value === null || value === '') {
            return;
        }

        if (Array.isArray(value) && value.length === 0) {
            return;
        }

        if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value as object).length === 0) {
            return;
        }

        linkCopy[key] = value;
    });

    const uid = typeof link.uid === 'string' && link.uid
        ? link.uid
        : (typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : Craft.randomString(16));

    linkCopy.uid = uid;

    return {
        version: HYPER_LINK_CLIPBOARD_VERSION,
        type: typeFqcn,
        linkTypeHandle: (link.linkTypeHandle ?? link.handle) as string | undefined,
        uid,
        link: linkCopy,
        copiedAt: Date.now(),
    };
}

export function writeClipboard(payload: HyperLinkClipboardPayload): boolean {
    try {
        localStorage.setItem(HYPER_LINK_CLIPBOARD_KEY, JSON.stringify(payload));
        window.dispatchEvent(new CustomEvent('hyper:clipboard-change'));

        return true;
    } catch {
        return false;
    }
}

export function readClipboard(): HyperLinkClipboardPayload | null {
    try {
        const raw = localStorage.getItem(HYPER_LINK_CLIPBOARD_KEY);

        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as HyperLinkClipboardPayload;

        if (!parsed || parsed.version !== HYPER_LINK_CLIPBOARD_VERSION || !parsed.type || !parsed.link) {
            return null;
        }

        return parsed;
    } catch {
        return null;
    }
}

export function clearClipboard(): void {
    try {
        localStorage.removeItem(HYPER_LINK_CLIPBOARD_KEY);
        window.dispatchEvent(new CustomEvent('hyper:clipboard-change'));
    } catch {
        // ignore
    }
}

/**
 * Pick a destination link-type handle that can accept the clipboard type.
 * Prefer matching handle when present and enabled, else first enabled FQCN match.
 */
export function resolvePasteHandle(
    clipboard: HyperLinkClipboardPayload,
    linkTypes: Array<{ handle: string; type?: string }>,
): string | null {
    if (clipboard.linkTypeHandle) {
        const sameHandle = linkTypes.find(
            (type) => type.handle === clipboard.linkTypeHandle && type.type === clipboard.type,
        );

        if (sameHandle) {
            return sameHandle.handle;
        }
    }

    const byClass = linkTypes.find((type) => type.type === clipboard.type);

    return byClass?.handle ?? null;
}
