<template>
    <div class="hyper-links">
        <slot></slot>

        <slick-list
            v-if="settings.multipleLinks"
            v-model:list="proxyValue"
            class="hc-sidebar-items"
            v-bind="dragOptions"
            @sort-start="onStartDrag"
            @sort-end="onEndDrag"
        >
            <slick-item v-for="(element, index) in proxyValue" :key="element.id" :index="index" class="hyper-link">
                <link-block :ref="`block-${index}`" :key="element.id" v-model="proxyValue[index]" :block-index="index" :hyper-field="this" @delete="deleteBlock" />
            </slick-item>
        </slick-list>

        <div v-else>
            <link-block v-for="(link, index) in proxyValue" :key="index" v-model="proxyValue[index]" :block-index="index" :hyper-field="this" />
        </div>

        <div v-if="settings.multipleLinks && !settings.isStatic" class="h-add-container">
            <div v-if="settings.linkTypes.length > 1">
                <button type="button" class="btn dashed icon add menubtn h-add-link-btn" :class="canAdd ? '' : 'disabled'" :disabled="!canAdd">{{ t('hyper', 'Add a link') }}</button>

                <div class="hyper-linktypes-template hyper-menu" style="display: none;">
                    <ul>
                        <li v-for="(linkType, index) in settings.linkTypes" :key="index">
                            <a class="menu-item" role="option" tabindex="-1" @click.prevent="newLinkBlock(linkType.handle)">{{ linkType.label }}</a>
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
import { get, debounce } from 'lodash-es';

import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import 'tippy.js/themes/light-border.css';

import { getId } from '@utils/string';
import { normalizeJson } from '@utils/object';

import LinkBlock from './LinkBlock.vue';

export default {
    name: 'HyperInput',

    components: {
        LinkBlock,
        SlickList,
        SlickItem,
    },

    props: {
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

        valueResources: {
            type: String,
            default: '',
        },
    },

    data() {
        return {
            tippy: null,
            proxyValue: [],
            proxyValueResources: [],
            cachedFieldHtml: {},
            cachedFieldJs: {},
            rendered: false,
            initValue: null,
            lastSerializedValue: null,
            portalLayerEl: null,
            portalScopeId: `hyper:${this.handle}:${Math.random().toString(36).slice(2, 10)}`,
            portals: new Map(),
            portalUpdateFns: new Map(),
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

    watch: {
        proxyValue: {
            deep: true,
            handler(newValue) {
                this.syncValueToStore(newValue);
            },
        },
    },

    created() {
        this.proxyValue = JSON.parse(this.clone(this.value));
        this.proxyValueResources = JSON.parse(this.clone(this.valueResources));

        // Prepare all link blocks by caching their HTML/JS
        this.proxyValue.forEach((link, index) => {
            // Server-generated HTML/JS is stored separate to the model values
            const resources = this.proxyValueResources[index] || [];

            this.cacheLinkTypeResources(link, resources);
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
        this.ensurePortalLayer();

        this.$nextTick(() => {
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

            // Let the component know we're finished rendering, and to start updating changes
            this.rendered = true;

            // Once the field has settled, take a snapshot of the value for the field. This helps us compare if anything
            // has changed, which it often does as jQuery kicks in, or Vue for other fields in Vizy blocks.
            setTimeout(() => {
                this.initValue = this.clone(this.proxyValue);
                this.lastSerializedValue = this.serializeValue(this.initValue);
            }, 1000);
        });
    },

    beforeUnmount() {
        // Destroy all portals
        for (const [key, entry] of this.portals.entries()) {
            entry.observer?.disconnect();
            $(entry.el).off();
            entry.el?.remove();
        }

        this.portals.clear();
        this.portalUpdateFns.clear();

        if (this.portalLayerEl) {
            this.portalLayerEl.remove();
            this.portalLayerEl = null;
        }
    },

    methods: {
        syncValueToStore(value, force = false) {
            // Don't update the DOM until the field is fully rendered.
            if (!this.rendered || !this.$el) {
                return;
            }

            // Preserve startup grace period unless this is an explicit forced sync (e.g. link-type change).
            if (!force && this.lastSerializedValue === null) {
                return;
            }

            const $dataStore = this.$el.querySelector('[data-store]');
            const $dataStoreDebug = this.$el.querySelector('[data-store-debug]');
            const updatedValue = this.serializeValue(value);

            // Check if there's an actual different between the last and new state (as serialized strings)
            if (this.lastSerializedValue === updatedValue) {
                return;
            }

            if ($dataStore) {
                $dataStore.value = updatedValue;
            }

            if ($dataStoreDebug) {
                $dataStoreDebug.innerHTML = updatedValue;
            }

            this.lastSerializedValue = updatedValue;
        },

        forceSyncValueToStore() {
            this.syncValueToStore(this.proxyValue, true);
        },

        ensurePortalLayer() {
            if (this.portalLayerEl) {
                return;
            }

            const el = document.createElement('div');
            el.className = 'hyper-portals-layer';
            el.style.position = 'absolute';
            el.style.left = '-99999px';
            el.style.top = '-99999px';
            el.style.width = '0';
            el.style.height = '0';
            el.style.overflow = 'hidden';

            this.portalLayerEl = el;
            this.$el.appendChild(this.portalLayerEl);
        },

        portalKey(cacheKey) {
            // Scope portals to this Hyper instance to avoid collisions with nested/multiple fields
            return `${this.portalScopeId}:${cacheKey}`;
        },

        portalEventName(cacheKey) {
            return `hyper:${this.portalScopeId}:portal:update:${cacheKey}`;
        },

        cacheLinkTypeResources(link, resources = {}) {
            this.settings.linkTypes.forEach((linkType) => {
                let blockHtml = get(resources, `html.${linkType.handle}`);
                let blockJs = get(resources, `js.${linkType.handle}`);

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

        ensurePortal(cacheKey) {
            this.ensurePortalLayer();
            const key = this.portalKey(cacheKey);
            let entry = this.portals.get(key);

            if (!entry) {
                const el = document.createElement('div');
                el.dataset.hyperPortal = cacheKey;
                el.dataset.hyperPortalScope = this.portalScopeId;

                // Initial HTML for first render
                const html = this.getCachedFieldHtml(cacheKey);
                el.innerHTML = html || '';

                // Init Craft UI ONCE
                Craft.initUiElements(el);

                // Observe changes ONCE
                const emitUpdate = this.debouncedPortalUpdate(cacheKey);

                const mo = new MutationObserver(() => { return emitUpdate(); });
                mo.observe(el, {
                    childList: true,
                    attributes: true,
                    subtree: true,
                    characterData: true,
                });

                $(el).on('input change', 'input, textarea, select', () => { return emitUpdate(); });

                entry = { el, observer: mo, jsAppended: false };
                this.portals.set(key, entry);

                // Keep it “parked” by default
                this.portalLayerEl.appendChild(el);
            }

            return entry;
        },

        attachPortal(cacheKey, mountEl) {
            if (!mountEl) {
                return;
            }

            const entry = this.ensurePortal(cacheKey);

            // Move persistent DOM into this block’s visible mount point
            if (entry.el.parentNode !== mountEl) {
                mountEl.innerHTML = '';
                mountEl.appendChild(entry.el);
            }

            // Append JS once per blockId
            if (!entry.jsAppended) {
                const js = this.getCachedFieldJs(cacheKey);

                if (js) {
                    Craft.appendBodyHtml(js);
                }

                entry.jsAppended = true;
            }
        },

        detachPortal(cacheKey) {
            if (!this.portalLayerEl) {
                return;
            }

            const key = this.portalKey(cacheKey);
            const entry = this.portals.get(key);

            if (!entry) {
                return;
            }

            if (entry.el.parentNode !== this.portalLayerEl) {
                this.portalLayerEl.appendChild(entry.el);
            }
        },

        destroyPortal(cacheKey) {
            const key = this.portalKey(cacheKey);
            const entry = this.portals.get(key);

            if (!entry) {
                return;
            }

            // Stop observers/listeners
            entry.observer?.disconnect();
            $(entry.el).off();

            // Remove DOM
            entry.el?.remove();

            // Cleanup maps
            this.portals.delete(key);
            this.portalUpdateFns.delete(key);
        },

        debouncedPortalUpdate(cacheKey) {
            const key = this.portalKey(cacheKey);

            // Return a stable debounced fn per blockId
            if (!this.portalUpdateFns.has(key)) {
                const fn = debounce(() => {
                    this.$events.emit(this.portalEventName(cacheKey));
                }, 50);

                this.portalUpdateFns.set(key, fn);
            }

            return this.portalUpdateFns.get(key);
        },

        getPortalElement(cacheKey) {
            const key = this.portalKey(cacheKey);

            return this.portals.get(key)?.el || null;
        },

        getParsedBlockHtml(html, cacheKey) {
            if (typeof html === 'string') {
                const linkId = this.getLinkIdFromCacheKey(cacheKey);
                return html.replace(new RegExp(`__HYPER_BLOCK_${this.settings.placeholderKey}__`, 'g'), linkId);
            }

            return '';
        },

        getLinkIdFromCacheKey(cacheKey) {
            if (!cacheKey) {
                return '';
            }

            return String(cacheKey).split('-')[0];
        },

        getCachedFieldHtml(blockId) {
            let html = this.cachedFieldHtml[blockId];

            // When serialized, htmlentities are used, so decode them
            if (typeof html === 'string') {
                html = html.replace(/&#(\d+);/g, (match, dec) => {
                    return String.fromCharCode(dec);
                });
            }

            return this.getParsedBlockHtml(html, blockId);
        },

        setCachedFieldHtml(blockId, value) {
            this.cachedFieldHtml[blockId] = value;
        },

        getCachedFieldJs(blockId) {
            let html = this.cachedFieldJs[blockId];

            // When serialized, htmlentities are used, so decode them
            if (typeof html === 'string') {
                html = html.replace(/&#(\d+);/g, (match, dec) => {
                    return String.fromCharCode(dec);
                });
            }

            return this.getParsedBlockHtml(html, blockId);
        },

        setCachedFieldJs(blockId, value) {
            this.cachedFieldJs[blockId] = value;
        },

        newLinkBlock(handle) {
            const newLink = {
                id: getId(),
                isNew: true,
                handle,
            };

            // Apply default new window setting
            if (this.settings.newWindow) {
                newLink.newWindow = this.settings.defaultNewWindow ?? false;
            }

            // Cache HTML/JS for this new link across all link types
            this.cacheLinkTypeResources(newLink);

            // Add it to the link collection
            this.proxyValue.push(newLink);

            if (this.tippy) {
                this.tippy.hide();
            }
        },

        deleteBlock(index) {
            const link = this.proxyValue[index];

            // Destroy all portals for that link (across all link types)
            if (link?.id) {
                this.settings.linkTypes.forEach((linkType) => {
                    const cacheKey = `${link.id}-${linkType.handle}`;
                    this.destroyPortal(cacheKey);
                });
            }

            this.proxyValue.splice(index, 1);
        },

        onStartDrag() {
            this.$el.classList.add('hyper-dragging');
            this.clearTextSelection();
        },

        onEndDrag() {
            this.$el.classList.remove('hyper-dragging');
            this.clearTextSelection();
        },

        clearTextSelection() {
            if (window.getSelection) {
                const selection = window.getSelection();
                selection?.removeAllRanges?.();
            } else if (document.selection) {
                document.selection.empty();
            }
        },

        serializeValue(value) {
            // Ensure that we normalize this object first, to ensure it's consistent with PHP-JSON notation
            // Parse original value to use as reference for type comparison
            const original = JSON.parse(this.clone(this.value));

            // Normalize current value against original reference
            const normalized = normalizeJson(value, original);

            // Serialize normalized output
            return JSON.stringify(normalized);
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

.hyper-drag-helper,
.hyper-drag-helper * {
    user-select: none;
}

.hyper-links.hyper-dragging,
.hyper-links.hyper-dragging * {
    user-select: none;
    cursor: grabbing;
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
