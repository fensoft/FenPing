import { createApp } from 'vue';
import '@tabler/core/dist/css/tabler.min.css';
import './styles.css';
import App from './App.vue';
import AppIcon from './components/AppIcon.vue';
import { t } from './lib/i18n.js';
import { router } from './router.js';

const app = createApp(App);
app.config.globalProperties.$t = t;
app.component('AppIcon', AppIcon).use(router).mount('#app');
