<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import QRCode from 'qrcode';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const activeTab = ref('resumen');
const profile = ref({});
const activity = ref([]);
const requests = ref([]);
const notifications = ref([]);

const passwordSaving = ref(false);
const twoFactorSaving = ref(false);
const authenticatorPreparing = ref(false);
const authenticatorConfirming = ref(false);
const qrImage = ref('');
const authenticatorSecret = ref('');

const passwordForm = ref({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const twoFactorForm = ref({
    current_password: '',
});

const authenticatorForm = ref({
    code: '',
});

const tabs = [
    { key: 'resumen', label: 'Resumen', icon: 'pi pi-user' },
    { key: 'seguridad', label: 'Seguridad', icon: 'pi pi-shield' },
    { key: 'actividad', label: 'Movimientos', icon: 'pi pi-history' },
];

const twoFactorMethod = computed(() => profile.value.two_factor_method || 'email');
const twoFactorLabel = computed(() => {
    if (twoFactorMethod.value === 'authenticator') return 'Google Authenticator';
    if (twoFactorMethod.value === 'disabled') return 'Sin segundo factor';
    return 'Código por correo';
});

const fetchProfile = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get('/api/profile');
        profile.value = data.profile || {};
        activity.value = data.activity || [];
        requests.value = data.requests || [];
        notifications.value = data.notifications || [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudo cargar la información del perfil.',
            life: 3500,
        });
    } finally {
        loading.value = false;
    }
};

const resetPasswordForm = () => {
    passwordForm.value = {
        current_password: '',
        password: '',
        password_confirmation: '',
    };
};

const changePassword = async () => {
    passwordSaving.value = true;

    try {
        await axios.post('/api/profile/password', passwordForm.value);
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: 'Contraseña actualizada correctamente.',
            life: 3000,
        });
        resetPasswordForm();
        await fetchProfile();
    } catch (error) {
        showRequestError(error, 'No se pudo actualizar la contraseña.');
    } finally {
        passwordSaving.value = false;
    }
};

const changeTwoFactor = async (method) => {
    if (!twoFactorForm.value.current_password) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmacion requerida',
            detail: 'Ingrese su contraseña para cambiar el metodo de seguridad.',
            life: 3200,
        });
        return;
    }

    twoFactorSaving.value = true;

    try {
        await axios.post('/api/profile/two-factor', {
            method,
            current_password: twoFactorForm.value.current_password,
        });
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: 'Metodo de seguridad actualizado.',
            life: 3000,
        });
        twoFactorForm.value.current_password = '';
        qrImage.value = '';
        authenticatorSecret.value = '';
        await fetchProfile();
    } catch (error) {
        showRequestError(error, 'No se pudo actualizar el metodo de seguridad.');
    } finally {
        twoFactorSaving.value = false;
    }
};

const prepareAuthenticator = async () => {
    if (!twoFactorForm.value.current_password) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmacion requerida',
            detail: 'Ingrese su contraseña para generar el código QR.',
            life: 3200,
        });
        return;
    }

    authenticatorPreparing.value = true;

    try {
        const { data } = await axios.post('/api/profile/two-factor/authenticator/prepare', {
            current_password: twoFactorForm.value.current_password,
        });
        authenticatorSecret.value = data.secret;
        qrImage.value = await QRCode.toDataURL(data.otpauth_url, {
            margin: 1,
            width: 180,
        });
    } catch (error) {
        showRequestError(error, 'No se pudo preparar Google Authenticator.');
    } finally {
        authenticatorPreparing.value = false;
    }
};

const confirmAuthenticator = async () => {
    if (!twoFactorForm.value.current_password) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmacion requerida',
            detail: 'Ingrese su contraseña para activar Google Authenticator.',
            life: 3200,
        });
        return;
    }

    authenticatorConfirming.value = true;

    try {
        await axios.post('/api/profile/two-factor/authenticator/confirm', {
            current_password: twoFactorForm.value.current_password,
            code: authenticatorForm.value.code,
        });
        toast.add({
            severity: 'success',
            summary: 'Activado',
            detail: 'Google Authenticator fue activado correctamente.',
            life: 3000,
        });
        twoFactorForm.value.current_password = '';
        authenticatorForm.value = { code: '' };
        authenticatorSecret.value = '';
        qrImage.value = '';
        await fetchProfile();
    } catch (error) {
        showRequestError(error, 'No se pudo confirmar el código del autenticador.');
    } finally {
        authenticatorConfirming.value = false;
    }
};

const openRequest = (url) => {
    if (url) router.push(url);
};

const showRequestError = (error, fallback) => {
    const errors = error.response?.data?.errors;

    if (errors) {
        Object.values(errors).flat().forEach((message) => {
            toast.add({ severity: 'warn', summary: 'Atencion', detail: message, life: 4000 });
        });
        return;
    }

    toast.add({
        severity: 'error',
        summary: 'Error',
        detail: error.response?.data?.message || fallback,
        life: 4000,
    });
};

onMounted(fetchProfile);
</script>

<template>
    <Toast />
    <div class="perfil-page">
        <div class="perfil-header">
            <div>
                <span class="perfil-eyebrow">Mi Perfil</span>
                <h1>Perfil y seguridad</h1>
                <p>Administra tu información personal, acceso y últimos movimientos.</p>
            </div>
            <div class="perfil-status" :class="{ 'perfil-status--active': profile.is_active }">
                <span></span>
                {{ profile.is_active ? 'Activo' : 'Inactivo' }}
            </div>
        </div>

        <div v-if="loading" class="perfil-panel perfil-loading">Cargando informacion...</div>

        <template v-else>
            <section class="perfil-summary">
                <div class="perfil-identity">
                    <div class="perfil-avatar">
                        {{ (profile.full_name || 'U').slice(0, 1).toUpperCase() }}
                    </div>
                    <div class="perfil-identity__text">
                        <h2>{{ profile.full_name }}</h2>
                        <span>{{ profile.email }}</span>
                    </div>
                </div>

                <div class="perfil-stat">
                    <small>Perfil</small>
                    <strong>{{ profile.role || 'Sin perfil' }}</strong>
                </div>
                <div class="perfil-stat">
                    <small>Unidad principal</small>
                    <strong>{{ profile.unit || 'Sin unidad' }}</strong>
                </div>
                <div class="perfil-stat">
                    <small>Seguridad</small>
                    <strong>{{ twoFactorLabel }}</strong>
                </div>
            </section>

            <nav class="perfil-tabs" aria-label="Secciones del perfil">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    :class="{ 'perfil-tab--active': activeTab === tab.key }"
                    @click="activeTab = tab.key"
                >
                    <i :class="tab.icon"></i>
                    <span>{{ tab.label }}</span>
                </button>
            </nav>

            <section v-if="activeTab === 'resumen'" class="perfil-grid">
                <div class="perfil-panel perfil-panel--wide">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Información personal</h3>
                            <p>Datos principales asociados a tu cuenta.</p>
                        </div>
                    </div>

                    <div class="perfil-info-grid">
                        <div>
                            <small>Carnet</small>
                            <strong>{{ profile.carnet || 'No registrado' }}</strong>
                        </div>
                        <div>
                            <small>Fecha de creacion</small>
                            <strong>{{ profile.created_at || 'No disponible' }}</strong>
                        </div>
                        <div>
                            <small>Ultimo acceso</small>
                            <strong>{{ profile.last_login_at || 'Sin registro' }}</strong>
                        </div>
                        <div>
                            <small>Ultimo cambio de contraseña</small>
                            <strong>{{ profile.password_changed_at || 'Sin registro' }}</strong>
                        </div>
                    </div>

                    <div class="perfil-units">
                        <span v-for="unit in profile.units" :key="unit.id" :class="{ 'perfil-unit--active': unit.is_active }">
                            {{ unit.name }}
                        </span>
                    </div>
                </div>

                <div class="perfil-panel">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Solicitudes recientes</h3>
                            <p>Ultimos movimientos de transferencia o prestamo.</p>
                        </div>
                    </div>

                    <div v-if="requests.length === 0" class="perfil-empty">Sin solicitudes recientes</div>
                    <button
                        v-for="request in requests"
                        :key="`${request.type}-${request.number}`"
                        type="button"
                        class="perfil-request"
                        @click="openRequest(request.url)"
                    >
                        <span>{{ request.type }}</span>
                        <strong>{{ request.number }}</strong>
                        <small>{{ request.status }} · {{ request.created_at }}</small>
                    </button>
                </div>
            </section>

            <section v-if="activeTab === 'seguridad'" class="perfil-grid">
                <div class="perfil-panel">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Cambio de contraseña</h3>
                            <p>Actualiza tu clave de acceso cuando lo necesites.</p>
                        </div>
                    </div>

                    <div class="perfil-form">
                        <label>
                            <span>Contraseña actual</span>
                            <InputText v-model="passwordForm.current_password" type="password" autocomplete="current-password" />
                        </label>
                        <label>
                            <span>Nueva contraseña</span>
                            <InputText v-model="passwordForm.password" type="password" autocomplete="new-password" placeholder="Minimo 10 caracteres" />
                        </label>
                        <p class="perfil-password-policy">
                            Usa al menos 10 caracteres con mayúscula, minúscula, número y símbolo. No uses secuencias de números o letras consecutivos ni una contraseña similar a la anterior.
                        </p>
                        <label>
                            <span>Confirmar nueva contraseña</span>
                            <InputText v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" />
                        </label>
                        <Button label="Guardar contraseña" icon="pi pi-save" class="perfil-primary" :loading="passwordSaving" @click="changePassword" />
                    </div>
                </div>

                <div class="perfil-panel">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Segundo factor</h3>
                            <p>Metodo actual: {{ twoFactorLabel }}</p>
                        </div>
                    </div>

                    <div class="perfil-form">
                        <label>
                            <span>Contraseña para confirmar cambios</span>
                            <InputText v-model="twoFactorForm.current_password" type="password" autocomplete="current-password" />
                        </label>

                        <div class="perfil-methods">
                            <button type="button" :class="{ active: twoFactorMethod === 'email' }" @click="changeTwoFactor('email')">
                                <i class="pi pi-envelope"></i>
                                <span>Código por correo</span>
                            </button>
                            <button type="button" :class="{ active: twoFactorMethod === 'disabled' }" @click="changeTwoFactor('disabled')">
                                <i class="pi pi-lock-open"></i>
                                <span>Sin 2FA</span>
                            </button>
                        </div>

                        <div class="perfil-authenticator">
                            <div>
                                <strong>Google Authenticator</strong>
                                <small>Escanea el QR y confirma con un código de 6 dígitos.</small>
                            </div>
                            <Button label="Generar QR" icon="pi pi-qrcode" severity="secondary" outlined :loading="authenticatorPreparing" @click="prepareAuthenticator" />
                        </div>

                        <div v-if="qrImage" class="perfil-qr">
                            <img :src="qrImage" alt="Código QR para Google Authenticator" />
                            <div>
                                <small>Clave manual</small>
                                <strong>{{ authenticatorSecret }}</strong>
                            </div>
                        </div>

                        <label v-if="qrImage">
                            <span>Código del autenticador</span>
                            <InputText v-model="authenticatorForm.code" maxlength="6" inputmode="numeric" />
                        </label>

                        <Button
                            v-if="qrImage"
                            label="Activar Google Authenticator"
                            icon="pi pi-check"
                            class="perfil-primary"
                            :loading="authenticatorConfirming"
                            @click="confirmAuthenticator"
                        />
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'actividad'" class="perfil-grid">
                <div class="perfil-panel">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Ultimos movimientos</h3>
                            <p>Actividad reciente de tu cuenta.</p>
                        </div>
                    </div>

                    <div v-if="activity.length === 0" class="perfil-empty">Sin movimientos recientes</div>
                    <div v-for="item in activity" :key="item.id" class="perfil-activity">
                        <span class="perfil-activity__dot"></span>
                        <div>
                            <strong>{{ item.title }}</strong>
                            <p>{{ item.description }}</p>
                            <small>{{ item.created_at }}</small>
                        </div>
                    </div>
                </div>

                <div class="perfil-panel">
                    <div class="perfil-panel__header">
                        <div>
                            <h3>Notificaciones</h3>
                            <p>Ultimos avisos recibidos.</p>
                        </div>
                    </div>

                    <div v-if="notifications.length === 0" class="perfil-empty">Sin notificaciones recientes</div>
                    <button
                        v-for="notification in notifications"
                        :key="notification.id"
                        type="button"
                        class="perfil-notification"
                        @click="openRequest(notification.action_url)"
                    >
                        <span :class="{ unread: !notification.is_read }"></span>
                        <div>
                            <strong>{{ notification.title }}</strong>
                            <small>{{ notification.created_at }}</small>
                            <p>{{ notification.description }}</p>
                        </div>
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>

<style scoped>
.perfil-page {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    color: #18324f;
}

.perfil-header,
.perfil-summary,
.perfil-panel {
    border: 1px solid #dce5ef;
    background: #ffffff;
    box-shadow: 0 6px 18px rgba(21, 96, 189, 0.08);
}

.perfil-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem;
    border-radius: 12px;
}

.perfil-eyebrow {
    color: #1560bd;
    font-size: .8rem;
    font-weight: 800;
    text-transform: uppercase;
}

.perfil-header h1 {
    margin: .2rem 0 .25rem;
    color: #1560bd;
    font-size: 1.75rem;
}

.perfil-header p,
.perfil-panel__header p,
.perfil-authenticator small,
.perfil-empty {
    margin: 0;
    color: #60738a;
}

.perfil-status {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .7rem;
    border-radius: 999px;
    background: #eef2f6;
    color: #667085;
    font-weight: 800;
    white-space: nowrap;
}

.perfil-status span,
.perfil-activity__dot,
.perfil-notification > span {
    width: .55rem;
    height: .55rem;
    border-radius: 999px;
    background: #98a2b3;
}

.perfil-status--active {
    background: #eaf8f0;
    color: #168447;
}

.perfil-status--active span,
.perfil-notification > span.unread {
    background: #1560bd;
}

.perfil-summary {
    display: grid;
    grid-template-columns: minmax(240px, 1.4fr) repeat(3, minmax(150px, 1fr));
    gap: .75rem;
    padding: 1rem;
    border-radius: 12px;
}

.perfil-identity {
    display: flex;
    align-items: center;
    gap: .9rem;
    min-width: 0;
}

.perfil-avatar {
    width: 3.4rem;
    height: 3.4rem;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: #e8f2ff;
    color: #1560bd;
    font-size: 1.35rem;
    font-weight: 900;
    flex: 0 0 auto;
}

.perfil-identity h2 {
    margin: 0;
    font-size: 1.1rem;
    color: #0f2440;
}

.perfil-identity span,
.perfil-stat small,
.perfil-info-grid small {
    color: #60738a;
    font-size: .82rem;
}

.perfil-stat,
.perfil-info-grid > div {
    min-width: 0;
    padding: .75rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #e1e9f2;
}

.perfil-stat strong,
.perfil-info-grid strong {
    display: block;
    margin-top: .25rem;
    color: #0f2440;
    word-break: break-word;
}

.perfil-tabs {
    display: flex;
    gap: .5rem;
    overflow-x: auto;
    padding-bottom: .1rem;
}

.perfil-tabs button,
.perfil-methods button,
.perfil-request,
.perfil-notification {
    border: 1px solid #d7e3ef;
    background: #ffffff;
    color: #31506f;
    cursor: pointer;
}

.perfil-tabs button {
    min-height: 2.8rem;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem .95rem;
    border-radius: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.perfil-tab--active {
    background: #1560bd !important;
    color: #ffffff !important;
    border-color: #1560bd !important;
}

.perfil-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(320px, .7fr);
    gap: 1rem;
}

.perfil-panel {
    border-radius: 12px;
    padding: 1.1rem;
    min-width: 0;
}

.perfil-panel--wide {
    grid-column: span 1;
}

.perfil-panel__header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.perfil-panel__header h3 {
    margin: 0 0 .25rem;
    color: #0f2440;
    font-size: 1.05rem;
}

.perfil-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .75rem;
}

.perfil-units {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: 1rem;
}

.perfil-units span {
    padding: .4rem .65rem;
    border-radius: 999px;
    background: #eef2f6;
    color: #526173;
    font-weight: 700;
    font-size: .82rem;
}

.perfil-units .perfil-unit--active {
    background: #e8f2ff;
    color: #1560bd;
}

.perfil-form {
    display: flex;
    flex-direction: column;
    gap: .85rem;
}

.perfil-form label {
    display: flex;
    flex-direction: column;
    gap: .35rem;
    color: #1560bd;
    font-weight: 800;
}

.perfil-password-policy {
    margin: -.35rem 0 .1rem;
    color: #60738a;
    font-size: .82rem;
    font-weight: 700;
    line-height: 1.45;
}

.perfil-primary {
    width: max-content;
    max-width: 100%;
    border: none !important;
    background: var(--ugda-brand) !important;
    color: var(--ugda-text-inverse) !important;
    font-weight: var(--ugda-weight-medium) !important;
}

.perfil-methods {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .65rem;
}

.perfil-methods button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    min-height: 3rem;
    padding: .7rem;
    border-radius: 10px;
    font-weight: 800;
}

.perfil-methods button.active {
    border-color: #1560bd;
    background: #e8f2ff;
    color: #1560bd;
}

.perfil-authenticator,
.perfil-qr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: .9rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #dfe8f2;
}

.perfil-authenticator div,
.perfil-qr div {
    display: flex;
    flex-direction: column;
    gap: .25rem;
    min-width: 0;
}

.perfil-qr {
    justify-content: flex-start;
}

.perfil-qr img {
    width: 132px;
    height: 132px;
    flex: 0 0 auto;
}

.perfil-qr strong {
    word-break: break-all;
    color: #0f2440;
}

.perfil-request,
.perfil-notification {
    width: 100%;
    display: flex;
    text-align: left;
    border-radius: 10px;
    padding: .85rem;
    margin-bottom: .6rem;
}

.perfil-request {
    flex-direction: column;
    gap: .2rem;
}

.perfil-request span {
    color: #1560bd;
    font-size: .8rem;
    font-weight: 800;
}

.perfil-request strong,
.perfil-activity strong,
.perfil-notification strong {
    color: #0f2440;
}

.perfil-request small,
.perfil-activity small,
.perfil-notification small {
    color: #667085;
}

.perfil-activity {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: .75rem;
    padding: .75rem 0;
    border-bottom: 1px solid #edf1f6;
}

.perfil-activity p,
.perfil-notification p {
    margin: .2rem 0;
    color: #475b73;
}

.perfil-notification {
    gap: .75rem;
}

.perfil-notification > span {
    margin-top: .35rem;
    flex: 0 0 auto;
}

.perfil-loading,
.perfil-empty {
    padding: 1.25rem;
    text-align: center;
    border-radius: 12px;
}

@media (max-width: 1100px) {
    .perfil-summary,
    .perfil-grid {
        grid-template-columns: 1fr;
    }

    .perfil-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .perfil-identity {
        grid-column: 1 / -1;
    }
}

@media (max-width: 640px) {
    .perfil-header {
        flex-direction: column;
    }

    .perfil-header h1 {
        font-size: 1.45rem;
    }

    .perfil-summary,
    .perfil-info-grid,
    .perfil-methods {
        grid-template-columns: 1fr;
    }

    .perfil-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .35rem;
        overflow-x: visible;
    }

    .perfil-tabs button {
        gap: .3rem;
        min-width: 0;
        justify-content: center;
        padding: .65rem .25rem;
        font-size: .76rem;
    }

    .perfil-tabs button span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .perfil-authenticator,
    .perfil-qr {
        align-items: stretch;
        flex-direction: column;
    }

    .perfil-primary {
        width: 100%;
        justify-content: center;
    }
}
</style>
