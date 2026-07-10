import { createRouter, createWebHistory } from 'vue-router';
import { getCurrentUser } from './services/authSession';

// 1. IMPORTACIÓN DE COMPONENTES
// Ajusta estas rutas si tus archivos están en carpetas diferentes
import Login from './components/Login.vue'; 
import Dashboard from './components/Dashboard.vue';
import Unidades from './components/views/Unidades.vue';
import Usuarios from './components/views/Usuarios.vue'; // <--- NUEVO IMPORT
import ArchivoGeneral from './components/views/ArchivoGeneral.vue';
import ArchivoGestion from './components/views/ArchivoGestion.vue';
import CrearCajaArchivo from './components/views/CrearCajaArchivo.vue';
import CrearDocumentoArchivo from './components/views/CrearDocumentoArchivo.vue';
import DocumentosReservados from './components/views/DocumentosReservados.vue';
import ReasignacionArchivo from './components/views/ReasignacionArchivo.vue';
import UbicacionesFisicas from './components/views/UbicacionesFisicas.vue';
import SeriesDocumentales from './components/views/SeriesDocumentales.vue';
import Solicitudes from './components/views/Solicitudes.vue';
import NuevaSolicitud from './components/views/NuevaSolicitud.vue';
import SolicitudPrestamo from './components/views/SolicitudPrestamo.vue';
import PrestamosPendientesAutorizacion from './components/views/PrestamosPendientesAutorizacion.vue';
import DetalleTransferencia from './components/views/DetalleTransferencia.vue';
import DetallePrestamo from './components/views/DetallePrestamo.vue';
import EtiquetaTransferencia from './components/views/EtiquetaTransferencia.vue';
import Reportes from './components/views/Reportes.vue';
import Notificaciones from './components/views/Notificaciones.vue';
import MiPerfil from './components/views/MiPerfil.vue';

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
        meta: { title: 'Gestión de Usuarios', permission: 'endpoint.api.users.create' },
        component: Usuarios 
    },

    {
        path: '/series-documentales',
        name: 'series-documentales',
        meta: { title: 'Series Documentales', permission: 'endpoint.api.documentary-series.create' },
        component: SeriesDocumentales
    },

    {
        path: '/archivo-general',
        name: 'archivo-general',
        meta: { title: 'Archivo General', allowedProfiles: ['Administrador'] },
        component: ArchivoGeneral
    },

    {
        path: '/archivo-general/gestion',
        name: 'archivo-general-gestion',
        meta: { title: 'Gestión de Archivo', allowedProfiles: ['Administrador'] },
        component: ArchivoGestion
    },

    {
        path: '/archivo-general/cajas/crear',
        name: 'archivo-general-cajas-crear',
        meta: { title: 'Crear Nueva Caja', allowedProfiles: ['Administrador'] },
        component: CrearCajaArchivo
    },

    {
        path: '/archivo-general/documentos/crear',
        name: 'archivo-general-documentos-crear',
        meta: { title: 'Crear Nuevo Documento', allowedProfiles: ['Administrador'] },
        component: CrearDocumentoArchivo
    },

    {
        path: '/archivo-general/documentos-reservados',
        name: 'archivo-general-documentos-reservados',
        meta: { title: 'Documentos Reservados', allowedProfiles: ['Administrador'] },
        component: DocumentosReservados
    },

    {
        path: '/archivo-general/reasignacion',
        name: 'archivo-general-reasignacion',
        meta: { title: 'Reasignacion de Archivo', allowedProfiles: ['Administrador'] },
        component: ReasignacionArchivo
    },

    {
        path: '/archivo-general/ubicaciones',
        name: 'archivo-general-ubicaciones',
        meta: { title: 'Ubicaciones Fisicas', allowedProfiles: ['Administrador'] },
        component: UbicacionesFisicas
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

    {
        path: '/notificaciones',
        name: 'notificaciones',
        meta: { title: 'Notificaciones' },
        component: Notificaciones
    },

    {
        path: '/mi-perfil',
        name: 'mi-perfil',
        meta: { title: 'Mi Perfil' },
        component: MiPerfil
    },

    // Comodín: Si escriben cualquier cosa rara, redirigir al login
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

    { path: '/solicitudes/prestamos/crear',
        name: 'solicitudes-prestamos-crear',
        meta: { title: 'Solicitud de Prestamo', allowedProfiles: ['Unidad Solicitante'] },
        component: SolicitudPrestamo
    },

    { path: '/solicitudes/prestamos/pendientes',
        name: 'solicitudes-prestamos-pendientes',
        meta: { title: 'Prestamos Pendientes', allowedProfiles: ['Director/Jefe de Unidad'] },
        component: PrestamosPendientesAutorizacion
    },

    { path: '/solicitudes/transferencias/:number/editar',
        name: 'solicitudes-transferencia-editar',
        meta: { title: 'Editar Transferencia Documental', permission: 'endpoint.api.documentary-series.read' },
        component: NuevaSolicitud
    },

    { path: '/solicitudes/transferencias/:number/proceso',
        name: 'solicitudes-transferencia-proceso',
        meta: { title: 'Proceso de Transferencia', allowedProfiles: ['Administrador', 'Usuario UGDA'] },
        component: DetalleTransferencia
    },

    { path: '/solicitudes/transferencias/:number',
        name: 'solicitudes-transferencia-detalle',
        meta: { title: 'Detalle de Transferencia' },
        component: DetalleTransferencia
    },

    { path: '/etiquetas/transferencias/:number/cajas/:boxNumber',
        name: 'etiqueta-transferencia',
        meta: { title: 'Etiqueta de Ubicacion', allowedProfiles: ['Administrador', 'Usuario UGDA'] },
        component: EtiquetaTransferencia
    },

    { path: '/solicitudes/prestamos/:number',
        name: 'solicitudes-prestamo-detalle',
        meta: { title: 'Detalle de Prestamo' },
        component: DetallePrestamo
    },

    {
        path: '/:pathMatch(.*)*',
        redirect: '/login'
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
        const user = await getCurrentUser({ force: true });
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
        return { path: '/login', query: { redirect: to.fullPath } };
    }
});

export default router;
