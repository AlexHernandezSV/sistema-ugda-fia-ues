import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ¡IMPORTANTE! Esto permite enviar las cookies de sesión y el token CSRF automáticamente
window.axios.defaults.withCredentials = true;
