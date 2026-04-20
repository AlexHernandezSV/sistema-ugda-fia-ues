<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Toast from 'primevue/toast';

// Importamos el Sidebar
import AppSidebar from './layout/AppSidebar.vue';

const route = useRoute();
const isLoginPage = computed(() => route.name === 'login');
const currentPageTitle = computed(() => route.meta?.title ?? '');

const toggleMobileSidebar = () => {
    window.dispatchEvent(new CustomEvent('ugda:toggle-sidebar'));
};
</script>

<template>
    <div class="min-h-screen flex font-custom" :class="{ 'bg-gray-50': !isLoginPage }">
        
        <Toast position="top-right" />
        
        <AppSidebar v-if="!isLoginPage" />

        <div class="flex flex-column transition-all transition-duration-200" 
             :class="[isLoginPage ? 'w-full' : 'flex-grow-1']"
             style="max-width: 100%; overflow-x: hidden;">
            
            <div class="h-full flex flex-column" :class="[isLoginPage ? 'p-0' : 'p-4']">
                <div v-if="!isLoginPage" class="mobile-page-header">
                    <button type="button" class="mobile-page-header__toggle" @click="toggleMobileSidebar">
                        <i class="pi pi-bars"></i>
                    </button>
                    <h1 class="mobile-page-header__title">{{ currentPageTitle }}</h1>
                </div>
                <router-view />
            </div>
            
        </div>
    </div>
</template>

<style>
/* 1. CONFIGURACIÓN GLOBAL DE FUENTE */
:root {
    /* ¡IMPORTANTE! Cambia 'NombreDeTuFuente' por el nombre real que usa tu clase font-custom */
    /* Ejemplos comunes: 'Poppins', 'Inter', 'Roboto', 'Lato' */
    --font-family: 'NombreDeTuFuente', sans-serif; 
}

/* 2. APLICAR AL BODY (Para todo el sitio) */
body {
    margin: 0;
    background-color: #f9fafb;
    font-family: var(--font-family); /* Usa la variable definida arriba */
    -webkit-font-smoothing: antialiased;
}

/* 3. FORZAR EN COMPONENTES DE PRIMEVUE (Modales, Inputs, Botones) */
/* Esto asegura que el modal, que vive fuera de tu div principal, tenga la fuente correcta */
.p-component, 
.p-dialog, 
.p-inputtext, 
.p-button, 
.p-dropdown-item,
.p-toast-message {
    font-family: var(--font-family) !important;
}

/* 4. Corrección para inputs y dropdowns */
.p-inputtext, .p-dropdown {
    font-family: var(--font-family) !important;
}

/* --- 4. CORRECCIÓN ESPECÍFICA PARA EL TOAST (NOTIFICACIONES) --- */

/* Forzamos la fuente en el contenedor del Toast */
.p-toast {
    font-family: var(--font-family) !important;
    opacity: 0.95; /* Un toque de transparencia para que se vea más moderno */
}

/* TÍTULO DEL TOAST (Ej: "Incompleto") */
.p-toast-summary {
    font-family: var(--font-family) !important;
    font-weight: 600 !important; /* Grosor medio (SemiBold), para que no se vea tan tosco */
    font-size: 1rem !important;
}

/* MENSAJE DEL TOAST (Ej: "Ingresa los 6 dígitos...") */
.p-toast-detail {
    font-family: var(--font-family) !important;
    font-weight: 400 !important; /* Normal */
    font-size: 0.9rem !important;
}

/* ICONO DEL TOAST */
.p-toast-message-icon {
    font-size: 1.2rem !important;
}

.mobile-page-header {
    display: none;
}

@media (max-width: 991px) {
    .mobile-page-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding: 0.25rem 0 0.5rem;
    }

    .mobile-page-header__toggle {
        width: 3rem;
        height: 3rem;
        border: 0;
        border-radius: 1rem;
        background: #1560bd;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 16px 32px rgba(21, 96, 189, 0.22);
        flex-shrink: 0;
        cursor: pointer;
    }

    .mobile-page-header__toggle .pi {
        font-size: 1.15rem;
    }

    .mobile-page-header__title {
        margin: 0;
        color: #1560bd;
        font-size: 1.95rem;
        line-height: 1.05;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .mobile-page-title {
        display: none !important;
    }

    .p-dialog-mask {
        padding: 1rem !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .p-dialog {
        width: min(42rem, calc(100vw - 2rem)) !important;
        max-width: calc(100vw - 2rem) !important;
        max-height: calc(100vh - 2rem) !important;
        margin: 0 !important;
    }

    .p-dialog-header,
    .p-dialog-footer {
        flex-shrink: 0;
    }

    .p-dialog-content {
        max-height: calc(100vh - 10rem) !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        -webkit-overflow-scrolling: touch;
    }

    .p-dialog .grid {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
}
</style>
