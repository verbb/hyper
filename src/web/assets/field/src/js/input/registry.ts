const syncCallbacks = new Set<() => void>();

export function registerHyperInputSync(callback: () => void): () => void {
    syncCallbacks.add(callback);

    return () => {
        syncCallbacks.delete(callback);
    };
}

export function syncAllHyperInputStores(): void {
    syncCallbacks.forEach((callback) => {
        callback();
    });
}
