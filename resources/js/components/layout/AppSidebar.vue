<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';

const route = useRoute();

const isProfileMenuOpen = ref(false);
const isExpanded = ref(true); // Estado del sidebar (abierto/cerrado)

// Menú de navegación
const menuItems = ref([
    { label: 'Inicio', icon: 'pi pi-home', to: '/dashboard' },
    { label: 'Todas las Solicitudes', icon: 'pi pi-file', to: '/solicitudes' },
    { label: 'Archivo General', icon: 'pi pi-box', to: '/archivo' },
    { label: 'Configuración', icon: 'pi pi-cog', to: '/configuracion' },
]);

const isActive = (path) => route.path === path;

// Expandir / Contraer
const toggleSidebar = () => {
    isExpanded.value = !isExpanded.value;
    if (!isExpanded.value) isProfileMenuOpen.value = false;
};

// Abrir / Cerrar menú de perfil
const toggleProfileMenu = () => {
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
};

// Logout
const handleLogout = async () => {
    try {
        await axios.get('/logout');
        window.location.href = '/login';
    } catch (error) {
        console.error("Error al salir", error);
    }
};
</script>

<template>
    <aside
        class="sticky top-0 h-screen flex flex-column transition-all transition-duration-300 z-5 font-custom shadow-4 overflow-hidden flex-shrink-0"
        :style="{ width: isExpanded ? '280px' : '80px', backgroundColor: '#1560bd', color: 'white' }">

        <div class="h-6rem flex align-items-center px-3 mt-2"
            :class="isExpanded ? 'justify-content-between' : 'justify-content-center'">

            <div v-if="isExpanded" class="flex align-items-center gap-3 fadein animation-duration-300">
                <div class="w-3rem h-3rem flex align-items-center justify-content-center">
                    <img src="/images/Logo-UES-White.png" alt="Logo" class="w-full h-full object-contain" />
                </div>
                <div class="flex flex-column white-space-nowrap overflow-hidden">
                    <span class="font-bold text-2xl tracking-wide">UGDA</span>
                    <span class="text-xs opacity-80 font-medium tracking-wide">FIA - UES</span>
                </div>
            </div>

            <i class="pi pi-bars text-xl cursor-pointer opacity-70 hover:opacity-100 transition-duration-200 p-2 border-round-md hover:bg-white-alpha-10"
                @click="toggleSidebar" :title="isExpanded ? 'Contraer menú' : 'Expandir menú'"></i>
        </div>

        <div class="w-full h-1px bg-white-alpha-10 mb-3"></div>

        <div class="flex-grow-1 overflow-y-auto px-2 py-2 text-center">
            <ul class="list-none p-0 m-0 flex flex-column gap-2">
                <li v-for="item in menuItems" :key="item.label">
                    <router-link :to="item.to"
                        class="flex align-items-center cursor-pointer p-3 border-round-lg transition-duration-200 no-underline overflow-hidden"
                        :class="[
                            isActive(item.to)
                                ? 'bg-white-alpha-20 text-white font-bold shadow-1'
                                : 'text-white hover:bg-white-alpha-10 opacity-90 hover:opacity-100',
                            isExpanded ? 'justify-content-start' : 'justify-content-center'
                        ]" :title="!isExpanded ? item.label : ''">

                        <i :class="[item.icon, 'text-xl flex-shrink-0', isExpanded ? 'mr-3' : '']"></i>
                        <span v-if="isExpanded" class="text-sm fadein animation-duration-300 white-space-nowrap">{{
                            item.label }}</span>
                    </router-link>
                </li>
            </ul>
        </div>

        <div class="p-3 mt-auto relative">

            <div v-if="isProfileMenuOpen && isExpanded"
                class="absolute left-0 px-3 pb-2 z-5 fadein animation-duration-200" style="bottom: 100%; width: 280px;">

                <div class="bg-white border-round-xl shadow-6 overflow-hidden text-gray-800">
                    <div @click="handleLogout"
                        class="flex align-items-center gap-3 p-3 cursor-pointer hover:surface-100 transition-duration-150 text-blue-600">
                        <i class="pi pi-sign-out text-lg"></i>
                        <span class="font-medium text-sm">Cerrar Sesión</span>
                    </div>
                    <div class="h-1px bg-gray-200 w-full"></div>
                    <div
                        class="flex align-items-center gap-3 p-3 cursor-pointer hover:surface-100 transition-duration-150">
                        <div
                            class="w-2rem h-2rem bg-blue-50 border-round-md flex align-items-center justify-content-center text-blue-600">
                            <i class="pi pi-user text-lg"></i>
                        </div>
                        <div class="flex flex-column">
                            <span class="text-sm font-bold text-gray-800">Carlos Rodríguez</span>
                            <span class="text-xs text-gray-500">Ver mi perfil</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex align-items-center border-round-xl border-1 cursor-pointer transition-duration-200 select-none overflow-hidden"
                :class="[
                    isProfileMenuOpen ? 'bg-white-alpha-20 border-white-alpha-30' : 'border-white-alpha-10 hover:bg-white-alpha-10',
                    isExpanded ? 'justify-content-between p-3' : 'justify-content-center p-2 h-4rem w-4rem mx-auto'
                ]" style="background-color: rgba(255, 255, 255, 0.05);"
                @click="isExpanded ? toggleProfileMenu() : toggleSidebar()">

                <div class="flex align-items-center gap-3">
                    <div
                        class="w-2rem h-2rem border-round-md bg-white-alpha-10 flex align-items-center justify-content-center flex-shrink-0">
                        <i class="pi pi-user text-white text-lg"></i>
                    </div>

                    <div v-if="isExpanded" class="flex flex-column fadein animation-duration-300 overflow-hidden">
                        <span class="text-sm font-bold text-white white-space-nowrap">Carlos Rodríguez</span>
                        <span class="text-xs text-white-alpha-70 white-space-nowrap">Decanato</span>
                    </div>
                </div>

                <i v-if="isExpanded"
                    class="pi pi-chevron-up text-xs text-white-alpha-70 transition-transform transition-duration-300"
                    :style="{ transform: isProfileMenuOpen ? 'rotate(180deg)' : 'rotate(0deg)' }">
                </i>
            </div>

            <div v-if="isExpanded"
                class="mt-4 mb-2 text-center text-white-alpha-50 text-xs line-height-3 fadein animation-duration-300 white-space-nowrap overflow-hidden">
                Facultad de Ingeniería y Arquitectura<br>
                Universidad de El Salvador
            </div>
        </div>
    </aside>
</template>

<style scoped>
.brightness-0 {
    filter: brightness(0);
}

.invert {
    filter: invert(1);
}

.tracking-wide {
    letter-spacing: 0.5px;
}

/* Scrollbar fino y transparente */
.overflow-y-auto::-webkit-scrollbar {
    width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
    background: transparent;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
    background-color: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
}
</style>