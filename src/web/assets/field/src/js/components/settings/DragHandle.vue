<template>
    <span>
        <slot></slot>
    </span>
</template>

<script>
export default {
    mounted() {
        this.$el.sortableHandle = true;
    },
};
</script>
