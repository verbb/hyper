/**
 * Craft ElementEditor hooks (layer 3).
 * Strips authoring-only hyperData params; hidden [data-hyper-store] is the save payload.
 *
 * We never re-serialize the full form or touch lastSerializedValue — only surgically
 * strip hyperData from the existing initialSerializedValue string when the hook attaches.
 */
import { syncAllHyperInputStores } from './registry';

type ElementEditorInstance = {
    serializeForm: (removeActionParams?: boolean) => string;
    formObserver?: { _serialize?: () => void };
    pause?: () => Promise<void>;
    resume?: () => void;
    pauseLevel?: number;
    on?: (event: string, handler: (event: { data: { serialized: string } }) => void) => void;
    __hyperSerializeHook?: boolean;
};

type HyperFormInitBatch = {
    callbacks: Array<() => void | Promise<void>>;
    promise: Promise<void>;
};

export const stripHyperPortalParams = (serialized: string): string => (
    serialized
        // Portal fields use hyperData[{linkId}] during render; Craft namespaces them under fields[hyperData].
        .replace(/&(?:fields%5BhyperData%5D|fields\[hyperData\])[^&]*/g, '')
        // Slideouts / nested contexts where hyperData isn't wrapped in fields.
        .replace(/&hyperData[^&]*/g, '')
);

const hyperFormInitByForm = new WeakMap<HTMLFormElement, HyperFormInitBatch>();

const getFormForContainer = (container: HTMLElement): HTMLFormElement | null => {
    const form = container.closest('form');

    return form instanceof HTMLFormElement ? form : null;
};

export const getElementEditor = (container: HTMLElement): ElementEditorInstance | undefined => {
    const form = getFormForContainer(container);

    if (!form) {
        return undefined;
    }

    return $(form).data('elementEditor') as ElementEditorInstance | undefined;
};

/**
 * Strip hyperData from Craft's stored baseline only — does not re-serialize the form
 * or touch other fields' params (or lastSerializedValue).
 */
export function sanitizeElementEditorSerializedBaseline(container: HTMLElement): void {
    const form = getFormForContainer(container);

    if (!form) {
        return;
    }

    const initial = $(form).data('initialSerializedValue');

    if (typeof initial !== 'string' || !initial.length) {
        return;
    }

    const sanitized = stripHyperPortalParams(initial);

    if (sanitized !== initial) {
        ($(form) as unknown as { data(key: string, value: string): void })
            .data('initialSerializedValue', sanitized);
    }
}

/** Align FormObserver's internal snapshot after init mutations (no baseline rewrite). */
export function syncElementEditorFormObserver(container: HTMLElement): void {
    getElementEditor(container)?.formObserver?._serialize?.();
}

export function ensureElementEditorSerializeHook(container: HTMLElement): void {
    const elementEditor = getElementEditor(container);

    if (!elementEditor || elementEditor.__hyperSerializeHook) {
        return;
    }

    // Match legacy Vue hyper.js: portal fields are authoring-only; persisted content
    // lives in [data-hyper-store] which HyperInput keeps updated via syncStore().
    elementEditor.on?.('serializeForm', (event) => {
        // Craft saves via ElementEditor.serializeForm(), not always a native form submit.
        syncAllHyperInputStores();
        event.data.serialized = stripHyperPortalParams(event.data.serialized);
    });

    elementEditor.__hyperSerializeHook = true;

    // ElementEditor may have captured initialSerializedValue before this script loaded.
    sanitizeElementEditorSerializedBaseline(container);
}

const waitForAnimationFrames = (count: number): Promise<void> => (
    new Promise((resolve) => {
        const step = (remaining: number) => {
            if (remaining <= 0) {
                resolve();
                return;
            }

            window.requestAnimationFrame(() => step(remaining - 1));
        };

        step(count);
    })
);

export function waitForElementEditor(
    container: HTMLElement,
    timeoutMs = 10000,
): Promise<ElementEditorInstance | undefined> {
    const existing = getElementEditor(container);

    if (existing) {
        return Promise.resolve(existing);
    }

    return new Promise((resolve) => {
        const startedAt = Date.now();

        const interval = window.setInterval(() => {
            const elementEditor = getElementEditor(container);

            if (elementEditor || Date.now() - startedAt >= timeoutMs) {
                window.clearInterval(interval);
                resolve(elementEditor);
            }
        }, 50);
    });
}

/**
 * Batch Hyper field inits per form under a single ElementEditor pause/resume cycle.
 * Prevents parallel Hyper instances from resuming autosave before siblings finish mounting.
 */
export function enqueueHyperFieldInit(
    container: HTMLElement,
    initFn: () => void | Promise<void>,
): Promise<void> {
    const form = getFormForContainer(container);

    if (!form) {
        return Promise.resolve(initFn());
    }

    let batch = hyperFormInitByForm.get(form);

    if (!batch) {
        batch = {
            callbacks: [],
            promise: Promise.resolve().then(async () => {
                const activeBatch = hyperFormInitByForm.get(form);

                if (!activeBatch) {
                    return;
                }

                // ElementEditor may not exist yet when hyper.ts first mounts fields.
                await waitForElementEditor(container);

                const elementEditor = getElementEditor(container);
                let paused = false;

                if (elementEditor?.pause) {
                    await elementEditor.pause();
                    paused = (elementEditor.pauseLevel ?? 0) > 0;
                }

                try {
                    const callbacks = activeBatch.callbacks.splice(0);

                    for (const callback of callbacks) {
                        await callback();
                    }

                    // Let Garnish / element selects finish mutating hyperData while still paused.
                    await waitForAnimationFrames(2);
                    await new Promise<void>((resolve) => {
                        window.setTimeout(resolve, 100);
                    });
                    await waitForAnimationFrames(2);
                    sanitizeElementEditorSerializedBaseline(container);
                    syncElementEditorFormObserver(container);
                } finally {
                    hyperFormInitByForm.delete(form);

                    if (paused && (elementEditor?.pauseLevel ?? 0) > 0) {
                        elementEditor?.resume?.();
                    }
                }
            }),
        };

        hyperFormInitByForm.set(form, batch);
    }

    batch.callbacks.push(initFn);

    return batch.promise;
}

export function pauseElementEditor(container: HTMLElement): Promise<void> {
    const elementEditor = getElementEditor(container);

    if (!elementEditor?.pause) {
        return Promise.resolve();
    }

    return elementEditor.pause();
}

export function resumeElementEditor(container: HTMLElement): void {
    const elementEditor = getElementEditor(container);

    // Craft throws if resume() runs when FormObserver pauseLevel is already 0.
    if (!elementEditor?.resume || (elementEditor.pauseLevel ?? 0) <= 0) {
        return;
    }

    elementEditor.resume();
}
