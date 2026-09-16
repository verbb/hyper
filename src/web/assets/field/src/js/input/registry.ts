import { syncEmbedWidgets } from './embed';

let syncing = false;

const syncCallbacks = new Map<HTMLElement, () => void>();

export function registerHyperInputSync(container: HTMLElement, callback: () => void): () => void {
    syncCallbacks.set(container, callback);
    return () => { syncCallbacks.delete(container); };
}

export function syncAllHyperInputStores(): void {
    if (syncing) return;
    syncing = true;
    try {
        syncEmbedWidgets();
        // Descendant stores must be current before an ancestor snapshots its Matrix/custom fields.
        const ordered = [...syncCallbacks].map(([container, callback]) => {
            let depth = 0;
            for (let parent = container.parentElement; parent; parent = parent.parentElement) depth++;
            return { depth, callback };
        });
        ordered.sort((a, b) => b.depth - a.depth).forEach(({ callback }) => callback());
    } finally {
        syncing = false;
    }
}
