<template>
    <div class="hyper-links">
        <slick-list
            v-if="settings.multipleLinks"
            v-model:list="proxyValue"
            class="hc-sidebar-items"
            v-bind="dragOptions"
            @sort-start="onStartDrag"
            @sort-end="onEndDrag"
        >
            <slick-item v-for="(element, index) in proxyValue" :key="element.id" :index="index" class="hyper-link">
                <link-block :ref="`block-${index}`" :key="element.id" :value="element" :block-index="index" :hyper-field="this" @delete="deleteBlock" />
            </slick-item>
        </slick-list>

        <div v-else>
            <link-block v-for="(link, index) in proxyValue" :key="index" :value="link" :block-index="index" :hyper-field="this" />
        </div>

        <div v-if="settings.multipleLinks && !settings.isStatic" class="h-add-container">
            <div v-if="settings.linkTypes.length > 1">
                <button type="button" class="btn dashed icon add menubtn h-add-link-btn" :class="canAdd ? '' : 'disabled'" :disabled="!canAdd">{{ t('hyper', 'Add a link') }}</button>

                <div class="hyper-linktypes-template hyper-menu" style="display: none;">
                    <ul class="padded" role="listbox" aria-hidden="true">
                        <li v-for="(linkType, index) in settings.linkTypes" :key="index">
                            <a role="option" tabindex="-1" @click.prevent="newLinkBlock(linkType.handle)">{{ linkType.label }}</a>
                        </li>
                    </ul>
                </div>
            </div>

            <div v-else>
                <button type="button" class="btn dashed icon add h-add-link-btn" :class="canAdd ? '' : 'disabled'" :disabled="!canAdd" @click.prevent="newLinkBlock(settings.linkTypes[0].handle)">{{ t('hyper', 'Add {type}', { type: settings.linkTypes[0].label }) }}</button>
            </div>
        </div>
    </div>
</template>

<script>
import { SlickList, SlickItem } from 'vue-slicksort';
import { get } from 'lodash-es';

import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import 'tippy.js/themes/light-border.css';

import { getId } from '@utils/string';

import LinkBlock from './LinkBlock.vue';

export default {
    name: 'HyperInput',

    components: {
        LinkBlock,
        SlickList,
        SlickItem,
    },

    props: {
        name: {
            type: String,
            required: true,
            default: '',
        },

        handle: {
            type: String,
            required: true,
            default: '',
        },

        elementId: {
            type: [Number, String],
            default: '',
        },

        elementType: {
            type: String,
            default: '',
        },

        elementSiteId: {
            type: [Number, String],
            default: 0,
        },

        elementDraftId: {
            type: [Number, String],
            default: '',
        },

        elementRevisionId: {
            type: [Number, String],
            default: '',
        },

        inputSettings: {
            type: String,
            default: '',
        },

        value: {
            type: String,
            default: '',
        },
    },

    data() {
        return {
            tippy: null,
            proxyValue: [],
            cachedFieldHtml: {},
            cachedFieldJs: {},
        };
    },

    computed: {
        dragOptions() {
            return {
                lockAxis: 'y',
                axis: 'y',
                helperClass: 'hyper-drag-helper',
                useDragHandle: true,
                lockToContainerEdges: true,
                lockOffset: '0',
            };
        },

        settings() {
            return JSON.parse(this.inputSettings);
        },

        canAdd() {
            if (this.settings.maxLinks && this.proxyValue.length >= this.settings.maxLinks) {
                return false;
            }

            return true;
        },
    },

    created() {
        this.proxyValue = JSON.parse(this.clone(this.value));

        // Prepare all link blocks by caching their HTML/JS
        this.proxyValue.forEach((link) => {
            this.setCache(link);
        });

        // Check if under the threshold of min links, and create new ones
        if (this.settings.minLinks && this.proxyValue.length <= this.settings.minLinks) {
            const toCreate = this.settings.minLinks - this.proxyValue.length;

            for (let i = 0; i < toCreate; i++) {
                this.newLinkBlock(this.settings.defaultLinkType);
            }
        }
    },

    mounted() {
        this.$nextTick(() => {
            // Modify the jQuery data for `ElementEditor.js`, otherwise a change will be detected, and the draft saved.
            // This is due to jQuery kicking in and serializing the form before Vue kicks in.
            this.updateInitialSerializedValue();

            // Ensure we target just _this_ Hyper field, and not any nested Hyper fields
            const $container = this.$el.querySelector(':scope > .h-add-container');

            if ($container) {
                const $template = $container.querySelector('.hyper-linktypes-template');

                if ($template) {
                    $template.style.display = 'block';

                    this.tippy = tippy($container.querySelector('.h-add-link-btn'), {
                        content: $template,
                        trigger: 'click',
                        allowHTML: true,
                        arrow: true,
                        interactive: true,
                        appendTo: document.body,
                        placement: 'bottom-end',
                        theme: 'light-border hyper-tippy-menu',
                        maxWidth: '300px',
                        zIndex: 100,
                        hideOnClick: true,
                    });
                }
            }
        });
    },

    methods: {
        updateInitialSerializedValue() {
            const $mainForm = $('form#main-form');

            if ($mainForm.length) {
                const elementEditor = $mainForm.data('elementEditor');

                if (elementEditor) {
                    // Serialize the form again, now Vue is ready
                    const formData = elementEditor.serializeForm(true);

                    // Update the local cache, and the DOM cache
                    elementEditor.lastSerializedValue = formData;
                    $mainForm.data('initialSerializedValue', formData);
                }
            }
        },

        setCache(link) {
            // For each link type, create HTML/JS. We use the Link's HTML/JS for the current link type
            // if it exists, because it may already have data. If we switch to another link type, it's fresh.
            this.settings.linkTypes.forEach((linkType) => {
                let blockHtml = get(link, `html.${linkType.handle}`);
                let blockJs = get(link, `js.${linkType.handle}`);

                if (!blockHtml) {
                    blockHtml = linkType.html;
                }

                if (!blockJs) {
                    blockJs = linkType.js;
                }

                const cacheKey = `${link.id}-${linkType.handle}`;

                if (blockHtml) {
                    this.setCachedFieldHtml(cacheKey, blockHtml);
                }

                if (blockJs) {
                    this.setCachedFieldJs(cacheKey, blockJs);
                }
            });
        },

        getCachedFieldHtml(blockId) {
            return this.cachedFieldHtml[blockId];
        },

        setCachedFieldHtml(blockId, value) {
            this.cachedFieldHtml[blockId] = value;
        },

        getCachedFieldJs(blockId) {
            return this.cachedFieldJs[blockId];
        },

        setCachedFieldJs(blockId, value) {
            this.cachedFieldJs[blockId] = value;
        },

        onStartDrag() {
            // Before we start dragging, cache the DOM contents of fields, which will be reset when Vue re-renders the
            // component once it's been moved. We need to do this for all link blocks in the field because of how
            // the re-render process works (other blocks other than the one moved will update).
            Object.values(this.$refs).forEach((linkComponent) => {
                if (linkComponent[0]) {
                    linkComponent[0].cacheHtml();
                }
            });
        },

        onEndDrag() {
            // When finishing dragging, update all link blocks with their cached HTML to restore what was.
            // For JS, because we're re-rendering HTML, the originally-bound JS will no longer work, so we
            // append it again, but there's also smarts to prevent duplication.
            this.updateFieldContent();
        },

        updateFieldContent() {
            Object.values(this.$refs).forEach((linkComponent) => {
                // Slight delay required to ensure that the DOM has caught up
                setTimeout(() => {
                    if (linkComponent[0]) {
                        linkComponent[0].updateHtml();
                        linkComponent[0].updateJs();
                    }
                }, 50);
            });
        },

        newLinkBlock(handle) {
            const newLink = {
                id: getId(),
                handle,
            };

            // Apply default new window setting
            if (this.settings.newWindow) {
                newLink.newWindow = this.settings.defaultNewWindow ?? false;
            }

            // Add it to the link collection
            this.proxyValue.push(newLink);

            // Generate the HTML/JS caches
            this.setCache(newLink);

            if (this.tippy) {
                this.tippy.hide();
            }
        },

        deleteBlock(index) {
            this.proxyValue.splice(index, 1);

            // Ensure that we update the name attributes of field when deleting things
            this.updateFieldContent();
        },
    },
};

</script>

<style lang="scss">

.hyper-link {
    margin-bottom: 0.75rem;

    // Fix for dragging in the element slide-out
    z-index: 100;
}

.hyper-iframe-container {
    position: relative;
    overflow: hidden;
    width: 100%;
    padding-top: 56.25%; // 16:9 Aspect Ratio

    iframe {
        position: absolute;
        top: 0;
        left: 0;
        bottom: 0;
        right: 0;
        width: 100%;
        height: 100%;
    }
}

</style>
