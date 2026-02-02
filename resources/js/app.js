import './bootstrap';
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import router from './router';
import App from './components/App.vue';

// 1. ESTILOS DE ICONOS
import 'primeicons/primeicons.css';

// 2. ESTILOS DE LAYOUT (¡ESTO ES LO QUE FALTA!)
// Sin esto, 'col-12', 'md:col-6', 'grid' no funcionan y el modal se ve roto.
import 'primeflex/primeflex.css'; 

import axios from 'axios';

// Componentes
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import Toast from 'primevue/toast';
import Card from 'primevue/card';

// Interceptor para sesión expirada
axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response && (error.response.status === 419 || error.response.status === 401)) {
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

const app = createApp(App);

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

// Registrar Componentes
app.component('Button', Button);
app.component('InputText', InputText);
app.component('DataTable', DataTable);
app.component('Column', Column);
app.component('Dialog', Dialog);
app.component('Dropdown', Dropdown);
app.component('Toast', Toast);
app.component('Card', Card);

app.mount('#app');