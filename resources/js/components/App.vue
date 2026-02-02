<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Toast from 'primevue/toast';

// Importamos el Sidebar
import AppSidebar from './layout/AppSidebar.vue';

const route = useRoute();
const isLoginPage = computed(() => route.name === 'login');
</script>

<template>
    <div class="min-h-screen flex font-custom" :class="{ 'bg-gray-50': !isLoginPage }">
        
        <Toast position="top-right" />
        
        <AppSidebar v-if="!isLoginPage" />

        <div class="flex flex-column transition-all transition-duration-200" 
             :class="[isLoginPage ? 'w-full' : 'flex-grow-1']"
             style="max-width: 100%; overflow-x: hidden;">
            
            <div class="h-full flex flex-column" :class="[isLoginPage ? 'p-0' : 'p-4']">
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
</style>