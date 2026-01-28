import { createRouter, createWebHistory } from 'vue-router';
import Login from './components/Login.vue';
import Dashboard from './components/Dashboard.vue';

const routes = [
    {
        path: '/',
        redirect: '/login' // Redirigir la raíz al login
    },
    {
        path: '/login',
        name: 'login',
        component: Login
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: Dashboard,
        // Aquí podrías agregar meta: { requiresAuth: true } más adelante
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

export default router;