import { createRouter, createWebHistory } from 'vue-router';

// 1. IMPORTACIÓN DE COMPONENTES
// Ajusta estas rutas si tus archivos están en carpetas diferentes
import Login from './components/Login.vue'; 
import Dashboard from './components/Dashboard.vue';
import Unidades from './components/views/Unidades.vue';
import Usuarios from './components/views/Usuarios.vue'; // <--- NUEVO IMPORT
import Solicitudes from './components/views/Solicitudes.vue';
import NuevaSolicitud from './components/views/NuevaSolicitud.vue';

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
        meta: { title: 'Dashboard' },
        component: Dashboard 
    },

    // Ruta Unidades
    { 
        path: '/unidades', 
        name: 'unidades', 
        meta: { title: 'Listado de Unidades' },
        component: Unidades 
    },

    // --- NUEVA RUTA: USUARIOS ---
    { 
        path: '/usuarios', 
        name: 'usuarios', 
        meta: { title: 'Gestion de Usuarios' },
        component: Usuarios 
    },

    // Comodín: Si escriben cualquier cosa rara, redirigir al login
    { 
        path: '/:pathMatch(.*)*', 
        redirect: '/login' 
    },
    // ... dentro del array de routes ...
    { path: '/solicitudes',
        name: 'solicitudes',
        meta: { title: 'Solicitudes de Transferencia' },
        component: Solicitudes 
    },

    { path: '/solicitudes/crear',
        name: 'solicitudes-crear',
        meta: { title: 'Nueva Transferencia Documental' },
        component: NuevaSolicitud 
    },


];

const router = createRouter({
    history: createWebHistory(),
    routes
});

export default router;
