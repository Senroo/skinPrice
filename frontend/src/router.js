import { createRouter, createWebHistory } from 'vue-router';
import DashboardPage from './pages/DashboardPage.vue';
import ReportTodayPage from './pages/ReportTodayPage.vue';
import HistoryPage from './pages/HistoryPage.vue';
import ItemDetailPage from './pages/ItemDetailPage.vue';
import AdminPage from './pages/AdminPage.vue';

const routes = [
  { path: '/', name: 'dashboard', component: DashboardPage },
  { path: '/report', name: 'report-today', component: ReportTodayPage },
  { path: '/history', name: 'history', component: HistoryPage },
  { path: '/items/:id', name: 'item-detail', component: ItemDetailPage, props: true },
  { path: '/admin', name: 'admin', component: AdminPage },
];

export default createRouter({
  history: createWebHistory(),
  routes,
});

