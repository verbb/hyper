import { debounce } from 'lodash-es';

import type { HyperInputSettings } from '../types';
import { appendBlockJs, initBlockCraftUi, initMenuBtn } from './craftUi';
import {
    applyPreservedAttrsToPortal,
    mergeLinkWithBlockContent,
    pickCompatibleLinkAttrs,
    readLinkMeta,
    setPortalLightswitch,
} from './blockContent';
import type { LinkInstance } from '../types';
import { decodeHtmlEntities, parseLinkIdHtml } from '../utils/string';

type HyperLinkBlockOptions = {
    settings: HyperInputSettings;
    onChange: () => void;
    onDelete: () => void;
    onMoveUp: () => void;
    onMoveDown: () => void;
    onCopy: () => void;
    onCut: () => void;
    onPaste: () => void;
};

export class HyperLinkBlock {
    readonly el: HTMLElement;

    private settings: HyperInputSettings;

    private onChange: () => void;

    private onDelete: () => void;

    private onMoveUp: () => void;

    private onMoveDown: () => void;

    private onCopy: () => void;

    private onCut: () => void;

    private onPaste: () => void;

    private menuBtn: Garnish.MenuBtn | null = null;

    private emitChangeDebounced: ReturnType<typeof debounce>;

    private headerAbort = new AbortController();

    private portalObserver: MutationObserver | null = null;

    private portalWatchEnabled = false;

    /** True while toggling layout tabs — pane `.hidden` must not sync the store / dirty EE. */
    private suppressPortalSync = false;

    constructor(el: HTMLElement, options: HyperLinkBlockOptions) {
        this.el = el;
        this.settings = options.settings;
        this.onChange = options.onChange;
        this.onDelete = options.onDelete;
        this.onMoveUp = options.onMoveUp;
        this.onMoveDown = options.onMoveDown;
        this.onCopy = options.onCopy;
        this.onCut = options.onCut;
        this.onPaste = options.onPaste;
        this.emitChangeDebounced = debounce(() => this.onChange(), 50);

        this.bindEvents();
        this.initMenu();
        // Init native Craft widgets before deferred {% js %} runs (matches FieldLayoutDesigner).
        initBlockCraftUi(this.el);
        this.appendInitialJs();
    }

    /** Attach portal listeners after Craft widget init so init mutations do not sync the store. */
    startPortalWatch(): void {
        if (this.portalWatchEnabled) {
            return;
        }

        this.portalWatchEnabled = true;
        this.watchPortal();
    }

    get index(): number {
        return Number(this.el.dataset.linkIndex ?? 0);
    }

    set index(value: number) {
        this.el.dataset.linkIndex = String(value);
    }

    getLinkData() {
        return mergeLinkWithBlockContent(readLinkMeta(this.el), this.el);
    }

    /**
     * Remount the portal for a new link type. Pass `previous` so shared attrs (e.g. linkText)
     * survive the blank type template.
     */
    changeType(
        handle: string,
        bodyHtml: string,
        js?: string,
        previous?: LinkInstance | null,
        tabLabels: string[] = [],
        showHeaderNewWindow = true,
    ): void {
        this.el.dataset.linkHandle = handle;

        const portal = this.el.querySelector('[data-hyper-portal]');
        const preserved = previous ? pickCompatibleLinkAttrs(previous) : {};

        if (portal instanceof HTMLElement) {
            portal.dataset.hyperPortal = `${this.el.dataset.linkId}-${handle}`;
            portal.innerHTML = bodyHtml;
            initBlockCraftUi(portal);
            applyPreservedAttrsToPortal(portal, preserved);
        }

        this.syncTypeLabel(handle);
        this.syncLayoutTabs(tabLabels);
        this.syncHeaderNewWindow(showHeaderNewWindow);

        if (js) {
            this.el.dataset.linkJs = js;
            appendBlockJs(js);
        }

        this.onChange();
    }

    /** Update Vizy-style type title after a type switch. */
    syncTypeLabel(handle: string): void {
        const label = this.settings.linkTypes.find((type) => type.handle === handle)?.label ?? handle;
        const text = this.el.querySelector('[data-hyper-type-label-text]');

        if (text instanceof HTMLElement) {
            text.textContent = label;
        }

        this.el.querySelectorAll('[data-hyper-change-type]').forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            const current = item.getAttribute('data-hyper-change-type') === handle;
            if (current) {
                item.setAttribute('aria-current', 'true');
            } else {
                item.removeAttribute('aria-current');
            }
        });
    }

    /**
     * Rebuild Vizy-style header tabs when the link type (and its layout) changes.
     */
    syncLayoutTabs(tabLabels: string[]): void {
        const header = this.el.querySelector('.hyper-header');

        if (!(header instanceof HTMLElement)) {
            return;
        }

        let actionsTabs = header.querySelector('.hyper-header-actions-tabs');

        if (!(actionsTabs instanceof HTMLElement)) {
            actionsTabs = document.createElement('div');
            actionsTabs.className = 'hyper-header-actions-tabs';
            const actions = header.querySelector('.hyper-header-actions');

            if (actions) {
                header.appendChild(actionsTabs);
                actionsTabs.appendChild(actions);
            } else {
                header.appendChild(actionsTabs);
            }
        }

        let tabsRoot = actionsTabs.querySelector('[data-hyper-layout-tabs]');
        let tabsMenu = actionsTabs.querySelector('[data-hyper-layout-tabs-menu]');

        if (tabLabels.length < 2) {
            tabsRoot?.remove();
            tabsMenu?.remove();
            this.selectTab(0);

            return;
        }

        if (!(tabsRoot instanceof HTMLElement)) {
            tabsRoot = document.createElement('div');
            tabsRoot.className = 'hyper-header-tabs';
            tabsRoot.setAttribute('data-hyper-layout-tabs', '');
            tabsRoot.setAttribute('role', 'tablist');
            const actions = actionsTabs.querySelector('.hyper-header-actions');

            if (actions) {
                actionsTabs.insertBefore(tabsRoot, actions);
            } else {
                actionsTabs.appendChild(tabsRoot);
            }
        }

        tabsRoot.innerHTML = tabLabels.map((label, index) => (
            `<button type="button" class="hyper-header-tab${index === 0 ? ' is-active' : ''}"`
            + ` role="tab" aria-selected="${index === 0 ? 'true' : 'false'}"`
            + ` data-hyper-tab-index="${index}" data-hyper-action="select-tab">`
            + `<span class="hyper-header-tab-label">${escapeHtml(label)}</span></button>`
        )).join('');

        if (!(tabsMenu instanceof HTMLElement)) {
            tabsMenu = document.createElement('pk-dropdown-menu');
            tabsMenu.className = 'hyper-header-tabs-menu';
            tabsMenu.setAttribute('data-hyper-layout-tabs-menu', '');
            tabsMenu.setAttribute('placement', 'bottom-end');
            tabsMenu.setAttribute('size', 'sm');
            const actions = actionsTabs.querySelector('.hyper-header-actions');

            if (actions) {
                actionsTabs.insertBefore(tabsMenu, actions);
            } else {
                actionsTabs.appendChild(tabsMenu);
            }
        }

        tabsMenu.innerHTML = [
            '<button type="button" slot="trigger" class="hyper-header-tab-menu-btn"',
            ` aria-label="${escapeHtml(Craft.t('hyper', 'Link fields tab'))}">`,
            `<span data-hyper-active-tab-label>${escapeHtml(tabLabels[0])}</span>`,
            '<pk-icon class="hyper-header-tab-chevron" icon="chevron-down"></pk-icon>',
            '</button>',
            ...tabLabels.map((label, index) => (
                `<pk-dropdown-item value="${index}" type="radio" data-hyper-tab-option`
                + `${index === 0 ? ' checked aria-current="true"' : ''}>${escapeHtml(label)}</pk-dropdown-item>`
            )),
        ].join('');

        this.selectTab(0);
    }

    /**
     * Show header New Window icon when the type layout does not include the native field.
     * Hidden when New Window is a layout lightswitch (one control only).
     */
    syncHeaderNewWindow(show: boolean): void {
        const toggle = this.el.querySelector('[data-hyper-new-window-switch]');

        if (!(toggle instanceof HTMLElement)) {
            return;
        }

        if (show) {
            toggle.removeAttribute('hidden');
        } else {
            toggle.setAttribute('hidden', '');
        }
    }

    syncMoveActions(index: number, total: number): void {
        this.setActionDisabled('move-up', index <= 0);
        this.setActionDisabled('move-down', index >= total - 1);
    }

    /** Show/hide Craft layout panes under the portal (sibling `.flex-fields`). */
    selectTab(index: number): void {
        // Tab chrome only — do not project portal → store (MutationObserver would see `.hidden`
        // toggles and rewrite fields[handle], creating a provisional draft).
        this.suppressPortalSync = true;
        this.emitChangeDebounced.cancel();

        try {
            const portal = this.el.querySelector('[data-hyper-portal]');

            if (portal instanceof HTMLElement) {
                const panes = portal.querySelectorAll(':scope > .flex-fields');

                panes.forEach((pane, i) => {
                    pane.classList.toggle('hidden', i !== index);
                });
            }

            this.el.querySelectorAll('[data-hyper-tab-index]').forEach((tab) => {
                if (!(tab instanceof HTMLElement)) {
                    return;
                }

                const active = Number(tab.dataset.hyperTabIndex) === index;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            const selectedLabel = this.el.querySelector<HTMLElement>(
                `[data-hyper-tab-index="${index}"]`,
            )?.textContent?.trim() ?? '';
            const activeLabel = this.el.querySelector('[data-hyper-active-tab-label]');

            if (activeLabel instanceof HTMLElement) {
                activeLabel.textContent = selectedLabel;
            }

            this.el.querySelectorAll('[data-hyper-tab-option]').forEach((option) => {
                if (!(option instanceof HTMLElement)) {
                    return;
                }

                const selected = Number(option.getAttribute('value')) === index;
                option.toggleAttribute('checked', selected);

                if (selected) {
                    option.setAttribute('aria-current', 'true');
                } else {
                    option.removeAttribute('aria-current');
                }
            });
        } finally {
            // Flush MutationObserver microtasks while still suppressed, then re-enable.
            window.requestAnimationFrame(() => {
                this.suppressPortalSync = false;
            });
        }
    }

    destroy(): void {
        this.headerAbort.abort();
        this.portalObserver?.disconnect();
        this.menuBtn?.destroy();
        $(this.el).off('.hyperBlock');
    }

    private appendInitialJs(): void {
        const js = this.el.dataset.linkJs;

        if (js) {
            appendBlockJs(decodeHtmlEntities(js));
        }
    }

    /** Reset new-window toggle when a block is cloned for a new link. */
    static applyNewWindowDefault(blockEl: HTMLElement, newWindow: boolean): void {
        const toggle = blockEl.querySelector('[data-hyper-new-window-switch]');

        if (toggle instanceof HTMLElement) {
            toggle.classList.toggle('is-active', newWindow);
            toggle.classList.toggle('on', newWindow);
            toggle.setAttribute('aria-pressed', newWindow ? 'true' : 'false');
        }

        // Layout-owned New Window lightswitch (when header icon is hidden).
        const portal = blockEl.querySelector('[data-hyper-portal]');

        if (portal instanceof HTMLElement) {
            setPortalLightswitch(portal, 'newWindow', newWindow);
        }
    }

    static readNewWindow(blockEl: HTMLElement): boolean {
        const toggle = blockEl.querySelector('[data-hyper-new-window-switch]');

        if (!(toggle instanceof HTMLElement) || toggle.hasAttribute('hidden')) {
            return false;
        }

        return toggle.classList.contains('is-active')
            || toggle.getAttribute('aria-pressed') === 'true'
            || toggle.classList.contains('on');
    }

    private toggleNewWindow(): void {
        const toggle = this.el.querySelector('[data-hyper-new-window-switch]');

        if (!(toggle instanceof HTMLElement) || toggle.hasAttribute('disabled')) {
            return;
        }

        const next = !HyperLinkBlock.readNewWindow(this.el);
        HyperLinkBlock.applyNewWindowDefault(this.el, next);
        this.onChange();
    }

    private bindEvents(): void {
        // Legacy <select> chrome (pre-compact header) — keep working if present.
        const typeSelect = this.el.querySelector('[data-hyper-link-type]');

        if (typeSelect instanceof HTMLSelectElement) {
            typeSelect.addEventListener('change', () => {
                this.el.dispatchEvent(new CustomEvent('hyper:change-type', {
                    bubbles: true,
                    detail: { handle: typeSelect.value },
                }));
            });
        }

        this.el.querySelector('[data-hyper-type-menu]')?.addEventListener('pk-select', (event) => {
            if (!(event instanceof CustomEvent)) {
                return;
            }

            const handle = String(event.detail?.value ?? '');

            if (!handle || handle === this.el.dataset.linkHandle) {
                return;
            }

            this.el.dispatchEvent(new CustomEvent('hyper:change-type', {
                bubbles: true,
                detail: { handle },
            }));
        });

        this.el.querySelector('[data-hyper-block-menu]')?.addEventListener('pk-select', (event) => {
            if (!(event instanceof CustomEvent)) {
                return;
            }

            this.runAction(String(event.detail?.value ?? ''));
        });

        // Delegate so a type switch can replace the responsive tab menu in place.
        this.el.addEventListener('pk-select', (event) => {
            if (
                !(event instanceof CustomEvent)
                || !(event.target instanceof HTMLElement)
                || !event.target.matches('[data-hyper-layout-tabs-menu]')
            ) {
                return;
            }

            const index = Number(event.detail?.value ?? 0);
            this.selectTab(Number.isFinite(index) ? index : 0);
        });

        this.el.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.closest('pk-dropdown-menu')) {
                return;
            }

            const action = target.closest('[data-hyper-action]')?.getAttribute('data-hyper-action');

            if (action === 'select-tab') {
                const tab = target.closest('[data-hyper-tab-index]');
                const index = Number(tab?.getAttribute('data-hyper-tab-index') ?? 0);
                this.selectTab(Number.isFinite(index) ? index : 0);

                return;
            }

            if (action === 'toggle-new-window') {
                this.toggleNewWindow();

                return;
            }

            this.runAction(action ?? '');
        });
    }

    private initMenu(): void {
        const trigger = this.el.querySelector('[data-hyper-block-menu]');

        if (!(trigger instanceof HTMLElement) || trigger.tagName.toLowerCase() === 'pk-dropdown-menu') {
            return;
        }

        this.menuBtn = initMenuBtn(trigger);
    }

    private runAction(action: string): void {
        if (this.el.querySelector(`[data-hyper-action="${action}"][disabled]`)) {
            return;
        }

        if (action === 'delete') {
            this.onDelete();
        } else if (action === 'move-up') {
            this.onMoveUp();
        } else if (action === 'move-down') {
            this.onMoveDown();
        } else if (action === 'copy') {
            this.onCopy();
        } else if (action === 'cut') {
            this.onCut();
        } else if (action === 'paste') {
            this.onPaste();
        }
    }

    private setActionDisabled(action: string, disabled: boolean): void {
        const item = this.el.querySelector(`[data-hyper-action="${action}"]`);

        if (!(item instanceof HTMLElement)) {
            return;
        }

        item.toggleAttribute('disabled', disabled);
        item.classList.toggle('disabled', disabled);
        item.setAttribute('aria-disabled', String(disabled));
    }

    private watchPortal(): void {
        const portal = this.el.querySelector('[data-hyper-portal]');

        if (!(portal instanceof HTMLElement)) {
            return;
        }

        this.portalObserver = new MutationObserver((mutations) => {
            if (this.suppressPortalSync) {
                return;
            }

            // Ignore layout-chrome attribute noise (tab panes toggle `.hidden` / aria).
            if (!mutations.some((mutation) => isPortalContentMutation(mutation))) {
                return;
            }

            this.emitChangeDebounced();
        });

        this.portalObserver.observe(portal, {
            childList: true,
            attributes: true,
            subtree: true,
            characterData: true,
        });

        $(portal).on('input change', 'input, textarea, select', () => {
            if (this.suppressPortalSync) {
                return;
            }

            this.onChange();
        });

        // Element select / asset fields mutate the portal without input/change on the container.
        $(portal).on('selectElements removeElements', '.elementselect, .assetselect', () => {
            if (this.suppressPortalSync) {
                return;
            }

            this.onChange();
        });
    }

    static getTemplateHtml(
        templatesRoot: HTMLElement,
        handle: string,
        linkId: string,
    ): {
        html: string;
        js?: string;
        tabCount: number;
        tabLabels: string[];
        label: string;
        showHeaderNewWindow: boolean;
    } | null {
        const template = templatesRoot.querySelector(`template[data-link-type="${handle}"]`);

        if (!(template instanceof HTMLTemplateElement)) {
            return null;
        }

        const html = parseLinkIdHtml(template.innerHTML, linkId);
        const js = template.dataset.linkJs
            ? parseLinkIdHtml(decodeHtmlEntities(template.dataset.linkJs), linkId)
            : undefined;

        let tabLabels: string[] = [];

        try {
            const raw = template.dataset.linkTabLabels;

            if (raw) {
                const parsed = JSON.parse(raw);

                if (Array.isArray(parsed)) {
                    tabLabels = parsed.map((label) => String(label));
                }
            }
        } catch {
            tabLabels = [];
        }

        return {
            html,
            js,
            tabCount: Number(template.dataset.linkTabCount ?? tabLabels.length ?? 0),
            tabLabels,
            label: template.dataset.linkLabel ?? handle,
            showHeaderNewWindow: template.dataset.linkShowHeaderNewWindow !== '0',
        };
    }
}

/**
 * Portal MutationObserver filter: layout tabs only toggle chrome attrs (`.hidden`, aria).
 * Those must not project to the hidden store. Real edits change values / DOM structure.
 */
function isPortalContentMutation(mutation: MutationRecord): boolean {
    if (mutation.type === 'characterData' || mutation.type === 'childList') {
        return true;
    }

    if (mutation.type !== 'attributes' || !mutation.attributeName) {
        return false;
    }

    const attr = mutation.attributeName;

    if (
        attr === 'class'
        || attr === 'style'
        || attr === 'hidden'
        || attr === 'aria-selected'
        || attr === 'aria-hidden'
        || attr === 'aria-pressed'
    ) {
        return false;
    }

    return true;
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
