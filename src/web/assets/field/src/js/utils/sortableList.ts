import { RestrictToVerticalAxis } from '@dnd-kit/abstract/modifiers';
import { DragDropManager } from '@dnd-kit/dom';
import { RestrictToElement } from '@dnd-kit/dom/modifiers';
import { Sortable, isSortable } from '@dnd-kit/dom/sortable';

export type HyperSortableListOptions = {
    container: HTMLElement;
    itemSelector: string;
    handleSelector: string;
    group?: string;
    enabled?: boolean;
    containerDraggingClass?: string;
    itemDraggingClass?: string;
    getItemId?: (element: HTMLElement, index: number) => string;
    onReorder: (fromIndex: number, toIndex: number) => void;
    /** Fired on dragstart — used to suspend host autosave while the DOM reorders optimistically. */
    onDragStart?: () => void;
    /** Fired on dragend (including cancel), after onReorder — resumes host autosave with one save. */
    onDragEnd?: () => void;
};

/**
 * Vanilla sortable list using dnd-kit's default sortable behaviour.
 *
 * OptimisticSortingPlugin (enabled by default on Sortable) reorders the DOM
 * during drag. We only sync application state on dragend via initialIndex/index.
 *
 * @see https://dndkit.com/concepts/sortable
 */
export class HyperSortableList {
    private manager: DragDropManager;

    private sortables: Sortable[] = [];

    private cleanupListeners: Array<() => void> = [];

    private options: HyperSortableListOptions;

    constructor(options: HyperSortableListOptions) {
        this.options = options;
        this.manager = new DragDropManager({
            modifiers: (defaults) => [
                ...defaults,
                RestrictToVerticalAxis,
                RestrictToElement.configure({
                    element: () => this.options.container,
                }),
            ],
        });
    }

    init(): void {
        this.createSortables();
        this.bindEvents();
    }

    refresh(): void {
        this.destroySortables();
        this.createSortables();
    }

    destroy(): void {
        this.cleanupListeners.forEach((cleanup) => cleanup());
        this.cleanupListeners = [];
        this.destroySortables();
    }

    private bindEvents(): void {
        const containerClass = this.options.containerDraggingClass ?? 'hyper-dragging';
        const itemClass = this.options.itemDraggingClass ?? 'is-dragging';

        this.cleanupListeners.push(
            this.manager.monitor.addEventListener('dragstart', (event) => {
                this.options.container.classList.add(containerClass);

                // Suspend host autosave before the optimistic DOM reorder starts, so the
                // host's mutation observer doesn't stream provisional drafts mid-drag.
                this.options.onDragStart?.();

                const { source } = event.operation;

                if (isSortable(source) && source.element instanceof HTMLElement) {
                    source.element.classList.add(itemClass);
                }
            }),
            this.manager.monitor.addEventListener('dragend', (event) => {
                this.options.container.classList.remove(containerClass);

                this.options.container.querySelectorAll(this.options.itemSelector).forEach((element) => {
                    element.classList.remove(itemClass);
                });

                const { source } = event.operation;

                // Only sync state for a committed, position-changing drop.
                if (!event.canceled && isSortable(source)) {
                    const { initialIndex, index } = source;

                    if (initialIndex !== index) {
                        this.options.onReorder(initialIndex, index);
                    }
                }

                // Always balance the dragstart pause — resume after onReorder so the single
                // post-drop store change produces exactly one autosave (cancel = no-op change).
                this.options.onDragEnd?.();
            }),
        );
    }

    private createSortables(): void {
        if (this.options.enabled === false) {
            return;
        }

        this.getItems().forEach((element, index) => {
            const handle = element.querySelector(this.options.handleSelector);
            const id = this.options.getItemId?.(element, index)
                ?? element.dataset.linkId
                ?? element.dataset.linkTypeHandle
                ?? `hyper-sortable-${index}`;

            this.sortables.push(new Sortable({
                id,
                element,
                index,
                group: this.options.group ?? 'hyper-sortable',
                handle: handle instanceof HTMLElement ? handle : undefined,
            }, this.manager));
        });
    }

    private destroySortables(): void {
        this.sortables.forEach((sortable) => {
            sortable.unregister();
            sortable.destroy();
        });
        this.sortables = [];
    }

    private getItems(): HTMLElement[] {
        return [...this.options.container.querySelectorAll(this.options.itemSelector)]
            .filter((element): element is HTMLElement => element instanceof HTMLElement);
    }
}
