import { createRouter, createWebHistory } from 'vue-router';

// 1. IMPORTACIÓN DE COMPONENTES
// Ajusta estas rutas si tus archivos están en carpetas diferentes
import Login from './components/Login.vue'; 
import Dashboard from './components/Dashboard.vue';
import Unidades from './components/views/Unidades.vue';
import Usuarios from './components/views/Usuarios.vue'; // <--- NUEVO IMPORT

const routes = [
    // Redirección inicial
    { 
        path: '/', 
        redirect: '/login' 
    },
    
    // Ruta Login
    { 
        path: '/login', 
        name: 'login',
        component: Login 
    },

    // Ruta Dashboard
    { 
        path: '/dashboard', 
        name: 'dashboard',
        component: Dashboard 
    },

    // Ruta Unidades
    { 
        path: '/unidades', 
        name: 'unidades', 
        component: Unidades 
    },

    // --- NUEVA RUTA: USUARIOS ---
    { 
        path: '/usuarios', 
        name: 'usuarios', 
        component: Usuarios 
    },

    // Comodín: Si escriben cualquier cosa rara, redirigir al login
    { 
        path: '/:pathMatch(.*)*', 
        redirect: '/login' 
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes
});

export default router;