<script setup>
import { ref, reactive, nextTick } from 'vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const toast = useToast();

// Estados
const step = ref(1); // 1: Credenciales, 2: Código 2FA
const loading = ref(false);

// Datos
const email = ref('');
const password = ref('');

// --- CAMBIO PARA 6 CAJITAS ---
const digits = ref(['', '', '', '', '', '']); // Array para los 6 dígitos
const inputs = ref([]); // Referencia a los elementos input del DOM

// --- PASO 1: Enviar Credenciales ---
const handleLogin = async () => {
    loading.value = true;
    try {
        const response = await axios.post('/login-auth', {
            email: email.value,
            password: password.value
        });

        if (response.data.require_2fa) {
            step.value = 2;
            toast.add({ severity: 'success', summary: 'Código Enviado', detail: 'Revisa tu correo electrónico.', life: 4000 });

            // Enfocar la primera cajita automáticamente al cambiar de pantalla
            nextTick(() => {
                if (inputs.value[0]) inputs.value[0].$el.focus();
            });
        }

    } catch (error) {
        handleErrors(error);
    } finally {
        loading.value = false;
    }
};

// --- PASO 2: Verificar Código ---
const handleVerifyCode = async () => {
    // Unimos los 6 dígitos en un solo string
    const code = digits.value.join('');

    if (code.length < 6) {
        toast.add({ severity: 'warn', summary: 'Incompleto', detail: 'Ingresa los 6 dígitos del código.', life: 3000 });
        return;
    }

    loading.value = true;
    try {
        const response = await axios.post('/login-verify', {
            email: email.value,
            code: code // Enviamos el código unido
        });

        toast.add({ severity: 'success', summary: 'Acceso Correcto', detail: 'Ingresando al sistema...', life: 2000 });

        setTimeout(() => {
            window.location.href = response.data.redirect;
        }, 1000);

    } catch (error) {
        if (error.response && error.response.status === 401) {
            toast.add({ severity: 'error', summary: 'Código Inválido', detail: 'El código es incorrecto o expiró.', life: 4000 });
            // Limpiar código si falla
            digits.value = ['', '', '', '', '', ''];
            inputs.value[0].$el.focus();
        } else {
            handleErrors(error);
        }
    } finally {
        loading.value = false;
    }
};

// --- LÓGICA DE AUTO-FOCUS (AVANZAR Y RETROCEDER) ---
const handleInput = (event, index) => {
    const value = event.target.value;

    // Si escribió un número y no es la última casilla, avanza
    if (value && index < 5) {
        inputs.value[index + 1].$el.focus();
    }
    // Si ya completó los 6, intentamos enviar automáticamente (opcional)
    if (index === 5 && value) {
        handleVerifyCode();
    }
};

const handleKeyDown = (event, index) => {
    // Si presiona Backspace y la casilla está vacía, retrocede
    if (event.key === 'Backspace' && !digits.value[index] && index > 0) {
        inputs.value[index - 1].$el.focus();
    }
};

const handlePaste = (event) => {
    const pasteData = event.clipboardData.getData('text');
    // Si pegan un código de 6 números (ej: 123456)
    if (/^\d{6}$/.test(pasteData)) {
        digits.value = pasteData.split(''); // Llenar array
        nextTick(() => {
            handleVerifyCode(); // Enviar directo
        });
        event.preventDefault(); // Evitar comportamiento default
    }
};

// Manejo genérico de errores
const handleErrors = (error) => {
    if (error.response) {
        if (error.response.status === 422) {
            const errors = error.response.data.errors;
            Object.keys(errors).forEach(key => {
                toast.add({ severity: 'warn', summary: 'Atención', detail: errors[key][0], life: 4000 });
            });
        } else if (error.response.status === 401) {
            toast.add({ severity: 'error', summary: 'Error de Acceso', detail: error.response.data.message, life: 4000 });
        } else {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Ocurrió un error inesperado.', life: 4000 });
        }
    }
};

const goBack = () => {
    step.value = 1;
    digits.value = ['', '', '', '', '', ''];
};
</script>

<template>
    <Toast position="top-right" />

    <div class="min-h-screen md:h-screen w-screen flex flex-column font-custom overflow-y-auto md:overflow-hidden relative"
        style="background-color: #f0f6ff;">

        <div class="fixed top-0 right-0 border-circle opacity-60 z-0"
            style="width: 40vw; height: 40vw; background: #dbeafe; transform: translate(20%, -30%); pointer-events: none;">
        </div>
        <div class="fixed bottom-0 left-0 border-circle opacity-60 z-0"
            style="width: 35vw; height: 35vw; background: #dbeafe; transform: translate(-20%, 30%); pointer-events: none;">
        </div>

        <div class="bg-blue-800 text-white px-4 py-3 flex align-items-center justify-content-between shadow-2 relative z-5 flex-shrink-0"
            style="height: 70px;">
            <div class="flex align-items-center gap-2">
                <div
                    class="bg-white text-blue-800 border-circle w-2rem h-2rem flex align-items-center justify-content-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 21h18" />
                        <path d="M5 21V7l8-4 8 4v14" />
                        <path d="M8 9v2" />
                        <path d="M8 13v2" />
                        <path d="M8 17v2" />
                        <path d="M16 9v2" />
                        <path d="M16 13v2" />
                        <path d="M16 17v2" />
                    </svg>
                </div>
                <div class="line-height-1">
                    <div class="text-sm font-bold">Sistema de Gestión Documental</div>
                    <div class="text-xs opacity-90">Universidad de El Salvador</div>
                </div>
            </div>
            <Button label="Ayuda"
                class="p-button-text text-white p-button-sm hover:surface-white hover:text-blue-800 font-bold" />
        </div>

        <div
            class="flex flex-column md:flex-row flex-grow-1 relative z-1 align-items-center justify-content-center w-full">
            <div class="grid w-full max-w-7xl mx-auto flex flex-column md:flex-row align-items-center m-0 h-full">

                <div class="col-12 md:col-7 flex flex-column justify-content-center p-4">
                    <div class="flex flex-column w-full mx-auto" style="max-width: 40rem;">
                        <div class="mb-3">
                            <div
                                class="inline-block bg-blue-700 text-white border-round-3xl px-3 py-1 font-bold mb-3 text-sm shadow-2">
                                UGDA - Unidad de Gestión Documental y Archivos</div>
                            <h1 class="text-blue-900 text-4xl md:text-5xl font-bold mb-2 mt-0 line-height-2">Centro de
                                Gestión de <br> Transferencias Documentales</h1>
                            <p class="text-700 text-base line-height-3 m-0 text-justify font-medium">Gestiona y
                                resguarda la documentación institucional, garantizando acceso oportuno, control
                                archivístico y preservación de la memoria histórica de la Facultad de Ingeniería y
                                Arquitectura.</p>
                        </div>
                        <div class="mb-4 shadow-4 border-round-2xl overflow-hidden bg-white w-full h-14rem md:h-18rem">
                            <img src="/images/gestion-documental.jpg" alt="Portada UGDA" class="block w-full h-full"
                                style="object-fit: cover; object-position: center;"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                            <div
                                class="w-full h-full bg-gray-200 flex align-items-center justify-content-center text-gray-600 font-bold hidden">
                                Imagen no encontrada</div>
                        </div>
                        <div class="grid w-full m-0 align-items-stretch">
                            <div class="col-12 md:col-4 pl-0 pr-1 pb-2 md:pb-0">
                                <div
                                    class="bg-white p-2 shadow-2 border-round-xl h-full flex flex-column align-items-start">
                                    <div
                                        class="bg-blue-50 text-blue-600 border-circle w-2rem h-2rem flex align-items-center justify-content-center mb-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10" />
                                            <polyline points="12 6 12 12 16 14" />
                                        </svg></div>
                                    <div class="font-bold text-blue-900 mb-1 text-sm">Ahorro de Tiempo</div>
                                    <p class="text-600 text-xs m-0 line-height-3">Localiza, comparte y almacena
                                        documentos de forma ágil y eficiente.</p>
                                </div>
                            </div>
                            <div class="col-12 md:col-4 px-1 pb-2 md:pb-0">
                                <div
                                    class="bg-white p-2 shadow-2 border-round-xl h-full flex flex-column align-items-start">
                                    <div
                                        class="bg-orange-50 text-orange-600 border-circle w-2rem h-2rem flex align-items-center justify-content-center mb-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z" />
                                            <path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z" />
                                            <path d="M12 2v2" />
                                            <path d="M12 22v-2" />
                                            <path d="m17 20.66-1-1.73" />
                                            <path d="M11 10.27 7 3.34" />
                                        </svg></div>
                                    <div class="font-bold text-blue-900 mb-1 text-sm">Eficiencia</div>
                                    <p class="text-600 text-xs m-0 line-height-3">Facilita el acceso a información y
                                        estandariza procedimientos institucionales.</p>
                                </div>
                            </div>
                            <div class="col-12 md:col-4 px-1 pb-2 md:pb-0">
                                <div
                                    class="bg-white p-2 shadow-2 border-round-xl h-full flex flex-column align-items-start">
                                    <div
                                        class="bg-green-50 text-green-600 border-circle w-2rem h-2rem flex align-items-center justify-content-center mb-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                        </svg></div>
                                    <div class="font-bold text-blue-900 mb-1 text-sm">Seguridad</div>
                                    <p class="text-600 text-xs m-0 line-height-3">Control y resguardo total,
                                        garantizando el control archivístico y estandarización de procesos.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 md:col-5 flex flex-column justify-content-center align-items-center p-4">
                    <div class="bg-white shadow-6 border-round-2xl p-5 w-full max-w-26rem relative overflow-hidden">

                        <div v-if="step === 1" class="fadein animation-duration-500">
                            <div class="text-center mb-4">
                                <div class="mb-3 flex justify-content-center">
                                    <img src="/images/Logo-UES-Blue.png" alt="Icono Login" class="w-6rem h-6rem block"
                                        onerror="this.style.display='none'" />
                                </div>
                                <h2 class="text-blue-800 font-bold mb-2 text-2xl">Iniciar Sesión</h2>
                                <p class="text-500 text-sm m-0">Ingresa tus credenciales para acceder</p>
                            </div>

                            <div class="mb-3">
                                <label class="block text-900 font-bold mb-2 text-sm">Correo Electrónico</label>
                                <InputText v-model="email" placeholder="usuario@ues.edu.sv"
                                    class="w-full bg-gray-50 border-gray-300 text-900" />
                            </div>

                            <div class="mb-4">
                                <label class="block text-900 font-bold mb-2 text-sm">Contraseña</label>
                                <InputText v-model="password" type="password" placeholder="••••••••"
                                    class="w-full bg-gray-50 border-gray-300 text-900" @keyup.enter="handleLogin" />
                            </div>

                            <Button label="Continuar"
                                class="w-full border-round-md bg-blue-700 border-blue-700 hover:bg-blue-800 font-bold shadow-3 mb-4 py-3"
                                :loading="loading" @click="handleLogin"></Button>

                            <div class="text-center">
                                <a href="#" class="text-blue-600 text-sm no-underline hover:underline font-bold">Olvidé
                                    mi contraseña</a>
                            </div>
                        </div>

                        <div v-if="step === 2" class="fadein animation-duration-500">
                            <div class="text-center mb-4">
                                <div class="mb-3 flex justify-content-center">
                                    <div
                                        class="w-6rem h-6rem bg-blue-50 border-circle flex align-items-center justify-content-center text-blue-600">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                            <path d="m9 12 2 2 4-4" />
                                        </svg>
                                    </div>
                                </div>
                                <h2 class="text-blue-800 font-bold mb-2 text-2xl">Verificación</h2>
                                <p class="text-500 text-sm m-0">Ingresa el código enviado a:</p>
                                <p class="text-blue-700 font-bold text-sm mt-1">{{ email }}</p>
                            </div>

                            <div class="flex gap-2 justify-content-center mb-5">
                                <InputText v-for="(digit, index) in 6" :key="index" :ref="el => inputs[index] = el"
                                    v-model="digits[index]" maxlength="1"
                                    class="w-3rem h-3rem text-center text-2xl font-bold p-0 border-round-md border-gray-300 focus:border-blue-600 focus:text-blue-700"
                                    @input="handleInput($event, index)" @keydown="handleKeyDown($event, index)"
                                    @paste="handlePaste" />
                            </div>

                            <Button label="Verificar Acceso"
                                class="w-full border-round-md bg-green-600 border-green-600 hover:bg-green-700 font-bold shadow-3 mb-3 py-3"
                                :loading="loading" @click="handleVerifyCode"></Button>

                            <div class="text-center">
                                <Button label="Volver / Cambiar correo"
                                    class="p-button-text p-button-sm text-gray-500 text-xs" @click="goBack" />
                            </div>
                        </div>
                        <div class="mt-4 text-center text-xs text-500 line-height-3 mb-4 md:mb-0">
                            © 2025 Universidad de El Salvador<br>Facultad de Ingeniería y Arquitectura
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
/* SOLO ESTILOS LOCALES */
.font-custom,
.font-custom * {
    font-family: 'Instrument Sans', sans-serif !important;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.p-inputtext {
    border-radius: 6px !important;
}

.grid {
    margin-right: -0.5rem;
    margin-left: -0.5rem;
    margin-top: -0.5rem;
    margin-bottom: -0.5rem;
}

/* Estilo específico para las cajitas de otp */
.p-inputtext.text-center {
    padding: 0 !important;
}
</style>