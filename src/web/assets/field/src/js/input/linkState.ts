import type { LinkInstance } from '../types';
import { mergeLinksWithBlockContent } from './blockContent';

/**
 * In-memory link content for one Hyper field instance (layer 2 in hyper-cp-field-architecture.md).
 * Seeded from server initialValue; portal DOM merges update this model before store projection.
 */
export class HyperLinkState {
    private values: LinkInstance[];

    private readonly serializationReference: LinkInstance[];

    constructor(initialValue: LinkInstance[]) {
        this.values = structuredClone(initialValue);
        this.serializationReference = structuredClone(initialValue);
    }

    getModel(): LinkInstance[] {
        return this.values;
    }

    getSerializationReference(): LinkInstance[] {
        return this.serializationReference;
    }

    /** Merge portal hyperData inputs into the model for each mounted block. */
    applyPortalContent(linksRoot: HTMLElement): void {
        this.values = mergeLinksWithBlockContent(linksRoot, this.values);
    }

    insertAt(index: number, link: LinkInstance): void {
        this.values.splice(index, 0, link);
    }

    removeAt(index: number): void {
        if (index >= 0 && index < this.values.length) {
            this.values.splice(index, 1);
        }
    }

    move(fromIndex: number, toIndex: number): void {
        if (
            fromIndex < 0
            || toIndex < 0
            || fromIndex >= this.values.length
            || toIndex >= this.values.length
        ) {
            return;
        }

        const [link] = this.values.splice(fromIndex, 1);
        this.values.splice(toIndex, 0, link);
    }

    createLinkStub(linkId: string, handle: string, newWindow: boolean): LinkInstance {
        const uid = typeof crypto !== 'undefined' && 'randomUUID' in crypto
            ? crypto.randomUUID()
            : Craft.randomString(16);

        return {
            id: linkId,
            handle,
            linkTypeHandle: handle,
            uid,
            isNew: true,
            newWindow,
        };
    }

    /** Replace the model row after paste seeding. */
    replaceAt(index: number, link: LinkInstance): void {
        if (index < 0 || index >= this.values.length) {
            return;
        }

        this.values[index] = link;
    }

    /**
     * Replace a row for a link-type switch: keep shared attrs, drop type-specific values.
     * Call before remounting the portal so the next merge cannot revive the old linkValue.
     */
    prepareTypeChange(
        index: number,
        handle: string,
        preserved: Partial<LinkInstance>,
    ): void {
        if (index < 0 || index >= this.values.length) {
            return;
        }

        const current = this.values[index];

        this.values[index] = {
            id: current.id,
            handle,
            linkTypeHandle: handle,
            isNew: current.isNew,
            newWindow: current.newWindow ?? false,
            ...preserved,
        };
    }
}
