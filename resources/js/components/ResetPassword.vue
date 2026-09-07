<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import Button from 'primevue/button';
import axios from 'axios';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const token = String(route.params.token ?? '');
const password = ref('');
const passwordConfirmation = ref('');
const showPassword = ref(false);
const showPasswordConfirmation = ref(false);
const loading = ref(false);
const resetCompleted = ref(false);
const linkChecking = ref(false);
const linkValid = ref(null);
const showHelpModal = ref(false);

const hasToken = computed(() => token.length > 0);
const isUsableLink = computed(() => hasToken.value && linkValid.value === true);

const validateResetLink = async () => {
    if (!hasToken.value) {
        linkValid.value = false;
        return;
    }

    linkChecking.value = true;

    try {
        await axios.get(`/reset-password/${encodeURIComponent(token)}/validate`);
        linkValid.value = true;
    } catch (error) {
        linkValid.value = false;
    } finally {
        linkChecking.value = false;
    }
};

const handleErrors = (error) => {
    const response = error.response;

    if (response?.status === 422) {
        const errors = response.data.errors;
        if (errors) {
            Object.values(errors).flat().forEach((message) => {
                toast.add({ severity: 'warn', summary: 'Revisa la información', detail: message, life: 4500 });
            });
        } else {
            toast.add({ severity: 'error', summary: 'Enlace no válido', detail: response.data.message, life: 5000 });
        }
        return;
    }

    toast.add({
        severity: 'error',
        summary: 'Error',
        detail: response?.data?.message || 'No fue posible restablecer la contraseña.',
        life: 4500
    });
};

const handleResetPassword = async () => {
    if (!isUsableLink.value || !password.value || !passwordConfirmation.value) {
        toast.add({
            severity: 'warn',
            summary: 'Campos incompletos',
            detail: 'Completa los campos de la nueva contraseña.',
            life: 3500
        });
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post('/reset-password', {
            token,
            password: password.value,
            password_confirmation: passwordConfirmation.value
        });

        resetCompleted.value = true;
        toast.add({
            severity: 'success',
            summary: 'Contraseña restablecida',
            detail: response.data.message,
            life: 5000
        });
    } catch (error) {
        handleErrors(error);
    } finally {
        loading.value = false;
    }
};

const goToLogin = () => router.replace('/login');

onMounted(validateResetLink);
</script>

<template>
    <div class="reset-page font-custom">
        <div class="reset-page__orb reset-page__orb--top"></div>
        <div class="reset-page__orb reset-page__orb--bottom"></div>

        <header class="reset-header">
            <div class="reset-header__content">
                <div class="reset-header__brand">
                    <div class="reset-header__icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 21h14" />
                            <path d="M7 21V6l5-3 5 3v15" />
                            <path d="M9.5 9.5h1" />
                            <path d="M9.5 13h1" />
                            <path d="M9.5 16.5h1" />
                            <path d="M13.5 9.5h1" />
                            <path d="M13.5 13h1" />
                            <path d="M13.5 16.5h1" />
                        </svg>
                    </div>
                    <div class="reset-header__text">
                        <span class="reset-header__title">Sistema de Gestión Documental</span>
                        <span class="reset-header__subtitle">Universidad de El Salvador</span>
                    </div>
                </div>

                <button type="button" class="reset-help-button" @click="showHelpModal = true">Ayuda</button>

            </div>
        </header>

        <main class="reset-main">
            <section class="reset-hero" aria-label="Información institucional">
                <div class="reset-pill">UGDA - Unidad de Gestión Documental y Archivos</div>

                <h1 class="reset-hero__title">
                    Centro de Gestión de
                    <br />
                    Transferencias Documentales
                </h1>

                <p class="reset-hero__description">
                    Gestiona y resguarda la documentación institucional, garantizando acceso oportuno,
                    control archivístico y preservación de la memoria histórica de la Facultad de
                    Ingeniería y Arquitectura.
                </p>

                <div class="reset-hero__image-card">
                    <img src="/images/gestion-documental.jpg" alt="Gestión documental" class="reset-hero__image"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                    <div class="reset-hero__image-fallback">Imagen no encontrada</div>
                </div>

                <div class="reset-feature-grid">
                    <article class="reset-feature-card">
                        <div class="reset-feature-card__icon reset-feature-card__icon--blue">
                            <i class="pi pi-clock"></i>
                        </div>
                        <h2 class="reset-feature-card__title">Reducción de costos y tiempo</h2>
                        <p class="reset-feature-card__description">Localiza, comparte y almacena documentos de forma ágil y eficiente.</p>
                    </article>

                    <article class="reset-feature-card">
                        <div class="reset-feature-card__icon reset-feature-card__icon--orange">
                            <i class="pi pi-cog"></i>
                        </div>
                        <h2 class="reset-feature-card__title">Mejora en los procesos</h2>
                        <p class="reset-feature-card__description">Facilita el acceso a información y estandariza procedimientos institucionales.</p>
                    </article>

                    <article class="reset-feature-card">
                        <div class="reset-feature-card__icon reset-feature-card__icon--green">
                            <i class="pi pi-shield"></i>
                        </div>
                        <h2 class="reset-feature-card__title">Reducción del riesgo profesional</h2>
                        <p class="reset-feature-card__description">Garantiza control archivístico y estandarización de procesos.</p>
                    </article>
                </div>
            </section>

            <section class="reset-auth" aria-labelledby="reset-title">
                <div class="reset-card">
                    <div class="reset-card__brand">
                        <img src="/images/Logo-UES-Blue.png" alt="Universidad de El Salvador" class="reset-card__logo" />
                        <h2 id="reset-title" class="reset-card__title">
                            {{ resetCompleted ? 'Contraseña actualizada' : 'Restablecer contraseña' }}
                        </h2>
                        <p class="reset-card__subtitle">
                            {{ resetCompleted
                                ? 'Tu contraseña se cambió correctamente.'
                                : 'Crea una nueva contraseña segura para acceder al sistema.' }}
                        </p>
                    </div>

                    <div v-if="resetCompleted" class="reset-success">
                        <i class="pi pi-check-circle"></i>
                        <p>Ya puedes ingresar con tu nueva contraseña.</p>
                        <Button label="Volver al inicio de sesión" icon="pi pi-sign-in"
                            class="reset-primary-button" @click="goToLogin" />
                    </div>

                    <div v-else-if="linkChecking" class="reset-link-status">
                        <i class="pi pi-spin pi-spinner"></i>
                        <span>Verificando el enlace de restablecimiento...</span>
                    </div>

                    <div v-else-if="!isUsableLink" class="reset-error">
                        <i class="pi pi-exclamation-triangle"></i>
                        <span>El enlace de restablecimiento no es válido o ya fue utilizado.</span>
                        <Button label="Solicitar un nuevo enlace" icon="pi pi-envelope"
                            class="reset-primary-button" @click="goToLogin" />
                    </div>

                    <form v-else class="reset-form" @submit.prevent="handleResetPassword">

                    <div class="reset-field">
                        <label for="reset-password">Nueva contraseña</label>
                        <div class="reset-field__control">
                            <i class="pi pi-lock"></i>
                            <input id="reset-password" v-model="password"
                                :type="showPassword ? 'text' : 'password'" autocomplete="new-password"
                                placeholder="Mínimo 10 caracteres" :disabled="loading" />
                            <button type="button" class="reset-password-toggle" @click="showPassword = !showPassword"
                                :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'">
                                <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <div class="reset-field">
                        <label for="reset-password-confirmation">Confirmar nueva contraseña</label>
                        <div class="reset-field__control">
                            <i class="pi pi-lock"></i>
                            <input id="reset-password-confirmation" v-model="passwordConfirmation"
                                :type="showPasswordConfirmation ? 'text' : 'password'" autocomplete="new-password"
                                placeholder="Repite la nueva contraseña" :disabled="loading" />
                            <button type="button" class="reset-password-toggle"
                                @click="showPasswordConfirmation = !showPasswordConfirmation"
                                :aria-label="showPasswordConfirmation ? 'Ocultar contraseña' : 'Mostrar contraseña'">
                                <i :class="showPasswordConfirmation ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <p class="reset-policy">Usa al menos 10 caracteres con mayúscula, minúscula, número y símbolo. No uses secuencias de números o letras consecutivos.</p>

                    <Button type="submit" label="Guardar nueva contraseña" icon="pi pi-check"
                        class="reset-primary-button" :loading="loading" :disabled="!isUsableLink" />
                        <Button type="button" label="Volver al inicio de sesión" severity="secondary" outlined
                            class="reset-secondary-button" :disabled="loading" @click="goToLogin" />
                    </form>

                    <div class="reset-card__footer">
                        © 2025 Universidad de El Salvador<br />
                        Facultad de Ingeniería y Arquitectura<br />
                        Todos los derechos reservados
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showHelpModal" class="reset-help-overlay" @click.self="showHelpModal = false">
            <div class="reset-help-modal" role="dialog" aria-modal="true" aria-labelledby="reset-help-title">
                <header class="reset-help-modal__header">
                    <h2 id="reset-help-title" class="reset-help-modal__title">Centro de Ayuda</h2>
                    <button type="button" class="reset-help-modal__close" @click="showHelpModal = false"
                        aria-label="Cerrar modal">
                        <i class="pi pi-times"></i>
                    </button>
                </header>

                <div class="reset-help-modal__body">
                    <div class="reset-help-modal__section">
                        <h3 class="reset-help-modal__subtitle">¿Necesitas ayuda con los procesos?</h3>
                        <p class="reset-help-modal__text">Para mayor información o asistencia con los procedimientos institucionales, contáctanos al correo:</p>
                        <a href="mailto:procesos-ugda@fia.ues.edu.sv" class="reset-help-modal__link">
                            <i class="pi pi-envelope"></i> procesos-ugda@fia.ues.edu.sv
                        </a>
                    </div>

                    <div class="reset-help-modal__section">
                        <h3 class="reset-help-modal__subtitle">¿Problemas de acceso?</h3>
                        <p class="reset-help-modal__text">Si necesitas recuperar tus credenciales o reportar problemas técnicos para ingresar, comunícate a:</p>
                        <a href="mailto:admin-ugda@fia.ues.edu.sv" class="reset-help-modal__link reset-help-modal__link--secondary">
                            <i class="pi pi-user-edit"></i> admin-ugda@fia.ues.edu.sv
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.font-custom,
.font-custom *:not(.pi) {
    font-family: var(--ugda-font-family) !important;
}

.reset-page {
    min-height: 100vh;
    background: linear-gradient(180deg, #f5f9ff 0%, #edf5ff 100%);
    color: var(--ugda-text-primary);
}

.reset-header {
    display: flex;
    min-height: 74px;
    align-items: center;
    padding: 0 48px;
    background: #296cba;
    box-shadow: 0 2px 16px rgba(17, 59, 115, 0.18);
}

.reset-header__brand {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #fff;
}

.reset-header__icon {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 50%;
    background: #fff;
    color: #296cba;
    font-size: 18px;
}

.reset-header__brand > div:last-child {
    display: flex;
    flex-direction: column;
}

.reset-header__title {
    font-size: 15px;
    font-weight: 800;
}

.reset-header__subtitle {
    margin-top: 2px;
    color: #d1e3f8;
    font-size: 12px;
}

.reset-main {
    display: flex;
    min-height: calc(100vh - 74px);
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
}

.reset-card {
    width: min(100%, 480px);
    padding: 36px 40px 32px;
    border: 1px solid rgba(204, 220, 239, 0.8);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 18px 42px rgba(18, 65, 116, 0.14);
}

.reset-card__brand {
    margin-bottom: 28px;
    text-align: center;
}

.reset-card__logo {
    width: 58px;
    height: 58px;
    object-fit: contain;
    margin-bottom: 12px;
}

.reset-card__title {
    margin: 0;
    color: #296cba;
    font-size: 26px;
    font-weight: 800;
}

.reset-card__subtitle {
    margin: 8px 0 0;
    color: #607a9c;
    font-size: 14px;
    line-height: 1.5;
}

.reset-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.reset-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.reset-field label {
    color: #2f6fad;
    font-size: 14px;
    font-weight: 800;
}

.reset-field__control {
    display: flex;
    min-height: 46px;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    border: 1px solid #cfd8e6;
    border-radius: 10px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.reset-field__control:focus-within {
    border-color: #2c6fbf;
    box-shadow: 0 0 0 3px rgba(44, 111, 191, 0.12);
}

.reset-field__control > .pi {
    color: #7e98b8;
    font-size: 16px;
}

.reset-field__control input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    color: #334e70;
    font: inherit;
}

.reset-field__control input::placeholder {
    color: #9aaabc;
}

.reset-password-toggle {
    border: 0;
    background: transparent;
    color: #7187a3;
    cursor: pointer;
    font-size: 17px;
}

.reset-policy {
    margin: -2px 0 2px;
    color: #57759a;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.5;
}

.reset-primary-button,
.reset-secondary-button {
    width: 100%;
    justify-content: center;
}

.reset-primary-button {
    min-height: 46px;
    border: 0 !important;
    background: linear-gradient(180deg, #3879b6 0%, #2f6fad 100%) !important;
    color: #fff !important;
    font-weight: 800 !important;
}

.reset-secondary-button {
    min-height: 44px;
    margin-top: -6px;
}

.reset-error,
.reset-success,
.reset-link-status {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    text-align: center;
}

.reset-error {
    padding: 14px;
    border-radius: 10px;
    background: #fff4e7;
    color: #92400e;
    font-size: 14px;
}

.reset-success {
    color: #047857;
}

.reset-link-status {
    color: #57759a;
    font-size: 14px;
}

.reset-success > .pi {
    font-size: 48px;
}

.reset-success p {
    margin: 0 0 6px;
    color: #526477;
    font-size: 14px;
}

@media (max-width: 640px) {
    .reset-header {
        padding: 0 18px;
    }

    .reset-card {
        padding: 30px 22px 26px;
    }

    .reset-main {
        padding: 28px 16px;
    }
}

/* Misma composición visual que el acceso principal */
.font-custom .pi,
.font-custom i.pi,
.font-custom span.pi {
    font-family: 'primeicons' !important;
    font-style: normal;
    font-weight: normal;
    font-variant: normal;
    text-transform: none;
    line-height: 1;
    display: inline-block;
}

.reset-page {
    position: relative;
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: linear-gradient(180deg, #f5f9ff 0%, #edf5ff 100%);
}

.reset-page__orb {
    position: fixed;
    border-radius: 999px;
    pointer-events: none;
    opacity: 0.8;
    z-index: 0;
}

.reset-page__orb--top {
    top: 76px;
    right: -140px;
    width: 560px;
    height: 560px;
    background: radial-gradient(circle, rgba(204, 226, 255, 0.9) 0%, rgba(204, 226, 255, 0.25) 60%, rgba(204, 226, 255, 0) 100%);
}

.reset-page__orb--bottom {
    left: -220px;
    bottom: -220px;
    width: 620px;
    height: 620px;
    background: radial-gradient(circle, rgba(214, 229, 251, 0.7) 0%, rgba(214, 229, 251, 0.2) 60%, rgba(214, 229, 251, 0) 100%);
}

.reset-header {
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    padding: 0;
    background: #296cba;
    box-shadow: 0 2px 16px rgba(17, 59, 115, 0.18);
}

.reset-header__content {
    width: 100%;
    max-width: 1320px;
    min-height: 60px;
    margin: 0 auto;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.reset-header__brand {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #ffffff;
}

.reset-header__icon {
    display: flex;
    width: 36px;
    height: 36px;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #ffffff;
    color: #296cba;
    box-shadow: 0 6px 12px rgba(18, 54, 104, 0.18);
}

.reset-header__text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.reset-header__title {
    font-size: 15px;
    font-weight: 800;
    line-height: 1.2;
}

.reset-header__subtitle {
    color: rgba(255, 255, 255, 0.95);
    font-size: 12px;
    font-weight: 500;
    line-height: 1.2;
}

.reset-header__back {
    padding: 8px 16px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    transition: background-color 0.2s ease, border-color 0.2s ease;
}

.reset-header__back:hover {
    border-color: rgba(255, 255, 255, 0.32);
    background: rgba(255, 255, 255, 0.18);
}

.reset-help-button {
    padding: 8px 16px;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 700;
    transition: background-color 0.2s ease, border-color 0.2s ease;
}

.reset-help-button:hover {
    border-color: rgba(255, 255, 255, 0.32);
    background: rgba(255, 255, 255, 0.18);
}

.reset-main {
    position: relative;
    z-index: 1;
    flex: 1;
    width: 100%;
    max-width: 1320px;
    min-height: 0;
    margin: 0 auto;
    padding: 0 24px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 416px;
    gap: 64px;
    align-content: center;
}

.reset-hero {
    max-width: 700px;
}

.reset-pill {
    display: inline-flex;
    min-height: 32px;
    align-items: center;
    justify-content: center;
    padding: 6px 16px;
    border-radius: 999px;
    background: #276cbc;
    box-shadow: 0 8px 16px rgba(39, 108, 188, 0.22);
    color: #ffffff;
    font-size: 13px;
    font-weight: 600;
}

.reset-hero__title {
    margin: clamp(10px, 2vh, 18px) 0 clamp(6px, 1vh, 10px);
    color: #2f6fae;
    font-size: clamp(32px, 3.6vw, 44px);
    font-weight: 800;
    line-height: 1.12;
}

.reset-hero__description {
    max-width: 610px;
    margin: 0 0 clamp(10px, 2vh, 18px);
    color: #516f95;
    font-size: 15px;
    line-height: 1.5;
}

.reset-hero__image-card {
    width: 100%;
    max-width: 610px;
    height: clamp(140px, 24vh, 260px);
    margin-bottom: clamp(10px, 2vh, 18px);
    overflow: hidden;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 18px 40px rgba(61, 96, 145, 0.18);
}

.reset-hero__image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.reset-hero__image-fallback {
    display: none;
    min-height: 100%;
    align-items: center;
    justify-content: center;
    background: #dfe8f6;
    color: #52719a;
    font-weight: 700;
}

.reset-feature-grid {
    width: 100%;
    max-width: 610px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.reset-feature-card {
    min-height: auto;
    padding: 12px 14px;
    border: 1px solid rgba(170, 197, 232, 0.52);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.95);
    box-shadow: 0 14px 30px rgba(66, 93, 137, 0.12);
}

.reset-feature-card__icon {
    display: flex;
    width: 34px;
    height: 34px;
    align-items: center;
    justify-content: center;
    margin-bottom: 8px;
    border-radius: 10px;
    font-size: 18px;
}

.reset-feature-card__icon--blue {
    background: #eaf3ff;
    color: #2a6db8;
}

.reset-feature-card__icon--orange {
    background: #fff4e7;
    color: #e39a2d;
}

.reset-feature-card__icon--green {
    background: #edf9ef;
    color: #5aa86c;
}

.reset-feature-card__title {
    margin: 0 0 6px;
    color: #2e6fad;
    font-size: 15px;
    font-weight: 800;
    line-height: 1.45;
}

.reset-feature-card__description {
    margin: 0;
    color: #607a9c;
    font-size: 12px;
    line-height: 1.55;
}

.reset-auth {
    display: flex;
    justify-content: flex-end;
}

.reset-card {
    display: flex;
    width: 100%;
    max-width: 416px;
    flex-direction: column;
    padding: clamp(24px, 4vh, 32px) 32px clamp(20px, 3vh, 28px);
    border: 1px solid rgba(204, 220, 239, 0.65);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 20px 40px rgba(48, 74, 111, 0.16);
}

.reset-card__brand {
    margin-bottom: clamp(16px, 3vh, 24px);
}

.reset-card__logo {
    display: block;
    width: clamp(60px, 8vh, 76px);
    height: auto;
    margin: 0 auto clamp(8px, 1.5vh, 12px);
}

.reset-card__title {
    margin: 0 0 6px;
    color: #2d6fb0;
    font-size: 27px;
    font-weight: 800;
    line-height: 1.2;
}

.reset-card__subtitle {
    margin: 0;
    color: #647fa0;
    font-size: 13px;
    line-height: 1.5;
}

.reset-card__footer {
    margin-top: auto;
    padding-top: clamp(22px, 4vh, 32px);
    color: #6b819f;
    font-size: 11.5px;
    line-height: 1.6;
    text-align: center;
}

.reset-form .reset-field {
    margin-bottom: 0;
}

.reset-primary-button,
.reset-secondary-button {
    border-radius: 12px !important;
}

.reset-help-overlay {
    position: fixed;
    inset: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(14, 32, 59, 0.45);
    backdrop-filter: blur(4px);
}

.reset-help-modal {
    width: 100%;
    max-width: 460px;
    overflow: hidden;
    border-radius: 18px;
    background: #ffffff;
    box-shadow: 0 24px 50px rgba(18, 43, 80, 0.2);
}

.reset-help-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid rgba(204, 220, 239, 0.5);
    background: rgba(245, 249, 255, 0.5);
}

.reset-help-modal__title {
    margin: 0;
    color: #296cba;
    font-size: 18px;
    font-weight: 800;
}

.reset-help-modal__close {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 6px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #7a8fa9;
    cursor: pointer;
    font-size: 16px;
}

.reset-help-modal__close:hover {
    background: #eaf3ff;
    color: #296cba;
}

.reset-help-modal__body {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 24px;
}

.reset-help-modal__section {
    padding: 20px;
    border: 1px solid rgba(204, 220, 239, 0.4);
    border-radius: 14px;
    background: #f8fafc;
}

.reset-help-modal__subtitle {
    margin: 0 0 8px;
    color: #2f6fae;
    font-size: 15px;
    font-weight: 700;
}

.reset-help-modal__text {
    margin: 0 0 12px;
    color: #607a9c;
    font-size: 13px;
    line-height: 1.55;
}

.reset-help-modal__link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #2f6fad;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.reset-help-modal__link:hover {
    color: #1e4f88;
    text-decoration: underline;
}

.reset-help-modal__link--secondary {
    color: #d78b20;
}

.reset-primary-button {
    min-height: 48px;
    margin-top: 8px;
    font-size: 14px !important;
}

.reset-secondary-button {
    min-height: 48px;
    margin-top: 12px;
}

@media (max-width: 1200px) {
    .reset-main {
        gap: 40px;
        grid-template-columns: minmax(0, 1fr) 392px;
    }

    .reset-hero__title {
        font-size: 32px;
    }
}

@media (max-width: 991px) {
    .reset-page {
        overflow-y: auto;
    }

    .reset-main {
        padding-top: 38px;
        padding-bottom: 38px;
        grid-template-columns: 1fr;
        gap: 28px;
        justify-items: center;
        align-content: start;
    }

    .reset-hero {
        width: 100%;
        max-width: 610px;
    }

    .reset-auth {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 767px) {
    .reset-header__content {
        min-height: 74px;
        padding: 0 16px;
    }

    .reset-header__title {
        font-size: 13px;
    }

    .reset-header__subtitle {
        font-size: 10px;
    }

    .reset-header__back {
        padding: 8px 14px;
        font-size: 12px;
    }

    .reset-help-button {
        padding: 8px 14px;
        font-size: 13px;
    }

    .reset-main {
        padding: 24px 16px 28px;
    }

    .reset-hero__title {
        margin-top: 24px;
        font-size: 28px;
    }

    .reset-hero__description {
        font-size: 15px;
        line-height: 1.65;
    }

    .reset-feature-grid {
        grid-template-columns: 1fr;
    }

    .reset-card {
        max-width: 100%;
        padding: 28px 20px 24px;
    }
}
</style>
