<template>
    <div ref="fld-container" class="hyper-block-editor-layout">
        <div v-show="loading || error" class="hyper-workspace">
            <div v-if="loading" class="hyper-loading-pane">
                <div class="hyper-loading hyper-loading-lg"></div>
            </div>

            <div v-if="error" class="hyper-error-pane error">
                <div class="hyper-error-content">
                    <span data-icon="alert"></span>

                    <span class="error" v-html="errorMessage"></span>
                </div>
            </div>
        </div>

        <div v-show="!loading && !error" ref="fld-content"></div>
    </div>
</template>

<script>
import { debounce } from 'lodash-es';

export default {
    name: 'FieldLayoutDesigner',

    props: {
        layoutUid: {
            type: String,
            default: null,
        },

        fieldId: {
            type: [Number, String],
            default: null,
        },

        type: {
            type: String,
            default: null,
        },

        load: {
            type: Boolean,
            default: false,
        },

        modelValue: {
            type: [Object, Array, String],
            default: () => {},
        },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            error: false,
            errorMessage: '',
            loading: false,
            mounted: false,
            proxyValue: {},
            cache: null,
        };
    },

    watch: {
        proxyValue(newValue) {
            // Clear the cache when the value changes
            this.cache = null;

            this.$emit('update:modelValue', newValue);
        },

        load(newValue) {
            if (newValue) {
                this.loadLayout();
            }
        },
    },

    created() {
        this.proxyValue = this.modelValue;

        if (this.load) {
            this.loadLayout();
        }
    },

    methods: {
        clearLayout() {
            if (this.$refs['fld-content']) {
                this.$refs['fld-content'].innerHTML = '';
            }
        },

        loadLayout() {
            this.loading = true;
            this.error = false;

            // Clear the UI for the field layout to prevent seeing potentially stale layouts
            this.clearLayout();

            if (this.cache) {
                this.loading = false;

                this.updateLayout();

                return;
            }

            const fieldIds = [];

            if (this.fieldId) {
                fieldIds.push(this.fieldId);
            }

            // When being used in Matrix
            const regex = /fields\/edit\/(\d*)$/g;
            const result = regex.exec(window.location.href);

            if (result && result[1]) {
                fieldIds.push(result[1]);
            }

            const data = {
                fieldIds,
                layoutUid: this.layoutUid,
                layout: this.proxyValue,
                type: this.type,
            };

            Craft.sendActionRequest('POST', 'hyper/fields/layout-designer', { data })
                .then((response) => {
                    if (response.data.html) {
                        this.cache = response.data;

                        this.updateLayout();
                    } else {
                        throw new Error(response.data);
                    }
                })
                .catch((error) => {
                    this.error = true;
                    this.errorMessage = error;
                })
                .finally(() => {
                    this.loading = false;
                });
        },

        updateLayout() {
            if (this.$refs['fld-content']) {
                this.$refs['fld-content'].innerHTML = this.cache.html;
                Craft.appendBodyHtml(this.cache.footHtml);

                this.watchForChanges();

                this.mounted = true;
            }
        },

        watchForChanges() {
            const updateFunction = debounce(this.serializeLayout, 250);

            // Use MutationObserver to detect _any_ change in the field layout designer, and be sure to debounce
            // calls as there are a lot of changes. Far more easier than overriding the FLD
            const observer = new MutationObserver((mutations) => {
                updateFunction();
            });

            if (this.$refs['fld-content']) {
                observer.observe(this.$refs['fld-content'], {
                    childList: true,
                    attributes: true,
                    subtree: true,
                    characterData: true,
                });
            }
        },

        serializeLayout() {
            // Prevent firing immediately on first render
            if (!this.mounted) {
                return;
            }

            if (this.$refs['fld-content']) {
                const $fieldLayout = this.$refs['fld-content'].querySelector('input[name="fieldLayout"]');

                if ($fieldLayout) {
                    this.proxyValue = $fieldLayout.value;
                }
            }
        },
    },
};

</script>


<style lang="scss">

.hyper-workspace {
    padding: 24px;
    border-radius: 3px;
    display: flex;
    flex: 1;
    background-color: #f3f7fc;
    background-image: linear-gradient(to right, #ecf2f9 1px, transparent 0px), linear-gradient(to bottom, #ecf2f9 1px, transparent 1px);
    background-size: 24px 24px;
    background-position: -1px -1px;
    box-shadow: inset 0 1px 3px -1px #acbed2;
}

.hyper-loading-pane,
.hyper-error-pane {
    margin: auto;
}


// ==========================================================================
// Loading
// ==========================================================================

@keyframes loading {
    0% {
        transform: rotate(0)
    } 100% {
        transform: rotate(360deg)
    }
}

.hyper-loading {
    position: relative;
    pointer-events: none;
    color: transparent !important;
    min-height: 1rem;

    &::after {
        position: absolute;
        display: block;
        height: 1rem;
        width: 1rem;
        margin-top: -0.65rem;
        margin-left: -0.65rem;
        border-width: 2px;
        border-style: solid;
        border-radius: 9999px;
        border-color: #E5422B;
        animation: loading 0.5s infinite linear;
        border-right-color: transparent;
        border-top-color: transparent;
        content: "";
        left: 50%;
        top: 50%;
        z-index: 1;
    }
}

.hyper-loading.hyper-loading-lg {
    min-height: 2rem;

    &::after {
        height: 2rem;
        width: 2rem;
        margin-top: -1rem;
        margin-left: -1rem;
    }
}


</style>
