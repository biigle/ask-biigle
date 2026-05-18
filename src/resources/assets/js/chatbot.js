import {createApp} from 'vue';
import Chatbot from './chatbot.vue';
import '../sass/chatbot.scss';

window.addEventListener('load', () => {
    const mountElement = document.createElement('div');
    mountElement.id = 'biiglebot-chatbot-container';
    document.body.appendChild(mountElement);

    const app = createApp(Chatbot);
    app.config.compilerOptions.whitespace = 'preserve';
    app.mount(mountElement);
});
