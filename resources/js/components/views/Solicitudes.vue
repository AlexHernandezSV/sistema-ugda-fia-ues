<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';

const route = useRoute();
const router = useRouter();

const activeTab = ref('transferencias');
const rowsPerPageOptions = ref([5, 10, 25, 50]);
const unitsList = ref([]);

const filters = ref({
    number: '',
    auth: null,
    status: null,
    search: null,
    unit: null,
    dateRange: null,
});

const transferRequests = ref([]);

const loanRequests = ref([]);

const requestFilterOptions = ref({
    transfers: {
        auth: ['Pendiente', 'Autorizado', 'Denegado'],
        status: ['Pendiente', 'Observado', 'Agendado', 'En Revisión', 'Obs. en Revisión', 'Subsanada', 'Transferido', 'Denegado', 'Cancelada'],
    },
    loans: {
        auth: ['Pendiente', 'Autorizado', 'Denegado'],
        status: ['Pendiente', 'Autorizado', 'Observado', 'Prestado', 'Devuelto', 'Denegado', 'Cancelada'],
        search: ['En proceso', 'Finalizada', 'No encontrados'],
    },
});

const pageTitles = {
    transferencias: 'Listado de solicitudes de transferencia documental',
    prestamos: 'Listado de solicitudes de préstamo documental',
};

const pageTitle = computed(() => pageTitles[activeTab.value] ?? pageTitles.transferencias);

const uniqueOptions = (values) => [...new Set((values ?? []).filter((value) => value && value !== '-'))];

const optionsWithDataValues = (catalogOptions, rows, field) => uniqueOptions([
    ...(catalogOptions ?? []),
    ...rows.map((item) => item[field]),
]);

const currentAuthOptions = computed(() => {
    if (activeTab.value === 'transferencias') {
        return optionsWithDataValues(requestFilterOptions.value.transfers?.auth, transferRequests.value, 'auth');
    }

    return optionsWithDataValues(requestFilterOptions.value.loans?.auth, loanRequests.value, 'auth');
});

const currentStatusOptions = computed(() =>
    activeTab.value === 'transferencias'
        ? optionsWithDataValues(requestFilterOptions.value.transfers?.status, transferRequests.value, 'status')
        : optionsWithDataValues(requestFilterOptions.value.loans?.status, loanRequests.value, 'status')
);

const currentLoanSearchOptions = computed(() =>
    optionsWithDataValues(requestFilterOptions.value.loans?.search, loanRequests.value, 'search')
);

const transferCount = computed(() => transferRequests.value.length);
const loanCount = computed(() => loanRequests.value.length);

const parseDisplayDate = (value) => {
    const rawValue = String(value ?? '').trim();

    if (!rawValue) {
        return null;
    }

    const [datePart] = rawValue.split(' ');
    const match = datePart.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);

    if (!match) {
        return null;
    }

    const [, day, month, year] = match;
    const parsed = new Date(Number(year), Number(month) - 1, Number(day));

    return Number.isNaN(parsed.getTime()) ? null : parsed;
};

const normalizeRangeDate = (value) => {
    if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
        return null;
    }

    return new Date(value.getFullYear(), value.getMonth(), value.getDate());
};

const matchesDateRange = (rawValue) => {
    const selectedRange = filters.value.dateRange;

    if (!Array.isArray(selectedRange) || selectedRange.length === 0 || !selectedRange[0]) {
        return true;
    }

    const itemDate = parseDisplayDate(rawValue);

    if (!itemDate) {
        return false;
    }

    const startDate = normalizeRangeDate(selectedRange[0]);
    const endDate = normalizeRangeDate(selectedRange[1] ?? selectedRange[0]);

    if (!startDate || !endDate) {
        return true;
    }

    return itemDate >= startDate && itemDate <= endDate;
};

const filteredTransfers = computed(() => transferRequests.value.filter((item) => {
    const matchesNumber = !filters.value.number || item.number.toLowerCase().includes(filters.value.number.toLowerCase());
    const matchesAuth = !filters.value.auth || item.auth === filters.value.auth;
    const matchesStatus = !filters.value.status || item.status === filters.value.status;
    const matchesUnit = !filters.value.unit || item.unit === filters.value.unit;
    const matchesDate = matchesDateRange(item.receivedAt);

    return matchesNumber && matchesAuth && matchesStatus && matchesUnit && matchesDate;
}));

const filteredLoans = computed(() => loanRequests.value.filter((item) => {
    const matchesNumber = !filters.value.number || item.number.toLowerCase().includes(filters.value.number.toLowerCase());
    const matchesAuth = !filters.value.auth || item.auth === filters.value.auth;
    const matchesStatus = !filters.value.status || item.status === filters.value.status;
    const matchesSearch = !filters.value.search || item.search === filters.value.search;
    const matchesUnit = !filters.value.unit || item.unit === filters.value.unit;
    const matchesDate = matchesDateRange(item.requestedAt);

    return matchesNumber && matchesAuth && matchesStatus && matchesSearch && matchesUnit && matchesDate;
}));

const clearFilters = () => {
    filters.value = {
        number: '',
        auth: null,
        status: null,
        search: null,
        unit: null,
        dateRange: null,
    };
};

const setTab = (tab) => {
    activeTab.value = tab;
    clearFilters();
};

const viewTransferDetail = (number) => {
    router.push({
        path: `/solicitudes/transferencias/${number}`,
        query: { tab: 'transferencias' },
    });
};

const viewLoanDetail = (number) => {
    router.push({
        path: `/solicitudes/prestamos/${number}`,
        query: { tab: 'prestamos' },
    });
};

const getAuthBadge = (status) => {
    switch (status) {
        case 'Autorizado':
            return 'solicitudes-chip solicitudes-chip--success';
        case 'Denegado':
            return 'solicitudes-chip solicitudes-chip--danger';
        default:
            return 'solicitudes-chip solicitudes-chip--neutral';
    }
};

const getStatusBadge = (status) => {
    switch (status) {
        case 'Transferido':
        case 'Autorizado':
        case 'Finalizada':
        case 'Devuelto':
            return 'solicitudes-chip solicitudes-chip--success';
        case 'Agendado':
        case 'En proceso':
        case 'Subsanada':
        case 'Prestado':
            return 'solicitudes-chip solicitudes-chip--info';
        case 'Observado':
        case 'Pendiente':
        case 'En Revisión':
        case 'Obs. en Revisión':
            return 'solicitudes-chip solicitudes-chip--warning';
        case 'Cancelada':
        case 'Denegado':
        case 'No encontrados':
            return 'solicitudes-chip solicitudes-chip--danger';
        default:
            return 'solicitudes-chip solicitudes-chip--neutral';
    }
};

onMounted(async () => {
    if (route.query.tab === 'prestamos' || route.query.tab === 'transferencias') {
        activeTab.value = route.query.tab;
    }

    try {
        const catalogRes = await axios.get('/api/request-catalog');
        transferRequests.value = catalogRes.data.transfers ?? [];
        loanRequests.value = catalogRes.data.loans ?? [];
        unitsList.value = catalogRes.data.units ?? [];
        requestFilterOptions.value = {
            transfers: {
                ...requestFilterOptions.value.transfers,
                ...(catalogRes.data.filters?.transfers ?? {}),
            },
            loans: {
                ...requestFilterOptions.value.loans,
                ...(catalogRes.data.filters?.loans ?? {}),
            },
        };
    } catch (error) {
        console.error('Error cargando catálogo de solicitudes:', error);
    }
});
</script>

<template>
    <div class="mb-4">
        <h1 class="text-2xl font-bold mobile-page-title solicitudes-page__title">{{ pageTitle }}</h1>
    </div>

    <div class="surface-card p-2 md:p-3 border-round-xl shadow-1 mb-4">
        <div class="solicitudes-tabs-grid">
            <div class="solicitudes-tabs-grid__item">
                <button
                    type="button"
                    class="solicitudes-tab"
                    :class="{ 'solicitudes-tab--active': activeTab === 'transferencias' }"
                    @click="setTab('transferencias')"
                >
                    <i class="pi pi-file"></i>
                    <span>Transferencias</span>
                    <span class="solicitudes-tab__badge">{{ transferCount }}</span>
                </button>
            </div>

            <div class="solicitudes-tabs-grid__item">
                <button
                    type="button"
                    class="solicitudes-tab"
                    :class="{ 'solicitudes-tab--active': activeTab === 'prestamos' }"
                    @click="setTab('prestamos')"
                >
                    <i class="pi pi-file-edit"></i>
                    <span>Préstamos</span>
                    <span class="solicitudes-tab__badge">{{ loanCount }}</span>
                </button>
            </div>
        </div>
    </div>

    <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
        <div v-if="activeTab === 'transferencias'" class="grid">
            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Número de transferencia</label>
                <InputText v-model="filters.number" placeholder="Escriba" class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Autorización</label>
                <Dropdown v-model="filters.auth" :options="currentAuthOptions" placeholder="Seleccionar" showClear class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Estado</label>
                <Dropdown v-model="filters.status" :options="currentStatusOptions" placeholder="Seleccionar" showClear class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Unidad</label>
                <Dropdown
                    v-model="filters.unit"
                    :options="unitsList"
                    placeholder="Seleccionar"
                    showClear
                    class="w-full mt-2 archive-long-label-dropdown"
                    overlayClass="archive-long-label-dropdown-overlay"
                />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Rango de fecha</label>
                <DatePicker
                    v-model="filters.dateRange"
                    selectionMode="range"
                    showIcon
                    iconDisplay="input"
                    dateFormat="dd/mm/yy"
                    placeholder="dd/mm/aaaa - dd/mm/aaaa"
                    :manualInput="false"
                    class="w-full mt-2 solicitudes-datepicker"
                />
            </div>
        </div>

        <div v-else class="grid">
            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Número de préstamo</label>
                <InputText v-model="filters.number" placeholder="Escriba" class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Autorización</label>
                <Dropdown v-model="filters.auth" :options="currentAuthOptions" placeholder="Seleccionar" showClear class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Estado</label>
                <Dropdown v-model="filters.status" :options="currentStatusOptions" placeholder="Seleccionar" showClear class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Búsqueda</label>
                <Dropdown v-model="filters.search" :options="currentLoanSearchOptions" placeholder="Seleccionar" showClear class="w-full mt-2" />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Unidad</label>
                <Dropdown
                    v-model="filters.unit"
                    :options="unitsList"
                    placeholder="Seleccionar"
                    showClear
                    class="w-full mt-2 archive-long-label-dropdown"
                    overlayClass="archive-long-label-dropdown-overlay"
                />
            </div>

            <div class="col-12 md:col-6 xl:col">
                <label class="solicitudes-filter__label">Rango de fecha</label>
                <DatePicker
                    v-model="filters.dateRange"
                    selectionMode="range"
                    showIcon
                    iconDisplay="input"
                    dateFormat="dd/mm/yy"
                    placeholder="dd/mm/aaaa - dd/mm/aaaa"
                    :manualInput="false"
                    class="w-full mt-2 solicitudes-datepicker"
                />
            </div>
        </div>

        <div class="flex flex-wrap gap-3 mt-3">
            <Button label="Buscar" class="border-none px-4 py-2" />
            <Button label="Limpiar" severity="secondary" outlined class="px-4 py-2" @click="clearFilters" />
        </div>
    </div>

    <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
        <DataTable
            v-if="activeTab === 'transferencias'"
            :value="filteredTransfers"
            :paginator="true"
                :rows="5"
            :rowsPerPageOptions="rowsPerPageOptions"
            stripedRows
            responsiveLayout="scroll"
        >
            <template #empty>
                <div class="text-center p-4 text-gray-500 font-medium">No se encontraron solicitudes.</div>
            </template>

            <Column field="number" header="Número de transferencia" headerClass="bg-primary-custom text-white font-semibold" bodyClass="text-center" style="min-width: 15rem"></Column>
            <Column field="responsible" header="Responsable del envío" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 19rem"></Column>
            <Column field="unit" header="Unidad productora" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 18rem"></Column>
            <Column field="receivedAt" header="Fecha de recepción" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 14rem"></Column>

            <Column header="Autorización" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column" style="min-width: 11rem">
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span :class="getAuthBadge(slotProps.data.auth)">{{ slotProps.data.auth }}</span>
                    </div>
                </template>
            </Column>

            <Column header="Estado" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column" style="min-width: 10rem">
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span :class="getStatusBadge(slotProps.data.status)">{{ slotProps.data.status }}</span>
                    </div>
                </template>
            </Column>

            <Column header="Acciones" headerClass="bg-primary-custom text-white font-semibold" bodyClass="text-center" style="min-width: 8rem">
                <template #body="slotProps">
                    <Button icon="pi pi-eye" text rounded severity="secondary" class="text-gray-600 hover:text-blue-600" @click="viewTransferDetail(slotProps.data.number)" />
                </template>
            </Column>
        </DataTable>

        <DataTable
            v-else
            :value="filteredLoans"
            :paginator="true"
                :rows="5"
            :rowsPerPageOptions="rowsPerPageOptions"
            stripedRows
            responsiveLayout="scroll"
        >
            <template #empty>
                <div class="text-center p-4 text-gray-500 font-medium">No se encontraron solicitudes.</div>
            </template>

            <Column field="number" header="Número de préstamo" headerClass="bg-primary-custom text-white font-semibold" bodyClass="text-center" style="min-width: 14rem"></Column>
            <Column field="applicant" header="Solicitante" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 18rem"></Column>
            <Column field="unit" header="Unidad solicitante" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 18rem"></Column>
            <Column field="requestedAt" header="Fecha de solicitud" headerClass="bg-primary-custom text-white font-semibold" style="min-width: 14rem"></Column>

            <Column header="Autorización" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column" style="min-width: 11rem">
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span :class="getAuthBadge(slotProps.data.auth)">{{ slotProps.data.auth }}</span>
                    </div>
                </template>
            </Column>

            <Column header="Estado" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column" style="min-width: 10rem">
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span :class="getStatusBadge(slotProps.data.status)">{{ slotProps.data.status }}</span>
                    </div>
                </template>
            </Column>

            <Column header="Búsqueda" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column" style="min-width: 11rem">
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span v-if="slotProps.data.search === '-'" class="text-gray-400">-</span>
                        <span v-else :class="getStatusBadge(slotProps.data.search)">{{ slotProps.data.search }}</span>
                    </div>
                </template>
            </Column>

            <Column header="Acciones" headerClass="bg-primary-custom text-white font-semibold" bodyClass="text-center" style="min-width: 8rem">
                <template #body="slotProps">
                    <Button icon="pi pi-eye" text rounded severity="secondary" class="text-gray-600 hover:text-blue-600" @click="viewLoanDetail(slotProps.data.number)" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<style scoped>
.solicitudes-page__title {
    color: var(--ugda-brand);
    font-size: var(--ugda-font-h1);
    font-weight: var(--ugda-weight-semibold);
    line-height: 1.15;
}

.solicitudes-tabs-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--ugda-space-md);
}

.solicitudes-tabs-grid__item {
    display: flex;
}

.solicitudes-tab {
    width: 100%;
    max-width: 100%;
    min-height: var(--ugda-control-height-lg);
    border: none;
    border-radius: var(--ugda-radius-lg);
    background: var(--ugda-surface-card);
    color: var(--ugda-text-secondary);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--ugda-space-sm);
    padding: var(--ugda-space-sm) var(--ugda-space-md);
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
    cursor: pointer;
    transition: all 0.2s ease;
}

.solicitudes-tab--active {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
    box-shadow: var(--ugda-shadow-card);
}

.solicitudes-tab__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.5rem;
    height: 1.5rem;
    padding: 0 0.35rem;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.2);
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
}

.solicitudes-filter__label {
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

:deep(.solicitudes-datepicker),
:deep(.solicitudes-datepicker .p-datepicker),
:deep(.solicitudes-datepicker .p-inputtext) {
    width: 100%;
}

.solicitudes-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    min-width: 6.4rem;
    padding: var(--ugda-space-xs) var(--ugda-space-sm);
    border-radius: 9999px;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
    line-height: 1;
    white-space: nowrap;
}

.solicitudes-chip--success {
    background: var(--ugda-success-soft);
    color: var(--ugda-success-text);
}

.solicitudes-chip--danger {
    background: var(--ugda-error-soft);
    color: var(--ugda-error-text);
}

.solicitudes-chip--warning {
    background: var(--ugda-warning-soft);
    color: var(--ugda-warning-text);
}

.solicitudes-chip--neutral {
    background: var(--ugda-surface-soft);
    color: var(--ugda-text-secondary);
}

.solicitudes-chip--info {
    background: var(--ugda-info-soft);
    color: var(--ugda-info-text);
}

:deep(.bg-primary-custom) {
    background-color: var(--ugda-brand) !important;
}

:deep(.p-datatable-thead > tr > th) {
    padding: var(--ugda-space-md);
    border-width: 0 0 1px 0;
}

:deep(.p-datatable-tbody > tr > td) {
    vertical-align: middle;
}

@media (max-width: 991px) {
    .solicitudes-page__title {
        font-size: var(--ugda-font-h2);
    }

    .solicitudes-tabs-grid {
        grid-template-columns: 1fr;
    }
}
</style>
