<template>
    <div class="hyper-header-move">
        <slot></slot>
    </div>
</template>

<script>
export default {
    mounted() {
        this.$el.sortableHandle = true;
    },
};
</script>
