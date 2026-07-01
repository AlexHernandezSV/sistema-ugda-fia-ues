<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import {
    fetchNotificationSummary,
    markAllNotificationsAsRead,
    markNotificationAsRead,
} from '../../services/notifications';

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const notifications = ref([]);
const typeOptions = ref([]);
const summary = ref({
    unread_count: 0,
    read_count: 0,
});
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 8,
    total: 0,
    from: null,
    to: null,
});

const filters = ref({
    search: '',
    status: null,
    type: null,
    dateRange: null,
});

const statusOptions = [
    { label: 'No leidas', value: 'unread' },
    { label: 'Leidas', value: 'read' },
];
const rowsPerPageOptions = [
    { label: '5', value: 5 },
    { label: '8', value: 8 },
    { label: '10', value: 10 },
    { label: '25', value: 25 },
    { label: '50', value: 50 },
];

const pageLabel = computed(() => {
    if (!meta.value.total) {
        return 'Sin notificaciones';
    }

    return `${meta.value.from ?? 0}-${meta.value.to ?? 0} de ${meta.value.total}`;
});
const visiblePages = computed(() => {
    const totalPages = meta.value.last_page || 1;
    const currentPage = meta.value.current_page || 1;
    const start = Math.max(1, currentPage - 1);
    const end = Math.min(totalPages, start + 2);
    const normalizedStart = Math.max(1, end - 2);
    const pages = [];

    for (let page = normalizedStart; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

const formatDateForQuery = (value) => {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
        return null;
    }

    const month = String(value.getMonth() + 1).padStart(2, '0');
    const day = String(value.getDate()).padStart(2, '0');

    return `${value.getFullYear()}-${month}-${day}`;
};

const toneClass = (tone) => {
    switch (tone) {
        case 'success':
            return 'notificaciones-chip notificaciones-chip--success';
        case 'danger':
            return 'notificaciones-chip notificaciones-chip--danger';
        case 'warning':
            return 'notificaciones-chip notificaciones-chip--warning';
        default:
            return 'notificaciones-chip notificaciones-chip--info';
    }
};

const toneIcon = (tone) => {
    switch (tone) {
        case 'success':
            return 'pi pi-check-circle';
        case 'danger':
            return 'pi pi-times-circle';
        case 'warning':
            return 'pi pi-exclamation-triangle';
        default:
            return 'pi pi-info-circle';
    }
};

const fetchNotifications = async (page = 1) => {
    loading.value = true;

    try {
        const perPage = Number(meta.value.per_page) || 8;
        const dateRange = filters.value.dateRange;
        const params = {
            page,
            per_page: perPage,
            search: filters.value.search || undefined,
            status: filters.value.status || undefined,
            type: filters.value.type || undefined,
            date_from: Array.isArray(dateRange) ? formatDateForQuery(dateRange[0]) : undefined,
            date_to: Array.isArray(dateRange) ? formatDateForQuery(dateRange[1] ?? dateRange[0]) : undefined,
        };

        const { data } = await axios.get('/api/notifications', { params });
        notifications.value = data.data ?? [];
        meta.value = data.meta ?? meta.value;
        meta.value.per_page = Number(meta.value.per_page) || perPage;
        summary.value = data.summary ?? summary.value;
        typeOptions.value = data.summary?.types ?? [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudieron cargar las notificaciones.',
            life: 3200,
        });
    } finally {
        loading.value = false;
    }
};

const clearFilters = () => {
    filters.value = {
        search: '',
        status: null,
        type: null,
        dateRange: null,
    };
    fetchNotifications(1);
};

const openAction = async (notification) => {
    if (!notification.is_read) {
        await markNotificationAsRead(notification.id);
    }

    await fetchNotificationSummary();

    if (notification.action_url) {
        router.push(notification.action_url);
    }
};

const markAllRead = async () => {
    await markAllNotificationsAsRead();
    await fetchNotifications(meta.value.current_page);
};

const previousPage = () => {
    if (meta.value.current_page > 1) {
        fetchNotifications(meta.value.current_page - 1);
    }
};

const nextPage = () => {
    if (meta.value.current_page < meta.value.last_page) {
        fetchNotifications(meta.value.current_page + 1);
    }
};

const goToPage = (page) => {
    if (page >= 1 && page <= meta.value.last_page && page !== meta.value.current_page) {
        fetchNotifications(page);
    }
};

const selectedRowsLabel = computed(() => String(Number(meta.value.per_page) || 8));

onMounted(() => fetchNotifications());
</script>

<template>
    <div class="notificaciones-page flex flex-column gap-4">
        <div class="flex flex-wrap justify-content-between align-items-start gap-3 mobile-page-title">
            <div>
                <h1 class="notificaciones-title m-0">Notificaciones</h1>
                <span class="notificaciones-subtitle">Seguimiento de acciones pendientes y avisos del sistema</span>
            </div>

            <button
                type="button"
                class="notificaciones-primary-action"
                :disabled="summary.unread_count === 0"
                @click="markAllRead"
            >
                <i class="pi pi-check"></i>
                <span>Marcar todo como leido</span>
            </button>
        </div>

        <div class="surface-card shadow-1 border-round-xl p-4">
            <div class="grid">
                <div class="col-12 md:col-6 xl:col">
                    <label class="notificaciones-filter-label">Busqueda</label>
                    <InputText v-model="filters.search" placeholder="Numero, titulo o detalle" class="w-full mt-2" />
                </div>
                <div class="col-12 md:col-6 xl:col">
                    <label class="notificaciones-filter-label">Estado</label>
                    <Dropdown v-model="filters.status" :options="statusOptions" optionLabel="label" optionValue="value" placeholder="Todas" showClear class="w-full mt-2" />
                </div>
                <div class="col-12 md:col-6 xl:col">
                    <label class="notificaciones-filter-label">Tipo</label>
                    <Dropdown v-model="filters.type" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="Todos" showClear class="w-full mt-2" />
                </div>
                <div class="col-12 md:col-6 xl:col">
                    <label class="notificaciones-filter-label">Fecha de creacion</label>
                    <DatePicker
                        v-model="filters.dateRange"
                        selectionMode="range"
                        showIcon
                        iconDisplay="input"
                        dateFormat="dd/mm/yy"
                        placeholder="dd/mm/aaaa - dd/mm/aaaa"
                        :manualInput="false"
                        class="w-full mt-2 notificaciones-datepicker"
                    />
                </div>
            </div>

            <div class="flex flex-wrap gap-3 mt-3">
                <Button label="Filtrar" class="border-none px-4 py-2 notificaciones-filter-button" @click="fetchNotifications(1)" />
                <Button label="Limpiar" severity="secondary" outlined class="px-4 py-2" @click="clearFilters" />
            </div>
        </div>

        <div class="surface-card shadow-1 border-round-xl overflow-hidden">
            <div class="notificaciones-list-header">
                <span>{{ pageLabel }}</span>
                <span>Pagina {{ meta.current_page }} de {{ meta.last_page }}</span>
            </div>

            <div v-if="loading" class="p-4 text-center text-gray-500">Cargando notificaciones...</div>

            <div v-else-if="notifications.length === 0" class="notificaciones-empty">
                <i class="pi pi-bell"></i>
                <strong>No hay notificaciones</strong>
                <span>No se encontraron avisos con los filtros seleccionados.</span>
            </div>

            <div v-else class="notificaciones-list">
                <article
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="notificaciones-item"
                    :class="{ 'notificaciones-item--unread': !notification.is_read }"
                >
                    <div class="notificaciones-item__summary">
                        <div class="notificaciones-item__main">
                            <div class="notificaciones-item__icon" :class="`notificaciones-item__icon--${notification.tone}`">
                                <i :class="toneIcon(notification.tone)"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="notificaciones-item__title-row">
                                    <h2>{{ notification.title }}</h2>
                                    <span v-if="!notification.is_read" class="notificaciones-unread-dot"></span>
                                </div>
                                <p>{{ notification.short_message }}</p>
                                <div class="notificaciones-item__meta">
                                    <span :class="toneClass(notification.tone)">{{ notification.type_label }}</span>
                                    <span>{{ notification.created_at_label }}</span>
                                    <span v-if="notification.reference_number">#{{ notification.reference_number }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="notificaciones-item__side">
                            <div class="notificaciones-detail-meta">
                                <strong
                                    class="notificaciones-read-state"
                                    :class="notification.is_read ? 'notificaciones-read-state--read' : 'notificaciones-read-state--unread'"
                                >
                                    <span></span>
                                    {{ notification.is_read ? 'Leida' : 'No leida' }}
                                </strong>
                                <span>Creada: {{ notification.created_at_label }}</span>
                                <span>Vista: {{ notification.read_at_label || 'Pendiente' }}</span>
                            </div>

                            <button v-if="notification.action_url" type="button" class="notificaciones-open-action" @click="openAction(notification)">
                                <i class="pi pi-external-link"></i>
                                <span>Abrir</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>

            <div class="notificaciones-pagination">
                <div class="notificaciones-pagination__pages">
                    <button
                        type="button"
                        class="notificaciones-pagination__icon"
                        :disabled="meta.current_page <= 1"
                        aria-label="Primera pagina"
                        @click="goToPage(1)"
                    >
                        <i class="pi pi-angle-double-left"></i>
                    </button>
                    <button
                        type="button"
                        class="notificaciones-pagination__icon"
                        :disabled="meta.current_page <= 1"
                        aria-label="Pagina anterior"
                        @click="previousPage"
                    >
                        <i class="pi pi-angle-left"></i>
                    </button>

                    <button
                        v-for="page in visiblePages"
                        :key="page"
                        type="button"
                        class="notificaciones-pagination__number"
                        :class="{ 'notificaciones-pagination__number--active': page === meta.current_page }"
                        @click="goToPage(page)"
                    >
                        {{ page }}
                    </button>

                    <button
                        type="button"
                        class="notificaciones-pagination__icon"
                        :disabled="meta.current_page >= meta.last_page"
                        aria-label="Pagina siguiente"
                        @click="nextPage"
                    >
                        <i class="pi pi-angle-right"></i>
                    </button>
                    <button
                        type="button"
                        class="notificaciones-pagination__icon"
                        :disabled="meta.current_page >= meta.last_page"
                        aria-label="Ultima pagina"
                        @click="goToPage(meta.last_page)"
                    >
                        <i class="pi pi-angle-double-right"></i>
                    </button>
                </div>

                <Dropdown
                    v-model="meta.per_page"
                    :options="rowsPerPageOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="notificaciones-page-size"
                    @change="fetchNotifications(1)"
                >
                    <template #value>
                        <span>{{ selectedRowsLabel }}</span>
                    </template>
                </Dropdown>
            </div>
        </div>
    </div>
</template>

<style scoped>
.notificaciones-title {
    color: #2f6f9f;
    font-size: 2.05rem;
    line-height: 1.15;
    font-weight: 700;
}

.notificaciones-subtitle {
    color: #64748b;
    display: inline-block;
    margin-top: 0.35rem;
}

.notificaciones-primary-action,
.notificaciones-open-action,
.notificaciones-pagination button {
    align-items: center;
    border-radius: var(--ugda-radius-md);
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    font-weight: var(--ugda-weight-medium);
    gap: 0.55rem;
    justify-content: center;
}

.notificaciones-primary-action {
    background: var(--ugda-brand);
    border: 1px solid var(--ugda-brand);
    color: var(--ugda-text-inverse);
    min-height: 2.8rem;
    padding: 0 1rem;
}

.notificaciones-primary-action:disabled,
.notificaciones-pagination button:disabled {
    cursor: not-allowed;
}

.notificaciones-filter-label {
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

.notificaciones-filter-button {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

:deep(.notificaciones-datepicker),
:deep(.notificaciones-datepicker .p-datepicker),
:deep(.notificaciones-datepicker .p-inputtext) {
    width: 100%;
}

.notificaciones-list-header,
.notificaciones-pagination {
    align-items: center;
    background: #f8fafc;
    color: #64748b;
    display: flex;
    font-size: 0.9rem;
    font-weight: 700;
    justify-content: space-between;
    padding: 1rem 1.25rem;
}

:deep(.notificaciones-page-size) {
    min-width: 5.2rem;
    position: absolute;
    right: 1.25rem;
}

:deep(.notificaciones-page-size .p-dropdown-label) {
    font-size: 0.86rem;
    font-weight: 700;
    padding-bottom: 0.55rem;
    padding-top: 0.55rem;
}

.notificaciones-list {
    display: flex;
    flex-direction: column;
}

.notificaciones-item {
    border-top: 1px solid #e2e8f0;
}

.notificaciones-item--unread {
    background: #f8fbff;
}

.notificaciones-item__summary {
    align-items: center;
    background: transparent;
    border: 0;
    color: inherit;
    display: flex;
    font: inherit;
    gap: 1rem;
    justify-content: space-between;
    padding: 1.15rem 1.25rem;
    text-align: left;
    width: 100%;
}

.notificaciones-item__main {
    align-items: flex-start;
    display: flex;
    flex: 1;
    gap: 1rem;
    min-width: 0;
}

.notificaciones-item__icon {
    align-items: center;
    border-radius: 0.85rem;
    display: inline-flex;
    flex-shrink: 0;
    height: 2.6rem;
    justify-content: center;
    width: 2.6rem;
}

.notificaciones-item__icon--success {
    background: #dcfce7;
    color: #16a34a;
}

.notificaciones-item__icon--danger {
    background: #fee2e2;
    color: #dc2626;
}

.notificaciones-item__icon--warning {
    background: #fef3c7;
    color: #b45309;
}

.notificaciones-item__icon--info {
    background: #dbeafe;
    color: #2563eb;
}

.notificaciones-item__title-row {
    align-items: center;
    display: flex;
    gap: 0.55rem;
    min-width: 0;
}

.notificaciones-item__title-row h2 {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 800;
    line-height: 1.35;
    margin: 0;
}

.notificaciones-item__summary p {
    color: #475569;
    line-height: 1.45;
    margin: 0.35rem 0 0;
}

.notificaciones-item__meta {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    margin-top: 0.75rem;
}

.notificaciones-chip {
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 0.28rem 0.65rem;
}

.notificaciones-chip--success {
    background: #dcfce7;
    color: #15803d;
}

.notificaciones-chip--danger {
    background: #fee2e2;
    color: #dc2626;
}

.notificaciones-chip--warning {
    background: #fef3c7;
    color: #b45309;
}

.notificaciones-chip--info {
    background: #dbeafe;
    color: #2563eb;
}

.notificaciones-unread-dot {
    background: #dc2626;
    border-radius: 999px;
    flex-shrink: 0;
    height: 0.55rem;
    width: 0.55rem;
}

.notificaciones-detail-meta {
    color: #64748b;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    color: #64748b;
    font-size: 0.86rem;
    line-height: 1.45;
    min-width: 12rem;
    text-align: right;
}

.notificaciones-item__side {
    align-items: flex-end;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-left: auto;
}

.notificaciones-open-action {
    background: #ffffff;
    border: 1px solid #2f6f9f;
    color: #2f6f9f;
    min-height: 2.65rem;
    padding: 0 1rem;
}

.notificaciones-read-state {
    align-items: center;
    display: inline-flex;
    font-size: 0.95rem;
    font-weight: 800;
    gap: 0.45rem;
    justify-content: flex-end;
    margin-bottom: 0.2rem;
}

.notificaciones-read-state span {
    border-radius: 999px;
    display: inline-flex;
    height: 0.62rem;
    width: 0.62rem;
}

.notificaciones-read-state--read {
    color: #475569;
}

.notificaciones-read-state--read span {
    background: #94a3b8;
}

.notificaciones-read-state--unread {
    color: #1d4ed8;
}

.notificaciones-read-state--unread span {
    background: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}

.notificaciones-empty {
    align-items: center;
    color: #64748b;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    padding: 3rem 1.25rem;
    text-align: center;
}

.notificaciones-empty i {
    color: #94a3b8;
    font-size: 2rem;
}

.notificaciones-empty strong {
    color: #334155;
}

.notificaciones-pagination {
    border-top: 1px solid #e2e8f0;
    gap: 1rem;
    justify-content: center;
    position: relative;
}

.notificaciones-pagination__pages {
    align-items: center;
    display: flex;
    gap: 0.5rem;
}

.notificaciones-pagination__icon,
.notificaciones-pagination__number {
    background: transparent;
    border: 0;
    color: #334155;
    min-height: 2.4rem;
    min-width: 2.4rem;
    padding: 0 0.55rem;
}

.notificaciones-pagination__icon {
    color: #64748b;
}

.notificaciones-pagination__number--active {
    background: #dcfce7;
    color: #0f766e;
}

.notificaciones-pagination__number {
    border-radius: 999px;
}

.notificaciones-pagination__icon:disabled {
    color: #cbd5e1;
}

@media (max-width: 991px) {
    .notificaciones-title {
        font-size: 1.6rem;
    }

    .notificaciones-primary-action {
        width: 100%;
    }

    .notificaciones-detail-grid {
        grid-template-columns: 1fr;
    }

    .notificaciones-item__side {
        align-items: flex-start;
        margin-left: 3.6rem;
    }

    .notificaciones-detail-meta {
        min-width: 0;
        text-align: left;
    }
}

@media (max-width: 640px) {
    .notificaciones-list-header,
    .notificaciones-pagination,
    .notificaciones-item__summary,
    .notificaciones-item__main,
    .notificaciones-item__side {
        align-items: stretch;
        flex-direction: column;
    }

    .notificaciones-item__side {
        margin-left: 0;
    }

    .notificaciones-pagination__pages {
        justify-content: center;
    }

    :deep(.notificaciones-page-size) {
        position: static;
        width: 100%;
    }
}
</style>
