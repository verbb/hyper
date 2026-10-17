import { debounce } from 'lodash-es';
import type { HyperInputSettings, LinkInstance } from '../types';
import { initBlockCraftUi } from './craftUi';
import { destroyEmbedWidgets } from './embed';

type LayoutTab = { uid: string; label: string; elements?: Array<{ uid: string; html: string | boolean; static: boolean }> };
type LayoutState = { conditional: boolean; tabs: LayoutTab[] };

export function readLayoutState(portal: HTMLElement): LayoutState | null {
    const template = portal.querySelector(':scope > template[data-hyper-layout-state]');
    if (!(template instanceof HTMLTemplateElement)) return null;
    try { return JSON.parse(template.content.textContent || 'null') as LayoutState | null; }
    catch { return null; }
}

/** Refresh only changed layout elements, preserving focused inputs and nested widget instances. */
export class ConditionalLayout {
    private version = 0;
    private destroyed = false;
    private lastValue: string | null = null;
    private refreshDebounced = debounce(() => { void this.refresh(); }, 250);

    constructor(
        private portal: HTMLElement,
        private settings: HyperInputSettings,
        private linkId: string,
        private getValue: () => LinkInstance,
        private syncTabs: (labels: string[], selected: number) => void,
    ) {}

    schedule(): void {
        this.version++;
        this.refreshDebounced();
    }

    destroy(): void {
        this.destroyed = true;
        this.version++;
        this.refreshDebounced.cancel();
    }

    private async refresh(): Promise<void> {
        if (this.destroyed || !this.portal.isConnected) return;
        const value = this.getValue();
        const serialized = JSON.stringify(value);
        if (serialized === this.lastValue) return;
        const version = this.version;
        const visibleElements: Record<string, string[]> = {};
        const staticElements: Record<string, string[]> = {};
        for (const tab of this.portal.querySelectorAll<HTMLElement>(':scope > [data-layout-tab]')) {
            const uid = tab.dataset.layoutTab || '';
            const fields = [...tab.querySelectorAll<HTMLElement>(':scope > [data-layout-element]:not([data-layout-element-placeholder])')];
            visibleElements[uid] = fields.map(field => field.dataset.layoutElement || '');
            staticElements[uid] = fields.filter(field => field.hasAttribute('data-static')).map(field => field.dataset.layoutElement || '');
        }
        const prefix = this.settings.inputNamePrefix;
        const namespace = `${prefix ? `${prefix}[hyperData]` : 'hyperData'}[${this.linkId}]`;
        try {
            const response = await Craft.sendActionRequest('POST', 'hyper/fields/link-field-layout', { data: {
                fieldId: this.settings.fieldId, siteId: this.settings.siteId,
                elementId: this.settings.elementId, inputContext: this.settings.inputContext,
                data: value, namespace, visibleElements, staticElements,
            } });
            if (this.destroyed || !this.portal.isConnected || version !== this.version || serialized !== JSON.stringify(this.getValue())) return;
            this.lastValue = serialized;
            const tabs = response.data.tabs as LayoutTab[];
            if (!Array.isArray(tabs)) throw new Error('Invalid field layout response');
            const previousTab = this.portal.querySelector<HTMLElement>(':scope > [data-layout-tab]:not(.hidden)')?.dataset.layoutTab;
            const newRoots: HTMLElement[] = [];
            for (const pane of this.portal.querySelectorAll<HTMLElement>(':scope > [data-layout-tab]')) {
                if (!tabs.some(tab => tab.uid === pane.dataset.layoutTab)) {
                    destroyEmbedWidgets(pane);
                    pane.remove();
                }
            }
            for (const [index, tab] of tabs.entries()) {
                let pane = [...this.portal.children].find(node => (node as HTMLElement).dataset.layoutTab === tab.uid) as HTMLElement | undefined;
                if (!pane) {
                    pane = document.createElement('div');
                    pane.className = 'flex-fields hidden';
                    pane.dataset.layoutTab = tab.uid;
                }
                // Keep pane order aligned with the header when a middle tab appears.
                const position = this.portal.querySelectorAll(':scope > [data-layout-tab]')[index];
                if (position !== pane) this.portal.insertBefore(pane, position || null);
                for (const field of tab.elements || []) {
                    const old = [...pane.children].find(node => (node as HTMLElement).dataset.layoutElement === field.uid) as HTMLElement | undefined;
                    if (field.html === true) continue;
                    if (field.html === false && old?.hasAttribute('data-layout-element-placeholder')) continue;
                    const template = document.createElement('template');
                    if (typeof field.html === 'string') {
                        template.innerHTML = field.html;
                    } else {
                        const placeholder = document.createElement('div');
                        placeholder.className = 'hidden';
                        placeholder.dataset.layoutElement = field.uid;
                        placeholder.dataset.layoutElementPlaceholder = '';
                        template.content.append(placeholder);
                    }
                    newRoots.push(...[...template.content.children].filter((node): node is HTMLElement => node instanceof HTMLElement));
                    if (old) {
                        destroyEmbedWidgets(old);
                        old.replaceWith(template.content);
                    } else pane.append(template.content);
                }
            }
            // Retain the selected tab without briefly hiding its focused input.
            const selected = Math.max(0, tabs.findIndex(tab => tab.uid === previousTab));
            this.syncTabs(tabs.map(tab => tab.label), selected);
            if (response.data.headHtml) Craft.appendHeadHtml(String(response.data.headHtml));
            for (const root of newRoots) initBlockCraftUi(root);
            if (response.data.bodyHtml) Craft.appendBodyHtml(String(response.data.bodyHtml));
        } catch {
            if (this.destroyed || version !== this.version) return;
            this.lastValue = null;
            Craft.cp.displayError(Craft.t('hyper', 'Couldn’t update conditional link fields. Edit the link again to retry.'));
        }
    }
}
