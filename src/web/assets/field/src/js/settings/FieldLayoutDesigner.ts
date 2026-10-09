import { createSpinner } from '../ui/Spinner';
import { createStatePanel, getErrorDetail } from '../ui/feedback';

type FieldLayoutDesignerOptions = {
    fieldId: number | string | null;
    layoutUid?: string;
    type: string;
    value: string;
    onChange: (value: string) => void;
};

type LayoutDesignerCache = {
    html: string;
    footHtml?: string;
    footHtmlAppended?: boolean;
};

type Disposable = { destroy: () => void };

type CraftDesigner = Disposable & {
    tabGrid?: Disposable;
    tabDrag?: Disposable;
    elementDrag?: Disposable;
    libraryPicker?: Disposable;
};

type ScrollPosition = {
    x: number;
    y: number;
};

/** Garnish HUD instance stored on `.fld-add-btn` via jQuery `.data('hud')`. */
type FldLibraryHud = {
    on: (event: string, callback: () => void) => void;
    off: (event: string, callback: () => void) => void;
    $main?: JQuery & { [index: number]: Element };
};

export class FieldLayoutDesigner {
    private container: HTMLElement;

    private options: FieldLayoutDesignerOptions;

    private stageEl: HTMLElement;

    private workspaceEl: HTMLElement;

    private contentEl: HTMLElement;

    private cache: LayoutDesignerCache | null = null;

    private observer: MutationObserver | null = null;

    private workingValue: string | null = null;

    private loaded = false;

    private loading = false;

    private pendingScrollRestore: ScrollPosition | null = null;

    private destroyed = false;

    private events = new AbortController();

    private frames = new Set<number>();

    private hudCleanups: Array<() => void> = [];

    constructor(container: HTMLElement, options: FieldLayoutDesignerOptions) {
        this.container = container;
        this.options = options;

        this.stageEl = document.createElement('div');
        this.stageEl.className = 'hyper-fld-stage';
        this.container.appendChild(this.stageEl);

        this.contentEl = document.createElement('div');
        this.contentEl.className = 'hyper-fld-content';
        this.stageEl.appendChild(this.contentEl);

        this.workspaceEl = document.createElement('div');
        this.workspaceEl.className = 'hyper-workspace hyper-fld-placeholder';
        this.stageEl.appendChild(this.workspaceEl);
    }

    destroy(): void {
        if (this.destroyed) return;

        this.flushPending();
        this.destroyed = true;
        this.events.abort();
        this.observer?.disconnect();
        this.observer = null;
        $(this.contentEl).find('input[data-config-input]').off('.hyperFld');
        this.frames.forEach((frame) => cancelAnimationFrame(frame));
        this.frames.clear();
        this.hudCleanups.forEach((cleanup) => cleanup());
        this.hudCleanups = [];
        // Craft's tab teardown changes its working config, so first detach our
        // change bridge. The saved layout was flushed before disposal began.
        const designers = new Set<CraftDesigner>();
        this.contentEl.querySelectorAll('[data-disclosure-trigger]').forEach((element) => {
            const menu = $(element).data('disclosureMenu') as (Disposable & { $container?: JQuery }) | undefined;
            menu?.destroy();
            // Garnish unregisters the controller but leaves its body-level DOM.
            menu?.$container?.[0]?.remove();
        });
        this.contentEl.querySelectorAll('[data-config-input]').forEach((input) => {
            if (!input.parentElement) return;
            const designer = $(input.parentElement).data('hyperFld') as CraftDesigner | undefined;
            if (designer) designers.add(designer);
        });
        this.contentEl.querySelectorAll('.fld-add-btn').forEach((element) => {
            ($(element).data('hud') as Disposable | undefined)?.destroy();
        });
        this.contentEl.querySelectorAll('.fld-tab').forEach((element) => {
            ($(element).data('fld-tab') as Disposable | undefined)?.destroy();
        });
        designers.forEach((designer) => {
            designer.tabDrag?.destroy();
            designer.elementDrag?.destroy();
            designer.tabGrid?.destroy();
            designer.libraryPicker?.destroy();
            designer.destroy();
        });
        this.cache = null;
        this.pendingScrollRestore = null;
        this.stageEl.remove();
    }

    private nextFrame(callback: () => void): void {
        const frame = requestAnimationFrame(() => {
            this.frames.delete(frame);
            if (!this.destroyed) callback();
        });
        this.frames.add(frame);
    }

    load(): void {
        if (this.destroyed) return;

        if (this.loaded) {
            this.showContent(true);
            return;
        }

        if (this.loading) {
            this.showLoading();
            return;
        }

        this.showLoading();
        this.fetchLayout();
    }

    syncValue(): void {
        if (this.destroyed || this.workingValue === null) return;

        // Craft FLD stores config on [data-config-input] (nameless so it never
        // participates in CP form serialize / confirm-unload).
        const target = this.contentEl.querySelector('input[data-config-input]');

        if (!(target instanceof HTMLInputElement) || target.value === this.workingValue) {
            return;
        }

        this.workingValue = target.value;
        this.options.value = target.value;
        this.options.onChange(target.value);
    }

    /**
     * Flush a pending FLD→layoutConfig sync (e.g. before switching link types).
     * No-op when the designer is pristine — avoid rewriting SSR layoutConfig with
     * Craft's fuller FLD JSON shape, which would false-dirty confirm-unload.
     */
    flushPending(): void {
        this.syncValue();
    }

    private showLoading(): void {
        this.stageEl.classList.remove('has-error');
        this.workspaceEl.classList.add('is-active');
        this.workspaceEl.classList.remove('is-leaving');
        this.workspaceEl.innerHTML = '';

        const pane = document.createElement('div');
        pane.className = 'hyper-loading-pane';
        pane.appendChild(createSpinner('lg'));
        this.workspaceEl.appendChild(pane);

        this.contentEl.classList.remove('is-visible');
    }

    private showContent(instant = false): void {
        this.stageEl.classList.remove('has-error');
        this.workspaceEl.classList.remove('is-active');
        this.contentEl.classList.add('is-visible');

        if (instant || this.prefersReducedMotion()) {
            this.workspaceEl.classList.remove('is-leaving');
            this.workspaceEl.innerHTML = '';
            return;
        }

        this.workspaceEl.classList.add('is-leaving');

        const finish = () => {
            this.workspaceEl.classList.remove('is-leaving');
            this.workspaceEl.innerHTML = '';
        };

        this.workspaceEl.addEventListener('transitionend', (event) => {
            if (event.propertyName === 'opacity') {
                finish();
            }
        }, { once: true, signal: this.events.signal });
    }

    private showError(error: unknown): void {
        this.loading = false;
        this.stageEl.classList.add('has-error');
        this.workspaceEl.classList.add('is-active');
        this.workspaceEl.classList.remove('is-leaving');
        this.contentEl.classList.remove('is-visible');
        this.workspaceEl.innerHTML = '';

        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn submit';
        retry.textContent = Craft.t('hyper', 'Try again');
        retry.addEventListener('click', () => {
            if (this.loading || this.destroyed) {
                return;
            }

            this.showLoading();
            this.fetchLayout();
        }, { signal: this.events.signal });

        this.workspaceEl.append(createStatePanel({
            variant: 'error',
            heading: Craft.t('hyper', 'Field layout unavailable'),
            message: Craft.t('hyper', 'Hyper couldn’t load the layout. Retry the request or reload the page.'),
            details: getErrorDetail(error) ?? Craft.t('app', 'An error occurred.'),
            detailsLabel: Craft.t('hyper', 'Technical details'),
            copyLabel: Craft.t('hyper', 'Copy details'),
            copiedLabel: Craft.t('hyper', 'Details copied.'),
            action: retry,
            announce: 'assertive',
        }));
    }

    private fetchLayout(): void {
        this.loading = true;

        const fieldIds: Array<number | string> = [];

        if (this.options.fieldId) {
            fieldIds.push(this.options.fieldId);
        }

        const match = /fields\/edit\/(\d*)$/g.exec(window.location.href);

        if (match?.[1]) {
            fieldIds.push(match[1]);
        }

        Craft.sendActionRequest('POST', 'hyper/fields/layout-designer', {
            data: {
                fieldIds,
                layoutUid: this.options.layoutUid,
                layout: this.options.value,
                type: this.options.type,
            },
        })
            .then((response) => {
                if (this.destroyed) return;

                const data = response.data as LayoutDesignerCache & { html?: string };

                if (!data.html) {
                    throw new Error(String(data));
                }

                this.cache = {
                    ...data,
                    footHtmlAppended: false,
                };

                return this.renderLayout();
            })
            .catch((error: unknown) => {
                if (!this.destroyed) this.showError(error);
            });
    }

    private async renderLayout(): Promise<void> {
        if (!this.cache) {
            return;
        }

        this.loading = false;
        this.loaded = true;

        // Preserve CP scroll while the async FLD swap runs — a late render can otherwise
        // yank the viewport back to the top if the user scrolled during the request.
        const scrollSnapshot = this.captureScrollPosition();

        this.contentEl.innerHTML = this.cache.html;
        Craft.initUiElements(this.contentEl);

        if (this.cache.footHtml && !this.cache.footHtmlAppended) {
            await Craft.appendBodyHtml(this.cache.footHtml);
            if (this.destroyed) return;
            this.cache.footHtmlAppended = true;
        }

        this.showContent();
        this.watchForChanges();
        this.bindFldScrollPreservation();
        this.restoreScrollPosition(scrollSnapshot);
    }

    /**
     * Craft's FLD opens its field library in a HUD and immediately focuses the search
     * input. When the designer was lazy-loaded, that focus call can scroll the CP to
     * the top. Capture scroll before those interactions and restore after focus.
     */
    private bindFldScrollPreservation(): void {
        const rememberScroll = () => {
            this.pendingScrollRestore = this.captureScrollPosition();
        };

        this.stageEl.addEventListener('pointerdown', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement)) {
                return;
            }

            if (target.closest('.fld-add-btn') || target.closest('.fld-library .fld-element')) {
                rememberScroll();
            }
        }, { capture: true, signal: this.events.signal });

        this.stageEl.addEventListener('keydown', (event) => {
            if (!(event instanceof KeyboardEvent)) {
                return;
            }

            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            const target = event.target;

            if (target instanceof HTMLElement && target.closest('.fld-add-btn')) {
                rememberScroll();
            }
        }, { capture: true, signal: this.events.signal });

        // FieldLayoutDesigner is constructed from footHtml; patch HUD handlers next frame.
        this.nextFrame(() => {
            this.contentEl.querySelectorAll('.fld-add-btn').forEach((button) => {
                const hud = $(button).data('hud') as FldLibraryHud | undefined;

                if (!hud?.on) {
                    return;
                }

                const onShow = () => {
                    rememberScroll();

                    this.nextFrame(() => {
                        const search = hud.$main?.[0]?.querySelector('.fld-field-library .search input');

                        if (search instanceof HTMLInputElement) {
                            search.focus({ preventScroll: true });
                        }

                        this.restorePendingScroll();
                    });
                };

                const onHide = () => {
                    this.nextFrame(() => {
                        if (button instanceof HTMLElement) {
                            button.focus({ preventScroll: true });
                        }

                        this.restorePendingScroll();
                    });
                };

                hud.on('show', onShow);
                hud.on('hide', onHide);
                this.hudCleanups.push(() => {
                    hud.off('show', onShow);
                    hud.off('hide', onHide);
                });
            });
        });
    }

    private captureScrollPosition(): ScrollPosition {
        return {
            x: window.scrollX,
            y: window.scrollY,
        };
    }

    private restoreScrollPosition(position: ScrollPosition): void {
        this.nextFrame(() => {
            window.scrollTo(position.x, position.y);
            this.nextFrame(() => window.scrollTo(position.x, position.y));
        });
    }

    private restorePendingScroll(): void {
        if (!this.pendingScrollRestore) {
            return;
        }

        const position = this.pendingScrollRestore;
        this.pendingScrollRestore = null;
        this.restoreScrollPosition(position);
    }

    private watchForChanges(): void {
        this.observer?.disconnect();
        const target = this.contentEl.querySelector('input[data-config-input]');

        // Craft expands the saved JSON when mounting. Keep that pristine shape out
        // of the submitted value, but sync actual edits before the next Save click.
        this.workingValue = target instanceof HTMLInputElement ? target.value : null;
        const syncValue = () => this.syncValue();

        this.observer = new MutationObserver(syncValue);

        this.observer.observe(this.contentEl, {
            childList: true,
            attributes: true,
            subtree: true,
            characterData: true,
        });

        if (target instanceof HTMLInputElement) {
            $(target).on('change.hyperFld', syncValue);
        }
    }

    private prefersReducedMotion(): boolean {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

}
