<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import { clearCachedUser, hasExpiredSessionActivity } from '../services/authSession';

const STORAGE_KEY = 'ugda_session_activity';
const status = ref(null);
const remaining = ref(0);
const renewing = ref(false);
const message = ref('');
let clockTimer;
let statusTimer;
let activityTimer;
let lastSent = 0;
let checking = false;
let stopped = false;
let clockOffset = 0;
let checkedDeadline = null;

const visible = computed(() => status.value && remaining.value <= status.value.warning_seconds);
const countdown = computed(() => {
    const seconds = Math.max(0, remaining.value);
    return `${Math.floor(seconds / 60).toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;
});

const updateClock = () => {
    if (!status.value) return;
    remaining.value = Math.max(0, Math.ceil(status.value.expires_at - (Date.now() / 1000 + clockOffset)));
};

const acceptStatus = (data, broadcast = false) => {
    if (stopped) return;
    if (status.value && data.session_key === status.value.session_key && data.last_activity < status.value.last_activity) return;
    status.value = data;
    clockOffset = data.server_time - Date.now() / 1000;
    updateClock();
    if (broadcast) {
        try {
            const previous = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (previous?.session_key === data.session_key && previous.last_activity >= data.last_activity) return;
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...data, sent_at: Date.now() }));
        } catch { /* La comprobación del servidor funciona sin almacenamiento local. */ }
    }
};

const handleError = (error) => {
    if ([401, 419].includes(error.response?.status)) {
        clearCachedUser();
        window.location.replace(error.response?.data?.code === 'SESSION_IDLE_EXPIRED' || hasExpiredSessionActivity()
            ? '/login?reason=inactivity'
            : '/login');
    } else if (visible.value) {
        message.value = 'No se pudo verificar la sesión. Comprueba tu conexión e intenta nuevamente.';
    }
};

const checkStatus = async () => {
    if (checking || stopped) return;
    checking = true;
    try {
        const { data } = await axios.get('/api/session/activity');
        acceptStatus(data, true);
        message.value = '';
    } catch (error) {
        handleError(error);
    } finally {
        checking = false;
    }
};

const sendActivity = async () => {
    if (renewing.value || stopped) return;
    renewing.value = true;
    lastSent = Date.now();
    message.value = '';
    try {
        const { data } = await axios.post('/api/session/activity');
        acceptStatus(data, true);
    } catch (error) {
        handleError(error);
    } finally {
        renewing.value = false;
    }
};

const recordActivity = (event) => {
    // El aviso requiere una confirmación explícita; no se renueva por mover el ratón.
    if (!event.isTrusted || document.visibilityState !== 'visible' || visible.value || !status.value) return;
    if (Date.now() - lastSent >= 30000) {
        clearTimeout(activityTimer);
        activityTimer = null;
        sendActivity();
    } else if (!activityTimer) {
        activityTimer = window.setTimeout(() => {
            activityTimer = null;
            updateClock();
            sendActivity();
        }, 30000 - (Date.now() - lastSent));
    }
};

const continueSession = () => {
    clearTimeout(activityTimer);
    activityTimer = null;
    sendActivity();
};

const receiveActivity = (event) => {
    if (event.key !== STORAGE_KEY || !event.newValue || !status.value) return;
    try {
        const data = JSON.parse(event.newValue);
        if (data.session_key !== status.value.session_key) return;
        // Corregir el tiempo transcurrido desde que otra pestaña recibió la respuesta.
        data.server_time += Math.max(0, (Date.now() - data.sent_at) / 1000);
        acceptStatus(data);
    } catch { /* Ignorar datos incompletos. */ }
};

const resume = () => {
    if (document.visibilityState === 'visible') {
        updateClock();
        checkStatus();
    }
};

const events = ['pointerdown', 'keydown', 'scroll', 'touchstart'];
onMounted(() => {
    checkStatus();
    clockTimer = window.setInterval(() => {
        updateClock();
        if (status.value && remaining.value === 0 && checkedDeadline !== status.value.expires_at) {
            checkedDeadline = status.value.expires_at;
            checkStatus();
        }
    }, 1000);
    statusTimer = window.setInterval(checkStatus, 20000);
    events.forEach((event) => window.addEventListener(event, recordActivity, { passive: true, capture: true }));
    window.addEventListener('storage', receiveActivity);
    window.addEventListener('focus', resume);
    document.addEventListener('visibilitychange', resume);
});

onBeforeUnmount(() => {
    stopped = true;
    clearInterval(clockTimer);
    clearInterval(statusTimer);
    clearTimeout(activityTimer);
    events.forEach((event) => window.removeEventListener(event, recordActivity, true));
    window.removeEventListener('storage', receiveActivity);
    window.removeEventListener('focus', resume);
    document.removeEventListener('visibilitychange', resume);
});
</script>

<template>
    <Dialog :visible="Boolean(visible)" modal header="Tu sesión está por expirar"
        :closable="false" :draggable="false" :dismissableMask="false" :closeOnEscape="false"
        class="session-idle-dialog" :style="{ width: '28rem', maxWidth: 'calc(100vw - 2rem)' }">
        <div class="session-idle-content">
            <i class="pi pi-clock session-idle-icon" aria-hidden="true"></i>
            <p>Llevas un tiempo sin actividad. Tu sesión se cerrará en:</p>
            <div class="session-idle-countdown" role="timer" aria-label="Tiempo restante de la sesión">{{ countdown }}</div>
            <p class="session-idle-note">Continúa la sesión para seguir trabajando.</p>
            <p v-if="message" role="alert" class="session-idle-error">{{ message }}</p>
        </div>
        <template #footer>
            <Button label="Continuar sesión" icon="pi pi-check" :loading="renewing" @click="continueSession" />
        </template>
    </Dialog>
</template>

<style scoped>
.session-idle-content { text-align: center; color: var(--ugda-text-primary); }
.session-idle-icon { font-size: 2rem; color: var(--ugda-brand); }
.session-idle-countdown { font-size: 2.5rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--ugda-brand); }
.session-idle-note { color: var(--ugda-text-secondary); }
.session-idle-error { color: var(--p-red-600); }
</style>
