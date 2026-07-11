import { createApp } from 'vue';
import '@tabler/core/dist/css/tabler.min.css';
import './styles.css';
import App from './App.vue';
import AppIcon from './components/AppIcon.vue';
import { router } from './router.js';

createApp(App).component('AppIcon', AppIcon).use(router).mount('#app');
