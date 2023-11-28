<template>
    <div class="hyper-wrapper">
        <div class="hyper-header">
            <div class="hyper-header-type">
                <select v-model="link.handle" :disabled="settings.isStatic || settings.linkTypes.length < 2">
                    <option v-for="type in settings.linkTypes" :key="type.handle" :value="type.handle">{{ type.label }}</option>
                </select>
            </div>

            <div class="hyper-header-actions">
                <div v-if="settings.newWindow" class="hyper-header-new-window">
                    <lightswitch-field ref="switch" v-model="link.newWindow" :extra-small="true" :is-static="settings.isStatic">
                        <template #label>
                            <span class="hyper-header-new-window-label">{{ t('hyper', 'New Window') }}</span>
                        </template>
                    </lightswitch-field>
                </div>

                <div v-if="!settings.isStatic && (!settings.multipleLinks && linkType.tabCount > 1) || settings.multipleLinks" class="hyper-header-settings" @click.prevent="openSettings">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 512 512"><path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 39.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 39.4c6.9 6.2 9.6 15.9 6.4 24.6c-4.4 11.9-9.7 23.3-15.8 34.3l-4.7 8.1c-6.6 11-14 21.4-22.1 31.2c-5.9 7.2-15.7 9.6-24.5 6.8l-55.7-17.7c-13.4 10.3-28.2 18.9-44 25.4l-12.5 57.1c-2 9.1-9 16.3-18.2 17.8c-13.8 2.3-28 3.5-42.5 3.5s-28.7-1.2-42.5-3.5c-9.2-1.5-16.2-8.7-18.2-17.8l-12.5-57.1c-15.8-6.5-30.6-15.1-44-25.4L83.1 425.9c-8.8 2.8-18.6 .3-24.5-6.8c-8.1-9.8-15.5-20.2-22.1-31.2l-4.7-8.1c-6.1-11-11.4-22.4-15.8-34.3c-3.2-8.7-.5-18.4 6.4-24.6l43.3-39.4C64.6 273.1 64 264.6 64 256s.6-17.1 1.7-25.4L22.4 191.2c-6.9-6.2-9.6-15.9-6.4-24.6c4.4-11.9 9.7-23.3 15.8-34.3l4.7-8.1c6.6-11 14-21.4 22.1-31.2c5.9-7.2 15.7-9.6 24.5-6.8l55.7 17.7c13.4-10.3 28.2-18.9 44-25.4l12.5-57.1c2-9.1 9-16.3 18.2-17.8C227.3 1.2 241.5 0 256 0s28.7 1.2 42.5 3.5c9.2 1.5 16.2 8.7 18.2 17.8l12.5 57.1c15.8 6.5 30.6 15.1 44 25.4l55.7-17.7c8.8-2.8 18.6-.3 24.5 6.8c8.1 9.8 15.5 20.2 22.1 31.2l4.7 8.1c6.1 11 11.4 22.4 15.8 34.3zM256 336c44.2 0 80-35.8 80-80s-35.8-80-80-80s-80 35.8-80 80s35.8 80 80 80z" /></svg>
                </div>

                <div id="hyper-settings-template" class="hyper-menu" style="display: none;">
                    <ul class="padded" role="listbox" aria-hidden="true">
                        <li v-if="linkType.tabCount > 1">
                            <a data-icon="settings" role="option" tabindex="-1" @click.prevent="openSlideout">{{ t('app', 'Settings') }}</a>
                        </li>

                        <hr v-if="linkType.tabCount > 1">

                        <li>
                            <a class="error" data-icon="remove" role="option" tabindex="-1" @click.prevent="deleteBlock">{{ t('app', 'Delete') }}</a>
                        </li>
                    </ul>
                </div>

                <drag-handle v-if="settings.multipleLinks">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 944.1 945.2"><path d="M630.2,787.7c0-87-70.5-157.5-157.5-157.5s-157.5,70.5-157.5,157.5s70.5,157.5,157.5,157.5S630.2,874.7,630.2,787.7zM315.1,472.6c0-87-70.5-157.5-157.5-157.5S0,385.6,0,472.6s70.5,157.5,157.5,157.5S315.1,559.6,315.1,472.6z M630.2,157.5C630.2,70.5,559.6,0,472.6,0S315.1,70.5,315.1,157.5s70.5,157.5,157.5,157.5S630.2,244.5,630.2,157.5z M944.1,472.6c0-86.4-70-156.4-156.4-156.4s-156.4,70-156.4,156.4S701.3,629,787.7,629S944.1,559,944.1,472.6L944.1,472.6z" /></svg>
                </drag-handle>
            </div>
        </div>

        <!-- Generate inputs for all properties and custom fields in a single location -->
        <!-- This helps to consolidate values coming from all over (Vue, server-rendered fields, slide-out) -->
        <!-- Also note this is _before_ the `fieldsHtml` which override these values as they aren't stored in Vue -->
        <input v-for="(input, index) in generateInputData(linkData)" :key="index" type="hidden" :name="input.name" :value="input.value">

        <link-block-fields v-if="fieldsHtml" ref="fields" class="hyper-body-wrapper" :template="fieldsHtml" />
    </div>
</template>

<script>
import { set, escapeRegExp } from 'lodash-es';

import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import 'tippy.js/themes/light-border.css';

import { namespaceString } from '@utils/string';
import htmlize from '@utils/htmlize';

import LightswitchField from './settings/LightswitchField.vue';
import LinkBlockFields from './input/LinkBlockFields.vue';
import DragHandle from './input/DragHandle.vue';

export default {
    name: 'LinkBlock',

    components: {
        LightswitchField,
        LinkBlockFields,
        DragHandle,
    },

    props: {
        blockIndex: {
            type: Number,
            required: true,
            default: 0,
        },

        hyperField: {
            type: Object,
            default: () => { return {}; },
        },

        value: {
            type: Object,
            default: () => { return {}; },
        },
    },

    emits: ['delete'],

    data() {
        return {
            tippy: null,
            slideout: null,
            fieldsHtml: '',
            link: {},
        };
    },

    computed: {
        settings() {
            return this.hyperField.settings;
        },

        name() {
            return this.hyperField.name;
        },

        cacheKey() {
            return `${this.link.id}-${this.link.handle}`;
        },

        linkType() {
            return this.settings.linkTypes.find((linkType) => {
                return linkType.handle === this.link.handle;
            }) || {};
        },

        linkData() {
            const items = this.link;
            const result = {};

            // Filter out some properties that are render-only
            Object.keys(items).forEach((key) => {
                const item = items[key];

                if (key !== 'html' && key !== 'js') {
                    result[key] = item;
                }
            });

            return result;
        },
    },

    watch: {
        'link.handle': function(newValue, oldValue) {
            // Because the link handle is changed in `created()` this alao fires immediately.
            this.updateHtml();
            this.updateJs();
        },
    },

    created() {
        this.link.handle = this.settings.defaultLinkType;
        this.link = this.clone(this.value);

        // Some important type-casting, where things can get messed up where fields are stored in a non-numerical-keyed array,
        // which isn't something I thought possible! This causes incorrect behvaiour when sending the values to element-slideout.
        // Using `set()` or `setWith()` won't change the property type from Array to Object.
        // https://github.com/verbb/hyper/issues/97
        if (this.link.fields && Array.isArray(this.link.fields)) {
            this.link.fields = {};
        }

        if (this.link.customAttributes && Array.isArray(this.link.customAttributes)) {
            this.link.customAttributes = {};
        }
    },

    mounted() {
        this.$nextTick(() => {
            if (this.settings.multipleLinks) {
                this.initSettingsMenu();
            }
        });
    },

    methods: {
        getParsedLinkTypeHtml(html) {
            if (typeof html === 'string') {
                if (this.settings.isStatic) {
                    // Add a disabled attribute to everything is static
                    html = html.replace(/<(?:input|textarea|select)\s[^>]*/ig, '$& disabled');
                }

                return html.replace(new RegExp(`__HYPER_BLOCK_${this.settings.placeholderKey}__`, 'g'), this.blockIndex);
            }

            return '';
        },

        updateJs() {
            this.$nextTick(() => {
                // Add any JS required by fields
                let footHtml = this.hyperField.getCachedFieldJs(this.cacheKey);
                footHtml = this.getParsedLinkTypeHtml(footHtml);

                const $script = document.querySelector(`#hyper-${this.settings.namespacedId}-${this.blockIndex}-script`);

                if (footHtml) {
                    // But first check if already output. Otherwise, multiple bindings!
                    if ($script) {
                        $script.parentElement.removeChild($script);
                    }

                    Craft.appendBodyHtml(footHtml);
                }
            });
        },

        updateHtml() {
            this.fieldsHtml = this.getParsedLinkTypeHtml(this.hyperField.getCachedFieldHtml(this.cacheKey));
        },

        cacheHtml() {
            // Before dragging this block, save a copy of the current DOM to the cache. We ue this to restore back
            // when finished moving. This is because Vue's rendering will not retain any edited non-Vue HTML.
            if (this.$refs.fields) {
                // Use `clone()` and `htmlize()` to properly copy existing DOM content
                const $fieldsHtml = $(this.$refs.fields.$el.childNodes).clone();

                // Special-case for Redactor. We need to reset it to its un-initialized form
                // because it doesn't have better double-binding checks.
                if ($fieldsHtml.find('.redactor-box').length) {
                    // Rip out the `textarea` which is all we need
                    const $textarea = $fieldsHtml.find('.redactor-box textarea').htmlize();
                    $fieldsHtml.find('.redactor-box').replaceWith($textarea);
                }

                // Special-case for Selectize. We need to reset it to its un-initialized form
                // because it doesn't have better double-binding checks.
                if ($fieldsHtml.find('.selectize').length) {
                    $fieldsHtml.find('.selectize').each((index, element) => {
                        // This is absolutely ridiculous. Selectize strips out `<option>` elements, so we can't
                        // fetch the original data from the DOM. Instead, find it in the original link type template.

                        // Get the original field HTML from it's `data-layout-element` which contains the UID
                        const fieldUid = $(element).parents('[data-type]').data('layout-element');

                        if (fieldUid) {
                            // Get the original HTML
                            const $newHtml = $(this.linkType.html).find(`[data-layout-element="${fieldUid}"] .selectize`);

                            if ($newHtml.length) {
                                // Restore any selected elements
                                $newHtml.find('select').val($(element).find('select').val());

                                // Replace the HTML with the altered original template
                                element.innerHTML = $newHtml.htmlize();
                            }
                        }
                    });
                }

                const $assetFields = $fieldsHtml.find('[data-type="craft\\\\fields\\\\Assets"]');

                // Prevent multiple "Upload files" buttons when re-rendering Assets fields
                if ($assetFields.length) {
                    $assetFields.each((index, element) => {
                        // Asset field's JS will create the button if required
                        $(element).find('[data-icon="upload"').remove();
                    });
                }

                let fieldsHtml = $fieldsHtml.htmlize();

                // Revert to blank namespacing for `id` and `name` now that the order has changed
                const idPlaceholder = `${this.settings.namespacedId}-__HYPER_BLOCK_${this.settings.placeholderKey}__`;
                const namePlaceholder = `${this.settings.namespacedName}[__HYPER_BLOCK_${this.settings.placeholderKey}__]`;
                const currentId = `${this.settings.namespacedId}-${this.blockIndex}`;
                const currentName = `${this.settings.namespacedName}[${this.blockIndex}]`;

                fieldsHtml = fieldsHtml.replace(new RegExp(escapeRegExp(currentId), 'g'), idPlaceholder);
                fieldsHtml = fieldsHtml.replace(new RegExp(escapeRegExp(currentName), 'g'), namePlaceholder);

                this.hyperField.setCachedFieldHtml(this.cacheKey, fieldsHtml);
            }
        },

        getName(name) {
            return namespaceString(`${this.settings.namespacedName}[${this.blockIndex}]`, name);
        },

        initSettingsMenu() {
            const $el = this.$el.parentElement;
            const $settingsBtn = $el.querySelector('.hyper-header-settings');
            const $template = $el.querySelector('#hyper-settings-template');

            if ($template && $settingsBtn) {
                $template.style.display = 'block';

                this.tippy = tippy($settingsBtn, {
                    content: $template,
                    trigger: 'click',
                    allowHTML: true,
                    arrow: true,
                    interactive: true,
                    appendTo: document.body,
                    placement: 'bottom',
                    theme: 'light-border hyper-tippy-menu',
                    maxWidth: '300px',
                    zIndex: 100,
                    hideOnClick: true,
                });
            }
        },

        openSettings() {
            if (!this.settings.multipleLinks) {
                this.openSlideout();
            }
        },

        openSlideout() {
            const params = {
                fieldId: this.settings.fieldId,
                blockIndex: this.blockIndex,
                data: this.linkData,
            };

            this.slideout = new Craft.CpScreenSlideout('hyper/fields/input-settings', { params });
            this.slideout.open();

            this.slideout.on('submit', (e) => {
                // Update the model with the data in the slideout
                Object.entries(e.response.data).forEach(([key, value]) => {
                    if (key === 'fields') {
                        Object.entries(value).forEach(([fieldKey, field]) => {
                            set(this.link, `${key}.${fieldKey}`, field);
                        });
                    } else {
                        this.link[key] = value;
                    }
                });
            });

            if (this.tippy) {
                this.tippy.hide();
            }
        },

        deleteBlock() {
            if (this.tippy) {
                this.tippy.hide();
            }

            this.$emit('delete', this.blockIndex);
        },

        generateInputData(data, prepend, items = []) {
            if (this.settings.isStatic) {
                return [];
            }

            for (const propertyKey in data) {
                let property = data[propertyKey];
                const name = prepend ? `${prepend}[${propertyKey}]` : propertyKey;

                if (typeof property === 'object') {
                    this.generateInputData(property, name, items);
                } else {
                    // Special-case for booleans, we don't want to output `false` as the value, instead omit it
                    property = (property === false) ? null : property;

                    items.push({ name: this.getName(name), value: property });
                }
            }

            return items;
        },
    },
};

</script>

<style lang="scss">

.hyper-wrapper {
    border: 1px solid #d8dee7;
    border-radius: 6px;
    // overflow: hidden;
}

.hyper-header {
    padding: 0.35rem 0.75rem;
    border-radius: 6px 6px 0 0;
    display: flex;
    align-items: center;
    background-color: #f3f7fc;
    border-bottom: 1px solid #cdd9e4;
}

.hyper-header-type {
    position: relative;
    user-select: none;

    select {
        color: #667c92;
        font-size: 12px;
        font-weight: 500;
        border: 1px solid #cdd9e4;
        border-radius: 3px;
        padding: 3px 28px 3px 10px;
        background: #dee7ef;
        appearance: none;
    }

    &::after {
        position: absolute;
        content: '';
        top: 50%;
        right: 10px;
        width: 10px;
        height: 10px;
        transform: translateY(-50%);
        pointer-events: none;
        background: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGZpbGw9IiM2NjdjOTIiIHZpZXdCb3g9IjAgMCA1MTIgNTEyIj48cGF0aCBkPSJNMjMzLjQgNDA2LjZjMTIuNSAxMi41IDMyLjggMTIuNSA0NS4zIDBsMTkyLTE5MmMxMi41LTEyLjUgMTIuNS0zMi44IDAtNDUuM3MtMzIuOC0xMi41LTQ1LjMgMEwyNTYgMzM4LjcgODYuNiAxNjkuNGMtMTIuNS0xMi41LTMyLjgtMTIuNS00NS4zIDBzLTEyLjUgMzIuOCAwIDQ1LjNsMTkyIDE5MnoiPjwvcGF0aD48L3N2Zz4=");
    }
}

.hyper-header-type-icon {
    display: inline-flex;
    width: 10px;
    height: 10px;
    margin-left: 0.5rem;
    color: #78838e;

    svg {
        width: 100%;
        height: 100%;
    }
}

.hyper-header-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
}

.hyper-header-new-window {
    display: flex;
    align-items: center;
    user-select: none;
}

.hyper-header-new-window-label {
    color: #667c92;
    font-size: 12px;
    font-weight: 500;
    padding-right: 0.5rem;
    cursor: pointer;
    display: block;
    margin-top: -2px;
}

.hyper-header-settings,
.hyper-header-move {
    display: inline-flex;
    width: 12px;
    height: 12px;
    margin-left: 0.75rem;
    color: #78838e;
    cursor: pointer;

    svg {
        width: 100%;
        height: 100%;
    }
}

.hyper-header-move {
    cursor: move;
}

.hyper-body-wrapper > .flex-fields {
    align-content: flex-start;
    display: flex;
    flex-wrap: wrap;
    margin: 0 calc(var(--row-gap)*-1) calc(var(--row-gap)*-1);
    width: calc(100% + var(--row-gap)*2);

    // Duplicate Craft styles so we can append blocks to the body when dragging and not mess up styles
    @media only screen and (min-width: 1535px) {
        > :not(h2):not(hr):not(.line-break).width-25,
        > :not(h2):not(hr):not(.line-break).width-50,
        > :not(h2):not(hr):not(.line-break):last-child.width-25,
        > :not(h2):not(hr):not(.line-break):last-child.width-50 {
            width: 50%;
        }
    }

    > :not(h2):not(hr):not(.line-break),
    > :not(h2):not(hr):not(.line-break):last-child {
        position: relative;
        width: 100%;
    }

    > * {
        box-sizing: border-box;
        margin: 0 0 var(--row-gap)!important;
        padding: 0 var(--row-gap);
    }
}

// Required to properly override `!important`
#content :not(.meta) .hyper-body-wrapper > .flex-fields > *,
.hyper-body-wrapper > .flex-fields > * {
    margin-bottom: 1rem !important;
}

#content :not(.meta).hyper-body-wrapper > .flex-fields > :not(h2):not(hr):not(.line-break):before,
.hyper-body-wrapper > .flex-fields > :not(h2):not(hr):not(.line-break):before {
    display: none;
}

.hyper-body-wrapper {
    display: flex;
    gap: 1rem;
    padding: 0.75rem 0.75rem;
    background: #fff;
    border-radius: 0 6px 6px 0;

    .flex-fields {
        --row-gap: 0.5rem !important;

        margin-bottom: -1rem !important;
    }

    .flex-fields > * {
        .copytextbtn.small {
            padding: 0 5px;
        }

        .copytextbtn.small .copytextbtn__value {
            font-size: .6rem;
        }

        .copytextbtn .copytextbtn__icon {
            margin-top: -2px;
            padding: 0;
            width: 8px;
            font-size: 9px;
        }

        .heading {
            font-size: 12px;
            margin-bottom: 4px;

            label {
                font-weight: 600;
            }
        }

        .instructions {
            font-size: 12px;
            line-height: 1;
            margin-top: -0.2rem;
            margin-bottom: 0.5rem;
            color: #8a97a3;
        }
    }

    .status-badge {
        display: none;
    }
}

[v-cloak] {
    display: none;
}

</style>
