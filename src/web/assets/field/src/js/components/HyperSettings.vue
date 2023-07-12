<template>
    <div class="hc-wrapper">
        <div class="hc-sidebar">
            <slick-list v-model:list="proxyLinkTypes" class="hc-sidebar-items" v-bind="dragOptions">
                <slick-item v-for="(element, index) in proxyLinkTypes" :key="element.handle" :index="index">
                    <div :class="['hc-sidebar-item', selectedLinkType.handle === element.handle ? 'sel' : '', element.hasErrors ? 'has-errors' : '']" @click.prevent="selectTab(element)">
                        <lightswitch-field v-model="element.enabled" :name="getName(`linkTypes[${element.handle}][enabled]`)" :extra-small="true" />

                        <input type="hidden" :name="getName(`linkTypes[${element.handle}][sortOrder]`)" :value="index">

                        <span class="hc-label-text">{{ element.label }}</span>

                        <drag-handle class="hc-sidebar-item-move hc-move">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 944.1 945.2"><path d="M630.2,787.7c0-87-70.5-157.5-157.5-157.5s-157.5,70.5-157.5,157.5s70.5,157.5,157.5,157.5S630.2,874.7,630.2,787.7zM315.1,472.6c0-87-70.5-157.5-157.5-157.5S0,385.6,0,472.6s70.5,157.5,157.5,157.5S315.1,559.6,315.1,472.6z M630.2,157.5C630.2,70.5,559.6,0,472.6,0S315.1,70.5,315.1,157.5s70.5,157.5,157.5,157.5S630.2,244.5,630.2,157.5z M944.1,472.6c0-86.4-70-156.4-156.4-156.4s-156.4,70-156.4,156.4S701.3,629,787.7,629S944.1,559,944.1,472.6L944.1,472.6z" /></svg>
                        </drag-handle>
                    </div>
                </slick-item>
            </slick-list>

            <button type="button" class="btn add icon menubtn">{{ t('hyper', 'New link type') }}</button>

            <div id="hyper-linktypes-template" class="hyper-menu" style="display: none;">
                <ul class="padded" role="listbox" aria-hidden="true">
                    <li v-for="(linkType, index) in registeredLinkTypes" :key="index">
                        <a role="option" tabindex="-1" @click.prevent="newLinkType(linkType.value, linkType.label)">{{ linkType.label }}</a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="hc-pane">
            <div v-if="isEmpty(selectedLinkType)" class="hc-pane-empty">
                <div class="hc-pane-empty-placeholder">{{ t('hyper', 'Select a link type to edit.') }}</div>

                <!-- eslint-disable-next-line -->
                <svg xmlns="http://www.w3.org/2000/svg" width="68px" height="32.9px" viewBox="0 0 68 32.9"><path fill="currentColor" d="M8.2,32.9c-0.3,0-0.6-0.2-0.7-0.5c-0.7-2.2-2.3-3.6-3.9-5.1c-1.3-1.2-2.6-2.5-3.6-4.1c-0.1-0.2-0.1-0.5,0-0.7s0.3-0.4,0.6-0.4c2.1-0.2,9.2-1,11.8-3.2c0.3-0.3,0.8-0.2,1.1,0.1c0.3,0.3,0.2,0.8-0.1,1.1c-2.5,2.1-8.1,3-11.4,3.4c0.8,1,1.7,1.9,2.6,2.8C6.4,27.8,8.1,29.4,9,32c0.1,0.4-0.1,0.8-0.5,0.9C8.4,32.9,8.3,32.9,8.2,32.9z M30,30.8c-8.1,0-16.5-1.8-24-5.4c-0.4-0.2-0.5-0.6-0.4-1s0.6-0.5,1-0.4c14.3,6.9,32.1,7,44.2,0.4c9-4.9,14.4-13.1,15.7-23.8C66.6,0.2,67,0,67.4,0c0.4,0.1,0.7,0.4,0.7,0.8C66.7,12,61,20.6,51.5,25.7C45.4,29.1,37.8,30.8,30,30.8z"/></svg>
            </div>

            <div v-for="(linkType) in proxyLinkTypes" :key="linkType.handle" :class="selectedLinkType.handle === linkType.handle ? '' : 'hidden'">
                <div v-html="getParsedLinkTypeHtml(linkType.html, linkType.handle)"></div>

                <div v-show="selectedLinkType.handle === linkType.handle" class="field">
                    <div class="heading">
                        <label id="layout-field-label" class="required" for="layout">{{ t('hyper', 'Link Fields') }}</label>
                    </div>

                    <div id="layout-field-instructions" class="instructions">
                        <p>{{ t('hyper', 'Configure the fields and UI elements available to links. Elements in the first tab will be always be shown, while any other tabs will be shown in a slide-out panel.') }}</p>
                    </div>

                    <div class="input ltr">
                        <field-layout-designer v-model="linkType.layoutConfig" :layout-uid="linkType.layoutUid" :field-id="fieldId" :type="linkType.type" :load="selectedLinkType.handle === linkType.handle" />
                    </div>
                </div>

                <input type="hidden" :name="getName(`linkTypes[${linkType.handle}][layoutConfig]`)" :value="linkType.layoutConfig">
                <input type="hidden" :name="getName(`linkTypes[${linkType.handle}][layoutUid]`)" :value="linkType.layoutUid">

                <template v-if="linkType.isCustom">
                    <hr>

                    <a class="error delete" @click.prevent="onDelete(linkType)">{{ t('app', 'Delete') }}</a>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
import { camelCase, capitalize, isEmpty } from 'lodash-es';
import { SlickList, SlickItem } from 'vue-slicksort';

import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import 'tippy.js/themes/light-border.css';

import { getId, namespaceString } from '@utils/string';

import LightswitchField from './settings/LightswitchField.vue';
import FieldLayoutDesigner from './settings/FieldLayoutDesigner.vue';
import DragHandle from './settings/DragHandle.vue';

export default {
    name: 'HyperSettings',

    components: {
        SlickList,
        SlickItem,
        LightswitchField,
        FieldLayoutDesigner,
        DragHandle,
    },

    props: {
        fieldId: {
            type: [Number, String],
            default: '',
        },

        namespacedName: {
            type: String,
            default: '',
        },

        namespacedId: {
            type: String,
            default: '',
        },

        linkTypes: {
            type: Array,
            default: () => { return []; },
        },

        linkTypeHtml: {
            type: Object,
            default: () => { return {}; },
        },

        registeredLinkTypes: {
            type: Array,
            default: () => { return []; },
        },
    },

    data() {
        return {
            drag: false,
            tippy: null,
            selectedLinkType: {},
            proxyLinkTypes: [],
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
                appendTo: '.hc-sidebar',
            };
        },
    },

    created() {
        this.proxyLinkTypes = this.clone(this.linkTypes);

        // Append any JS for link type settings
        this.linkTypes.forEach((linkType) => {
            Craft.appendBodyHtml(this.getParsedLinkTypeHtml(linkType.js, linkType.handle));
        });
    },

    mounted() {
        this.$nextTick(() => {
            const $template = this.$el.querySelector('#hyper-linktypes-template');

            this.initEventListeners();

            if ($template) {
                $template.style.display = 'block';

                this.tippy = tippy(this.$el.querySelector('.hc-sidebar .btn'), {
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
        });
    },

    methods: {
        selectTab(linkType) {
            this.selectedLinkType = linkType;
        },

        isEmpty(value) {
            return isEmpty(value);
        },

        getName(name) {
            return namespaceString(this.namespacedName.replace('[__PREFIX__]', ''), name);
        },

        getParsedLinkTypeHtml(html, id) {
            if (typeof html === 'string') {
                return html.replace(new RegExp('__LINK_TYPE__', 'g'), id);
            }

            return '';
        },

        initEventListeners() {
            // Ensure any Craft fields are prepped.
            Craft.initUiElements(this.$el);

            // Watch for field changes (injected HTML, so can't use Vue)
            this.$el.querySelectorAll('[data-label-field]').forEach((element) => {
                element.addEventListener('input', this.onLabelInput);
            });

            this.$el.querySelectorAll('[data-enabled-field] .lightswitch').forEach((element) => {
                $(element).on('change', this.onEnabledChange);
            });

        },

        onLabelInput(e) {
            this.selectedLinkType.label = e.target.value;
        },

        onEnabledChange(e) {
            this.selectedLinkType.enabled = e.target.classList.contains('on');
        },

        onDelete(linkType) {
            const confirmationMessage = this.t('hyper', 'Are you sure you want to delete “{name}”?', { name: linkType.label });

            if (confirm(confirmationMessage)) {
                for (let i = 0; this.proxyLinkTypes.length; i++) {
                    if (this.proxyLinkTypes[i].handle === linkType.handle) {
                        this.proxyLinkTypes.splice(i, 1);
                        this.selectedLinkType = {};

                        break;
                    }
                }
            }
        },

        newLinkType(type, label) {
            const linkContent = this.linkTypes.find((o) => { return o.type === type; });

            const newLinkType = {
                label: `New ${linkContent.displayName}`,
                handle: getId(),
                enabled: true,
                isCustom: true,
                type: linkContent.type,
                html: linkContent.htmlTemplate,
            };

            this.proxyLinkTypes.push(newLinkType);

            Craft.appendBodyHtml(this.getParsedLinkTypeHtml(linkContent.jsTemplate, newLinkType.handle));

            this.selectedLinkType = newLinkType;

            this.$nextTick(() => {
                this.initEventListeners();
            });

            if (this.tippy) {
                this.tippy.hide();
            }
        },
    },
};

</script>

<style lang="scss">

.hc-wrapper {
    display: flex;
    background-color: #fff;
    border: 1px solid #d8dee7;
    border-radius: 3px;
    overflow: hidden;
}

.hc-sidebar {
    width: 250px;
    background-color: #f3f7fb;
    border-right: 1px solid #d8dee7;
}

.hc-sidebar .btn {
    margin: 14px;
}

.hc-sidebar-item-move {
    display: inline-flex;
    width: 13px;
    height: 13px;
    margin-left: auto;
    color: #78838e;
    cursor: move;

    svg {
        width: 100%;
        height: 100%;
    }
}

.hc-sidebar-item {
    display: flex;
    align-items: center;
    min-height: 48px;
    position: relative;
    user-select: none;
    background-color: #f3f7fb;
    box-shadow: 0 -1px 0 #dfe4ea, inset 0 -1px 0 #dfe4ea;
    padding: 8px 14px;
    cursor: pointer;
    transition: background 0.15s ease;

    &.has-errors {
        background: #ffe8ec;
        color: var(--error-color);

        &.sel {
            background: #ffdfe4;
        }
    }

    &.sel {
        background: #d8dee7;
    }

    .hc-label-text {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        padding-left: 10px;
        padding-right: 10px;
    }

    .lightswitch,
    .hc-move {
        flex-shrink: 0;
    }
}

.hc-pane {
    flex: 1;
    padding: 20px;
}

.hc-pane-empty {
    color: #000;
    padding: 20px;
    flex: 1;
    margin-left: 24px;
    display: flex;
    justify-content: center;
    font-size: 2em;
    font-weight: 400;
    opacity: 0.4;
    color: #265275;
    margin-top: 3rem;
    position: relative;

    svg {
        position: absolute;
        top: 4rem;
        left: 50%;
        width: 170px;
        height: auto;
        margin-left: -85px;
        transform: rotate(7deg) translateX(-50%);
    }
}

.tippy-box[data-theme~='hyper-tippy-menu'] > .tippy-content {
    padding: 0;
    min-height: auto;
    min-width: 100px;
}

.hyper-menu ul {
    hr {
        margin: 5px 0;
    }

    li a {
        position: relative;
        padding: 10px 14px;
        color: #3f4d5a;
        text-decoration: none;
        white-space: nowrap;
        font-size: 14px;
        outline: 0;
        display: block;

        &:hover {
            color: #3f4d5a;
            background-color: #f3f7fc;
        }

        &[data-icon] {
            padding-left: 26px;
        }

        &::before {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
        }
    }
}


</style>
