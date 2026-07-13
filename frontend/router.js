import { createRouter, createWebHistory } from 'vue-router';
import BackupsPage from './pages/BackupsPage.vue';
import DoctorPage from './pages/DoctorPage.vue';
import HostDetailPage from './pages/HostDetailPage.vue';
import IpamPage from './pages/IpamPage.vue';
import InventoryPage from './pages/InventoryPage.vue';
import NetbootPage from './pages/NetbootPage.vue';
import NotificationsPage from './pages/NotificationsPage.vue';
import ScansPage from './pages/ScansPage.vue';
import ServicesPage from './pages/ServicesPage.vue';

export const routeNames = Object.freeze({
  inventory: 'inventory',
  backups: 'backups',
  doctor: 'doctor',
  ipam: 'ipam',
  notify: 'notify',
  scans: 'scans',
  services: 'services',
  netboot: 'netboot',
  host: 'host',
  hostByIp: 'host-by-ip'
});

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: routeNames.inventory, component: InventoryPage },
    { path: '/backups', name: routeNames.backups, component: BackupsPage },
    { path: '/doctor', name: routeNames.doctor, component: DoctorPage },
    { path: '/ipam', name: routeNames.ipam, component: IpamPage },
    { path: '/notify', name: routeNames.notify, component: NotificationsPage },
    { path: '/scans', name: routeNames.scans, component: ScansPage },
    { path: '/services', name: routeNames.services, component: ServicesPage },
    { path: '/netboot-images', name: routeNames.netboot, component: NetbootPage },
    { path: '/hosts/:id(\\d+)', name: routeNames.host, component: HostDetailPage },
    { path: '/hosts/by-ip/:ip', name: routeNames.hostByIp, component: HostDetailPage },
    { path: '/:pathMatch(.*)*', redirect: { name: routeNames.inventory } }
  ],
  scrollBehavior: () => ({ top: 0 })
});
