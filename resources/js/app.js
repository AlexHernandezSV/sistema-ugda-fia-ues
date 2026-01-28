import './bootstrap';
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import router from './router'; // <--- 1. Importamos el Router que creamos
import App from './components/App.vue'; // <--- 2. Importamos el nuevo contenedor padre
import 'primeicons/primeicons.css';

// CORRECCIÓN: Creamos la app con el contenedor principal 'App.vue'
const app = createApp(App);

// Configuración de PrimeVue
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.fake-dark-mode', 
        }
    }
});

app.use(ToastService);
app.use(router); // <--- 3. Le decimos a Vue que use el Router

app.mount('#app');