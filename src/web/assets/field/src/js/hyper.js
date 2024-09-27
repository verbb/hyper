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

Craft.Hyper.Input = Garnish.Base.extend({
    init(idPrefix) {
        const app = createVueApp({
            components: {
                HyperInput,
            },
        });

        app.mount(`#${idPrefix}-field .hyper-input-component`);
    },
});

Craft.Hyper.Settings = Garnish.Base.extend({
    init(inputNamePrefix, settings) {
        this.inputNamePrefix = inputNamePrefix;
        this.inputIdPrefix = Craft.formatInputId(this.inputNamePrefix);

        const app = createVueApp({
            components: {
                HyperSettings,
            },

            data() {
                return {
                    settings,
                };
            },
        });

        app.mount(`.${this.inputIdPrefix}-hyper-configurator`);
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

                Craft.sendActionRequest('GET', `hyper/fields/preview-embed?value=${value}`)
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


// Re-broadcast the custom `vite-script-loaded` event so that we know that this module has loaded
// Needed because when <script> tags are appended to the DOM, the `onload` handlers
// are not executed, which happens in the field Settings page, and in slideouts
// Do this after the document is ready to ensure proper execution order
$(document).ready(() => {
    // Create a global-loaded flag when switching entry types. This won't be fired multiple times.
    Craft.HyperReady = true;

    document.dispatchEvent(new CustomEvent('vite-script-loaded', { detail: { path: 'field/src/js/hyper.js' } }));

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
