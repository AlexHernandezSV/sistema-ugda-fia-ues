import './bootstrap';
import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import router from './router';
import App from './components/App.vue';
import 'primeicons/primeicons.css';

// 1. IMPORTAR LOS COMPONENTES QUE USAMOS EN LAS VISTAS
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Dropdown from 'primevue/dropdown';
import Toast from 'primevue/toast';
import Card from 'primevue/card';

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

// 2. REGISTRAR LOS COMPONENTES GLOBALMENTE
// Esto hace que tags como <Button>, <DataTable>, etc. funcionen en cualquier archivo .vue
app.component('Button', Button);
app.component('InputText', InputText);
app.component('DataTable', DataTable);
app.component('Column', Column);
app.component('Dialog', Dialog);
app.component('Dropdown', Dropdown);
app.component('Toast', Toast);
app.component('Card', Card);

app.mount('#app');