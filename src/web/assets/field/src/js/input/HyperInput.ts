import type { HyperBulkConfig, HyperInputConfig, HyperLinkTypeConfig, HyperSeededBlock, LinkInstance } from '../types';
import { getId, parseLinkIdHtml } from '../utils/string';
import {
    ensureElementEditorSerializeHook,
    enqueueHyperFieldInit,
    pauseElementEditor,
    resumeElementEditor,
} from './elementEditor';
import { serializeLinksForStore, storePayloadsEqual } from './serialize';
import { initMenuBtn } from './craftUi';
import { HyperLinkBlock } from './HyperLinkBlock';
import { HyperLinkState } from './linkState';
import { registerHyperInputSync } from './registry';
import { HyperSortableList } from '../utils/sortableList';
import { pickCompatibleLinkAttrs } from './blockContent';
import {
    buildClipboardPayload,
    readClipboard,
    resolvePasteHandle,
    writeClipboard,
} from './clipboard';

export class HyperInput {
    private container: HTMLElement;

    private config: HyperInputConfig;

    private storeInput: HTMLInputElement | null = null;

    private fieldRoot: HTMLElement | null = null;

    private linksRoot: HTMLElement | null = null;

    private templatesRoot: HTMLElement | null = null;

    private blocks: HyperLinkBlock[] = [];

    /** Layer 2: in-memory LinkInstance[] — source of truth for store projection. */
    private linkState: HyperLinkState;

    /** SSR hidden-store JSON — preserved byte-for-byte until content actually changes. */
    private initialStoreValue = '[]';

    private lastProjectedStoreValue: string | null = null;

    /** False during batched form init — blocks portal/Garnish noise from writing the hidden store. */
    private storeWritesEnabled = false;

    /**
     * True after a real author edit on this field (portal, add/delete/reorder/type/paste).
     * Untouched fields must no-op on serializeForm force-flush — portal projection often
     * drifts from SSR (Category selects, newWindow:false) and would mark siblings dirty.
     */
    private contentTouched = false;

    private sortable: HyperSortableList | null = null;

    private unregisterSubmitSync: (() => void) | null = null;

    private initialized = false;

    private clipboardChangeHandler: (() => void) | null = null;

    private storageHandler: ((event: StorageEvent) => void) | null = null;

    constructor(container: HTMLElement) {
        this.container = container;

        const configRaw = container.getAttribute('data-hyper-input-config');

        if (!configRaw) {
            throw new Error('[Hyper] Missing input config.');
        }

        this.config = JSON.parse(configRaw) as HyperInputConfig;
        this.linkState = new HyperLinkState(this.config.initialValue ?? []);
    }

    init(): void {
        const fieldRoot = this.container.closest('.hyper-input-component') ?? this.container.parentElement;

        if (!(fieldRoot instanceof HTMLElement)) {
            return;
        }

        this.fieldRoot = fieldRoot;
        this.storeInput = fieldRoot.querySelector('[data-hyper-store]');
        this.linksRoot = this.container.querySelector('[data-hyper-links]');
        this.templatesRoot = this.container.querySelector('[data-hyper-link-templates]');

        if (!this.linksRoot || !this.storeInput) {
            return;
        }

        this.initialStoreValue = this.storeInput.value;
        ensureElementEditorSerializeHook(fieldRoot);

        void enqueueHyperFieldInit(fieldRoot, async () => {
            // Mount blocks + Craft widgets while ElementEditor is paused. Do not start portal
            // watches here — element selects (Category, etc.) mutate hyperData during init; a
            // deferred MutationObserver sync after resume was rewriting the hidden store and
            // creating provisional drafts on every load.
            this.initializeInterface(fieldRoot);
        }).then(() => {
            // Batch already settled + FormObserver._serialize()'d while paused, then resumed.
            // Enable store writes and portal watches only after that adopt — leave SSR store bytes alone.
            this.storeWritesEnabled = true;
            this.blocks.forEach((block) => block.startPortalWatch());
        }).catch(() => {
            // Still allow store writes if init coordination fails (e.g. no ElementEditor).
            this.storeWritesEnabled = true;
            this.blocks.forEach((block) => block.startPortalWatch());
        });
    }

    private get settings() {
        return this.config.settings;
    }

    private mountExistingBlocks(): void {
        this.linksRoot?.querySelectorAll('[data-hyper-link]').forEach((el, index) => {
            if (el instanceof HTMLElement) {
                this.mountBlock(el, index);
            }
        });
    }

    private mountBlock(el: HTMLElement, index: number): HyperLinkBlock {
        el.dataset.linkIndex = String(index);

        const block = new HyperLinkBlock(el, {
            settings: this.settings,
            onChange: () => {
                this.markContentTouched();
                this.syncStore();
            },
            onDelete: () => this.deleteBlock(block),
            onMoveUp: () => this.moveBlock(block, -1),
            onMoveDown: () => this.moveBlock(block, 1),
            onCopy: () => this.copyBlock(block),
            onCut: () => this.cutBlock(block),
            onPaste: () => this.pasteLink(),
        });

        this.blocks.push(block);

        return block;
    }

    private initSortable(): void {
        if (!this.linksRoot || !this.settings.multipleLinks) {
            return;
        }

        this.sortable = new HyperSortableList({
            container: this.linksRoot,
            itemSelector: '[data-hyper-link]',
            handleSelector: '[data-hyper-drag-handle]',
            group: `hyper-links-${this.settings.fieldId}`,
            containerDraggingClass: 'hyper-dragging',
            itemDraggingClass: 'hyper-link--dragging',
            getItemId: (element) => element.dataset.linkId ?? element.dataset.linkHandle ?? 'hyper-link',
            onReorder: (fromIndex, toIndex) => this.reorderBlocks(fromIndex, toIndex),
            // Suspend Craft's draft autosave for the duration of the drag: dnd-kit reorders the
            // DOM optimistically mid-drag, which the FormObserver would otherwise treat as a
            // change and provisionally save before the user has dropped.
            onDragStart: () => this.pauseAutosaveForDrag(),
            onDragEnd: () => this.resumeAutosaveAfterDrag(),
        });

        this.sortable.init();
    }

    private pauseAutosaveForDrag(): void {
        if (this.fieldRoot) {
            // Fire-and-forget: pause() resolves once any in-flight save settles; we don't block the drag.
            void pauseElementEditor(this.fieldRoot);
        }
    }

    private resumeAutosaveAfterDrag(): void {
        if (this.fieldRoot) {
            // reorderBlocks() has already run syncStore() by now, so resuming lets Craft
            // detect the single committed change and autosave once.
            resumeElementEditor(this.fieldRoot);
        }
    }

    private reorderBlocks(fromIndex: number, toIndex: number): void {
        if (fromIndex < 0 || toIndex < 0 || fromIndex >= this.blocks.length || toIndex >= this.blocks.length) {
            return;
        }

        this.markContentTouched();
        const [block] = this.blocks.splice(fromIndex, 1);
        this.blocks.splice(toIndex, 0, block);
        this.linkState.move(fromIndex, toIndex);
        this.reindexBlocks();
        this.syncStore(true);
    }

    private bindAddLink(): void {
        const addRoot = this.container.querySelector('[data-hyper-add-link]');

        if (!addRoot) {
            return;
        }

        addRoot.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.closest('pk-dropdown-menu')) {
                return;
            }

            if (target.closest('[data-hyper-action="paste"]')) {
                event.preventDefault();
                this.pasteLink();
                return;
            }

            if (target.closest('[data-hyper-bulk-add-open]')) {
                event.preventDefault();
                this.openBulkAdd();
                return;
            }

            const handle = target.closest('[data-hyper-add-type]')?.getAttribute('data-hyper-add-type');

            if (handle) {
                event.preventDefault();
                this.requestAddLink(handle);
            }
        });

        addRoot.addEventListener('pk-select', (event) => {
            if (!(event instanceof CustomEvent)) {
                return;
            }

            const value = event.detail?.value as string | undefined;

            if (value === '__paste__') {
                this.pasteLink();
                return;
            }

            if (value === '__bulk-add__') {
                this.openBulkAdd();
                return;
            }

            if (value) {
                this.requestAddLink(value);
            }
        });

        const trigger = addRoot.querySelector('[data-hyper-add-trigger]');

        if (trigger instanceof HTMLElement && !trigger.closest('pk-dropdown-menu')) {
            initMenuBtn(trigger);
        }

        this.refreshPasteUi();
        this.clipboardChangeHandler = () => this.refreshPasteUi();
        this.storageHandler = (event: StorageEvent) => {
            if (event.key === 'hyper:linkClipboard') {
                this.refreshPasteUi();
            }
        };
        window.addEventListener('hyper:clipboard-change', this.clipboardChangeHandler);
        window.addEventListener('storage', this.storageHandler);
    }

    /** Tear down listeners when the field root is removed from the CP (Astra H3-A16). */
    destroy(): void {
        this.unregisterSubmitSync?.();
        this.unregisterSubmitSync = null;

        if (this.clipboardChangeHandler) {
            window.removeEventListener('hyper:clipboard-change', this.clipboardChangeHandler);
            this.clipboardChangeHandler = null;
        }

        if (this.storageHandler) {
            window.removeEventListener('storage', this.storageHandler);
            this.storageHandler = null;
        }

        this.sortable?.destroy();
        this.sortable = null;

        this.blocks.forEach((block) => block.destroy());
        this.blocks = [];
        this.initialized = false;
    }

    private bindTypeChanges(): void {
        this.container.addEventListener('hyper:change-type', (event) => {
            if (!(event instanceof CustomEvent)) {
                return;
            }

            const blockEl = event.target;

            if (!(blockEl instanceof HTMLElement)) {
                return;
            }

            const handle = event.detail?.handle as string | undefined;

            if (!handle || !this.templatesRoot) {
                return;
            }

            const linkId = blockEl.dataset.linkId ?? getId();
            const template = HyperLinkBlock.getTemplateHtml(this.templatesRoot, handle, linkId);

            if (!template) {
                return;
            }

            const block = this.blocks.find((item) => item.el === blockEl);

            if (!block) {
                return;
            }

            // Capture portal + header state before the blank type template remounts.
            const previous = block.getLinkData();
            const index = this.blocks.indexOf(block);
            this.linkState.prepareTypeChange(index, handle, pickCompatibleLinkAttrs(previous));
            block.changeType(
                handle,
                template.html,
                template.js,
                previous,
                template.tabLabels,
                template.showHeaderNewWindow,
            );
            this.updateEmptyChrome();
        });
    }

    /** Clicking a link type in the Add menu always adds a single blank row). */
    private requestAddLink(handle: string): void {
        this.addLink(handle);
    }

    /** Remaining capacity before hitting maxLinks, or null when unbounded. */
    private remainingCapacity(): number | null {
        return this.settings.maxLinks
            ? Math.max(0, this.settings.maxLinks - this.blocks.length)
            : null;
    }

    /** Enabled link types that opted into bulk creation. */
    private bulkAddTypes(): BulkTypeOption[] {
        return this.settings.linkTypes
            .filter((type): type is typeof type & { bulk: HyperBulkConfig } => !!type.bulk)
            .map((type) => ({ handle: type.handle, label: type.label, bulk: type.bulk }));
    }

    /**
     * Bulk Add entry point. A single dynamic pk-dialog: a Link Type chooser (shown only
     * when more than one type opts in) drives a body that swaps between a one-per-line textarea
     * (text types) and a native element picker (element types). pk-dialog yields to Craft's
     * Garnish element-selector modal so it can stack above, keeping this one flicker-free dialog.
     */
    private openBulkAdd(): void {
        if (this.remainingCapacity() === 0) {
            Craft.cp.displayError(Craft.t('hyper', 'This field cannot accept another link.'));
            return;
        }

        const types = this.bulkAddTypes();

        if (!types.length) {
            return;
        }

        const dialog = buildBulkAddDialog({
            // Mount inside the field (deep in #content), never on document.body. The CP <body> is
            // both a `flex-flow: row nowrap` flex container and a `container-type: inline-size`
            // query container; injecting the dialog host there perturbs its layout and trips
            // Craft's responsive sidebar @media boundary (sidebar collapses). pk-dialog is built
            // to live in place — its :host is `position:absolute; 0x0` so it can't disturb the
            // field — and the native <dialog> still renders in the top layer regardless of parent.
            mount: this.fieldRoot ?? this.container,
            types,
            remaining: this.remainingCapacity(),
            loadElementSelect: (handle, limit) => this.loadBulkElementSelect(handle, limit),
            commit: (handle, mode, params) => this.createSeededBlocks(handle, mode, params),
        });

        dialog.open();
    }

    /**
     * Fetch a server-rendered native element select (chips) for an element bulk type.
     *
     * Rendering server-side (vs. a hand-rolled preview) is what gives real element chips/cards
     * with the type's own source/criteria/condition applied. `bodyHtml` carries the
     * `Craft.BaseElementSelectInput` init JS, which the dialog runs against the mounted markup.
     */
    private async loadBulkElementSelect(
        handle: string,
        limit: number | null,
    ): Promise<{ html: string; headHtml?: string; bodyHtml?: string }> {
        const response = await Craft.sendActionRequest('POST', 'hyper/fields/bulk-element-select', {
            data: {
                fieldId: this.settings.fieldId,
                siteId: this.settings.siteId,
                elementId: this.settings.elementId ?? undefined,
                handle,
                limit,
            },
        });

        return {
            html: (response?.data?.html ?? '') as string,
            headHtml: response?.data?.headHtml as string | undefined,
            bodyHtml: response?.data?.bodyHtml as string | undefined,
        };
    }

    /**
     * Ask the server to render fully-populated blocks for a bulk selection, then mount them.
     * Server rendering (vs. cloning the blank template) is what makes element cards / custom
     * fields come back populated instead of blank.
     */
    private async createSeededBlocks(
        handle: string,
        mode: 'elements' | 'text',
        params: Record<string, unknown>,
    ): Promise<void> {
        try {
            const response = await Craft.sendActionRequest('POST', 'hyper/fields/create-links', {
                data: {
                    fieldId: this.settings.fieldId,
                    siteId: this.settings.siteId,
                    elementId: this.settings.elementId ?? undefined,
                    handle,
                    mode,
                    ...params,
                },
            });

            const blocks = (response?.data?.blocks ?? []) as HyperSeededBlock[];
            const headHtml = response?.data?.headHtml as string | undefined;
            const bodyHtml = response?.data?.bodyHtml as string | undefined;

            // Load any newly-referenced element-select assets before mounting the blocks.
            if (headHtml) {
                Craft.appendHeadHtml(headHtml);
            }

            if (bodyHtml) {
                Craft.appendBodyHtml(bodyHtml);
            }

            blocks.forEach((block) => {
                this.addLink(
                    block.handle,
                    this.seedFromServerBlock(block),
                    block,
                );
            });

            if (blocks.length) {
                Craft.cp.displayNotice(Craft.t('hyper', '{num} links added.', { num: String(blocks.length) }));
            }
        } catch (error) {
            Craft.cp.displayError(Craft.t('hyper', 'Couldn’t add links.'));
        }
    }

    /** Build the store-stub seed from a server block's serialized content. */
    private seedFromServerBlock(block: HyperSeededBlock): Record<string, unknown> {
        return {
            ...(block.serialized ?? {}),
            newWindow: block.newWindow ?? this.settings.defaultNewWindow ?? false,
        };
    }

    private addLink(
        handle: string,
        seed?: Record<string, unknown>,
        serverBlock?: HyperSeededBlock,
    ): void {
        if (!this.linksRoot || !this.templatesRoot) {
            return;
        }

        if (this.settings.maxLinks && this.blocks.length >= this.settings.maxLinks) {
            return;
        }

        this.markContentTouched();

        // Single-link fields replace the existing row when pasting.
        if (!this.settings.multipleLinks && this.blocks.length > 0) {
            this.deleteBlock(this.blocks[0]);
        }

        const linkId = getId();

        // Bulk / paste flows pass a server-rendered block (populated element cards, custom
        // fields, etc.); everything else clones the blank client template.
        const template = serverBlock
            ? {
                html: parseLinkIdHtml(serverBlock.html, linkId),
                js: serverBlock.js ? parseLinkIdHtml(serverBlock.js, linkId) : undefined,
                tabCount: serverBlock.tabCount ?? (serverBlock.tabLabels?.length ?? 0),
                tabLabels: serverBlock.tabLabels ?? [],
                label: serverBlock.label ?? handle,
                showHeaderNewWindow: serverBlock.showHeaderNewWindow ?? true,
            }
            : HyperLinkBlock.getTemplateHtml(this.templatesRoot, handle, linkId);

        if (!template) {
            return;
        }

        const prototype = this.linksRoot.querySelector('[data-hyper-link]');
        const chromeTemplate = this.container.querySelector('template[data-hyper-link-chrome]');
        let blockEl: HTMLElement;

        if (prototype instanceof HTMLElement) {
            blockEl = prototype.cloneNode(true) as HTMLElement;
        } else if (chromeTemplate instanceof HTMLTemplateElement) {
            const chrome = chromeTemplate.content.querySelector('[data-hyper-link]');
            blockEl = chrome
                ? chrome.cloneNode(true) as HTMLElement
                : document.createElement('div');
        } else {
            blockEl = document.createElement('div');
            blockEl.className = 'hyper-link';
            blockEl.innerHTML = `
                <div class="hyper-wrapper">
                    <div class="hyper-header">
                        <div class="hyper-header-type">
                            <span class="hyper-header-type-btn is-static" data-hyper-type-label>
                                <span data-hyper-type-label-text></span>
                            </span>
                        </div>
                        <div class="hyper-header-actions-tabs">
                            <div class="hyper-header-actions"></div>
                        </div>
                    </div>
                    <div class="hyper-body-wrapper" data-hyper-body>
                        <div data-hyper-portal="${linkId}-${handle}"></div>
                    </div>
                </div>
            `;
        }

        blockEl.dataset.linkId = linkId;
        blockEl.dataset.linkHandle = handle;
        blockEl.dataset.linkJs = template.js ?? '';
        blockEl.setAttribute('data-hyper-link', '');

        // Legacy select chrome
        const typeSelect = blockEl.querySelector('[data-hyper-link-type]');

        if (typeSelect instanceof HTMLSelectElement) {
            typeSelect.innerHTML = this.settings.linkTypes.map((type) => (
                `<option value="${type.handle}"${type.handle === handle ? ' selected' : ''}>${type.label}</option>`
            )).join('');
        }

        const portal = blockEl.querySelector('[data-hyper-portal]');

        if (portal instanceof HTMLElement) {
            portal.dataset.hyperPortal = `${linkId}-${handle}`;
            portal.innerHTML = template.html;
        }

        this.linksRoot.appendChild(blockEl);
        const blockIndex = this.blocks.length;
        const newWindow = typeof seed?.newWindow === 'boolean'
            ? seed.newWindow
            : (this.settings.defaultNewWindow ?? false);
        HyperLinkBlock.applyNewWindowDefault(blockEl, newWindow);
        const block = this.mountBlock(blockEl, blockIndex);
        this.reindexBlocks();
        block.syncTypeLabel(handle);
        block.syncLayoutTabs(template.tabLabels);
        block.syncHeaderNewWindow(template.showHeaderNewWindow);
        block.startPortalWatch();

        const stub = this.linkState.createLinkStub(
            linkId,
            handle,
            newWindow,
        );

        if (seed) {
            Object.entries(seed).forEach(([key, value]) => {
                if (key === 'id' || key === 'handle' || key === 'linkTypeHandle' || key === 'isNew') {
                    return;
                }

                if (value !== undefined) {
                    stub[key] = value;
                }
            });

            stub.handle = handle;
            stub.linkTypeHandle = handle;
            stub.isNew = true;
        }

        this.linkState.insertAt(blockIndex, stub);

        // Server-rendered blocks already have populated portal inputs — only poke inputs when
        // seeding a blank client template (clipboard paste).
        if (seed && !serverBlock && portal instanceof HTMLElement) {
            this.applyLinkSeedToPortal(portal, seed);
        }

        this.sortable?.refresh();
        this.syncStore(true);
        this.updateEmptyChrome();
        this.refreshPasteUi();
    }

    /** Apply clipboard / bulk-add scalar values into portal inputs. */
    private applyLinkSeedToPortal(
        portal: HTMLElement,
        seed: Record<string, unknown>,
    ): void {
        const scalarKeys = [
            'linkValue',
            'linkText',
            'linkTitle',
            'ariaLabel',
            'classes',
            'urlSuffix',
            'linkSiteId',
        ];

        for (const key of scalarKeys) {
            const value = seed[key];

            if (value === undefined || value === null) {
                continue;
            }

            const input = portal.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
                `input[name$="[${key}]"], input[name$="[${key}][]"], textarea[name$="[${key}]"], select[name$="[${key}]"]`,
            );

            if (input) {
                const writeValue = Array.isArray(value) ? String(value[0] ?? '') : String(value);
                input.value = writeValue;
            }
        }

        const fields = seed.fields;

        if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
            Object.entries(fields as Record<string, unknown>).forEach(([handle, value]) => {
                if (value === undefined || value === null || typeof value === 'object') {
                    return;
                }

                const input = portal.querySelector<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(
                    `input[name$="[fields][${handle}]"], textarea[name$="[fields][${handle}]"], select[name$="[fields][${handle}]"]`,
                );

                if (input) {
                    input.value = String(value);
                }
            });
        }
    }

    private deleteBlock(block: HyperLinkBlock): void {
        const index = this.blocks.indexOf(block);

        if (index < 0) {
            return;
        }

        this.markContentTouched();
        block.destroy();
        block.el.remove();
        this.blocks = this.blocks.filter((item) => item !== block);
        this.linkState.removeAt(index);
        this.reindexBlocks();
        this.sortable?.refresh();
        this.syncStore(true);
        this.updateEmptyChrome();
        this.refreshPasteUi();
    }

    private copyBlock(block: HyperLinkBlock, options: { silent?: boolean; preserveUid?: boolean } = {}): boolean {
        if (this.linksRoot) {
            this.linkState.applyPortalContent(this.linksRoot);
        }

        const index = this.blocks.indexOf(block);
        const link = this.linkState.getModel()[index] ?? block.getLinkData();
        const typeConfig = this.resolveLinkTypeConfig(link, block);

        if (!typeConfig?.type) {
            // Copy failed to resolve the registry class — not a paste destination problem.
            Craft.cp.displayError(Craft.t('hyper', 'Couldn’t copy this link.'));
            return false;
        }

        // Clipboard always stores the field’s canonical handle + FQCN so paste matching is stable,
        // even when the in-memory row still carries a legacy linkTypeHandle.
        const clipboardLink: LinkInstance = {
            ...link,
            handle: typeConfig.handle,
            linkTypeHandle: typeConfig.handle,
        };

        if (!writeClipboard(buildClipboardPayload(clipboardLink, typeConfig.type, {
            preserveUid: !!options.preserveUid,
        }))) {
            Craft.cp.displayError(Craft.t('app', 'A server error occurred.'));
            return false;
        }

        if (!options.silent) {
            Craft.cp.displayNotice(Craft.t('hyper', 'Link copied.'));
        }

        this.refreshPasteUi();

        return true;
    }

    /**
     * Map a block/model row to an enabled link-type config (FQCN + handle).
     * Prefer the live DOM handle (canonical CP identity), then linkTypeHandle / handle on the model.
     */
    private resolveLinkTypeConfig(
        link: LinkInstance,
        block: HyperLinkBlock,
    ): HyperLinkTypeConfig | null {
        const candidates = [
            block.el.dataset.linkHandle,
            typeof link.linkTypeHandle === 'string' ? link.linkTypeHandle : null,
            typeof link.handle === 'string' ? link.handle : null,
        ].filter((value): value is string => !!value);

        for (const handle of candidates) {
            const match = this.settings.linkTypes.find((type) => type.handle === handle);

            if (match?.type) {
                return match;
            }
        }

        // Last resort: single enabled type of a matching FQCN is not available here without type
        // on the model — fail closed so copy does not write a broken clipboard payload.
        return null;
    }

    private cutBlock(block: HyperLinkBlock): void {
        if (!this.copyBlock(block, { silent: true, preserveUid: true })) {
            return;
        }

        this.deleteBlock(block);
        Craft.cp.displayNotice(Craft.t('hyper', 'Link cut.'));
    }

    private async pasteLink(): Promise<void> {
        const clipboard = readClipboard();

        if (!clipboard) {
            Craft.cp.displayError(Craft.t('hyper', 'Nothing to paste.'));
            return;
        }

        const handle = resolvePasteHandle(clipboard, this.settings.linkTypes);

        if (!handle) {
            Craft.cp.displayError(Craft.t('hyper', 'This field cannot accept the copied link type.'));
            return;
        }

        if (
            this.settings.multipleLinks
            && this.settings.maxLinks
            && this.blocks.length >= this.settings.maxLinks
        ) {
            Craft.cp.displayError(Craft.t('hyper', 'This field cannot accept another link.'));
            return;
        }

        // Server-render populated widgets (same path as bulk add) — blank templates cannot
        // hydrate element cards / complex custom fields (Astra H3-A12).
        try {
            const response = await Craft.sendActionRequest('POST', 'hyper/fields/create-links', {
                data: {
                    fieldId: this.settings.fieldId,
                    siteId: this.settings.siteId,
                    elementId: this.settings.elementId ?? undefined,
                    handle,
                    mode: 'seed',
                    seeds: [clipboard.link],
                },
            });

            const blocks = (response?.data?.blocks ?? []) as HyperSeededBlock[];
            const headHtml = response?.data?.headHtml as string | undefined;
            const bodyHtml = response?.data?.bodyHtml as string | undefined;

            if (!blocks.length) {
                Craft.cp.displayError(Craft.t('hyper', 'Couldn’t paste link.'));
                return;
            }

            if (headHtml) {
                Craft.appendHeadHtml(headHtml);
            }

            if (bodyHtml) {
                Craft.appendBodyHtml(bodyHtml);
            }

            blocks.forEach((block) => {
                this.addLink(block.handle, this.seedFromServerBlock(block), block);
            });

            Craft.cp.displayNotice(Craft.t('hyper', 'Link pasted.'));
        } catch {
            Craft.cp.displayError(Craft.t('hyper', 'Couldn’t paste link.'));
        }
    }

    /** Show/hide paste affordances from localStorage clipboard state. */
    private refreshPasteUi(): void {
        const clipboard = readClipboard();
        const canPaste = !!clipboard && !!resolvePasteHandle(clipboard, this.settings.linkTypes);
        const atMax = !!(
            this.settings.multipleLinks
            && this.settings.maxLinks
            && this.blocks.length >= this.settings.maxLinks
        );
        const pasteEnabled = canPaste && !atMax;

        this.container.querySelectorAll('[data-hyper-paste-item]').forEach((el) => {
            if (!(el instanceof HTMLElement)) {
                return;
            }

            if (el.classList.contains('h-paste-link-btn')) {
                el.hidden = !pasteEnabled;
            } else {
                // Dropdown paste actions should not occupy a row when there is no
                // compatible clipboard payload. Keep them disabled at field capacity.
                el.hidden = !canPaste;
            }

            el.toggleAttribute('disabled', !pasteEnabled);
            el.classList.toggle('disabled', !pasteEnabled);
        });

        this.container.querySelectorAll<HTMLElement>('[data-hyper-paste-separator]')
            .forEach((el) => {
                el.hidden = !canPaste;
            });
    }

    private moveBlock(block: HyperLinkBlock, direction: number): void {
        const index = this.blocks.indexOf(block);
        const targetIndex = index + direction;

        if (index < 0 || targetIndex < 0 || targetIndex >= this.blocks.length || !this.linksRoot) {
            return;
        }

        const target = this.blocks[targetIndex];
        const reference = direction < 0 ? target.el : target.el.nextElementSibling;

        this.linksRoot.insertBefore(block.el, reference);
        this.markContentTouched();
        this.blocks.splice(index, 1);
        this.blocks.splice(targetIndex, 0, block);
        this.linkState.move(index, targetIndex);
        this.reindexBlocks();
        this.sortable?.refresh();
        this.syncStore(true);
    }

    private reindexBlocks(): void {
        this.blocks.forEach((block, index) => {
            block.index = index;
            block.syncMoveActions(index, this.blocks.length);
        });
    }

    /** Layer 3: project in-memory link state into Craft's hidden store input. */
    private syncStore(force = false): void {
        if (!this.storeInput || (!this.storeWritesEnabled && !force)) {
            return;
        }

        // serializeForm / jQuery serializer flush every Hyper field. Untouched fields must
        // not run applyPortalContent — projection drift (element selects, newWindow:false)
        // rewrites siblings and Craft marks them changed (e.g. Cut on field A dirties B).
        if (!this.contentTouched) {
            if (!storePayloadsEqual(this.storeInput.value, this.initialStoreValue)) {
                this.storeInput.value = this.initialStoreValue;
            }

            return;
        }

        if (this.linksRoot) {
            this.linkState.applyPortalContent(this.linksRoot);
        }

        const links = this.linkState.getModel();
        const serialized = JSON.stringify(serializeLinksForStore(
            links,
            this.linkState.getSerializationReference(),
        ));

        if (this.lastProjectedStoreValue === serialized) {
            // force=true still rewrites the hidden input when it drifted (e.g. before serializeForm).
            if (!force || storePayloadsEqual(this.storeInput.value, serialized)) {
                return;
            }
        }

        this.lastProjectedStoreValue = serialized;

        if (storePayloadsEqual(this.storeInput.value, serialized)) {
            return;
        }

        this.storeInput.value = serialized;

        const debug = this.container.closest('.hyper-input-component')?.querySelector('[data-store-debug]');

        if (debug) {
            debug.textContent = serialized;
        }
    }

    private markContentTouched(): void {
        this.contentTouched = true;
    }

    private initializeInterface(fieldRoot: HTMLElement): void {
        if (this.initialized) {
            return;
        }

        this.initialized = true;

        this.mountExistingBlocks();
        this.reindexBlocks();
        this.initSortable();
        this.bindAddLink();
        this.bindTypeChanges();
        this.updateEmptyChrome();
        this.unregisterSubmitSync = registerHyperInputSync(() => {
            this.syncStore(true);
        });
        this.container.classList.add('hyper-input--ready');
        fieldRoot.classList.add('hyper-input--ready');
        // Portal watches start after ElementEditor baseline adopt (see init()) — Craft widget
        // init mutates hyperData and must not mark the entry dirty.
    }

    /**
     * Toggle empty chrome: Add CTA visible when there are no links (single or multi),
     * or when multi-link is under maxLinks. Hide Add once a single-link field has a row.
     */
    private updateEmptyChrome(): void {
        const isEmpty = this.blocks.length === 0;
        this.container.classList.toggle('hyper-input--empty', isEmpty);

        const addRoot = this.container.querySelector('[data-hyper-add-link]');

        if (!(addRoot instanceof HTMLElement)) {
            return;
        }

        if (this.settings.isStatic) {
            addRoot.hidden = true;
            return;
        }

        if (this.settings.multipleLinks) {
            const atMax = !!(this.settings.maxLinks && this.blocks.length >= this.settings.maxLinks);
            addRoot.hidden = atMax;
            return;
        }

        // Single-link: Add only while empty.
        addRoot.hidden = !isEmpty;
        this.refreshPasteUi();
    }
}

/** A bulk-capable link type paired with its collection config. */
type BulkTypeOption = { handle: string; label: string; bulk: HyperBulkConfig };

/** pk-dialog exposes `open` as a reactive property that drives show/close. */
type PkDialogElement = HTMLElement & { open: boolean };

/**
 * Single dynamic Bulk Add dialog. Built on `pk-dialog` (not Garnish.Modal) for two
 * reasons: it renders the built-in Plugin Kit header, and it automatically demotes from
 * `showModal()` to `show()` while a Craft Garnish element-selector is open so that picker can
 * stack above. One persistent dialog whose body swaps by link-type mode also removes the
 * chooser→modal hop (two overlapping Garnish fades) that caused the open flicker.
 */
function buildBulkAddDialog(options: {
    mount: HTMLElement;
    types: BulkTypeOption[];
    remaining: number | null;
    loadElementSelect: (
        handle: string,
        limit: number | null,
    ) => Promise<{ html: string; headHtml?: string; bodyHtml?: string }>;
    commit: (
        handle: string,
        mode: 'elements' | 'text',
        params: Record<string, unknown>,
    ) => Promise<void> | void;
}): { open: () => void } {
    const { types, remaining } = options;

    const dialog = document.createElement('pk-dialog') as PkDialogElement;
    dialog.setAttribute('label', Craft.t('hyper', 'Bulk Add'));

    let currentHandle = types[0].handle;
    let textarea: HTMLTextAreaElement | null = null;

    // Element mode mounts Craft's native element select; we read the chosen chips straight off it.
    let elementContainer: HTMLElement | null = null;
    // Bumps per render so a slow fetch from a superseded Link Type can't clobber the current body.
    let elementLoadToken = 0;
    // MutationObservers watching the chip list — torn down on re-render/close to avoid leaks.
    const observers: MutationObserver[] = [];

    const disconnectObservers = (): void => {
        observers.forEach((observer) => observer.disconnect());
        observers.length = 0;
    };

    // Selected element ids (+ site) read from the native select's chips (`.elements > li > .element`).
    const readSelectedElements = (): { id: number; siteId?: number }[] => {
        if (!elementContainer) {
            return [];
        }

        const seen = new Set<number>();
        const out: { id: number; siteId?: number }[] = [];

        elementContainer.querySelectorAll<HTMLElement>('.elements li > .element[data-id]').forEach((node) => {
            const id = Number(node.getAttribute('data-id'));

            if (!id || seen.has(id)) {
                return;
            }

            seen.add(id);
            const siteAttr = node.getAttribute('data-site-id');
            out.push({ id, siteId: siteAttr ? Number(siteAttr) : undefined });
        });

        return out;
    };

    const body = document.createElement('div');
    body.className = 'hyper-bulk-dialog';

    // Link Type chooser only earns its place when more than one type opts in.
    if (types.length > 1) {
        const typeField = document.createElement('div');
        typeField.className = 'field';
        typeField.innerHTML = `
            <div class="heading"><label>${escapeAttr(Craft.t('hyper', 'Link Type'))}</label></div>
            <div class="input ltr"><div class="select fullwidth"><select data-hyper-bulk-type></select></div></div>
        `;
        const select = typeField.querySelector<HTMLSelectElement>('[data-hyper-bulk-type]');

        if (select) {
            select.innerHTML = types
                .map((type) => `<option value="${escapeAttr(type.handle)}">${escapeAttr(type.label)}</option>`)
                .join('');
            select.addEventListener('change', () => {
                currentHandle = select.value;
                renderRegion();
            });
        }

        body.appendChild(typeField);
    }

    const region = document.createElement('div');
    region.className = 'hyper-bulk-region';
    body.appendChild(region);
    dialog.appendChild(body);

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn';
    cancelBtn.setAttribute('slot', 'footer');
    cancelBtn.textContent = Craft.t('app', 'Cancel');

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn submit';
    addBtn.setAttribute('slot', 'footer');
    addBtn.textContent = Craft.t('app', 'Add');

    dialog.appendChild(cancelBtn);
    dialog.appendChild(addBtn);

    const close = (): void => {
        dialog.open = false;
    };

    cancelBtn.addEventListener('click', close);

    // Tear the dialog out of the DOM once its close animation reports done.
    dialog.addEventListener('pk-open-change', (event) => {
        if (event instanceof CustomEvent && event.detail?.open === false) {
            disconnectObservers();
            window.setTimeout(() => dialog.remove(), 0);
        }
    });

    const currentType = (): BulkTypeOption => types.find((type) => type.handle === currentHandle) ?? types[0];

    const cap = (values: unknown[]): number =>
        (remaining === null ? values.length : Math.min(values.length, remaining));

    const updateAddState = (): void => {
        const type = currentType();
        let ready: boolean;

        if (type.bulk.mode === 'text') {
            ready = !!textarea && textarea.value.split(/\r\n|\r|\n/).some((line) => line.trim() !== '');
        } else {
            ready = readSelectedElements().length > 0;
        }

        addBtn.disabled = !ready;
        addBtn.classList.toggle('disabled', !ready);
    };

    function renderRegion(): void {
        // Reset per-mode state; element observers must be dropped before the DOM is replaced.
        disconnectObservers();
        elementContainer = null;
        region.innerHTML = '';
        textarea = null;
        const type = currentType();

        if (type.bulk.mode === 'text') {
            const wrap = document.createElement('div');
            wrap.className = 'field';
            wrap.innerHTML = `<div class="instructions"><p>${escapeAttr(Craft.t('hyper', 'Enter one value per line.'))}</p></div>`;
            textarea = document.createElement('textarea');
            textarea.className = 'text fullwidth';
            textarea.rows = 8;
            textarea.addEventListener('input', updateAddState);
            wrap.appendChild(textarea);
            region.appendChild(wrap);
            window.setTimeout(() => textarea?.focus(), 50);
            updateAddState();
            return;
        }

        // Element mode: mount Craft's real element select (native chips). Fetch server-side so
        // its add button opens the native picker and the type's source/criteria/condition apply.
        const wrap = document.createElement('div');
        wrap.className = 'field';
        wrap.innerHTML = '<div class="spinner"></div>';
        region.appendChild(wrap);

        const token = ++elementLoadToken;

        void options.loadElementSelect(type.handle, remaining)
            .then((payload) => {
                // A newer render (Link Type switch / re-open) superseded this response.
                if (token !== elementLoadToken) {
                    return;
                }

                wrap.innerHTML = payload.html;

                if (payload.headHtml) {
                    Craft.appendHeadHtml(payload.headHtml);
                }

                if (payload.bodyHtml) {
                    // Runs `new Craft.BaseElementSelectInput(...)` against the markup just mounted.
                    Craft.appendBodyHtml(payload.bodyHtml);
                }

                elementContainer = wrap.querySelector<HTMLElement>('.elementselect');

                // Chip add/remove mutate the list; recompute the Add button on every change.
                const list = elementContainer?.querySelector('.elements');

                if (list) {
                    const observer = new MutationObserver(updateAddState);
                    observer.observe(list, { childList: true, subtree: true });
                    observers.push(observer);
                }

                updateAddState();
            })
            .catch(() => {
                if (token === elementLoadToken) {
                    wrap.innerHTML = `<p class="error">${escapeAttr(Craft.t('hyper', 'Couldn’t add links.'))}</p>`;
                }
            });

        updateAddState();
    }

    addBtn.addEventListener('click', () => {
        const type = currentType();

        if (type.bulk.mode === 'text') {
            const lines = (textarea?.value ?? '')
                .split(/\r\n|\r|\n/)
                .map((line) => line.trim())
                .filter((line) => line !== '');
            const values = lines.slice(0, cap(lines));

            if (!values.length) {
                return;
            }

            close();
            void options.commit(type.handle, 'text', { values });
            return;
        }

        // The native select already enforces `limit`, but cap defensively against remaining.
        const selected = readSelectedElements().slice(0, cap(readSelectedElements()));

        if (!selected.length) {
            return;
        }

        close();
        void options.commit(type.handle, 'elements', { elements: selected });
    });

    renderRegion();

    return {
        open() {
            options.mount.appendChild(dialog);
            // Flip `open` after the element upgrades + connects so the very first frame is the
            // fade-in, not a painted-then-hidden panel.
            window.requestAnimationFrame(() => {
                dialog.open = true;
            });
        },
    };
}

function escapeAttr(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
