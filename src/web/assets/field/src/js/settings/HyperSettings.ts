// Import from the granular subpath so the heavy markdown util can never leak into the
// field bundle, even on toolchains with weaker tree-shaking.
import { findUniqueHandle, generateHandle } from '@verbb/plugin-kit-core/utils/string';
import type { HyperSettingsConfig } from '../types';
import { appendBlockJs } from '../input/craftUi';
import { HyperSortableList } from '../utils/sortableList';
import { getName, parseLinkTypeHtml } from '../utils/string';
import { FieldLayoutDesigner } from './FieldLayoutDesigner';

export class HyperSettings {
    private container: HTMLElement;

    private config: HyperSettingsConfig;

    private designers = new Map<string, FieldLayoutDesigner>();

    private handleGenerators = new Map<string, InstanceType<CraftGlobal['HandleGenerator']>>();

    private sortable: HyperSortableList | null = null;

    private selectedHandle: string | null = null;

    private addMenuEl: HTMLElement | null = null;

    private addMenuCleanup: (() => void) | null = null;

    constructor(container: HTMLElement) {
        this.container = container;

        const configRaw = container.getAttribute('data-hyper-settings-config');

        if (!configRaw) {
            throw new Error('[Hyper] Missing settings config.');
        }

        this.config = JSON.parse(configRaw) as HyperSettingsConfig;
    }

    init(): void {
        this.populateAddMenu();
        Craft.initUiElements(this.container);
        this.bindSidebar();
        this.initSortable();
        this.bindAddMenu();
        this.bindDelete();
        this.bindLabelFields();
        this.initDesigners();
        this.container.classList.add('hyper-settings--ready');
    }

    private populateAddMenu(): void {
        const list = this.container.querySelector('[data-hyper-settings-add-options]');

        if (!(list instanceof HTMLElement)) {
            return;
        }

        this.addMenuEl = list.closest('[data-hyper-settings-add-list]');

        list.innerHTML = this.config.registeredLinkTypes.map((type) => {
            const value = this.escapeAttribute(type.value);

            return `<li><a href="#" role="option" class="menu-item" data-hyper-settings-add-type="${value}">${type.label}</a></li>`;
        }).join('');
    }

    private initSortable(): void {
        const sidebar = this.container.querySelector('[data-hyper-settings-sidebar]');

        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        this.sortable = new HyperSortableList({
            container: sidebar,
            itemSelector: '[data-hyper-settings-item]',
            handleSelector: '[data-hyper-settings-drag-handle]',
            group: `hyper-settings-${this.config.fieldId}`,
            itemDraggingClass: 'is-dragging',
            getItemId: (element) => element.dataset.linkTypeHandle ?? 'hyper-settings-item',
            onReorder: () => this.syncSidebarOrder(),
        });

        this.sortable.init();
    }

    private syncSidebarOrder(): void {
        this.container.querySelectorAll('[data-hyper-settings-item]').forEach((item, index) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const sortOrderInput = item.querySelector('[data-hyper-settings-sort-order]');

            if (sortOrderInput instanceof HTMLInputElement) {
                sortOrderInput.value = String(index);
            }
        });

        this.reorderSettingsPanes();
    }

    private reorderSettingsPanes(): void {
        const paneRoot = this.container.querySelector('[data-hyper-settings-pane]');

        if (!(paneRoot instanceof HTMLElement)) {
            return;
        }

        this.container.querySelectorAll('[data-hyper-settings-item]').forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const handle = item.dataset.linkTypeHandle;

            if (!handle) {
                return;
            }

            const pane = this.container.querySelector(
                `[data-hyper-settings-link-pane][data-link-type-handle="${handle}"]`,
            );

            if (pane instanceof HTMLElement) {
                paneRoot.appendChild(pane);
            }
        });
    }

    private bindSidebar(): void {
        const sidebar = this.container.querySelector('[data-hyper-settings-sidebar]');

        sidebar?.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (
                target.closest('[data-hyper-settings-drag-handle]')
                || target.closest('.lightswitch')
                || target.closest('[data-hyper-settings-enabled]')
            ) {
                return;
            }

            const item = target.closest('[data-hyper-settings-item]');

            if (item instanceof HTMLElement) {
                this.selectItem(item);
            }
        });

        sidebar?.addEventListener('keydown', (event) => {
            if (!(event instanceof KeyboardEvent)) {
                return;
            }

            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const item = event.target instanceof HTMLElement
                ? event.target.closest('[data-hyper-settings-item]')
                : null;

            if (item instanceof HTMLElement) {
                event.preventDefault();
                this.selectItem(item);
            }
        });
    }

    private selectItem(item: HTMLElement): void {
        const handle = item.dataset.linkTypeHandle;

        if (!handle) {
            return;
        }

        if (this.selectedHandle && this.selectedHandle !== handle) {
            // Only flush author edits still in the debounce window — never rewrite
            // a pristine layoutConfig from the FLD working input.
            this.designers.get(this.selectedHandle)?.flushPending();
        }

        this.selectedHandle = handle;

        this.container.querySelectorAll('[data-hyper-settings-item]').forEach((node) => {
            node.classList.toggle('sel', node === item);
        });

        this.container.querySelectorAll('[data-hyper-settings-link-pane]').forEach((pane) => {
            if (pane instanceof HTMLElement) {
                pane.classList.toggle('hidden', pane.dataset.linkTypeHandle !== handle);
            }
        });

        this.designers.get(handle)?.load();
    }

    private bindAddMenu(): void {
        if (!this.addMenuEl) {
            return;
        }

        const onMenuClick = (event: Event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            const option = target.closest('[data-hyper-settings-add-type]');

            if (!(option instanceof HTMLElement)) {
                return;
            }

            event.preventDefault();

            const type = option.getAttribute('data-hyper-settings-add-type');

            if (type) {
                this.addLinkType(type);
            }
        };

        // Garnish MenuBtn reparents the menu outside `.hyper-configurator`, so listen on the menu node directly.
        this.addMenuEl.addEventListener('click', onMenuClick);
        this.addMenuCleanup = () => {
            this.addMenuEl?.removeEventListener('click', onMenuClick);
        };
    }

    private escapeAttribute(value: string): string {
        return value
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;');
    }

    private addLinkType(type: string): void {
        const template = this.config.linkTypeTemplates.find((entry) => entry.type === type);

        if (!template?.htmlTemplate) {
            Craft.cp?.displayError?.(Craft.t('hyper', 'Unable to add link type.'));
            return;
        }

        const label = `New ${template.displayName ?? Craft.t('hyper', 'Link Type')}`;

        // The handle is the single identity for a link type (DOM key, namespaced input
        // key, GraphQL/programmatic handle). Derive a clean, unique one from the default
        // label so new rows match saved rows (`linkTypes[<handle>]`) — no throwaway ids.
        // Reuse Plugin Kit's Craft-parity handle helpers instead of a bespoke slugger.
        const handle = findUniqueHandle(generateHandle(label), [...this.linkTypeHandles()]);
        const sidebarItem = this.createSidebarItem(handle, label);
        const pane = this.createLinkTypePane(handle, template);
        const handleField = pane.querySelector('[data-handle-field]');

        // Seed the author-facing handle with the derived value; Craft.HandleGenerator keeps
        // it in sync with later label edits (re-keying the row via the handle listener).
        if (handleField instanceof HTMLInputElement) {
            handleField.value = handle;
            handleField.dataset.generateHandle = '1';
        }

        appendBlockJs(parseLinkTypeHtml(template.jsTemplate, handle));
        Craft.initUiElements(pane);
        this.bindLabelField(pane, sidebarItem);
        this.registerDesigner(handle, pane, template);
        this.sortable?.refresh();
        this.selectItem(sidebarItem);
    }

    /** Current handles across all rows, optionally excluding one (the row being edited). */
    private linkTypeHandles(exclude?: string | null): Set<string> {
        const handles = new Set<string>();

        this.container.querySelectorAll('[data-hyper-settings-item]').forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const handle = item.dataset.linkTypeHandle;

            if (handle && handle !== exclude) {
                handles.add(handle);
            }
        });

        return handles;
    }

    /**
     * Move a row's identity from one handle to another: DOM keys, every namespaced input
     * name, and the designer/handle-generator maps. Keeps the handle as the sole identity
     * without stranding inputs under the previous key.
     */
    private rekeyLinkType(oldHandle: string, newHandle: string): void {
        if (!oldHandle || oldHandle === newHandle) {
            return;
        }

        const nodes = [
            this.container.querySelector(
                `[data-hyper-settings-item][data-link-type-handle="${CSS.escape(oldHandle)}"]`,
            ),
            this.container.querySelector(
                `[data-hyper-settings-link-pane][data-link-type-handle="${CSS.escape(oldHandle)}"]`,
            ),
        ];

        nodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            node.dataset.linkTypeHandle = newHandle;

            node.querySelectorAll('[name]').forEach((input) => {
                if (
                    input instanceof HTMLInputElement
                    || input instanceof HTMLSelectElement
                    || input instanceof HTMLTextAreaElement
                ) {
                    // Handle both bracket styles: SSR `linkTypes[<h>]` and the fully
                    // namespaced `…[linkTypes][<h>]` names the designer builds via getName().
                    input.name = input.name
                        .replace(`[linkTypes][${oldHandle}]`, `[linkTypes][${newHandle}]`)
                        .replace(`linkTypes[${oldHandle}]`, `linkTypes[${newHandle}]`);
                }
            });
        });

        const designer = this.designers.get(oldHandle);

        if (designer) {
            this.designers.delete(oldHandle);
            this.designers.set(newHandle, designer);
        }

        const generator = this.handleGenerators.get(oldHandle);

        if (generator) {
            this.handleGenerators.delete(oldHandle);
            this.handleGenerators.set(newHandle, generator);
        }

        if (this.selectedHandle === oldHandle) {
            this.selectedHandle = newHandle;
        }
    }

    private createSidebarItem(handle: string, label: string): HTMLElement {
        const sidebar = this.container.querySelector('[data-hyper-settings-sidebar]');
        const prototype = this.container.querySelector('[data-hyper-settings-item]');

        if (!(sidebar instanceof HTMLElement) || !(prototype instanceof HTMLElement)) {
            throw new Error('[Hyper] Missing sidebar item prototype.');
        }

        const sortOrder = sidebar.querySelectorAll('[data-hyper-settings-item]').length;
        const item = prototype.cloneNode(true) as HTMLElement;

        item.classList.remove('sel', 'has-errors');
        item.dataset.linkTypeHandle = handle;

        const labelEl = item.querySelector('[data-hyper-settings-label]');

        if (labelEl) {
            labelEl.textContent = label;
        }

        item.querySelectorAll('[name]').forEach((input) => {
            if (input instanceof HTMLInputElement || input instanceof HTMLSelectElement) {
                input.name = input.name.replace(/linkTypes\[[^\]]+\]/, `linkTypes[${handle}]`);
            }
        });

        const enabledInput = item.querySelector('.lightswitch input[type="hidden"]');

        if (enabledInput instanceof HTMLInputElement) {
            enabledInput.value = '1';
        }

        item.querySelector('.lightswitch')?.classList.add('on');

        const sortOrderInput = item.querySelector('[data-hyper-settings-sort-order]');

        if (sortOrderInput instanceof HTMLInputElement) {
            sortOrderInput.value = String(sortOrder);
        }

        sidebar.appendChild(item);
        Craft.initUiElements(item);

        return item;
    }

    private createLinkTypePane(
        handle: string,
        template: HyperSettingsConfig['linkTypeTemplates'][number],
    ): HTMLElement {
        const paneRoot = this.container.querySelector('[data-hyper-settings-pane]');

        if (!(paneRoot instanceof HTMLElement)) {
            throw new Error('[Hyper] Missing settings pane root.');
        }

        const pane = document.createElement('div');
        pane.className = 'hyper-settings-ssr-pane hidden';
        pane.setAttribute('data-hyper-settings-link-pane', '');
        pane.dataset.linkTypeHandle = handle;
        pane.innerHTML = parseLinkTypeHtml(template.htmlTemplate, handle);

        const linkFields = document.createElement('div');
        linkFields.className = 'field';
        linkFields.innerHTML = `
            <div class="heading">
                <label class="required">${Craft.t('hyper', 'Link Fields')}</label>
            </div>
            <div class="instructions">
                <p>${Craft.t('hyper', 'Configure the fields and UI elements available to links.')}</p>
            </div>
            <div class="input ltr"></div>
        `;

        const input = linkFields.querySelector('.input.ltr');

        if (input instanceof HTMLElement) {
            const fldContainer = document.createElement('div');
            fldContainer.className = 'hyper-block-editor-layout';
            fldContainer.setAttribute('data-hyper-fld', '');
            fldContainer.dataset.fieldId = String(this.config.fieldId ?? '');
            fldContainer.dataset.layoutUid = template.layoutUid ?? '';
            fldContainer.dataset.linkType = template.type;
            fldContainer.dataset.layoutConfig = template.layoutConfig ?? '';
            input.appendChild(fldContainer);
        }

        pane.appendChild(linkFields);

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'btn delete icon';
        deleteButton.setAttribute('data-hyper-settings-delete', '');
        deleteButton.textContent = Craft.t('app', 'Delete');
        pane.appendChild(deleteButton);

        paneRoot.appendChild(pane);

        return pane;
    }

    private registerDesigner(
        handle: string,
        pane: HTMLElement,
        template: HyperSettingsConfig['linkTypeTemplates'][number],
    ): void {
        const node = pane.querySelector('[data-hyper-fld]');

        if (!(node instanceof HTMLElement)) {
            return;
        }

        const designer = new FieldLayoutDesigner(node, {
            fieldId: this.config.fieldId,
            layoutUid: node.dataset.layoutUid,
            type: node.dataset.linkType ?? template.type,
            value: node.dataset.layoutConfig ?? '',
            onChange: (value: string) => {
                // Read the live handle so a re-keyed row writes under its current identity.
                const currentHandle = pane.dataset.linkTypeHandle ?? handle;
                const name = getName(this.config.namespacedName, `linkTypes[${currentHandle}][layoutConfig]`);
                const input = pane.querySelector(`input[name="${name}"]`);

                if (input instanceof HTMLInputElement) {
                    input.value = value;
                    return;
                }

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = value;
                pane.appendChild(hidden);
            },
        });

        this.designers.set(handle, designer);
    }

    private bindDelete(): void {
        this.container.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (!target.closest('[data-hyper-settings-delete]')) {
                return;
            }

            const pane = target.closest('[data-hyper-settings-link-pane]');
            const handle = pane instanceof HTMLElement ? pane.dataset.linkTypeHandle : null;
            const sidebarItem = handle
                ? this.container.querySelector(`[data-hyper-settings-item][data-link-type-handle="${handle}"]`)
                : null;
            const label = pane?.querySelector('[data-hyper-settings-label]')?.textContent?.trim()
                ?? sidebarItem?.querySelector('[data-hyper-settings-label]')?.textContent?.trim()
                ?? '';

            if (!confirm(Craft.t('hyper', 'Are you sure you want to delete “{name}”?', { name: label }))) {
                return;
            }

            if (handle) {
                this.designers.delete(handle);
                this.handleGenerators.get(handle)?.destroy();
                this.handleGenerators.delete(handle);

                if (this.selectedHandle === handle) {
                    this.selectedHandle = null;
                }
            }

            sidebarItem?.remove();
            pane?.remove();
            this.syncSidebarOrder();
            this.sortable?.refresh();

            const next = this.container.querySelector('[data-hyper-settings-item]');

            if (next instanceof HTMLElement) {
                this.selectItem(next);
            }
        });
    }

    private bindLabelFields(): void {
        this.container.querySelectorAll('[data-hyper-settings-link-pane]').forEach((pane) => {
            if (!(pane instanceof HTMLElement)) {
                return;
            }

            const handle = pane.dataset.linkTypeHandle;
            const sidebarItem = handle
                ? this.container.querySelector(`[data-hyper-settings-item][data-link-type-handle="${handle}"]`)
                : null;

            if (sidebarItem instanceof HTMLElement) {
                this.bindLabelField(pane, sidebarItem);
            }
        });
    }

    private bindLabelField(pane: HTMLElement, sidebarItem: HTMLElement): void {
        const labelField = pane.querySelector('[data-label-field]');
        const handleField = pane.querySelector('[data-handle-field]');
        const copyButton = pane.querySelector('[data-handle-copy]') as (HTMLElement & { value: string }) | null;
        const sidebarLabel = sidebarItem.querySelector('[data-hyper-settings-label]');

        if (!(labelField instanceof HTMLInputElement) || !(sidebarLabel instanceof HTMLElement)) {
            return;
        }

        labelField.addEventListener('input', () => {
            sidebarLabel.textContent = labelField.value;
        });

        if (!(handleField instanceof HTMLInputElement)) {
            return;
        }

        const defaultSelect = pane.closest('form')?.querySelector(
            'select[name="defaultLinkType"], select[name$="[defaultLinkType]"]',
        );
        let previousHandle = handleField.value;

        const defaultOption = (): HTMLOptionElement | null => {
            if (!(defaultSelect instanceof HTMLSelectElement)) {
                return null;
            }

            return [...defaultSelect.options].find((option) => option.value === previousHandle) ?? null;
        };

        const syncCopyValue = (): void => {
            if (copyButton) {
                copyButton.value = handleField.value;
            }
        };

        // Keep the row's identity in step with the handle field. `enforce` runs on blur to
        // resolve any collision deterministically; while typing we only re-key when the
        // value is already unique, so we never fight the author mid-keystroke. Duplicate
        // handles that slip through are the server's job to reject.
        const applyHandle = (enforce: boolean): void => {
            const current = pane.dataset.linkTypeHandle ?? '';
            const desired = handleField.value;

            syncCopyValue();

            if (!desired || desired === current) {
                const sameOption = defaultOption();

                if (sameOption) {
                    sameOption.value = desired;
                }

                previousHandle = desired || previousHandle;
                return;
            }

            let next = desired;

            if (this.linkTypeHandles(current).has(desired)) {
                if (!enforce) {
                    return;
                }

                next = findUniqueHandle(desired, [...this.linkTypeHandles(current)]);
                handleField.value = next;
                syncCopyValue();
            }

            this.rekeyLinkType(current, next);

            const option = defaultOption();

            if (option) {
                option.value = next;
            }

            previousHandle = next;
        };

        handleField.addEventListener('input', () => applyHandle(false));
        handleField.addEventListener('change', () => applyHandle(true));

        labelField.addEventListener('input', () => {
            const option = defaultOption();

            if (option) {
                option.textContent = labelField.value;
            }

            // Craft's generator sets the handle value without firing `input`, so re-sync on
            // the next tick (after it writes) to keep the row key aligned with the handle.
            window.setTimeout(() => applyHandle(false), 0);
        });

        labelField.addEventListener('change', () => {
            window.setTimeout(() => applyHandle(true), 0);
        });

        syncCopyValue();

        // Match Craft's field edit screen: new custom instances keep their handle in sync
        // with the label (via Craft's generator) until the author edits the handle directly.
        // The seed value is already set in addLinkType(), so we don't force an initial pass.
        if (handleField.dataset.generateHandle === '1') {
            const handle = pane.dataset.linkTypeHandle;
            const generator = new Craft.HandleGenerator(labelField, handleField);

            if (handle) {
                this.handleGenerators.set(handle, generator);
            }
        }
    }

    private initDesigners(): void {
        this.container.querySelectorAll('[data-hyper-fld]').forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            const pane = node.closest('[data-hyper-settings-link-pane]');
            const handle = pane instanceof HTMLElement ? pane.dataset.linkTypeHandle : null;

            if (!handle || !(pane instanceof HTMLElement)) {
                return;
            }

            this.registerDesigner(handle, pane, {
                type: node.dataset.linkType ?? '',
                layoutUid: node.dataset.layoutUid,
                layoutConfig: node.dataset.layoutConfig ?? '',
            });
        });

        const first = this.container.querySelector('[data-hyper-settings-item].sel') as HTMLElement | null;

        if (first) {
            this.selectItem(first);
            return;
        }

        const fallback = this.container.querySelector('[data-hyper-settings-item]') as HTMLElement | null;

        if (fallback) {
            this.selectItem(fallback);
        }
    }
}
