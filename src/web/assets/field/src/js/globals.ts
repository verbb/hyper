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

    Craft.Hyper.Embed = Garnish.Base.extend({
        init(fieldId: string) {
            const $container = $(fieldId);
            const $spinner = $container.find('.spinner');
            const $response = $container.find('.hyper-embed-response');
            const hyperFieldId = $container.attr('data-hyper-field-id') || '';
            const linkTypeHandle = $container.attr('data-hyper-link-type-handle') || '';
            const $ownerField = $container.closest('[data-hyper-input]');
            const ownerElementId = $ownerField.attr('data-hyper-element-id') || '';
            const ownerSiteId = $ownerField.attr('data-hyper-site-id') || '';
            // Correlate async responses with the latest typed URL (Astra H3-A13).
            let fetchGeneration = 0;

            $('body').on('keyup blur change', `${fieldId} input`, debounce((event: JQueryEventObject) => {
                const $target = $(event.target);
                const value = String($target.val() ?? '');
                const prevValue = $target.attr('data-value');

                if (value === prevValue) {
                    return;
                }

                $target.attr('data-value', value);
                const generation = ++fetchGeneration;

                $container.find('.favicon-icon').remove();
                $response.empty();

                // Persist the latest URL immediately so a slow/failed fetch cannot wipe it.
                const $embedData = $container.find('.link-embed-data');

                if (value) {
                    $embedData.val(JSON.stringify({ url: value }));
                    $spinner.removeClass('hidden');

                    Craft.sendActionRequest('GET', Craft.getActionUrl('hyper/fields/preview-embed', {
                        value,
                        fieldId: hyperFieldId || undefined,
                        linkTypeHandle: linkTypeHandle || undefined,
                        elementId: ownerElementId || undefined,
                        siteId: ownerSiteId || undefined,
                    }))
                        .then((response) => {
                            if (generation !== fetchGeneration) {
                                return;
                            }

                            if (response?.data?.data) {
                                const embedPayload = response.data.data as Record<string, unknown>;
                                $embedData.val(JSON.stringify(embedPayload));

                                const icon = embedPayload.icon;

                                if (typeof icon === 'string' && icon) {
                                    const wrap = document.createElement('div');
                                    wrap.className = 'favicon-icon';
                                    const img = document.createElement('img');
                                    img.src = icon;
                                    wrap.appendChild(img);
                                    $container.append(wrap);
                                }
                            }
                        })
                        .catch(({ response }) => {
                            if (generation !== fetchGeneration) {
                                return;
                            }

                            if (response?.data?.message) {
                                const err = document.createElement('div');
                                err.className = 'error';
                                err.textContent = String(response.data.message);
                                $response.empty().append(err);
                            }
                        })
                        .finally(() => {
                            if (generation === fetchGeneration) {
                                $spinner.addClass('hidden');
                            }
                        });
                } else {
                    // Clear invalidates pending results by bumping generation above.
                    $embedData.val(JSON.stringify({}));
                    $spinner.addClass('hidden');
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
