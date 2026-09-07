<template>
    <chatbot v-if="load" @vue:mounted="handleChatbotMounted"></chatbot>
</template>

<script>
import {defineAsyncComponent} from 'vue';

// The chat interface bundles the Markdown renderer and the HTML sanitizer. This
// component is mounted on every page that shows the navbar, so the interface itself
// is loaded as an async component once a user actually opens the chat.
export default {
    components: {
        chatbot: defineAsyncComponent(() => import('./chatbot.vue')),
    },
    data() {
        return {
            load: false,
        };
    },
    methods: {
        handleOpen() {
            this.load = true;
        },
        handleChatbotMounted() {
            // The chat interface opens the modal when it receives this event but it
            // was not mounted yet when the event that started the loading was
            // dispatched.
            window.dispatchEvent(new CustomEvent('ask-biigle:open'));
        },
    },
    created() {
        window.addEventListener('ask-biigle:open', this.handleOpen);
    },
    beforeUnmount() {
        window.removeEventListener('ask-biigle:open', this.handleOpen);
    },
};
</script>
