import { isEqual } from 'lodash-es';

import type { LinkInstance } from '../types';

const isConvertibleNumber = (value: string): boolean => /^[0-9]+(\.[0-9]+)?$/.test(value);

const isEmptyObject = (obj: unknown): obj is Record<string, never> => (
    typeof obj === 'object' && obj !== null && !Array.isArray(obj) && Object.keys(obj).length === 0
);

export const normalizeJson = (data: unknown, reference: unknown = null): unknown => {
    if (Array.isArray(data)) {
        return data.map((item, index) => {
            if (typeof item === 'string' && isConvertibleNumber(item)) {
                return Number(item);
            }

            const refItem = Array.isArray(reference) ? reference[index] : undefined;

            return normalizeJson(item, refItem);
        });
    }

    if (data && typeof data === 'object') {
        const normalized: Record<string, unknown> = {};

        for (const [key, value] of Object.entries(data as Record<string, unknown>)) {
            const refValue = reference && typeof reference === 'object' && !Array.isArray(reference)
                ? (reference as Record<string, unknown>)[key]
                : undefined;

            if (isEmptyObject(value)) {
                normalized[key] = [];
            } else if (value === '' && (refValue === null || refValue === undefined)) {
                normalized[key] = null;
            } else if ((value === null || value === '') && Array.isArray(refValue)) {
                normalized[key] = [];
            } else if (Array.isArray(value)) {
                normalized[key] = normalizeJson(value, refValue);
            } else if (typeof value === 'string' && isConvertibleNumber(value)) {
                normalized[key] = Number(value);
            } else {
                normalized[key] = normalizeJson(value, refValue);
            }
        }

        return normalized;
    }

    return data;
};

const pruneEmptyFieldValues = (fields: unknown): Record<string, unknown> | undefined => {
    if (!fields || typeof fields !== 'object' || Array.isArray(fields)) {
        return undefined;
    }

    const pruned: Record<string, unknown> = {};

    Object.entries(fields as Record<string, unknown>).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) {
            return;
        }

        if (Array.isArray(value) && value.length === 0) {
            return;
        }

        pruned[key] = value;
    });

    return Object.keys(pruned).length > 0 ? pruned : undefined;
};

export const normalizePortalBlockContent = (
    blockContent: Record<string, unknown>,
    reference: Record<string, unknown>,
): Record<string, unknown> => {
    const normalized: Record<string, unknown> = { ...blockContent };

    Object.entries(normalized).forEach(([key, value]) => {
        if (value === '' && (reference[key] === null || reference[key] === undefined)) {
            normalized[key] = null;
        }
    });

    if ('fields' in normalized) {
        const prunedFields = pruneEmptyFieldValues(normalized.fields);

        if (prunedFields) {
            normalized.fields = prunedFields;
        } else {
            delete normalized.fields;
        }
    }

    return normalized;
};

const CP_ONLY_LINK_KEYS = new Set([
    'id',
    'isNew',
    'type',
    'html',
    'js',
    'handle',
]);

const isEmptyStoredValue = (value: unknown): boolean => (
    value === null
    || value === undefined
    || value === ''
    || (Array.isArray(value) && value.length === 0)
);

/** Deep-compare projected store payloads — avoids spurious hidden-input writes on key-order drift. */
export const storePayloadsEqual = (left: string, right: string): boolean => {
    if (left === right) {
        return true;
    }

    try {
        return isEqual(JSON.parse(left), JSON.parse(right));
    } catch {
        return false;
    }
};

export const serializeLinksForStore = (
    links: LinkInstance[],
    reference: LinkInstance[] | null = null,
): unknown[] => links.map((link, index) => {
    const stored: Record<string, unknown> = {};
    const linkTypeHandle = (link.linkTypeHandle ?? link.handle) as string | undefined;

    if (linkTypeHandle) {
        stored.linkTypeHandle = linkTypeHandle;
    }

    Object.entries(link).forEach(([key, value]) => {
        if (CP_ONLY_LINK_KEYS.has(key) || key === 'linkTypeHandle') {
            return;
        }

        if (isEmptyStoredValue(value)) {
            return;
        }

        stored[key] = value;
    });

    const refItem = reference?.[index] ?? null;
    const normalized = normalizeJson(stored, refItem) as Record<string, unknown>;

    ['customAttributes', 'fields'].forEach((key) => {
        const value = normalized[key];

        if (Array.isArray(value) && value.length === 0) {
            delete normalized[key];
        }

        if (
            key === 'fields'
            && value
            && typeof value === 'object'
            && !Array.isArray(value)
            && Object.keys(value as Record<string, unknown>).length === 0
        ) {
            delete normalized[key];
        }
    });

    return normalized;
});
