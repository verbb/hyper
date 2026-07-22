import { debounce } from 'lodash-es';

import {
    ensureElementEditorSerializeHook,
    sanitizeElementEditorSerializedBaseline,
    syncElementEditorFormObserver,
} from './input/elementEditor';
import { syncAllHyperInputStores } from './input/registry';

export function registerHyperGlobals(): void {
    Craft.Hyper = Craft.Hyper || {};

    if (Craft.Hyper.__globalsRegistered) {
        return;
    }

    Craft.Hyper.__globalsRegistered = true;
    Craft.Hyper.syncInputStores = syncAllHyperInputStores;

    Craft.Hyper.ElementSelect = Garnish.Base.extend({
        init(elementSelect: string, siteId: string) {
            const $elementSelect = $(elementSelect);
            const $siteId = $(siteId);

            if (!$elementSelect.length) {
                return;
            }

            const elementSelectInstance = $elementSelect.data('elementSelect');

            if (!elementSelectInstance) {
                return;
            }

            elementSelectInstance.on('selectElements', (event: { elements?: Array<{ siteId: number }> }) => {
                if (event.elements && event.elements.length) {
                    $siteId.val(event.elements[0].siteId);
                }
            });

            elementSelectInstance.on('removeElements', () => {
                $siteId.val('');
            });
        },
    });

    Craft.Hyper.Embed = Garnish.Base.extend({
        init(fieldId: string) {
            const $container = $(fieldId);
            const $spinner = $container.find('.spinner');
            const $response = $container.find('.hyper-embed-response');
            const hyperFieldId = $container.attr('data-hyper-field-id') || '';
            const linkTypeHandle = $container.attr('data-hyper-link-type-handle') || '';

            $('body').on('keyup blur change', `${fieldId} input`, debounce((event) => {
                const $target = $(event.target);
                const value = $target.val();
                const prevValue = $target.attr('data-value');

                if (value === prevValue) {
                    return;
                }

                $target.attr('data-value', value);

                $container.find('.favicon-icon').remove();
                $container.find('.link-embed-data').val(JSON.stringify());

                if (value) {
                    $spinner.removeClass('hidden');
                    $response.html('');

                    Craft.sendActionRequest('GET', Craft.getActionUrl('hyper/fields/preview-embed', {
                        value,
                        fieldId: hyperFieldId || undefined,
                        linkTypeHandle: linkTypeHandle || undefined,
                    }))
                        .then((response) => {
                            if (response?.data?.data) {
                                $container.find('.link-embed-data').val(JSON.stringify(response.data.data));

                                if (response.data.data.icon) {
                                    $container.append(`<div class="favicon-icon"><img src="${response.data.data.icon}"></div>`);
                                }
                            }
                        })
                        .catch(({ response }) => {
                            if (response?.data?.message) {
                                $response.html(`<div class="error">${response.data.message}</div>`);
                            }
                        })
                        .finally(() => {
                            $spinner.addClass('hidden');
                        });
                }
            }, 500));
        },
    });
}

/** Wrap Craft's jQuery `serializer` callback so Hyper hidden stores flush on every save/autosave. */
const wrapFormSerializer = (form: HTMLFormElement): void => {
    const $form = $(form);

    if ($form.data('hyperSerializerWrap')) {
        return;
    }

    const applyWrap = (): boolean => {
        const existing = $form.data('serializer');

        if (typeof existing !== 'function') {
            return false;
        }

        $form.data('serializer', () => {
            syncAllHyperInputStores();
            return existing();
        });
        $form.data('hyperSerializerWrap', true);

        return true;
    };

    if (applyWrap()) {
        return;
    }

    const startedAt = Date.now();
    const interval = window.setInterval(() => {
        if (applyWrap() || Date.now() - startedAt >= 10000) {
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
