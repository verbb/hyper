<script>
import { h } from 'vue';

export default {
    name: 'LinkBlockFields',

    // Add nonsense as we don't want any Vue templating included in this template
    // Otherwise including Twig in field content will throw an error.
    delimiters: ['$!{', '}!$'],

    props: {
        template: {
            type: String,
            default: '',
        },
    },

    mounted() {
        this.$nextTick(() => {
            // Ensure any Craft fields are prepped.
            Craft.initUiElements(this.$el);
        });
    },

    render() {
        // Apply our dynamically provided template, rendered via Craft.
        return h('div', {
            innerHTML: this.template,
        });
    },
};

</script>
