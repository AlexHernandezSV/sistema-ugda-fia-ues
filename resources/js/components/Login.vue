<script setup>
import { ref, nextTick, computed, onBeforeUnmount } from 'vue';
import Button from 'primevue/button';
import { useToast } from 'primevue/usetoast';
import { useRoute } from 'vue-router';
import axios from 'axios';

const toast = useToast();
const route = useRoute();

const step = ref(1);
const loading = ref(false);
const showHelpModal = ref(false); // Estado para controlar el modal de ayuda
const showForgotPasswordModal = ref(false);
const forgotPasswordLoading = ref(false);
const showPassword = ref(false);
const showTemporaryPassword = ref(false);
const showNewPassword = ref(false);
const showNewPasswordConfirmation = ref(false);
const twoFactorMethod = ref('email');
const lockoutSeconds = ref(0);

const email = ref('');
const forgotPasswordEmail = ref('');
const password = ref('');
const temporaryPassword = ref('');
const newPassword = ref('');
const newPasswordConfirmation = ref('');
const digits = ref(['', '', '', '', '', '']);
const inputs = ref([]);
let lockoutTimer = null;

const isLockoutActive = computed(() => lockoutSeconds.value > 0);

const maskedEmail = computed(() => {
    const value = String(email.value ?? '');
    const separatorIndex = value.indexOf('@');

    if (separatorIndex <= 0 || separatorIndex === value.length - 1) {
        return 'correo registrado';
    }

    const localPart = value.slice(0, separatorIndex);
    const domain = value.slice(separatorIndex + 1);

    if (localPart.length === 1) {
        return `*@${domain}`;
    }

    return `${localPart[0]}${'*'.repeat(Math.max(2, localPart.length - 2))}${localPart.slice(-1)}@${domain}`;
});

const lockoutMessage = computed(() => {
    const minutes = Math.floor(lockoutSeconds.value / 60);
    const seconds = lockoutSeconds.value % 60;
    const time = minutes > 0
        ? `${minutes} min ${seconds.toString().padStart(2, '0')} s`
        : `${seconds} s`;

    return `Acceso bloqueado temporalmente. Intenta de nuevo en ${time}.`;
});

const focusOtpInput = (index) => {
    inputs.value[index]?.focus?.();
};

const togglePasswordVisibility = () => {
    showPassword.value = !showPassword.value;
};

const resetOtp = () => {
    digits.value = ['', '', '', '', '', ''];
};

const startLockout = (retryAfter = 60) => {
    lockoutSeconds.value = Math.max(1, Number(retryAfter) || 60);

    if (lockoutTimer) {
        clearInterval(lockoutTimer);
    }

    lockoutTimer = setInterval(() => {
        lockoutSeconds.value = Math.max(0, lockoutSeconds.value - 1);

        if (lockoutSeconds.value === 0 && lockoutTimer) {
            clearInterval(lockoutTimer);
            lockoutTimer = null;
        }
    }, 1000);
};

const warnIfLocked = () => {
    if (!isLockoutActive.value) {
        return false;
    }

    toast.add({
        severity: 'warn',
        summary: 'Acceso bloqueado',
        detail: lockoutMessage.value,
        life: 3500
    });

    return true;
};

const verificationSubtitle = () => {
    if (twoFactorMethod.value === 'authenticator') {
        return 'Ingresa el código generado por tu aplicación autenticadora.';
    }

    return 'Ingresa el código enviado a tu correo electrónico.';
};

const loginRedirectTarget = computed(() => {
    const redirect = Array.isArray(route.query.redirect)
        ? route.query.redirect[0]
        : route.query.redirect;

    if (!redirect || typeof redirect !== 'string' || !redirect.startsWith('/') || redirect.startsWith('//')) {
        return null;
    }

    if (redirect === '/login' || redirect.startsWith('/login?')) {
        return null;
    }

    return redirect;
});

const resolveLoginRedirect = (fallback = '/dashboard') => loginRedirectTarget.value || fallback || '/dashboard';

const handleLoginResponse = (data) => {
    if (data.redirect) {
        window.location.href = resolveLoginRedirect(data.redirect);
        return;
    }

    if (data.must_change_password) {
        step.value = 3;
        temporaryPassword.value = password.value;
        toast.add({
            severity: 'warn',
            summary: 'Cambio requerido',
            detail: 'Actualiza tu contrasena temporal para continuar.',
            life: 4000
        });
        return;
    }

    if (data.require_2fa) {
        twoFactorMethod.value = data.two_factor_method || 'email';
        step.value = 2;
        resetOtp();

        toast.add({
            severity: 'success',
            summary: twoFactorMethod.value === 'authenticator' ? 'Verificacion requerida' : 'Código enviado',
            detail: twoFactorMethod.value === 'authenticator'
                ? 'Abre Google Authenticator en tu teléfono para continuar.'
                : 'Revisa tu correo electrónico para continuar.',
            life: 4000
        });

        nextTick(() => {
            focusOtpInput(0);
        });
    }
};

const handleLogin = async () => {
    if (warnIfLocked()) {
        return;
    }

    twoFactorMethod.value = 'email';
    loading.value = true;

    try {
        const response = await axios.post('/login-auth', {
            email: email.value,
            password: password.value
        });

        if (response.data.redirect || response.data.must_change_password || response.data.require_2fa) {
            handleLoginResponse(response.data);
        }
    } catch (error) {
        handleErrors(error);
    } finally {
        loading.value = false;
    }
};

const openForgotPassword = () => {
    forgotPasswordEmail.value = email.value;
    showForgotPasswordModal.value = true;
};

const closeForgotPassword = () => {
    if (!forgotPasswordLoading.value) {
        showForgotPasswordModal.value = false;
    }
};

const sendResetLink = async () => {
    if (!forgotPasswordEmail.value) {
        toast.add({
            severity: 'warn',
            summary: 'Correo requerido',
            detail: 'Ingresa el correo asociado a tu cuenta.',
            life: 3500
        });
        return;
    }

    forgotPasswordLoading.value = true;

    try {
        const response = await axios.post('/forgot-password', {
            email: forgotPasswordEmail.value
        });

        showForgotPasswordModal.value = false;
        toast.add({
            severity: 'success',
            summary: 'Solicitud recibida',
            detail: response.data.message,
            life: 6000
        });
    } catch (error) {
        if (error.response?.status === 429) {
            toast.add({
                severity: 'warn',
                summary: 'Solicitud reciente',
                detail: error.response.data.message,
                life: 5000
            });
        } else {
            handleErrors(error);
        }
    } finally {
        forgotPasswordLoading.value = false;
    }
};

const resendCode = async () => {
    if (warnIfLocked()) {
        return;
    }

    if (twoFactorMethod.value !== 'email') {
        toast.add({
            severity: 'warn',
            summary: 'No disponible',
            detail: 'Esta cuenta no usa verificación por correo.',
            life: 4000
        });
        return;
    }

    if (!email.value || !password.value) {
        toast.add({
            severity: 'warn',
            summary: 'Información incompleta',
            detail: 'Debes iniciar sesión primero para reenviar el código.',
            life: 4000
        });
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post('/login-auth', {
            email: email.value,
            password: password.value
        });

        if (response.data.require_2fa && response.data.two_factor_method === 'email') {
            handleLoginResponse(response.data);
            toast.add({
                severity: 'success',
                summary: 'Código reenviado',
                detail: 'Revisa tu correo electrónico nuevamente para obtener el nuevo código.',
                life: 4000
            });
        }
    } catch (error) {
        handleErrors(error);
    } finally {
        loading.value = false;
    }
};

const handleTemporaryPasswordChange = async () => {
    if (warnIfLocked()) {
        return;
    }

    if (!temporaryPassword.value || !newPassword.value || !newPasswordConfirmation.value) {
        toast.add({
            severity: 'warn',
            summary: 'Campos incompletos',
            detail: 'Completa la contrasena temporal y la nueva contrasena.',
            life: 3500
        });
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post('/login-change-password', {
            email: email.value,
            current_password: temporaryPassword.value,
            password: newPassword.value,
            password_confirmation: newPasswordConfirmation.value
        });

        toast.add({
            severity: 'success',
            summary: 'Contraseña actualizada',
            detail: 'Continua con la verificación de acceso.',
            life: 3000
        });

        password.value = newPassword.value;
        newPassword.value = '';
        newPasswordConfirmation.value = '';
        handleLoginResponse(response.data);
    } catch (error) {
        handleErrors(error);
    } finally {
        loading.value = false;
    }
};

const handleVerifyCode = async () => {
    if (warnIfLocked()) {
        return;
    }

    const code = digits.value.join('');

    if (code.length < 6) {
        toast.add({
            severity: 'warn',
            summary: 'Incompleto',
            detail: 'Ingresa los 6 dígitos del código.',
            life: 3000
        });
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post('/login-verify', {
            email: email.value,
            code
        });

        toast.add({
            severity: 'success',
            summary: 'Acceso correcto',
            detail: 'Ingresando al sistema...',
            life: 2000
        });

        setTimeout(() => {
            window.location.href = resolveLoginRedirect(response.data.redirect);
        }, 1000);
    } catch (error) {
        if (error.response && error.response.status === 429) {
            handleErrors(error);
        } else if (error.response && error.response.status === 401) {
            toast.add({
                severity: 'error',
                summary: 'Código inválido',
                detail: twoFactorMethod.value === 'authenticator'
                    ? 'Inserta el código desde Google Authenticator; el código de correo no funcionará.'
                    : 'El código es incorrecto o expiró.',
                life: 4000
            });
            resetOtp();
            focusOtpInput(0);
        } else {
            handleErrors(error);
        }
    } finally {
        loading.value = false;
    }
};

const handleInput = (event, index) => {
    const value = event.target.value.replace(/\D/g, '').slice(0, 1);
    digits.value[index] = value;

    if (value && index < 5) {
        focusOtpInput(index + 1);
    }

    if (index === 5 && value) {
        handleVerifyCode();
    }
};

const handleKeyDown = (event, index) => {
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        focusOtpInput(index - 1);
    }
};

const handlePaste = (event) => {
    const pasteData = event.clipboardData.getData('text');

    if (/^\d{6}$/.test(pasteData)) {
        digits.value = pasteData.split('');
        nextTick(() => {
            handleVerifyCode();
        });
        event.preventDefault();
    }
};

const handleErrors = (error) => {
    if (error.response) {
        if (error.response.status === 422) {
            const errors = error.response.data.errors;
            if (!errors) {
                toast.add({
                    severity: 'warn',
                    summary: 'Atencion',
                    detail: error.response.data.message || 'Revise la información ingresada.',
                    life: 4000
                });
                return;
            }
            Object.keys(errors).forEach((key) => {
                toast.add({
                    severity: 'warn',
                    summary: 'Atencion',
                    detail: errors[key][0],
                    life: 4000
                });
            });
            return;
        }

        if (error.response.status === 401) {
            toast.add({
                severity: 'error',
                summary: 'Error de acceso',
                detail: error.response.data.message,
                life: 4000
            });
            return;
        }

        if (error.response.status === 429) {
            startLockout(error.response.data.retry_after);
            toast.add({
                severity: 'warn',
                summary: 'Acceso bloqueado',
                detail: error.response.data.message || lockoutMessage.value,
                life: 6000
            });
            return;
        }

        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response.data.message || 'Ocurrio un error inesperado.',
            life: 4000
        });
        return;
    }

    toast.add({
        severity: 'error',
        summary: 'Error',
        detail: 'No fue posible completar la solicitud.',
        life: 4000
    });
};

const goBack = () => {
    step.value = 1;
    twoFactorMethod.value = 'email';
    resetOtp();
    newPassword.value = '';
    newPasswordConfirmation.value = '';
};

onBeforeUnmount(() => {
    if (lockoutTimer) {
        clearInterval(lockoutTimer);
    }
});
</script>

<template>
    <div class="login-shell font-custom">
        <div class="login-shell__orb login-shell__orb--top"></div>
        <div class="login-shell__orb login-shell__orb--bottom"></div>

        <header class="login-header">
            <div class="login-header__content">
                <div class="login-brand">
                    <div class="login-brand__icon">
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

                    <div class="login-brand__text">
                        <span class="login-brand__title">Sistema de Gestión Documental</span>
                        <span class="login-brand__subtitle">Universidad de El Salvador</span>
                    </div>
                </div>

                <button type="button" class="login-help-button" @click="showHelpModal = true">Ayuda</button>
            </div>
        </header>

        <main class="login-main">
            <section class="login-hero">
                <div class="login-pill">UGDA - Unidad de Gestión Documental y Archivos</div>

                <h1 class="login-hero__title">
                    Centro de Gestión de
                    <br />
                    Transferencias Documentales
                </h1>

                <p class="login-hero__description">
                    Gestiona y resguarda la documentación institucional, garantizando acceso oportuno,
                    control archivístico y preservación de la memoria histórica de la Facultad de
                    Ingeniería y Arquitectura.
                </p>

                <div class="login-hero__image-card">
                    <img src="/images/gestion-documental.jpg" alt="Gestión documental" class="login-hero__image"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                    <div class="login-hero__image-fallback">Imagen no encontrada</div>
                </div>

                <div class="login-feature-grid">
                    <article class="login-feature-card">
                        <div class="login-feature-card__icon login-feature-card__icon--blue">
                            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                        </div>
                        <h3 class="login-feature-card__title">Reducción de costos y tiempo</h3>
                        <p class="login-feature-card__description">
                            Localiza, comparte y almacena documentos de forma ágil y eficiente.
                        </p>
                    </article>

                    <article class="login-feature-card">
                        <div class="login-feature-card__icon login-feature-card__icon--orange">
                            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3" />
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.08a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.08a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.08a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                            </svg>
                        </div>
                        <h3 class="login-feature-card__title">Mejora en los procesos</h3>
                        <p class="login-feature-card__description">
                            Facilita el acceso a información y estandariza procedimientos institucionales.
                        </p>
                    </article>

                    <article class="login-feature-card">
                        <div class="login-feature-card__icon login-feature-card__icon--green">
                            <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                <path d="m9 12 2 2 4-4" />
                            </svg>
                        </div>
                        <h3 class="login-feature-card__title">Reducción del riesgo profesional</h3>
                        <p class="login-feature-card__description">
                            Garantiza control archivístico y estandarización de procesos.
                        </p>
                    </article>
                </div>
            </section>

            <section class="login-auth">
                <div class="login-auth__card">
                    <div v-if="step === 1" class="login-step">
                        <div class="login-step__brand">
                            <img src="/images/Logo-UES-Blue.png" alt="Universidad de El Salvador"
                                class="login-step__logo" onerror="this.style.display='none'" />
                            <h2 class="login-step__title">Iniciar Sesión</h2>
                            <p class="login-step__subtitle">Ingresa tus credenciales para acceder</p>
                        </div>

                        <div class="login-field">
                            <label class="login-field__label">Correo Electrónico</label>
                            <div class="login-field__shell">
                                <i class="pi pi-envelope login-field__icon"></i>
                                <input v-model="email" type="email" autocomplete="email"
                                    placeholder="usuario@ues.edu.sv" class="login-field__input"
                                    @keyup.enter="handleLogin" />
                            </div>
                        </div>

                        <div class="login-field">
                            <label class="login-field__label">Contraseña</label>
                            <div class="login-field__shell">
                                <i class="pi pi-lock login-field__icon"></i>
                                <input v-model="password"
                                    :type="showPassword && password.length > 0 ? 'text' : 'password'"
                                    autocomplete="current-password"
                                    placeholder="*******" class="login-field__input login-password-input"
                                    @keyup.enter="handleLogin" />
                                <button v-if="password.length > 0" type="button" class="login-password-toggle"
                                    :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                    @mousedown.prevent="togglePasswordVisibility">
                                    <i :class="showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <Button label="Iniciar Sesión" icon="pi pi-sign-in"
                            class="login-primary-button w-full justify-content-center"
                            :loading="loading" :disabled="isLockoutActive" @click="handleLogin" />

                        <p v-if="isLockoutActive" class="login-lockout-message">
                            {{ lockoutMessage }}
                        </p>

                        <button type="button" class="login-forgot-link" @click="openForgotPassword">
                            ¿Olvidaste tu contraseña?
                        </button>

                        <div class="login-step__footer">
                            © 2025 Universidad de El Salvador<br />
                            Facultad de Ingeniería y Arquitectura<br />
                            Todos los derechos reservados
                        </div>
                    </div>

                    <div v-else-if="step === 2" class="login-step">
                        <div class="login-step__brand">
                            <img src="/images/Logo-UES-Blue.png" alt="Universidad de El Salvador"
                                class="login-step__logo" onerror="this.style.display='none'" />
                            <h2 class="login-step__title">Verificación de acceso</h2>
                        <p class="login-step__subtitle">
                            {{ verificationSubtitle() }}
                        </p>
                        <p class="login-step__subtitle">
                            Método de verificación: <strong>{{ twoFactorMethod === 'authenticator' ? 'Google Authenticator' : 'Correo electrónico' }}</strong>
                        </p>
                        <p class="login-step__subtitle" v-if="twoFactorMethod === 'email'">
                            Se envió un código a <strong>{{ maskedEmail }}</strong>. Usa ese código aquí.
                        </p>
                        <p class="login-step__subtitle" v-else>
                            Abre Google Authenticator en tu teléfono y copia el código de 6 dígitos.
                        </p>
                        </div>

                        <div class="login-code-grid">
                            <input v-for="(_, index) in 6" :key="index" :ref="(el) => inputs[index] = el"
                                v-model="digits[index]" maxlength="1" inputmode="numeric" class="login-code-input"
                                @input="handleInput($event, index)" @keydown="handleKeyDown($event, index)"
                                @paste="handlePaste" />
                        </div>

                        <Button class="login-primary-button w-full justify-content-center"
                            :loading="loading" :disabled="isLockoutActive" @click="handleVerifyCode">
                            <span class="login-primary-button__content">
                                <svg class="login-primary-button__icon-svg" xmlns="http://www.w3.org/2000/svg"
                                    width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"
                                    aria-hidden="true">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                                <span>Verificar acceso</span>
                            </span>
                        </Button>

                        <Button label="Reenviar código" class="login-secondary-button w-full justify-content-center"
                            severity="secondary" outlined :disabled="isLockoutActive" @click="resendCode" v-if="twoFactorMethod === 'email'" />

                        <p v-if="isLockoutActive" class="login-lockout-message">
                            {{ lockoutMessage }}
                        </p>

                        <Button label="Volver" class="login-secondary-button w-full justify-content-center"
                            severity="secondary" outlined @click="goBack" />

                        <div class="login-step__footer">
                            © 2025 Universidad de El Salvador<br />
                            Facultad de Ingeniería y Arquitectura<br />
                            Todos los derechos reservados
                        </div>
                    </div>

                    <div v-else class="login-step">
                        <div class="login-step__brand">
                            <img src="/images/Logo-UES-Blue.png" alt="Universidad de El Salvador"
                                class="login-step__logo" onerror="this.style.display='none'" />
                            <h2 class="login-step__title">Cambiar contrasena temporal</h2>
                            <p class="login-step__subtitle">
                                Antes de continuar, crea una contrasena propia para
                                <br />
                                <strong>{{ email }}</strong>
                            </p>
                        </div>

                        <div class="login-field">
                            <label class="login-field__label">Contraseña temporal</label>
                            <div class="login-field__shell">
                                <i class="pi pi-key login-field__icon"></i>
                                <input v-model="temporaryPassword"
                                    :type="showTemporaryPassword && temporaryPassword.length > 0 ? 'text' : 'password'"
                                    autocomplete="current-password" class="login-field__input login-password-input"
                                    placeholder="Contraseña recibida" @keyup.enter="handleTemporaryPasswordChange" />
                                <button v-if="temporaryPassword.length > 0" type="button" class="login-password-toggle"
                                    :aria-label="showTemporaryPassword ? 'Ocultar contrasena' : 'Mostrar contrasena'"
                                    @mousedown.prevent="showTemporaryPassword = !showTemporaryPassword">
                                    <i :class="showTemporaryPassword ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <div class="login-field">
                            <label class="login-field__label">Nueva contrasena</label>
                            <div class="login-field__shell">
                                <i class="pi pi-lock login-field__icon"></i>
                                <input v-model="newPassword"
                                    :type="showNewPassword && newPassword.length > 0 ? 'text' : 'password'"
                                    autocomplete="new-password" class="login-field__input login-password-input"
                                    placeholder="Minimo 10 caracteres" @keyup.enter="handleTemporaryPasswordChange" />
                                <button v-if="newPassword.length > 0" type="button" class="login-password-toggle"
                                    :aria-label="showNewPassword ? 'Ocultar contrasena' : 'Mostrar contrasena'"
                                    @mousedown.prevent="showNewPassword = !showNewPassword">
                                    <i :class="showNewPassword ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <div class="login-field">
                            <label class="login-field__label">Confirmar nueva contrasena</label>
                            <div class="login-field__shell">
                                <i class="pi pi-lock login-field__icon"></i>
                                <input v-model="newPasswordConfirmation"
                                    :type="showNewPasswordConfirmation && newPasswordConfirmation.length > 0 ? 'text' : 'password'"
                                    autocomplete="new-password" class="login-field__input login-password-input"
                                    placeholder="Repite la nueva contrasena" @keyup.enter="handleTemporaryPasswordChange" />
                                <button v-if="newPasswordConfirmation.length > 0" type="button" class="login-password-toggle"
                                    :aria-label="showNewPasswordConfirmation ? 'Ocultar contrasena' : 'Mostrar contrasena'"
                                    @mousedown.prevent="showNewPasswordConfirmation = !showNewPasswordConfirmation">
                                    <i :class="showNewPasswordConfirmation ? 'pi pi-eye-slash' : 'pi pi-eye'"></i>
                                </button>
                            </div>
                        </div>

                        <p class="login-password-policy">
                            Usa al menos 10 caracteres con mayúscula, minúscula, número y símbolo. No uses secuencias de números o letras consecutivos.
                        </p>

                        <Button label="Actualizar y continuar" icon="pi pi-check"
                            class="login-primary-button w-full justify-content-center"
                            :loading="loading" :disabled="isLockoutActive" @click="handleTemporaryPasswordChange" />

                        <p v-if="isLockoutActive" class="login-lockout-message">
                            {{ lockoutMessage }}
                        </p>

                        <Button label="Volver" class="login-secondary-button w-full justify-content-center"
                            severity="secondary" outlined @click="goBack" />
                            
                        <div class="login-step__footer">
                            © 2025 Universidad de El Salvador<br />
                            Facultad de Ingeniería y Arquitectura<br />
                            Todos los derechos reservados
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showForgotPasswordModal" class="login-modal-overlay" @click.self="closeForgotPassword">
            <div class="login-modal login-modal--forgot-password">
                <header class="login-modal__header">
                    <h3 class="login-modal__title">Restablecer contraseña</h3>
                    <button type="button" class="login-modal__close" @click="closeForgotPassword" aria-label="Cerrar ventana">
                        <i class="pi pi-times"></i>
                    </button>
                </header>

                <form class="login-modal__body login-modal__form" @submit.prevent="sendResetLink">
                    <p class="login-modal__text">Ingresa tu correo electrónico y te enviaremos un enlace para crear una nueva contraseña.</p>
                    <div class="login-modal__field">
                        <label for="forgot-password-email" class="login-field__label">Correo electrónico</label>
                        <input id="forgot-password-email" v-model.trim="forgotPasswordEmail" type="email"
                            autocomplete="email" placeholder="usuario@ues.edu.sv" class="login-modal__input" />
                    </div>
                    <div class="login-modal__actions">
                        <Button type="submit" label="Enviar enlace" icon="pi pi-envelope"
                            class="login-primary-button" :loading="forgotPasswordLoading" />
                        <Button type="button" label="Cancelar" severity="secondary" outlined
                            class="login-secondary-button" :disabled="forgotPasswordLoading" @click="closeForgotPassword" />
                    </div>
                </form>
            </div>
        </div>

        <div v-if="showHelpModal" class="login-modal-overlay" @click.self="showHelpModal = false">
            <div class="login-modal">
                <header class="login-modal__header">
                    <h3 class="login-modal__title">Centro de Ayuda</h3>
                    <button type="button" class="login-modal__close" @click="showHelpModal = false" aria-label="Cerrar modal">
                        <i class="pi pi-times"></i>
                    </button>
                </header>
                
                <div class="login-modal__body">
                    <div class="login-modal__section">
                        <h4 class="login-modal__subtitle">¿Necesitas ayuda con los procesos?</h4>
                        <p class="login-modal__text">Para mayor información o asistencia con los procedimientos institucionales, contáctanos al correo:</p>
                        <a href="mailto:procesos-ugda@fia.ues.edu.sv" class="login-modal__link">
                            <i class="pi pi-envelope"></i> procesos-ugda@fia.ues.edu.sv
                        </a>
                    </div>
                    
                    <div class="login-modal__section">
                        <h4 class="login-modal__subtitle">¿Problemas de acceso?</h4>
                        <p class="login-modal__text">Si necesitas recuperar tus credenciales o reportar problemas técnicos para ingresar, comunícate a:</p>
                        <a href="mailto:admin-ugda@fia.ues.edu.sv" class="login-modal__link login-modal__link--secondary">
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
.font-custom * {
    font-family: var(--ugda-font-family) !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

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
    -webkit-font-smoothing: antialiased;
}

.login-shell {
    position: relative;
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: linear-gradient(180deg, #f5f9ff 0%, #edf5ff 100%);
}

.login-shell__orb {
    position: fixed;
    border-radius: 999px;
    pointer-events: none;
    opacity: 0.8;
    z-index: 0;
}

.login-shell__orb--top {
    top: 76px;
    right: -140px;
    width: 560px;
    height: 560px;
    background: radial-gradient(circle, rgba(204, 226, 255, 0.9) 0%, rgba(204, 226, 255, 0.25) 60%, rgba(204, 226, 255, 0) 100%);
}

.login-shell__orb--bottom {
    left: -220px;
    bottom: -220px;
    width: 620px;
    height: 620px;
    background: radial-gradient(circle, rgba(214, 229, 251, 0.7) 0%, rgba(214, 229, 251, 0.2) 60%, rgba(214, 229, 251, 0) 100%);
}

.login-header {
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    background: #296cba;
    box-shadow: 0 2px 16px rgba(17, 59, 115, 0.18);
}

.login-header__content {
    max-width: 1320px;
    margin: 0 auto;
    min-height: 60px;
    padding: 0 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.login-brand__icon {
    width: 36px;
    height: 36px;
    border-radius: 999px;
    background: #ffffff;
    color: #296cba;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 12px rgba(18, 54, 104, 0.18);
}

.login-brand__text {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.login-brand__title {
    color: #ffffff;
    font-size: var(--ugda-font-secondary);
    font-weight: 800;
    line-height: 1.2;
}

.login-brand__subtitle {
    color: rgba(255, 255, 255, 0.95);
    font-size: var(--ugda-font-helper);
    font-weight: 500;
    line-height: 1.2;
}

.login-help-button {
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    font-size: var(--ugda-font-secondary);
    font-weight: 700;
    padding: 8px 16px;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease;
}

.login-help-button:hover {
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.32);
}

.login-main {
    position: relative;
    z-index: 1;
    flex: 1;
    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
    padding: 0 24px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 416px;
    gap: 64px;
    align-content: center;
}

.login-hero {
    max-width: 700px;
}

.login-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 6px 16px;
    border-radius: 999px;
    background: #276cbc;
    color: #ffffff;
    font-size: var(--ugda-font-secondary);
    font-weight: 600;
    box-shadow: 0 8px 16px rgba(39, 108, 188, 0.22);
}

.login-hero__title {
    margin: clamp(10px, 2vh, 18px) 0 clamp(6px, 1vh, 10px);
    color: #2f6fae;
    font-size: var(--ugda-font-h1);
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: 0;
}

.login-hero__description {
    max-width: 610px;
    margin: 0 0 clamp(10px, 2vh, 18px);
    color: #516f95;
    font-size: var(--ugda-font-body);
    font-weight: 400;
    line-height: 1.5;
}

.login-hero__image-card {
    width: 100%;
    max-width: 610px;
    height: clamp(140px, 24vh, 260px);
    margin-bottom: clamp(10px, 2vh, 18px);
    border-radius: 16px;
    overflow: hidden;
    background: #ffffff;
    box-shadow: 0 18px 40px rgba(61, 96, 145, 0.18);
}

.login-hero__image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.login-hero__image-fallback {
    display: none;
    min-height: 100%;
    align-items: center;
    justify-content: center;
    background: #dfe8f6;
    color: #52719a;
    font-weight: 700;
}

.login-feature-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    max-width: 610px;
}

.login-feature-card {
    min-height: auto;
    padding: clamp(10px, 1.5vh, 12px) 14px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid rgba(170, 197, 232, 0.52);
    box-shadow: 0 14px 30px rgba(66, 93, 137, 0.12);
}

.login-feature-card__icon {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: clamp(4px, 1vh, 8px);
}

.login-feature-card__icon--blue {
    background: #eaf3ff;
    color: #2a6db8;
}

.login-feature-card__icon--orange {
    background: #fff4e7;
    color: #e39a2d;
}

.login-feature-card__icon--green {
    background: #edf9ef;
    color: #5aa86c;
}

.login-feature-card__title {
    margin: 0 0 6px;
    color: #2e6fad;
    font-size: var(--ugda-font-body);
    font-weight: 800;
    line-height: 1.45;
}

.login-feature-card__description {
    margin: 0;
    color: #607a9c;
    font-size: var(--ugda-font-helper);
    font-weight: 500;
    line-height: 1.55;
}

.login-auth {
    display: flex;
    justify-content: flex-end;
}

.login-auth__card {
    width: 100%;
    max-width: 416px;
    padding: clamp(24px, 4vh, 32px) 32px clamp(20px, 3vh, 28px);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.97);
    border: 1px solid rgba(204, 220, 239, 0.65);
    box-shadow: 0 20px 40px rgba(48, 74, 111, 0.16);
}

.login-step {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.login-step__brand {
    text-align: center;
    margin-bottom: clamp(16px, 3vh, 24px); 
}

.login-step__logo {
    width: clamp(60px, 8vh, 76px);
    height: auto;
    object-fit: contain;
    display: block;
    margin: 0 auto clamp(8px, 1.5vh, 12px);
}

.login-step__title {
    margin: 0 0 6px;
    color: #2d6fb0;
    font-size: var(--ugda-font-h2);
    font-weight: 800;
    line-height: 1.2;
}

.login-step__subtitle {
    margin: 0;
    color: #647fa0;
    font-size: var(--ugda-font-secondary);
    font-weight: 500;
    line-height: 1.5;
}

.login-field {
    margin-bottom: clamp(16px, 2vh, 20px); 
}

.login-field__label {
    display: block;
    margin-bottom: clamp(6px, 1vh, 8px); 
    color: #2e6fad;
    font-size: var(--ugda-font-body);
    font-weight: 700;
}

.login-field__shell {
    display: flex;
    align-items: center;
    min-height: 48px;
    border: 1px solid #cfd8e6;
    border-radius: 12px;
    background: #ffffff;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.login-field__shell:focus-within {
    border-color: #2c6fbf;
    box-shadow: 0 0 0 3px rgba(44, 111, 191, 0.12);
}

.login-field__icon,
.login-field__trail {
    color: #7a8fa9;
    font-size: 18px;
}

.login-field__icon {
    margin-left: 16px;
    margin-right: 12px;
}

.login-field__trail {
    margin-right: 16px;
    margin-left: 12px;
}

.login-field__input {
    width: 100%;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    color: #506b8c;
    font-size: var(--ugda-font-body);
    font-weight: 500;
}

.login-field__input::placeholder {
    color: #9aaabc;
}

.login-password-input {
    padding-right: 8px;
}

.login-password-input::-ms-reveal {
    display: none;
}

.login-password-input::-ms-clear {
    display: none;
}

.login-password-toggle {
    margin-left: 10px;
    margin-right: 22px;
    padding: 0;
    border: none;
    background: transparent;
    color: #202b37;
    font-size: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: color 0.2s ease;
}

.login-password-toggle:hover {
    color: #2c6fbf;
}

.login-primary-button {
    min-height: 48px;
    margin-top: 8px;
    border: none !important;
    border-radius: 12px !important;
    background: linear-gradient(180deg, #3879b6 0%, #2f6fad 100%) !important;
    box-shadow: 0 10px 20px rgba(35, 94, 150, 0.25) !important;
    color: #ffffff !important;
    font-size: var(--ugda-font-body) !important;
    font-weight: 800 !important;
}

.login-primary-button:hover {
    background: linear-gradient(180deg, #2f6fad 0%, #285f98 100%) !important;
}

.login-primary-button:disabled,
.login-secondary-button:disabled {
    cursor: not-allowed;
}

.login-primary-button__content {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.login-primary-button__icon-svg {
    flex-shrink: 0;
}

:deep(.login-primary-button .p-button-icon) {
    font-size: 1rem;
}

:deep(.login-primary-button .p-button-icon.pi) {
    font-family: 'primeicons' !important;
    font-style: normal;
    font-weight: normal;
    font-variant: normal;
    text-transform: none;
    line-height: 1;
}

:deep(.login-primary-button .p-button-icon.pi-sign-in)::before {
    font-family: 'primeicons' !important;
    content: "\e91b";
}

.login-secondary-button {
    min-height: var(--ugda-control-height-lg);
    margin-top: var(--ugda-space-sm);
    background: #ffffff !important;
    border: 1px solid var(--ugda-border-strong) !important;
    border-radius: var(--ugda-radius-md) !important;
    border-color: var(--ugda-border-strong) !important;
    color: var(--ugda-text-secondary) !important;
    font-size: var(--ugda-font-body) !important;
    font-weight: var(--ugda-weight-medium) !important;
}

.login-secondary-button:hover:not(:disabled) {
    background: #f8fafc !important;
    border-color: #9db4d1 !important;
    color: #2f6fad !important;
}

.login-secondary-button:focus,
.login-secondary-button:focus-visible {
    background: #ffffff !important;
    border-color: #2c6fbf !important;
    box-shadow: 0 0 0 3px rgba(44, 111, 191, 0.12) !important;
    color: #2f6fad !important;
}

.login-lockout-message {
    margin: 12px 0 0;
    color: #b42318;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.45;
    text-align: center;
}

.login-forgot-link {
    align-self: center;
    margin: 16px 0 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: #2f6fad;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
}

.login-forgot-link:hover {
    color: #1e4f88;
    text-decoration: underline;
}

.login-password-policy {
    margin: -10px 0 18px;
    color: #57759a;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
}

.login-code-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 16px;
}

.login-code-input {
    width: 100%;
    height: 56px;
    border: 1px solid #cfd8e6;
    border-radius: 12px;
    background: #ffffff;
    color: #2d6fb0;
    font-size: 24px;
    font-weight: 800;
    text-align: center;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.login-code-input:focus {
    border-color: #2c6fbf;
    box-shadow: 0 0 0 3px rgba(44, 111, 191, 0.12);
}

.login-step__footer {
    margin-top: auto; 
    padding-top: clamp(24px, 4vh, 32px); 
    color: #6b819f;
    font-size: 11.5px; 
    font-weight: 500;
    line-height: 1.6;
    text-align: center;
}

/* ------------------------------------- */
/* ESTILOS DEL MODAL DE AYUDA            */
/* ------------------------------------- */
.login-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(14, 32, 59, 0.45);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 24px;
    animation: loginFadeIn 0.2s ease-out forwards;
}

.login-modal {
    width: 100%;
    max-width: 460px;
    background: #ffffff;
    border-radius: 18px;
    box-shadow: 0 24px 50px rgba(18, 43, 80, 0.2);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: loginSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.login-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid rgba(204, 220, 239, 0.5);
    background: rgba(245, 249, 255, 0.5);
}

.login-modal__title {
    margin: 0;
    color: #296cba;
    font-size: 18px;
    font-weight: 800;
}

.login-modal__close {
    background: transparent;
    border: none;
    color: #7a8fa9;
    font-size: 16px;
    cursor: pointer;
    padding: 6px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s, background 0.2s;
}

.login-modal__close:hover {
    color: #296cba;
    background: #eaf3ff;
}

.login-modal__body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.login-modal__form {
    gap: 16px;
}

.login-modal__field {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.login-modal__input {
    width: 100%;
    min-height: 44px;
    padding: 0 14px;
    border: 1px solid #cfd8e6;
    border-radius: 10px;
    color: #334e70;
    outline: none;
    font: inherit;
}

.login-modal__input:focus {
    border-color: #2c6fbf;
    box-shadow: 0 0 0 3px rgba(44, 111, 191, 0.12);
}

.login-modal__actions {
    display: flex;
    flex-direction: column;
}

.login-modal__section {
    background: #f8fafc;
    padding: 20px;
    border-radius: 14px;
    border: 1px solid rgba(204, 220, 239, 0.4);
}

.login-modal__subtitle {
    margin: 0 0 8px;
    color: #2f6fae;
    font-size: 15px;
    font-weight: 700;
}

.login-modal__text {
    margin: 0 0 14px;
    color: #607a9c;
    font-size: 14px;
    line-height: 1.55;
    font-weight: 500;
}

.login-modal__link {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #296cba;
    font-size: 14.5px;
    font-weight: 700;
    text-decoration: none;
    background: #eaf3ff;
    padding: 10px 16px;
    border-radius: 10px;
    transition: background 0.2s, color 0.2s, transform 0.1s;
}

.login-modal__link:hover {
    background: #296cba;
    color: #ffffff;
    transform: translateY(-1px);
}

.login-modal__link--secondary {
    background: #fff4e7;
    color: #e39a2d;
}

.login-modal__link--secondary:hover {
    background: #e39a2d;
    color: #ffffff;
}

.login-modal__footer {
    padding: 16px 24px 24px;
    background: #ffffff;
}

@keyframes loginFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes loginSlideUp {
    from { 
        transform: translateY(20px) scale(0.98); 
        opacity: 0; 
    }
    to { 
        transform: translateY(0) scale(1); 
        opacity: 1; 
    }
}

@media (max-width: 1200px) {
    .login-main {
        gap: 40px;
        grid-template-columns: minmax(0, 1fr) 392px;
    }

    .login-hero__title {
        font-size: 32px;
    }
}

@media (max-width: 991px) {
    .login-shell {
        overflow-y: auto;
    }

    .login-main {
        padding-top: 38px;
        padding-bottom: 38px;
        grid-template-columns: 1fr;
        gap: 28px;
        justify-items: center;
        align-content: start;
    }

    .login-auth {
        justify-content: center;
        padding-top: 0;
    }

    .login-hero {
        max-width: 610px;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
    }

    .login-feature-grid {
        max-width: 610px;
        width: 100%;
    }
}

@media (max-width: 767px) {
    .login-header__content {
        min-height: 74px;
        padding: 0 16px;
    }

    .login-brand__title {
        font-size: 13px;
    }

    .login-brand__subtitle {
        font-size: 10px;
    }

    .login-help-button {
        padding: 8px 14px;
        font-size: 13px;
    }

    .login-main {
        padding: 24px 16px 28px;
    }

    .login-hero__title {
        margin-top: 24px;
        font-size: 28px;
    }

    .login-hero__description {
        font-size: 15px;
        line-height: 1.65;
    }

    .login-feature-grid {
        grid-template-columns: 1fr;
    }

    .login-auth__card {
        max-width: 100%;
        padding: 28px 20px 24px;
    }

    .login-code-grid {
        gap: 8px;
    }

    .login-code-input {
        height: 50px;
        font-size: 21px;
    }
    
    .login-modal__link {
        font-size: 13px;
        padding: 10px 12px;
    }
}
</style>
