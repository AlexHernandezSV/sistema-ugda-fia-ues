import './bootstrap';
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import router from './router';
import App from './components/App.vue';
import 'primeicons/primeicons.css';

// 1. IMPORTAR AXIOS (Para configurar el interceptor)
import axios from 'axios';

// 2. IMPORTAR LOS COMPONENTES QUE USAMOS EN LAS VISTAS
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import Toast from 'primevue/toast';
import Card from 'primevue/card';

// --- CONFIGURACIÓN DEL INTERCEPTOR (SOLUCIÓN SESIÓN EXPIRADA) ---
// Esto detecta si el servidor rechaza la petición por tiempo de inactividad (419 o 401)
// y recarga la página para renovar el token y evitar el error de "Credenciales incorrectas".
axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response && (error.response.status === 419 || error.response.status === 401)) {
            // Si no estamos ya en el login, forzamos la recarga hacia el login
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

const app = createApp(App);

// Configuración de PrimeVue (Mantenemos tu config de Aura)
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.fake-dark-mode', 
        }
    }
});

app.use(ToastService);
app.use(router);

// 3. REGISTRAR LOS COMPONENTES GLOBALMENTE
app.component('Button', Button);
app.component('InputText', InputText);
app.component('DataTable', DataTable);
app.component('Column', Column);
app.component('Dialog', Dialog);
app.component('Dropdown', Dropdown);
app.component('Toast', Toast);
app.component('Card', Card);

app.mount('#app');