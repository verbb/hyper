/**
 * Node assertions for portal serialize/merge (Astra H3-A01).
 * Run: npx --yes tsx tests/Frontend/serialize-a01.mts
 */
import { merge } from 'lodash-es';
import { mergeLinkWithBlockContent } from '../../src/web/assets/field/src/js/input/blockContent.ts';
import {
    normalizePortalBlockContent,
    serializeLinksForStore,
} from '../../src/web/assets/field/src/js/input/serialize.ts';

class Portal {}
(globalThis as any).HTMLElement = Portal;
(globalThis as any).Garnish = { getPostData: () => ({}) };

let posted: Record<string, unknown> = {};
(globalThis as any).Craft = {
    expandPostArray: () => ({ fields: { hyperData: { row: posted } } }),
};

const block: any = { querySelector: () => new Portal() };
const initial: any = {
    id: 'row',
    linkTypeHandle: 'url',
    linkValue: 'https://example.test',
    fields: { label: 'old', related: [11, 22] },
    customAttributes: [
        { attribute: 'data-a', value: 'A' },
        { attribute: 'data-b', value: 'B' },
    ],
};

function assert(condition: boolean, message: string): void {
    if (!condition) {
        throw new Error(message);
    }
}

posted = { fields: { label: '', related: [] }, customAttributes: [] };
const cleared = mergeLinkWithBlockContent(initial, block);
const clearedStore = serializeLinksForStore([cleared]) as any[];

assert(!clearedStore[0].fields?.label, 'cleared label must not restore');
assert(!clearedStore[0].fields?.related?.length, 'cleared related must not restore');
assert(!clearedStore[0].customAttributes, 'cleared customAttributes must not restore');

posted = { fields: { related: [11] } };
const shortened = mergeLinkWithBlockContent(initial, block) as any;
assert(
    JSON.stringify(shortened.fields?.related) === JSON.stringify([11]),
    'array replace must drop trailing entries',
);

const numeric = serializeLinksForStore([{
    id: 'p',
    handle: 'phone',
    linkTypeHandle: 'phone',
    linkValue: '001234567890',
    linkText: '0012',
    fields: { code: '00123', large: '9007199254740993' },
}]) as any[];

assert(numeric[0].linkValue === '001234567890', 'phone must stay string');
assert(numeric[0].linkText === '0012', 'linkText must stay string');
assert(numeric[0].fields.code === '00123', 'custom code must stay string');
assert(numeric[0].fields.large === '9007199254740993', 'large id must stay string');

// Lodash merge baseline still demonstrates why we avoid it for fields
const lodashMerged = merge({}, initial.fields, { related: [11] });
assert(JSON.stringify(lodashMerged.related) === JSON.stringify([11, 22]), 'lodash merge still retains trailing');

const portal = normalizePortalBlockContent({ fields: { label: '' } }, initial);
assert(portal.fields && (portal.fields as any).label === '', 'empty label kept pre-merge');

console.log(JSON.stringify({ ok: true, probes: ['clear', 'array-replace', 'numeric-strings'] }));
