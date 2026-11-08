<template>
    <div class="app">
        <h1>Hello</h1>
        <button @click="increment">Count: {{ count }}</button>
    </div>
</template>

<script>
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'App',
    data() {
        return {
            count: 0,
        };
    },
    methods: {
        increment() {
            this.count++;
        },
    },
});
</script>
