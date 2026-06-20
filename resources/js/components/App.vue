<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Toast from 'primevue/toast';

import AppSidebar from './layout/AppSidebar.vue';

const route = useRoute();
const isLoginPage = computed(() => route.name === 'login');
const currentPageTitle = computed(() => route.meta?.title ?? '');

const toggleMobileSidebar = () => {
    window.dispatchEvent(new CustomEvent('ugda:toggle-sidebar'));
};
</script>

<template>
    <div class="min-h-screen flex font-custom" :class="{ 'bg-gray-50': !isLoginPage }">
        <Toast position="top-right" />

        <AppSidebar v-if="!isLoginPage" />

        <div
            class="flex flex-column transition-all transition-duration-200 app-shell"
            :class="[isLoginPage ? 'w-full' : 'flex-grow-1']"
        >
            <div class="h-full flex flex-column" :class="[isLoginPage ? 'p-0' : 'app-shell__content']">
                <div v-if="!isLoginPage" class="mobile-page-header">
                    <button type="button" class="mobile-page-header__toggle" @click="toggleMobileSidebar">
                        <i class="pi pi-bars"></i>
                    </button>
                    <h1 class="mobile-page-header__title">{{ currentPageTitle }}</h1>
                </div>
                <router-view />
            </div>
        </div>
    </div>
</template>

<style>
.app-shell {
    max-width: 100%;
    overflow-x: hidden;
}

.mobile-page-header {
    display: none;
}

.app-shell__content {
    padding: var(--ugda-space-lg);
}

@media (max-width: 991px) {
    .app-shell__content {
        padding: var(--ugda-space-md);
    }

    .mobile-page-header {
        align-items: center;
        display: flex;
        gap: var(--ugda-space-md);
        margin-bottom: var(--ugda-space-md);
        padding: var(--ugda-space-xs) 0 var(--ugda-space-sm);
    }

    .mobile-page-header__toggle {
        align-items: center;
        background: var(--ugda-brand);
        border: 0;
        border-radius: var(--ugda-radius-lg);
        color: var(--ugda-text-inverse);
        cursor: pointer;
        display: inline-flex;
        flex-shrink: 0;
        height: var(--ugda-control-height-lg);
        justify-content: center;
        width: var(--ugda-control-height-lg);
    }

    .mobile-page-header__toggle .pi {
        font-size: var(--ugda-font-card-title);
    }

    .mobile-page-header__title {
        color: var(--ugda-brand);
        font-size: var(--ugda-font-h2);
        font-weight: var(--ugda-weight-semibold);
        letter-spacing: 0;
        line-height: 1.05;
        margin: 0;
    }

    .mobile-page-title {
        display: none !important;
    }

    .p-dialog-mask {
        align-items: center !important;
        justify-content: center !important;
        padding: var(--ugda-space-md) !important;
    }

    .p-dialog {
        margin: 0 !important;
        max-height: calc(100vh - (var(--ugda-space-md) * 2)) !important;
        max-width: calc(100vw - (var(--ugda-space-md) * 2)) !important;
        width: min(42rem, calc(100vw - (var(--ugda-space-md) * 2))) !important;
    }

    .p-dialog-header,
    .p-dialog-footer {
        flex-shrink: 0;
    }

    .p-dialog-content {
        -webkit-overflow-scrolling: touch;
        max-height: calc(100vh - 160px) !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
    }

    .p-dialog .grid {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
}

@media (max-width: 640px) {
    .mobile-page-header {
        gap: var(--ugda-space-sm);
    }

    .mobile-page-header__toggle {
        border-radius: var(--ugda-radius-md);
        height: 42px;
        width: 42px;
    }

    .mobile-page-header__title {
        font-size: var(--ugda-font-h3);
    }
}
</style>
