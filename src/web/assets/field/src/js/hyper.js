// CSS needs to be imported here as it's treated as a module
import '@/scss/style.scss';

// Accept HMR as per: https://vitejs.dev/guide/api-hmr.html
if (import.meta.hot) {
    import.meta.hot.accept();
}

import { debounce } from 'lodash-es';

//
// Start Vue Apps
//

if (typeof Craft.Hyper === typeof undefined) {
    Craft.Hyper = {};
}

import { createVueApp } from './config';

import HyperInput from './components/HyperInput.vue';
import HyperSettings from './components/HyperSettings.vue';

const HYPER_INPUT_SELECTOR = '[data-hyper-auto-mount="input"], .hyper-input-component';
const HYPER_SETTINGS_SELECTOR = '[data-hyper-auto-mount="settings"], .hyper-configurator';
// Guard each mount root so observer-driven scans cannot double-mount Vue apps.
const mountedRoots = new WeakSet();

const mountInputRoot = (root) => {
    if (!root || mountedRoots.has(root)) {
        return;
    }

    const app = createVueApp({
        components: {
            HyperInput,
        },
    });

    app.mount(root);
    mountedRoots.add(root);
};

const mountSettingsRoot = (root) => {
    if (!root || mountedRoots.has(root)) {
        return;
    }

    const app = createVueApp({
        components: {
            HyperSettings,
        },
    });

    app.mount(root);
    mountedRoots.add(root);
};

const rootsForSelector = (scope, selector) => {
    if (!scope) {
        return [];
    }

    const roots = [];

    if (scope.matches && scope.matches(selector)) {
        roots.push(scope);
    }

    roots.push(...scope.querySelectorAll(selector));

    return roots;
};

Craft.Hyper.mountAll = (scope = document) => {
    rootsForSelector(scope, HYPER_INPUT_SELECTOR).forEach((root) => {
        mountInputRoot(root);
    });

    rootsForSelector(scope, HYPER_SETTINGS_SELECTOR).forEach((root) => {
        mountSettingsRoot(root);
    });
};

Craft.Hyper.startAutoMountObserver = () => {
    if (Craft.Hyper.__autoMountObserverStarted) {
        return;
    }

    Craft.Hyper.__autoMountObserverStarted = true;

    // New Hyper roots can appear in slideouts or dynamically injected markup.
    // Observing body lets us auto-mount without per-field inline init JS.
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }

                Craft.Hyper.mountAll(node);
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
};

Craft.Hyper.Input = Garnish.Base.extend({
    init(idPrefix) {
        const root = document.querySelector(`#${idPrefix}-field ${HYPER_INPUT_SELECTOR}`);

        mountInputRoot(root);
    },
});

Craft.Hyper.Settings = Garnish.Base.extend({
    init(inputNamePrefix, settings) {
        this.inputNamePrefix = inputNamePrefix;
        this.inputIdPrefix = Craft.formatInputId(this.inputNamePrefix);

        const root = document.querySelector(`.${this.inputIdPrefix}-hyper-configurator`);

        mountSettingsRoot(root);
    },
});

Craft.Hyper.ElementSelect = Garnish.Base.extend({
    init(elementSelect, siteId) {
        const $elementSelect = $(elementSelect);
        const $siteId = $(siteId);

        if ($elementSelect) {
            const elementSelect = $elementSelect.data('elementSelect');

            if (elementSelect) {
                elementSelect.on('selectElements', (event) => {
                    if (event.elements && event.elements.length) {
                        $siteId.val(event.elements[0].siteId);
                    }
                });

                elementSelect.on('removeElements', (event) => {
                    $siteId.val('');
                });
            }
        }
    },
});

Craft.Hyper.Embed = Garnish.Base.extend({
    init(fieldId) {
        const $container = $(fieldId);
        const $spinner = $container.find('.spinner');
        const $response = $container.find('.hyper-embed-response');

        $('body').on('keyup blur change', `${fieldId} input`, debounce((e) => {
            const value = $(e.target).val();
            const prevValue = $(e.target).attr('data-value');

            // Prevent from firing unless the value has actually changed
            if (value === prevValue) {
                return;
            }

            // Update the previous value
            $(e.target).attr('data-value', value);

            // Reset some bits
            $container.find('.favicon-icon').remove();
            $container.find('.link-embed-data').val(JSON.stringify());

            if (value) {
                $spinner.removeClass('hidden');
                $response.html('');

                Craft.sendActionRequest('GET', Craft.getActionUrl('hyper/fields/preview-embed', {
                    value,
                }))
                    .then((response) => {
                        if (response && response.data && response.data.data) {
                        // Update the hidden input with the JSON data. That's our field value, not the inputted URL
                            $container.find('.link-embed-data').val(JSON.stringify(response.data.data));

                            if (response.data.data.icon) {
                                $container.append(`<div class="favicon-icon"><img src="${response.data.data.icon}"></div>`);
                            }
                        }
                    })
                    .catch(({ response }) => {
                        if (response && response.data && response.data.message) {
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


$(document).ready(() => {
    Craft.Hyper.mountAll(document);
    Craft.Hyper.startAutoMountObserver();

    // We don't want to send the Hyper block data to the server, as the content is serialized ourselves with the field.
    // We do this by changing the namespace of field content to `hyperData`, which is used in our Hyper field data JSON.
    // This method hooks into the ElementEditor.js serialization
    const $mainForm = $('form#main-form');

    if ($mainForm.length) {
        const elementEditor = $mainForm.data('elementEditor');

        if (elementEditor) {
            elementEditor.on('serializeForm', (e) => {
                e.data.serialized = e.data.serialized.replace(/&hyperData[^&]*/g, '');
            });
        }
    }
});
