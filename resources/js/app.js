import './bootstrap'; // <--- ESTO SOLUCIONA EL ERROR CSRF
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura'; // Tu tema Aura
import ToastService from 'primevue/toastservice';
import Login from './components/Login.vue';

// CORRECCIÓN: En lugar de crear una app vacía, creamos la app DIRECTAMENTE con el Login.
// Esto hace que Login.vue sea tu página principal inmediatamente.
const app = createApp(Login);

// Configuración de PrimeVue (Mantenemos tu config de Dark Mode desactivado)
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            // Esto fuerza el modo claro siempre, ignorando el sistema operativo
            darkModeSelector: '.fake-dark-mode', 
        }
    }
});

// Servicio de Alertas
app.use(ToastService);

// Montamos la app en el div con id="app"
app.mount('#app');