import { registerHostSerialization } from './input/hostSerialization';
import { mountEmbed } from './input/embed';
import { createMatrixInput } from './input/matrix';

import {
    ensureElementEditorSerializeHook,
    sanitizeElementEditorSerializedBaseline,
    stripHyperPortalParams,
    syncElementEditorFormObserver,
} from './input/elementEditor';
import { syncAllHyperInputStores } from './input/registry';

type FormSerializer = () => string;

export function registerHyperGlobals(): void {
    Craft.Hyper = Craft.Hyper || {};

    if (Craft.Hyper.__globalsRegistered) {
        return;
    }

    Craft.Hyper.__globalsRegistered = true;
    registerHostSerialization();
    Craft.Hyper.syncInputStores = syncAllHyperInputStores;

    Craft.Hyper.ElementSelect = Garnish.Base.extend({
        init(elementSelect: string, siteId: string) {
            const $elementSelect = $(elementSelect);
            const $siteId = $(siteId);

            if (!$elementSelect.length) {
                return;
            }

            const elementSelectInstance = $elementSelect.data('elementSelect') as {
                on(event: string, handler: (event?: { elements?: Array<{ siteId: number }> }) => void): void;
            } | undefined;

            if (!elementSelectInstance) {
                return;
            }

            elementSelectInstance.on('selectElements', (event) => {
                if (event?.elements && event.elements.length) {
                    $siteId.val(event.elements[0].siteId);
                }
            });

            elementSelectInstance.on('removeElements', () => {
                $siteId.val('');
            });
        },
    });

    Craft.Hyper.MatrixInput = createMatrixInput;

    Craft.Hyper.Embed = Garnish.Base.extend({
        init(fieldId: string) {
            const container = $(fieldId)[0];
            if (container instanceof HTMLElement) mountEmbed(container);
        },
    });
}

const installFormSerializer = ($form: JQuery, existing?: FormSerializer): FormSerializer => {
    const serializer = () => {
        syncAllHyperInputStores();

        return stripHyperPortalParams(existing ? existing() : $form.serialize());
    };

    $form.data('serializer', serializer);
    $form.data('hyperSerializerWrap', serializer);

    return serializer;
};

/** Keep Hyper's canonical stores in Craft form comparisons and exclude authoring portals. */
export const wrapFormSerializer = (form: HTMLFormElement): void => {
    const $form = $(form);
    const active = $form.data('serializer');
    const installed = $form.data('hyperSerializerWrap');

    if (typeof active === 'function' && active === installed) {
        return;
    }

    const existing = typeof active === 'function' ? active as FormSerializer : undefined;
    installFormSerializer($form, existing);

    if (existing) {
        return;
    }

    // Global Set forms never receive Craft's Element Editor serializer, so the
    // raw fallback must exist before Craft captures its unload/delta baseline.
    // Keep watching briefly in case another host installs a richer serializer.
    const startedAt = Date.now();
    const interval = window.setInterval(() => {
        const current = $form.data('serializer');
        const currentWrap = $form.data('hyperSerializerWrap');

        if (typeof current === 'function' && current !== currentWrap) {
            installFormSerializer($form, current as FormSerializer);
            window.clearInterval(interval);
        } else if (!form.isConnected || Date.now() - startedAt >= 10000) {
            window.clearInterval(interval);
        }
    }, 50);
};

const attachSerializeHookFromForm = (form: HTMLFormElement) => {
    ensureElementEditorSerializeHook(form);
    sanitizeElementEditorSerializedBaseline(form);
    syncElementEditorFormObserver(form);
    wrapFormSerializer(form);

    if (form.dataset.hyperSubmitHook) {
        return;
    }

    form.dataset.hyperSubmitHook = '1';
    form.addEventListener('submit', () => {
        syncAllHyperInputStores();
    }, true);
};

export function registerHyperFormHooks(): void {
    if (Craft.Hyper.__formHookObserverStarted) {
        return;
    }

    Craft.Hyper.__formHookObserverStarted = true;

    document.querySelectorAll('form[data-element-editor]').forEach((form) => {
        if (form instanceof HTMLFormElement) {
            attachSerializeHookFromForm(form);
        }
    });

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (
                mutation.type === 'attributes'
                && mutation.target instanceof HTMLFormElement
                && mutation.target.hasAttribute('data-element-editor')
            ) {
                attachSerializeHookFromForm(mutation.target);
            }

            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }

                if (node.matches('form[data-element-editor]') && node instanceof HTMLFormElement) {
                    attachSerializeHookFromForm(node);
                }

                node.querySelectorAll('form[data-element-editor]').forEach((form) => {
                    if (form instanceof HTMLFormElement) {
                        attachSerializeHookFromForm(form);
                    }
                });
            });
        });
    });

    observer.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['data-element-editor'],
    });

    const tryMainForm = (): boolean => {
        const mainForm = document.querySelector('form#main-form');

        if (mainForm instanceof HTMLFormElement) {
            attachSerializeHookFromForm(mainForm);
            return true;
        }

        return false;
    };

    if (!tryMainForm()) {
        const interval = window.setInterval(() => {
            tryMainForm();
        }, 50);

        window.setTimeout(() => {
            window.clearInterval(interval);
        }, 10000);
    }
}
