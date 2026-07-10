import { createApp } from 'vue';
import '@tabler/core/dist/css/tabler.min.css';
import '@tabler/icons-webfont/dist/tabler-icons.min.css';
import './styles.css';
import App from './App.vue';
import { router } from './router.js';

createApp(App).use(router).mount('#app');
