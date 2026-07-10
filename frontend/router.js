import { createRouter, createWebHistory } from 'vue-router';
import HostDetailPage from './pages/HostDetailPage.vue';
import InventoryPage from './pages/InventoryPage.vue';
import NetbootPage from './pages/NetbootPage.vue';
import NotificationsPage from './pages/NotificationsPage.vue';
import ScansPage from './pages/ScansPage.vue';

export const routeNames = Object.freeze({
  inventory: 'inventory',
  notify: 'notify',
  scans: 'scans',
  netboot: 'netboot',
  host: 'host'
});

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: routeNames.inventory, component: InventoryPage },
    { path: '/notify', name: routeNames.notify, component: NotificationsPage },
    { path: '/scans', name: routeNames.scans, component: ScansPage },
    { path: '/netboot-images', name: routeNames.netboot, component: NetbootPage },
    { path: '/hosts/:id(\\d+)', name: routeNames.host, component: HostDetailPage },
    { path: '/:pathMatch(.*)*', redirect: { name: routeNames.inventory } }
  ],
  scrollBehavior: () => ({ top: 0 })
});
