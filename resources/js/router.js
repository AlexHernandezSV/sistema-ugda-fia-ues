import { createRouter, createWebHistory } from 'vue-router';
import { getCurrentUser } from './services/authSession';

// 1. IMPORTACIÓN DE COMPONENTES
// Ajusta estas rutas si tus archivos están en carpetas diferentes
import Login from './components/Login.vue'; 
import Dashboard from './components/Dashboard.vue';
import Unidades from './components/views/Unidades.vue';
import Usuarios from './components/views/Usuarios.vue'; // <--- NUEVO IMPORT
import SeriesDocumentales from './components/views/SeriesDocumentales.vue';
import Solicitudes from './components/views/Solicitudes.vue';
import NuevaSolicitud from './components/views/NuevaSolicitud.vue';
import DetalleTransferencia from './components/views/DetalleTransferencia.vue';
import DetallePrestamo from './components/views/DetallePrestamo.vue';
import Reportes from './components/views/Reportes.vue';

const normalizeValue = (value) => String(value ?? '').trim().toLowerCase();

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
        meta: { title: 'Listado de Unidades', permission: 'endpoint.api.units.create' },
        component: Unidades 
    },

    // --- NUEVA RUTA: USUARIOS ---
    { 
        path: '/usuarios', 
        name: 'usuarios', 
        meta: { title: 'Gestion de Usuarios', permission: 'endpoint.api.users.create' },
        component: Usuarios 
    },

    {
        path: '/series-documentales',
        name: 'series-documentales',
        meta: { title: 'Series Documentales', permission: 'endpoint.api.documentary-series.create' },
        component: SeriesDocumentales
    },

    {
        path: '/reportes',
        name: 'reportes',
        meta: {
            title: 'Reportes Dinamicos',
            permission: 'endpoint.ui.reports.read',
            allowedProfiles: ['Usuario UGDA'],
        },
        component: Reportes
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
        meta: { title: 'Nueva Transferencia Documental', permission: 'endpoint.api.documentary-series.read' },
        component: NuevaSolicitud 
    },

    { path: '/solicitudes/transferencias/:number/editar',
        name: 'solicitudes-transferencia-editar',
        meta: { title: 'Editar Transferencia Documental', permission: 'endpoint.api.documentary-series.read' },
        component: NuevaSolicitud
    },

    { path: '/solicitudes/transferencias/:number',
        name: 'solicitudes-transferencia-detalle',
        meta: { title: 'Detalle de Transferencia' },
        component: DetalleTransferencia
    },

    { path: '/solicitudes/prestamos/:number',
        name: 'solicitudes-prestamo-detalle',
        meta: { title: 'Detalle de Prestamo' },
        component: DetallePrestamo
    },


];

const router = createRouter({
    history: createWebHistory(),
    routes
});

router.beforeEach(async (to) => {
    if (to.name === 'login') {
        return true;
    }

    try {
        const user = await getCurrentUser();
        const permissions = user?.permissions ?? [];
        const profileName = user?.role ?? user?.profile?.name ?? '';
        const hasPermission = to.meta?.permission ? permissions.includes(to.meta.permission) : false;
        const allowedProfiles = Array.isArray(to.meta?.allowedProfiles) ? to.meta.allowedProfiles : [];
        const matchesAllowedProfile = allowedProfiles
            .map(normalizeValue)
            .includes(normalizeValue(profileName));

        if (to.meta?.permission && !hasPermission && allowedProfiles.length === 0) {
            return { path: '/dashboard' };
        }

        if ((to.meta?.permission || allowedProfiles.length > 0) && !hasPermission && !matchesAllowedProfile) {
            return { path: '/dashboard' };
        }

        return true;
    } catch (error) {
        return { path: '/login' };
    }
});

export default router;
