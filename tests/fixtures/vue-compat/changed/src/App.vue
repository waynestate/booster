<template>
    <div class="app">
        <button @click="onClick">Click</button>
    </div>
</template>

<script>
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'App',
    methods: {
        onClick() {
            window.alert('Children: ' + this.$children.length);
        },
    },
});
</script>
