import { createRouter, createWebHistory } from 'vue-router';

// Importación de componentes existentes
import Login from './components/Login.vue';
import Dashboard from './components/Dashboard.vue';

// --- NUEVA IMPORTACIÓN ---
// Asegúrate de que esta ruta coincida con donde guardaste el archivo.
// En los pasos anteriores lo creamos dentro de 'views'.
import Unidades from './components/views/Unidades.vue'; 

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
    },
    
    // --- NUEVA RUTA PARA UNIDADES ---
    {
        path: '/unidades',
        name: 'unidades',
        component: Unidades
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

export default router;