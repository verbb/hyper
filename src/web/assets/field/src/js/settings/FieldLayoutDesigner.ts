import { debounce } from 'lodash-es';

import { createSpinner } from '../ui/Spinner';

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

type ScrollPosition = {
    x: number;
    y: number;
};

/** Garnish HUD instance stored on `.fld-add-btn` via jQuery `.data('hud')`. */
type FldLibraryHud = {
    on: (event: string, callback: () => void) => void;
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

    private syncDebounced: ReturnType<typeof debounce> | null = null;

    private loaded = false;

    private loading = false;

    private mounted = false;

    private pendingScrollRestore: ScrollPosition | null = null;

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

    load(): void {
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
        // Craft FLD stores config on [data-config-input] (nameless so it never
        // participates in CP form serialize / confirm-unload).
        const target = this.contentEl.querySelector('input[data-config-input]');

        if (!(target instanceof HTMLInputElement)) {
            return;
        }

        this.options.value = target.value;
        this.options.onChange(target.value);
    }

    /**
     * Flush a pending FLD→layoutConfig sync (e.g. before switching link types).
     * No-op when the designer is pristine — avoid rewriting SSR layoutConfig with
     * Craft's fuller FLD JSON shape, which would false-dirty confirm-unload.
     */
    flushPending(): void {
        this.syncDebounced?.flush();
    }

    private showLoading(): void {
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
        }, { once: true });
    }

    private showError(message: string): void {
        this.loading = false;
        this.workspaceEl.classList.add('is-active');
        this.workspaceEl.classList.remove('is-leaving');
        this.contentEl.classList.remove('is-visible');
        this.workspaceEl.innerHTML = (
            '<div class="hyper-error-pane error">'
            + '<div class="hyper-error-content">'
            + '<span data-icon="alert"></span>'
            + `<span class="error">${this.escapeHtml(message)}</span>`
            + '</div>'
            + '</div>'
        );
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
                const data = response.data as LayoutDesignerCache & { html?: string };

                if (!data.html) {
                    throw new Error(String(data));
                }

                this.cache = {
                    ...data,
                    footHtmlAppended: false,
                };

                this.renderLayout();
            })
            .catch((error: unknown) => {
                this.showError(this.formatError(error));
            });
    }

    private renderLayout(): void {
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
            Craft.appendBodyHtml(this.cache.footHtml);
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
        }, true);

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
        }, true);

        // FieldLayoutDesigner is constructed from footHtml; patch HUD handlers next frame.
        requestAnimationFrame(() => {
            this.contentEl.querySelectorAll('.fld-add-btn').forEach((button) => {
                const hud = $(button).data('hud') as FldLibraryHud | undefined;

                if (!hud?.on) {
                    return;
                }

                hud.on('show', () => {
                    rememberScroll();

                    requestAnimationFrame(() => {
                        const search = hud.$main?.[0]?.querySelector('.fld-field-library .search input');

                        if (search instanceof HTMLInputElement) {
                            search.focus({ preventScroll: true });
                        }

                        this.restorePendingScroll();
                    });
                });

                hud.on('hide', () => {
                    requestAnimationFrame(() => {
                        if (button instanceof HTMLElement) {
                            button.focus({ preventScroll: true });
                        }

                        this.restorePendingScroll();
                    });
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
        requestAnimationFrame(() => {
            window.scrollTo(position.x, position.y);
            requestAnimationFrame(() => window.scrollTo(position.x, position.y));
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
        this.syncDebounced?.cancel();

        // First observer tick is Craft FLD mounting itself — skip so we don't
        // push its working JSON onto layoutConfig until the author edits.
        const syncValue = debounce(() => {
            if (!this.mounted) {
                this.mounted = true;
                return;
            }

            this.syncValue();
        }, 250);

        this.syncDebounced = syncValue;

        this.observer = new MutationObserver(() => {
            syncValue();
        });

        this.observer.observe(this.contentEl, {
            childList: true,
            attributes: true,
            subtree: true,
            characterData: true,
        });

        const target = this.contentEl.querySelector('input[data-config-input]');

        if (target instanceof HTMLInputElement) {
            $(target).on('change.hyperFld', syncValue);
        }
    }

    private prefersReducedMotion(): boolean {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    private formatError(error: unknown): string {
        if (error instanceof Error && error.message) {
            return error.message;
        }

        if (typeof error === 'string' && error.trim()) {
            return error;
        }

        return Craft.t('app', 'An error occurred.');
    }

    private escapeHtml(value: string): string {
        return value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
}
