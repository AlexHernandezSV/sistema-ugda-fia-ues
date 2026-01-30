<script setup>
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';

const route = useRoute();
const isExpanded = ref(true); // Controla si la barra lateral está ancha o delgada
const isProfileMenuOpen = ref(false); // Controla el popup del usuario

// Controla qué menús están desplegados (Ej: 'Configuración': true/false)
const expandedMenus = ref({});

// Función para abrir/cerrar submenús
const toggleSubmenu = (label) => {
    // Si la barra está cerrada (80px), la abrimos automáticamente al intentar ver un submenú
    if (!isExpanded.value) {
        isExpanded.value = true;
    }
    // Invierte el estado: Si estaba abierto se cierra, si cerrado se abre
    expandedMenus.value[label] = !expandedMenus.value[label];
};

// --- DEFINICIÓN DEL MENÚ ---
const menuItems = ref([
    { label: 'Inicio', icon: 'pi pi-home', to: '/dashboard' },
    { label: 'Todas las Solicitudes', icon: 'pi pi-file', to: '/solicitudes' }, // (Ruta futura)
    { label: 'Archivo General', icon: 'pi pi-box', to: '/archivo' }, // (Ruta futura)
    { 
        label: 'Configuración', 
        icon: 'pi pi-cog', 
        // IMPORTANTE: Este ítem no tiene 'to', tiene 'children'
        children: [
            { label: 'Unidades', to: '/unidades' }, // <--- AQUÍ ESTÁ EL ENLACE A TU NUEVA VISTA
            { label: 'Usuarios', to: '/usuarios' }, // (Futuro)
            { label: 'Sistema', to: '/sistema' }    // (Futuro)
        ]
    },
]);

// Verifica si la ruta actual coincide exactamente
const isActive = (path) => route.path === path;

// Verifica si alguno de los hijos está activo (para mantener el padre iluminado)
const isChildActive = (children) => {
    if (!children) return false;
    return children.some(child => child.to === route.path);
};

// Lógica para abrir/cerrar la barra lateral completa
const toggleSidebar = () => {
    isExpanded.value = !isExpanded.value;
    if (!isExpanded.value) {
        isProfileMenuOpen.value = false;
        // Opcional: Cerrar todos los submenús al contraer la barra
        // expandedMenus.value = {}; 
    }
};

const toggleProfileMenu = () => isProfileMenuOpen.value = !isProfileMenuOpen.value;

const handleLogout = async () => {
    try { 
        await axios.get('/logout'); 
        window.location.href = '/login'; 
    } catch (e) { 
        console.error(e); 
    }
};
</script>

<template>
    <aside class="sticky top-0 h-screen flex flex-column transition-all transition-duration-300 z-5 font-custom shadow-4 overflow-hidden flex-shrink-0"
           :style="{ width: isExpanded ? '280px' : '80px', backgroundColor: '#1560bd', color: 'white' }">
        
        <div class="h-6rem flex align-items-center px-3 mt-2" :class="isExpanded ? 'justify-content-between' : 'justify-content-center'">
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
               @click="toggleSidebar"></i>
        </div>

        <div class="w-full h-1px bg-white-alpha-10 mb-3"></div>

        <div class="flex-grow-1 overflow-y-auto px-2 py-2">
            <ul class="list-none p-0 m-0 flex flex-column gap-2">
                <li v-for="item in menuItems" :key="item.label">
                    
                    <router-link v-if="!item.children" :to="item.to" 
                       class="flex align-items-center cursor-pointer p-3 border-round-lg transition-duration-200 no-underline overflow-hidden"
                       :class="[
                           isActive(item.to) ? 'bg-white-alpha-20 text-white font-bold shadow-1' : 'text-white hover:bg-white-alpha-10 opacity-90 hover:opacity-100',
                           isExpanded ? 'justify-content-start' : 'justify-content-center'
                       ]"
                       :title="!isExpanded ? item.label : ''">
                        <i :class="[item.icon, 'text-xl flex-shrink-0', isExpanded ? 'mr-3' : '']"></i>
                        <span v-if="isExpanded" class="text-sm fadein white-space-nowrap">{{ item.label }}</span>
                    </router-link>

                    <div v-else>
                        <div class="flex align-items-center cursor-pointer p-3 border-round-lg transition-duration-200 overflow-hidden select-none"
                             :class="[
                                isChildActive(item.children) ? 'bg-white-alpha-10 text-white font-medium' : 'text-white hover:bg-white-alpha-10 opacity-90',
                                isExpanded ? 'justify-content-between' : 'justify-content-center'
                             ]"
                             @click="toggleSubmenu(item.label)"
                             :title="!isExpanded ? item.label : ''">
                            
                            <div class="flex align-items-center">
                                <i :class="[item.icon, 'text-xl flex-shrink-0', isExpanded ? 'mr-3' : '']"></i>
                                <span v-if="isExpanded" class="text-sm fadein white-space-nowrap">{{ item.label }}</span>
                            </div>
                            
                            <i v-if="isExpanded" class="pi pi-chevron-down text-xs transition-transform transition-duration-300"
                               :style="{ transform: expandedMenus[item.label] ? 'rotate(180deg)' : 'rotate(0deg)' }"></i>
                        </div>

                        <div v-if="isExpanded && expandedMenus[item.label]" 
                             class="flex flex-column gap-1 mt-1 ml-3 border-left-2 border-white-alpha-20 pl-2 fadein animation-duration-300">
                            
                            <router-link v-for="child in item.children" :key="child.label" :to="child.to"
                                class="p-2 border-round-md text-sm text-white-alpha-80 hover:text-white hover:bg-white-alpha-10 no-underline transition-duration-200 flex align-items-center"
                                :class="{ 'text-white font-bold bg-white-alpha-10': isActive(child.to) }">
                                <i class="pi pi-circle-fill mr-2" style="font-size: 4px;"></i>
                                {{ child.label }}
                            </router-link>
                        </div>
                    </div>

                </li>
            </ul>
        </div>

        <div class="mt-auto relative" :class="isExpanded ? 'p-3' : 'p-2'">
            
            <div v-if="isProfileMenuOpen && isExpanded" 
                 class="absolute left-0 px-3 pb-2 z-5 fadein animation-duration-200" 
                 style="bottom: 100%; width: 280px;">
                <div class="bg-white border-round-xl shadow-6 overflow-hidden text-gray-800">
                    <div @click="handleLogout" class="flex align-items-center gap-3 p-3 cursor-pointer hover:surface-100 text-blue-600">
                        <i class="pi pi-sign-out text-lg"></i><span class="font-medium text-sm">Cerrar Sesión</span>
                    </div>
                </div>
            </div>

            <div class="flex align-items-center border-round-xl border-1 cursor-pointer transition-duration-200 select-none overflow-hidden"
                 :class="[ 
                    isProfileMenuOpen ? 'bg-white-alpha-20 border-white-alpha-30' : 'border-white-alpha-10 hover:bg-white-alpha-10',
                    isExpanded ? 'justify-content-between p-3' : 'justify-content-center h-4rem w-full' 
                 ]"
                 style="background-color: rgba(255, 255, 255, 0.05);"
                 @click="isExpanded ? toggleProfileMenu() : toggleSidebar()">
                
                <div class="flex align-items-center gap-3">
                    <div class="w-2rem h-2rem border-round-md bg-white-alpha-10 flex align-items-center justify-content-center flex-shrink-0">
                        <i class="pi pi-user text-white text-lg"></i>
                    </div>
                    <div v-if="isExpanded" class="flex flex-column fadein white-space-nowrap">
                        <span class="text-sm font-bold text-white">Carlos Rodríguez</span>
                        <span class="text-xs text-white-alpha-70">Decanato</span>
                    </div>
                </div>
                 <i v-if="isExpanded" class="pi pi-chevron-up text-xs text-white-alpha-70" :style="{ transform: isProfileMenuOpen ? 'rotate(180deg)' : 'rotate(0deg)' }"></i>
            </div>
        </div>
    </aside>
</template>