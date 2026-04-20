<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import axios from 'axios';

const MOBILE_BREAKPOINT = 991;

const route = useRoute();
const isExpanded = ref(true);
const isMobile = ref(false);
const isMobileMenuOpen = ref(false);
const isProfileMenuOpen = ref(false);
const expandedMenus = ref({});

const userData = ref({
    fullName: '',
    unitName: '',
    role: ''
});

const menuItems = ref([
    { label: 'Inicio', icon: 'pi pi-home', to: '/dashboard' },
    { label: 'Todas las Solicitudes', icon: 'pi pi-file', to: '/solicitudes' },
    { label: 'Archivo General', icon: 'pi pi-box', to: '/archivo' },
    {
        label: 'Configuracion',
        icon: 'pi pi-cog',
        children: [
            { label: 'Unidades', to: '/unidades' },
            { label: 'Usuarios', to: '/usuarios' },
            { label: 'Sistema', to: '/sistema' }
        ]
    }
]);

const sidebarIsExpanded = computed(() => isMobile.value ? true : isExpanded.value);
const sidebarVisible = computed(() => !isMobile.value || isMobileMenuOpen.value);
const sidebarShellWidth = computed(() => {
    if (isMobile.value) {
        return '0px';
    }

    return isExpanded.value ? '280px' : '80px';
});
const profileMenuWidth = computed(() => isMobile.value ? 'calc(100vw - 2rem)' : '280px');
const sidebarStyle = computed(() => ({
    width: isMobile.value ? '100vw' : sidebarShellWidth.value,
    backgroundColor: '#1560bd',
    color: 'white',
    position: isMobile.value ? 'fixed' : 'sticky',
    top: '0',
    left: '0',
    height: '100vh',
    zIndex: isMobile.value ? '1100' : '5'
}));

const isActive = (path) => route.path === path;

const isChildActive = (children) => {
    if (!children) return false;
    return children.some(child => child.to === route.path);
};

const syncMenuWithRoute = () => {
    menuItems.value.forEach(item => {
        if (item.children && isChildActive(item.children)) {
            expandedMenus.value[item.label] = true;
        }
    });
};

const updateViewportState = () => {
    const mobileNow = window.innerWidth <= MOBILE_BREAKPOINT;

    if (mobileNow === isMobile.value) {
        return;
    }

    isMobile.value = mobileNow;
    isProfileMenuOpen.value = false;

    if (mobileNow) {
        isMobileMenuOpen.value = false;
    }
};

const updateBodyScrollLock = () => {
    document.body.style.overflow = (isMobile.value && isMobileMenuOpen.value) ? 'hidden' : '';
};

const handleExternalSidebarToggle = () => {
    toggleSidebar();
};

const fetchUserProfile = async () => {
    const cachedUser = localStorage.getItem('ugda_user_profile');
    if (cachedUser) {
        userData.value = JSON.parse(cachedUser);
    } else {
        userData.value.fullName = 'Cargando...';
    }

    try {
        const response = await axios.get('/api/user');
        const user = response.data;
        const unitNames = Array.isArray(user.units) && user.units.length
            ? user.units.map(unit => unit.name).join(', ')
            : (user.unit ? user.unit.name : 'Sin Unidad Asignada');

        const freshData = {
            fullName: `${user.first_name} ${user.first_last_name}`,
            unitName: unitNames,
            role: user.role
        };

        userData.value = freshData;
        localStorage.setItem('ugda_user_profile', JSON.stringify(freshData));
    } catch (error) {
        console.error('Error al cargar perfil:', error);
        if (!userData.value.fullName) userData.value.fullName = 'Usuario';
    }
};

const closeMobileMenu = () => {
    if (!isMobile.value) {
        return;
    }

    isMobileMenuOpen.value = false;
    isProfileMenuOpen.value = false;
};

const handleNavigationSelection = () => {
    closeMobileMenu();
};

const toggleSubmenu = (label) => {
    if (!sidebarIsExpanded.value) {
        isExpanded.value = true;
        setTimeout(() => {
            expandedMenus.value[label] = true;
        }, 50);
        return;
    }

    expandedMenus.value[label] = !expandedMenus.value[label];
};

const toggleSidebar = () => {
    if (isMobile.value) {
        isMobileMenuOpen.value = !isMobileMenuOpen.value;
        if (!isMobileMenuOpen.value) {
            isProfileMenuOpen.value = false;
        }
        return;
    }

    isExpanded.value = !isExpanded.value;
};

const toggleProfileMenu = () => {
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
};

const goToProfile = () => {
    console.log('Navegando a Mi Perfil...');
    isProfileMenuOpen.value = false;
    closeMobileMenu();
};

const handleLogout = async () => {
    try {
        localStorage.removeItem('ugda_user_profile');
        await axios.get('/logout');
        closeMobileMenu();
        window.location.href = '/login';
    } catch (error) {
        console.error(error);
    }
};

watch(() => route.path, () => {
    syncMenuWithRoute();
    closeMobileMenu();
});

watch(isExpanded, (newValue) => {
    if (newValue) {
        syncMenuWithRoute();
    } else {
        isProfileMenuOpen.value = false;
    }
});

watch([isMobile, isMobileMenuOpen], () => {
    updateBodyScrollLock();
});

onMounted(() => {
    updateViewportState();
    fetchUserProfile();
    syncMenuWithRoute();
    window.addEventListener('resize', updateViewportState);
    window.addEventListener('ugda:toggle-sidebar', handleExternalSidebarToggle);
    updateBodyScrollLock();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateViewportState);
    window.removeEventListener('ugda:toggle-sidebar', handleExternalSidebarToggle);
    document.body.style.overflow = '';
});
</script>

<template>
    <div class="app-sidebar-shell flex-shrink-0" :style="{ width: sidebarShellWidth }">
        <aside
            v-if="sidebarVisible"
            class="app-sidebar flex flex-column transition-all transition-duration-300 z-5 font-custom shadow-4 overflow-hidden"
            :style="sidebarStyle"
        >
            <div
                class="h-6rem flex align-items-center px-3 mt-2"
                :class="sidebarIsExpanded ? 'justify-content-between' : 'justify-content-center'"
            >
                <div v-if="sidebarIsExpanded" class="flex align-items-center gap-3 fadein animation-duration-300">
                    <div class="w-3rem h-3rem flex align-items-center justify-content-center">
                        <img src="/images/Logo-UES-White.png" alt="Logo" class="w-full h-full object-contain" />
                    </div>
                    <div class="flex flex-column white-space-nowrap overflow-hidden">
                        <span class="font-bold text-2xl tracking-wide">UGDA</span>
                        <span class="text-xs opacity-80 font-medium tracking-wide">FIA - UES</span>
                    </div>
                </div>

                <i
                    :class="[
                        isMobile && isMobileMenuOpen ? 'pi pi-times' : 'pi pi-bars',
                        'text-xl cursor-pointer opacity-70 hover:opacity-100 transition-duration-200 p-2 border-round-md hover:bg-white-alpha-10'
                    ]"
                    @click="toggleSidebar"
                ></i>
            </div>

            <div class="w-full h-1px bg-white-alpha-10 mb-3"></div>

            <div class="flex-grow-1 overflow-y-auto overflow-x-hidden px-2 py-2">
                <ul class="list-none p-0 m-0 flex flex-column gap-2">
                    <li v-for="item in menuItems" :key="item.label">
                        <router-link
                            v-if="!item.children"
                            :to="item.to"
                            class="flex align-items-center cursor-pointer p-3 border-round-lg transition-duration-200 no-underline overflow-hidden"
                            :class="[
                                isActive(item.to) ? 'bg-white-alpha-20 text-white font-bold shadow-1' : 'text-white hover:bg-white-alpha-10 opacity-90 hover:opacity-100',
                                sidebarIsExpanded ? 'justify-content-start' : 'justify-content-center'
                            ]"
                            :title="!sidebarIsExpanded ? item.label : ''"
                            @click="handleNavigationSelection"
                        >
                            <i :class="[item.icon, 'text-xl flex-shrink-0', sidebarIsExpanded ? 'mr-3' : '']"></i>
                            <span v-if="sidebarIsExpanded" class="text-sm fadein white-space-nowrap">{{ item.label }}</span>
                        </router-link>

                        <div v-else>
                            <div
                                class="flex align-items-center cursor-pointer p-3 border-round-lg transition-duration-200 overflow-hidden select-none"
                                :class="[
                                    isChildActive(item.children) ? 'bg-white-alpha-10 text-white font-medium' : 'text-white hover:bg-white-alpha-10 opacity-90',
                                    sidebarIsExpanded ? 'justify-content-between' : 'justify-content-center'
                                ]"
                                :title="!sidebarIsExpanded ? item.label : ''"
                                @click="toggleSubmenu(item.label)"
                            >
                                <div class="flex align-items-center">
                                    <i :class="[item.icon, 'text-xl flex-shrink-0', sidebarIsExpanded ? 'mr-3' : '']"></i>
                                    <span v-if="sidebarIsExpanded" class="text-sm fadein white-space-nowrap">{{ item.label }}</span>
                                </div>

                                <i
                                    v-if="sidebarIsExpanded"
                                    class="pi pi-chevron-down text-xs transition-transform transition-duration-300"
                                    :style="{ transform: expandedMenus[item.label] ? 'rotate(180deg)' : 'rotate(0deg)' }"
                                ></i>
                            </div>

                            <div
                                v-if="sidebarIsExpanded && expandedMenus[item.label]"
                                class="flex flex-column gap-1 mt-1 ml-3 border-left-2 border-white-alpha-20 pl-2 fadein animation-duration-300"
                            >
                                <router-link
                                    v-for="child in item.children"
                                    :key="child.label"
                                    :to="child.to"
                                    class="p-2 border-round-md text-sm text-white-alpha-80 hover:text-white hover:bg-white-alpha-10 no-underline transition-duration-200 flex align-items-center"
                                    :class="{ 'text-white font-bold bg-white-alpha-10': isActive(child.to) }"
                                    @click="handleNavigationSelection"
                                >
                                    <i class="pi pi-circle-fill mr-2" style="font-size: 4px;"></i>
                                    {{ child.label }}
                                </router-link>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="mt-auto relative" :class="sidebarIsExpanded ? 'p-3' : 'p-2'">
                <div
                    v-if="isProfileMenuOpen && sidebarIsExpanded"
                    class="absolute left-0 px-3 pb-2 z-5 fadein animation-duration-200"
                    :style="{ bottom: '100%', width: profileMenuWidth }"
                >
                    <div class="bg-white border-round-xl shadow-6 overflow-hidden text-gray-800 flex flex-column">
                        <div
                            class="flex align-items-center gap-3 p-3 cursor-pointer hover:surface-100 text-gray-700 transition-colors transition-duration-150 border-bottom-1 border-gray-100"
                            @click="goToProfile"
                        >
                            <i class="pi pi-user-edit text-lg"></i>
                            <span class="font-medium text-sm">Mi Perfil</span>
                        </div>
                        <div
                            class="flex align-items-center gap-3 p-3 cursor-pointer hover:surface-100 text-red-600 transition-colors transition-duration-150"
                            @click="handleLogout"
                        >
                            <i class="pi pi-sign-out text-lg"></i>
                            <span class="font-medium text-sm">Cerrar Sesion</span>
                        </div>
                    </div>
                </div>

                <div
                    class="flex align-items-center border-round-xl border-1 cursor-pointer transition-duration-200 select-none overflow-hidden"
                    :class="[
                        isProfileMenuOpen ? 'bg-white-alpha-20 border-white-alpha-30' : 'border-white-alpha-10 hover:bg-white-alpha-10',
                        sidebarIsExpanded ? 'justify-content-between p-3' : 'justify-content-center h-4rem w-full'
                    ]"
                    style="background-color: rgba(255, 255, 255, 0.05);"
                    @click="sidebarIsExpanded ? toggleProfileMenu() : toggleSidebar()"
                >
                    <div class="flex align-items-center gap-3 overflow-hidden">
                        <div class="w-2rem h-2rem border-round-md bg-white-alpha-10 flex align-items-center justify-content-center flex-shrink-0">
                            <i class="pi pi-user text-white text-lg"></i>
                        </div>
                        <div v-if="sidebarIsExpanded" class="flex flex-column fadein white-space-nowrap overflow-hidden">
                            <span class="text-sm font-bold text-white text-overflow-ellipsis overflow-hidden">{{ userData.fullName }}</span>
                            <span class="text-xs text-white-alpha-70 text-overflow-ellipsis overflow-hidden">{{ userData.unitName }}</span>
                        </div>
                    </div>

                    <i
                        v-if="sidebarIsExpanded"
                        class="pi pi-chevron-up text-xs text-white-alpha-70 flex-shrink-0 transition-transform transition-duration-200"
                        :style="{ transform: isProfileMenuOpen ? 'rotate(180deg)' : 'rotate(0deg)' }"
                    ></i>
                </div>
            </div>
        </aside>
    </div>
</template>

<style scoped>
@media (max-width: 991px) {
    .app-sidebar {
        border-radius: 0;
    }
}
</style>
