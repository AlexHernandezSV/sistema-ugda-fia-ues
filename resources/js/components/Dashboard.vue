<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();
const loading = ref(true);
const stats = ref([]);
const alertBlock = ref({
    count: 0,
    title: '',
    description: '',
    items: [],
});
const activeUnits = ref([]);
const recentRequests = ref([]);

const statusChipClass = (status) => {
    switch (status) {
        case 'Transferido':
        case 'Autorizado':
        case 'Finalizada':
            return 'dashboard-chip dashboard-chip--success';
        case 'Agendado':
        case 'En proceso':
            return 'dashboard-chip dashboard-chip--info';
        case 'Observado':
        case 'Pendiente':
            return 'dashboard-chip dashboard-chip--warning';
        case 'Cancelada':
        case 'Denegado':
            return 'dashboard-chip dashboard-chip--danger';
        default:
            return 'dashboard-chip dashboard-chip--neutral';
    }
};

const goToAlert = (route) => {
    router.push(route);
};

const fetchDashboard = async () => {
    try {
        const { data } = await axios.get('/api/dashboard-summary');
        stats.value = data.stats ?? [];
        alertBlock.value = data.alerts ?? alertBlock.value;
        activeUnits.value = data.active_units ?? [];
        recentRequests.value = data.recent_requests ?? [];
    } catch (error) {
        console.error('Error cargando dashboard:', error);
    } finally {
        loading.value = false;
    }
};

onMounted(fetchDashboard);
</script>

<template>
    <div class="grid p-fluid animation-duration-500 fadein dashboard-page">
        <div class="col-12 mb-2">
            <h1 class="text-2xl font-bold text-gray-800 m-0 mobile-page-title dashboard-page__title">Dashboard</h1>
            <span class="text-gray-500 text-sm dashboard-page__subtitle">Sistema de Gestión Documental - Facultad de Ingeniería y Arquitectura</span>
        </div>

        <div v-for="(stat, index) in stats" :key="index" class="col-12 md:col-6 lg:col-3">
            <div class="surface-card shadow-1 p-3 border-round-xl h-full border-1 border-transparent hover:border-gray-200 transition-duration-200 dashboard-stat-card">
                <div class="flex justify-content-between align-items-start gap-3">
                    <div class="min-w-0">
                        <span class="block text-500 font-medium text-xs mb-1 uppercase tracking-wide dashboard-stat-card__label">{{ stat.title }}</span>
                        <div class="text-900 font-bold text-3xl dashboard-stat-card__value">{{ stat.value }}</div>
                    </div>
                    <div class="flex align-items-center justify-content-center border-round dashboard-stat-card__icon" :class="[stat.bg, stat.color]">
                        <i :class="[stat.icon, 'text-xl']"></i>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="alertBlock.count > 0" class="col-12">
            <div class="dashboard-alert shadow-1 border-round-xl p-4">
                <div class="flex align-items-start gap-3 mb-3">
                    <div class="dashboard-alert__icon">
                        <i class="pi pi-exclamation-circle text-xl"></i>
                    </div>
                    <div>
                        <h2 class="m-0 dashboard-alert__title">{{ alertBlock.count }} {{ alertBlock.title }}</h2>
                        <p class="m-0 dashboard-alert__description">{{ alertBlock.description }}</p>
                    </div>
                </div>

                <div class="dashboard-alert__items">
                    <div v-for="item in alertBlock.items" :key="`${item.type}-${item.number}`" class="dashboard-alert__item">
                        <div class="dashboard-alert__item-main">
                            <div class="dashboard-alert__item-icon">
                                <i class="pi pi-file"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="dashboard-alert__item-title">{{ item.title }}</div>
                                <div class="dashboard-alert__item-subtitle">{{ item.subtitle }}</div>
                            </div>
                        </div>

                        <Button label="Revisar" class="dashboard-alert__item-action" @click="goToAlert(item.route)" />
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 lg:col-6">
            <div class="surface-card shadow-1 p-4 border-round-xl h-full">
                <h5 class="text-lg font-bold text-gray-800 mb-4">Unidades más activas</h5>

                <div class="flex flex-column gap-4">
                    <div v-for="unit in activeUnits" :key="unit.name">
                        <div class="flex justify-content-between mb-2 gap-3">
                            <span class="text-sm font-medium text-gray-700">{{ unit.name }}</span>
                            <span class="text-sm font-bold text-blue-600 flex-shrink-0">{{ unit.count }}</span>
                        </div>
                        <div class="w-full bg-gray-100 border-round-2xl h-1rem overflow-hidden">
                            <div class="bg-blue-600 h-full border-round-2xl" :style="{ width: unit.percent + '%' }"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 lg:col-6">
            <div class="surface-card shadow-1 p-4 border-round-xl h-full">
                <h5 class="text-lg font-bold text-gray-800 mb-4">Solicitudes recientes</h5>

                <ul class="list-none p-0 m-0">
                    <li v-for="item in recentRequests" :key="item.id" class="dashboard-recent-item">
                        <div class="dashboard-recent-item__icon">
                            <i class="pi pi-file text-lg"></i>
                        </div>
                        <div class="dashboard-recent-item__body">
                            <div class="font-bold text-gray-800 text-sm">{{ item.id }}</div>
                            <div class="text-xs text-gray-600 mt-1">{{ item.user }}</div>
                        </div>
                        <div class="dashboard-recent-item__meta">
                            <span class="text-xs text-gray-400 block">{{ item.date }}</span>
                            <span :class="statusChipClass(item.status)" class="mt-2">{{ item.status }}</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="loading" class="col-12">
            <div class="surface-card shadow-1 p-4 border-round-xl text-center text-gray-500">
                Cargando información del dashboard...
            </div>
        </div>
    </div>
</template>

<style scoped>
.dashboard-page__title {
    line-height: 1.1;
    font-size: 2.05rem;
}

.dashboard-page__subtitle {
    display: inline-block;
    margin-top: 0.35rem;
    line-height: 1.45;
    font-size: 0.92rem;
}

.tracking-wide {
    letter-spacing: 0.5px;
}

.dashboard-stat-card {
    min-height: 7rem;
}

.dashboard-stat-card__label {
    line-height: 1.45;
    font-size: 0.82rem;
    letter-spacing: 0.04em;
}

.dashboard-stat-card__value {
    line-height: 1;
    font-size: 2rem;
}

.dashboard-stat-card__icon {
    width: 2.85rem;
    height: 2.85rem;
    flex-shrink: 0;
}

.dashboard-alert {
    background: #fff4cf;
    border-left: 4px solid #f59e0b;
}

.dashboard-alert__icon {
    width: 2.6rem;
    height: 2.6rem;
    border-radius: 0.85rem;
    background: #fde68a;
    color: #b45309;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dashboard-alert__title {
    color: #8a5c17;
    font-size: 1.15rem;
    font-weight: 700;
}

.dashboard-alert__description {
    color: #9a6a1f;
    font-size: 0.92rem;
    margin-top: 0.3rem;
}

.dashboard-alert__items {
    display: flex;
    flex-direction: column;
    gap: 0.95rem;
    max-height: 25rem;
    overflow-y: auto;
    padding-right: 0.35rem;
}

.dashboard-alert__item {
    background: #ffffff;
    border-radius: 1rem;
    padding: 1rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-alert__item-main {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    flex: 1;
    min-width: 0;
}

.dashboard-alert__item-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 0.75rem;
    background: #eff6ff;
    color: #2f6f9f;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.dashboard-alert__item-title {
    color: #0f172a;
    font-size: 0.95rem;
    font-weight: 700;
}

.dashboard-alert__item-subtitle {
    color: #64748b;
    font-size: 0.88rem;
    margin-top: 0.2rem;
    line-height: 1.35;
}

.dashboard-alert__item-action {
    background: #2f6f9f;
    border: none;
    flex-shrink: 0;
}

.dashboard-alert__items::-webkit-scrollbar {
    width: 0.55rem;
}

.dashboard-alert__items::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.55);
    border-radius: 9999px;
}

.dashboard-alert__items::-webkit-scrollbar-thumb {
    background: rgba(180, 131, 15, 0.35);
    border-radius: 9999px;
}

.dashboard-recent-item {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0.9rem 0;
    border-bottom: 1px solid var(--surface-border);
}

.dashboard-recent-item:last-child {
    border-bottom: none;
}

.dashboard-recent-item__icon {
    width: 3rem;
    height: 3rem;
    background: #eff6ff;
    color: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.85rem;
    flex-shrink: 0;
}

.dashboard-recent-item__body {
    flex: 1;
    min-width: 0;
}

.dashboard-recent-item__meta {
    text-align: right;
    flex-shrink: 0;
}

:deep(.surface-card h5) {
    font-size: 1rem;
}

.dashboard-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.9rem;
    min-width: 6.4rem;
    padding: 0.28rem 0.9rem;
    border-radius: 9999px;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
}

.dashboard-chip--success {
    background: #c9f2e8;
    color: #0c8a66;
}

.dashboard-chip--danger {
    background: #fee2e2;
    color: #dc2626;
}

.dashboard-chip--warning {
    background: #fde9a9;
    color: #b45309;
}

.dashboard-chip--neutral {
    background: #d9dee7;
    color: #64748b;
}

.dashboard-chip--info {
    background: #dbeafe;
    color: #2563eb;
}

@media (max-width: 991px) {
    .dashboard-page__subtitle {
        font-size: 0.88rem;
    }

    .dashboard-stat-card {
        min-height: auto;
    }

    .dashboard-stat-card__value {
        font-size: 1.8rem;
    }

    .dashboard-alert__title {
        font-size: 1.05rem;
    }

    .dashboard-alert__item {
        flex-direction: column;
        align-items: stretch;
        padding: 1rem;
    }

    .dashboard-alert__items {
        max-height: 23rem;
        padding-right: 0.15rem;
    }

    .dashboard-alert__item-main {
        align-items: flex-start;
    }

    .dashboard-alert__item-action {
        width: 100%;
    }

    .dashboard-recent-item {
        align-items: flex-start;
    }

    .dashboard-recent-item__meta {
        text-align: left;
    }
}

@media (max-width: 640px) {
    .dashboard-page__title {
        font-size: 1.5rem;
    }

    .dashboard-page__subtitle {
        font-size: 0.82rem;
    }

    .dashboard-stat-card {
        padding: 1rem !important;
    }

    .dashboard-stat-card__label {
        font-size: 0.76rem;
    }

    .dashboard-stat-card__value {
        font-size: 1.55rem;
    }

    .dashboard-stat-card__icon {
        width: 2.5rem;
        height: 2.5rem;
    }

    .dashboard-alert {
        padding: 1rem !important;
    }

    .dashboard-alert__title {
        font-size: 0.98rem;
    }

    .dashboard-alert__description {
        font-size: 0.84rem;
    }

    .dashboard-alert__items {
        max-height: 21.5rem;
    }

    .dashboard-alert__item-title {
        font-size: 0.88rem;
    }

    .dashboard-alert__item-subtitle {
        font-size: 0.8rem;
    }

    .dashboard-recent-item {
        flex-direction: column;
    }

    .dashboard-recent-item__body,
    .dashboard-recent-item__meta {
        width: 100%;
    }
}
</style>
