<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { clearCachedUser, getCachedUser, getCurrentUser } from '../../services/authSession';
import {
    fetchNotificationSummary,
    getNotificationState,
    markNotificationAsRead,
    refreshDueDateNotifications,
} from '../../services/notifications';

const MOBILE_BREAKPOINT = 991;
const REPORTS_PERMISSION = 'endpoint.ui.reports.read';
const ADMIN_PROFILE = 'administrador';
const UGDA_PROFILE = 'usuario ugda';
const REQUESTER_PROFILE = 'unidad solicitante';
const UNIT_DIRECTOR_PROFILE = 'director/jefe de unidad';

const route = useRoute();
const router = useRouter();
const isExpanded = ref(true);
const isMobile = ref(false);
const isMobileMenuOpen = ref(false);
const isProfileMenuOpen = ref(false);
const isNotificationMenuOpen = ref(false);
const isLoggingOut = ref(false);
const notificationPollingId = ref(null);
const notifications = ref({
    unreadCount: 0,
    latest: [],
});
const notificationView = ref('unread');
const expandedMenus = ref({});

const userData = ref({
    fullName: '',
    unitName: '',
    role: '',
    permissions: []
});

const hasPermission = (permission) => userData.value.permissions.includes(permission);
const normalizedRole = computed(() => String(userData.value.role ?? '').trim().toLowerCase());
const isAdminProfile = computed(() => normalizedRole.value === ADMIN_PROFILE);
const isUgdaProfile = computed(() => normalizedRole.value === UGDA_PROFILE);
const canAccessArchive = computed(() => isAdminProfile.value || isUgdaProfile.value);
const canAccessReports = computed(() => hasPermission(REPORTS_PERMISSION) || normalizedRole.value === UGDA_PROFILE);
const isRequesterProfile = computed(() => normalizedRole.value === REQUESTER_PROFILE);
const isUnitDirectorProfile = computed(() => normalizedRole.value === UNIT_DIRECTOR_PROFILE);
const unreadNotificationLabel = computed(() => {
    if (notifications.value.unreadCount > 99) {
        return '99+';
    }

    return String(notifications.value.unreadCount);
});
const hasUnreadNotifications = computed(() => notifications.value.unreadCount > 0);

const menuItems = computed(() => {
    const items = [
        { label: 'Inicio', icon: 'pi pi-home', to: '/dashboard' },
        {
            label: isRequesterProfile.value ? 'Mis Solicitudes' : 'Todas las Solicitudes',
            icon: 'pi pi-file',
            to: '/solicitudes',
        },
    ];

    if (isRequesterProfile.value && hasPermission('endpoint.api.documentary-series.read')) {
        items.push({
            label: 'Solicitud de Transferencia',
            icon: 'pi pi-file-plus',
            to: '/solicitudes/crear',
        });
        items.push({
            label: 'Solicitud de préstamo',
            icon: 'pi pi-file-export',
            to: '/solicitudes/prestamos/crear',
        });
    }

    if (isUnitDirectorProfile.value) {
        items.push({
            label: 'Pendientes',
            icon: 'pi pi-file-check',
            to: '/solicitudes/prestamos/pendientes',
        });
    }

    if (canAccessArchive.value) {
        items.push({
            label: 'Archivo General',
            icon: 'pi pi-box',
            children: [
                { label: 'Buscar Documentos', icon: 'pi pi-search', to: '/archivo-general' },
                { label: 'Gestión de Archivo', icon: 'pi pi-folder-plus', to: '/archivo-general/gestion' },
            ],
        });
    }

    if (canAccessReports.value) {
        items.push({ label: 'Reportes', icon: 'pi pi-chart-bar', to: '/reportes' });
    }

    const configurationChildren = [
        hasPermission('endpoint.api.units.create') ? { label: 'Unidades', icon: 'pi pi-building', to: '/unidades' } : null,
        hasPermission('endpoint.api.users.create') ? { label: 'Usuarios', icon: 'pi pi-users', to: '/usuarios' } : null,
        hasPermission('endpoint.api.documentary-series.create') ? { label: 'Series Documentales', icon: 'pi pi-clone', to: '/series-documentales' } : null,
        isAdminProfile.value ? { label: 'Ubicaciones Fisicas', icon: 'pi pi-map-marker', to: '/archivo-general/ubicaciones' } : null,
    ].filter(Boolean);

    if (configurationChildren.length > 0) {
        items.push({
            label: 'Configuracion',
            icon: 'pi pi-cog',
            children: configurationChildren,
        });
    }

    return items;
});

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
    backgroundColor: 'var(--ugda-brand)',
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
    const cachedUser = getCachedUser();
    if (cachedUser) {
        const unitNames = Array.isArray(cachedUser.units) && cachedUser.units.length
            ? cachedUser.units.map(unit => unit.name).join(', ')
            : (cachedUser.unit ? cachedUser.unit.name : 'Sin Unidad Asignada');

        userData.value = {
            fullName: `${cachedUser.first_name ?? ''} ${cachedUser.first_last_name ?? ''}`.trim() || 'Usuario',
            unitName: unitNames,
            role: cachedUser.role,
            permissions: Array.isArray(cachedUser.permissions) ? cachedUser.permissions : [],
        };
    } else {
        userData.value.fullName = 'Cargando...';
    }

    try {
        const user = await getCurrentUser({ force: true });
        const unitNames = Array.isArray(user.units) && user.units.length
            ? user.units.map(unit => unit.name).join(', ')
            : (user.unit ? user.unit.name : 'Sin Unidad Asignada');

        const freshData = {
            fullName: `${user.first_name ?? ''} ${user.first_last_name ?? ''}`.trim() || 'Usuario',
            unitName: unitNames,
            role: user.role,
            permissions: Array.isArray(user.permissions) ? user.permissions : []
        };

        userData.value = freshData;
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
    isNotificationMenuOpen.value = false;
};

const handleNavigationSelection = () => {
    isNotificationMenuOpen.value = false;
    isProfileMenuOpen.value = false;
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
            isNotificationMenuOpen.value = false;
        }
        return;
    }

    isExpanded.value = !isExpanded.value;
    isNotificationMenuOpen.value = false;
};

const toggleProfileMenu = () => {
    isNotificationMenuOpen.value = false;
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
};

const syncNotificationState = (payload) => {
    notifications.value = {
        unreadCount: payload?.unreadCount ?? payload?.unread_count ?? 0,
        latest: payload?.latest ?? [],
    };
};

const refreshNotifications = async ({ syncDueDates = false } = {}) => {
    try {
        const data = syncDueDates
            ? await refreshDueDateNotifications(notificationView.value)
            : await fetchNotificationSummary(notificationView.value);
        syncNotificationState(data);
    } catch (error) {
        console.error('Error al cargar notificaciones:', error);
    }
};

const toggleNotificationView = () => {
    notificationView.value = notificationView.value === 'unread' ? 'read' : 'unread';
    refreshNotifications({ syncDueDates: true });
};

const handleNotificationsUpdated = (event) => {
    syncNotificationState(event.detail);
};

const toggleNotificationMenu = () => {
    if (!sidebarIsExpanded.value) {
        isExpanded.value = true;
        setTimeout(() => {
            isNotificationMenuOpen.value = true;
            isProfileMenuOpen.value = false;
        }, 50);
        return;
    }

    isNotificationMenuOpen.value = !isNotificationMenuOpen.value;
    isProfileMenuOpen.value = false;
};

const openNotificationsPage = () => {
    isNotificationMenuOpen.value = false;
    closeMobileMenu();
    router.push('/notificaciones');
};

const openNotification = async (notification) => {
    try {
        if (!notification.is_read) {
            await markNotificationAsRead(notification.id, notificationView.value);
        }

        isNotificationMenuOpen.value = false;
        closeMobileMenu();

        if (notification.action_url) {
            const target = String(notification.action_url);

            // External/full URLs: navigate the browser
            if (/^https?:\/\//.test(target)) {
                window.location.href = target;
                return;
            }

            // Internal routes: if already on the same route, force a full reload
            const current = router.currentRoute.value.fullPath || router.currentRoute.value.path || window.location.pathname;
            if (current === target) {
                window.location.reload();
                return;
            }

            router.push(target);
        } else {
            router.push('/notificaciones');
        }
    } catch (error) {
        console.error('Error al abrir notificacion:', error);
    }
};

const refreshNotificationsOnVisibility = () => {
    if (document.visibilityState === 'visible') {
        refreshNotifications({ syncDueDates: true });
    }
};

const goToProfile = () => {
    isProfileMenuOpen.value = false;
    closeMobileMenu();
    router.push('/mi-perfil');
};

const handleLogout = async () => {
    isLoggingOut.value = true;
    clearCachedUser();

    try {
        await axios.get('/logout');
        closeMobileMenu();
        window.location.replace('/login');
    } catch (error) {
        console.error(error);
        isLoggingOut.value = false;
    }
};

watch(() => route.path, () => {
    syncMenuWithRoute();
    closeMobileMenu();
    refreshNotifications({ syncDueDates: true });
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
    syncNotificationState(getNotificationState());
    refreshNotifications({ syncDueDates: true });
    notificationPollingId.value = window.setInterval(
        () => refreshNotifications({ syncDueDates: true }),
        20000,
    );
    window.addEventListener('resize', updateViewportState);
    window.addEventListener('focus', refreshNotificationsOnVisibility);
    document.addEventListener('visibilitychange', refreshNotificationsOnVisibility);
    window.addEventListener('ugda:toggle-sidebar', handleExternalSidebarToggle);
    window.addEventListener('ugda:notifications-updated', handleNotificationsUpdated);
    updateBodyScrollLock();
});

onBeforeUnmount(() => {
    if (notificationPollingId.value) {
        window.clearInterval(notificationPollingId.value);
    }

    window.removeEventListener('resize', updateViewportState);
    window.removeEventListener('focus', refreshNotificationsOnVisibility);
    document.removeEventListener('visibilitychange', refreshNotificationsOnVisibility);
    window.removeEventListener('ugda:toggle-sidebar', handleExternalSidebarToggle);
    window.removeEventListener('ugda:notifications-updated', handleNotificationsUpdated);
    document.body.style.overflow = '';
});
</script>

<template>
    <div v-if="isLoggingOut" class="logout-overlay" role="status" aria-live="polite">
        <i class="pi pi-spin pi-spinner text-3xl"></i>
        <span>Cerrando sesión...</span>
    </div>

    <div v-else class="app-sidebar-shell flex-shrink-0" :style="{ width: sidebarShellWidth }">
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
                                    <i :class="[child.icon || 'pi pi-angle-right', 'mr-2 flex-shrink-0']"></i>
                                    {{ child.label }}
                                </router-link>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <div class="mt-auto relative" :class="sidebarIsExpanded ? 'p-3' : 'p-2'">
                <div
                    v-if="isNotificationMenuOpen && sidebarIsExpanded"
                    class="sidebar-notifications-panel fadein animation-duration-200"
                >
                    <div class="sidebar-notifications-panel__header">
                        <div>
                            <strong>Notificaciones</strong>
                            <span>{{ notificationView === 'unread' ? `${notifications.unreadCount} no leídas` : 'Últimas leídas' }}</span>
                        </div>
                        <button
                            type="button"
                            class="sidebar-notifications-panel__mark"
                            @click="toggleNotificationView"
                        >
                            {{ notificationView === 'unread' ? 'Ver leídas' : 'Ver no leídas' }}
                        </button>
                    </div>

                    <div v-if="notifications.latest.length === 0" class="sidebar-notifications-panel__empty">
                        Sin notificaciones recientes
                    </div>

                    <div v-else class="sidebar-notifications-panel__list">
                        <button
                            v-for="notification in notifications.latest"
                            :key="notification.id"
                            type="button"
                            class="sidebar-notification-item"
                            :class="{ 'sidebar-notification-item--unread': !notification.is_read }"
                            @click="openNotification(notification)"
                        >
                            <span class="sidebar-notification-item__dot"></span>
                            <span class="sidebar-notification-item__body">
                                <strong>{{ notification.title }}</strong>
                                <small>{{ notification.short_message }}</small>
                                <em>{{ notification.created_at_label }}</em>
                            </span>
                        </button>
                    </div>

                    <button type="button" class="sidebar-notifications-panel__all" @click="openNotificationsPage">
                        Ver todas
                    </button>
                </div>

                <button
                    type="button"
                    class="sidebar-notifications-trigger"
                    :class="[
                        isNotificationMenuOpen ? 'bg-white-alpha-20 border-white-alpha-30' : 'border-white-alpha-10 hover:bg-white-alpha-10',
                        sidebarIsExpanded ? 'justify-content-between p-3 mb-2' : 'justify-content-center h-4rem w-full mb-2'
                    ]"
                    :title="!sidebarIsExpanded ? 'Notificaciones' : ''"
                    @click="toggleNotificationMenu"
                >
                    <span class="sidebar-notifications-trigger__content flex align-items-center gap-3">
                        <span class="sidebar-notifications-trigger__icon">
                            <i class="pi pi-bell text-white text-lg"></i>
                            <span v-if="hasUnreadNotifications" class="sidebar-notifications-trigger__badge">{{ unreadNotificationLabel }}</span>
                        </span>
                        <span v-if="sidebarIsExpanded" class="flex flex-column fadein white-space-nowrap overflow-hidden">
                            <span class="text-sm font-bold text-white">Notificaciones</span>
                            <span class="text-xs text-white-alpha-70">
                                {{ hasUnreadNotifications ? `${notifications.unreadCount} pendientes` : 'Sin pendientes' }}
                            </span>
                        </span>
                    </span>

                    <i
                        v-if="sidebarIsExpanded"
                        class="pi pi-chevron-up text-xs text-white-alpha-70 flex-shrink-0 transition-transform transition-duration-200"
                        :style="{ transform: isNotificationMenuOpen ? 'rotate(180deg)' : 'rotate(0deg)' }"
                    ></i>
                </button>

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
                            <span class="font-medium text-sm">Cerrar sesión</span>
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
                            <span class="text-xs text-white-alpha-70 text-overflow-ellipsis overflow-hidden">{{ userData.unitName || userData.role }}</span>
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
.logout-overlay {
    align-items: center;
    background: var(--ugda-surface-page, #f8fafc);
    color: var(--ugda-brand);
    display: flex;
    flex-direction: column;
    font-size: 1rem;
    font-weight: 600;
    gap: 0.75rem;
    inset: 0;
    justify-content: center;
    position: fixed;
    z-index: 2000;
}

.sidebar-notifications-trigger {
    align-items: center;
    background-color: rgba(255, 255, 255, 0.05);
    border: 1px solid;
    border-radius: var(--ugda-radius-md);
    box-sizing: border-box;
    color: var(--ugda-text-inverse);
    cursor: pointer;
    display: flex;
    font: inherit;
    min-height: 64px;
    text-align: left;
    transition: background-color 0.2s ease, border-color 0.2s ease;
    width: 100%;
}

.sidebar-notifications-trigger__content {
    min-width: 0;
    overflow: visible;
}

.sidebar-notifications-trigger__icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.1);
    border-radius: var(--ugda-radius-md);
    display: inline-flex;
    flex-shrink: 0;
    height: 2rem;
    justify-content: center;
    position: relative;
    width: 2rem;
}

.sidebar-notifications-trigger__badge {
    align-items: center;
    background: var(--ugda-error);
    border: 2px solid var(--ugda-text-inverse);
    border-radius: 999px;
    color: var(--ugda-text-inverse);
    display: inline-flex;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
    height: 1.2rem;
    justify-content: center;
    line-height: 1;
    min-width: 1.2rem;
    padding: 0 0.25rem;
    position: absolute;
    right: -0.35rem;
    top: -0.35rem;
}

.sidebar-notifications-panel {
    background: var(--ugda-surface-card);
    border-radius: var(--ugda-radius-lg);
    box-shadow: var(--ugda-shadow-dialog);
    color: var(--ugda-text-primary);
    margin-bottom: var(--ugda-space-sm);
    overflow: hidden;
}

.sidebar-notifications-panel__header {
    align-items: center;
    border-bottom: 1px solid var(--ugda-border);
    display: flex;
    justify-content: space-between;
    gap: var(--ugda-space-sm);
    padding: var(--ugda-space-md);
}

.sidebar-notifications-panel__header strong,
.sidebar-notification-item strong {
    display: block;
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
    line-height: 1.3;
}

.sidebar-notifications-panel__header span {
    color: var(--ugda-text-secondary);
    display: block;
    font-size: var(--ugda-font-helper);
    margin-top: var(--ugda-space-xs);
}

.sidebar-notifications-panel__mark {
    background: var(--ugda-surface-soft);
    border: 0;
    border-radius: 999px;
    color: var(--ugda-brand);
    cursor: pointer;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
    padding: var(--ugda-space-xs) var(--ugda-space-sm);
}

.sidebar-notifications-panel__mark:disabled {
    cursor: not-allowed;
}

.sidebar-notifications-panel__list {
    max-height: 17rem;
    overflow-y: auto;
}

.sidebar-notification-item {
    align-items: flex-start;
    background: var(--ugda-surface-card);
    border: 0;
    border-bottom: 1px solid var(--ugda-border);
    color: var(--ugda-text-primary);
    cursor: pointer;
    display: flex;
    font: inherit;
    gap: var(--ugda-space-sm);
    padding: var(--ugda-space-md);
    text-align: left;
    width: 100%;
}

.sidebar-notification-item:hover,
.sidebar-notification-item--unread {
    background: var(--ugda-brand-soft);
}

.sidebar-notification-item__dot {
    background: var(--ugda-border-strong);
    border-radius: 999px;
    flex-shrink: 0;
    height: 0.55rem;
    margin-top: 0.28rem;
    width: 0.55rem;
}

.sidebar-notification-item--unread .sidebar-notification-item__dot {
    background: var(--ugda-error);
}

.sidebar-notification-item__body {
    min-width: 0;
}

.sidebar-notification-item small {
    color: var(--ugda-text-secondary);
    display: block;
    font-size: var(--ugda-font-secondary);
    line-height: 1.35;
    margin-top: var(--ugda-space-xs);
}

.sidebar-notification-item em {
    color: var(--ugda-text-muted);
    display: block;
    font-size: var(--ugda-font-helper);
    font-style: normal;
    margin-top: var(--ugda-space-xs);
}

.sidebar-notifications-panel__empty {
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-secondary);
    padding: var(--ugda-space-md);
    text-align: center;
}

.sidebar-notifications-panel__all {
    background: var(--ugda-surface-page);
    border: 0;
    color: var(--ugda-brand);
    cursor: pointer;
    font: inherit;
    font-size: var(--ugda-font-secondary);
    font-weight: var(--ugda-weight-medium);
    padding: var(--ugda-space-md);
    width: 100%;
}

@media (max-width: 991px) {
    .app-sidebar {
        border-radius: 0;
    }
}
</style>
