<script setup>
import { computed, onMounted, ref, watch } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import { useToast } from "primevue/usetoast";

const router = useRouter();
const toast = useToast();
const loading = ref(false);
const saving = ref(false);
const offices = ref([]);
const aisles = ref([]);
const shelves = ref([]);
const optionOffices = ref([]);
const optionAisles = ref([]);
const dialogVisible = ref(false);
const deleteDialogVisible = ref(false);
const activeType = ref("office");
const editingId = ref(null);
const deleting = ref(false);
const pendingDelete = ref(null);
const rowsPerPageOptions = ref([5, 10, 25, 50]);
const textFieldMaxLength = 100;
const createTableFilters = () => ({
    global: { value: null, matchMode: "contains" },
});
const officeFilters = ref(createTableFilters());
const aisleFilters = ref(createTableFilters());
const shelfFilters = ref(createTableFilters());
const searchTimeout = ref(null);
const tableState = ref({
    office: { page: 1, perPage: 10, total: 0 },
    aisle: { page: 1, perPage: 10, total: 0 },
    shelf: { page: 1, perPage: 10, total: 0 },
});

const form = ref({
    name: "",
    code: "",
    is_active: true,
    office_id: null,
    aisle_id: null,
});

const typeConfig = {
    office: {
        title: "Oficina",
        createTitle: "Nueva Oficina",
        editTitle: "Editar Oficina",
        endpoint: "/api/physical-location-offices",
    },
    aisle: {
        title: "Pasillo",
        createTitle: "Nuevo Pasillo",
        editTitle: "Editar Pasillo",
        endpoint: "/api/physical-location-aisles",
    },
    shelf: {
        title: "Estante",
        createTitle: "Nuevo Estante",
        editTitle: "Editar Estante",
        endpoint: "/api/physical-location-shelves",
    },
};

const dialogTitle = computed(() =>
    editingId.value
        ? typeConfig[activeType.value].editTitle
        : typeConfig[activeType.value].createTitle,
);

const deleteTargetTitle = computed(() => {
    if (!pendingDelete.value) {
        return "";
    }

    return typeConfig[pendingDelete.value.type].title;
});

const officeOptions = computed(() =>
    optionOffices.value.map((office) => ({
        ...office,
        label: `${office.name} (${office.code})`,
    })),
);

const aisleOptions = computed(() =>
    optionAisles.value.map((aisle) => ({
        ...aisle,
        label: `${aisle.office_name} / ${aisle.name} (${aisle.code})`,
    })),
);

const filteredAisleOptions = computed(() => {
    if (!form.value.office_id) {
        return aisleOptions.value;
    }

    return aisleOptions.value.filter(
        (aisle) => aisle.office_id === form.value.office_id,
    );
});

watch(
    () => form.value.office_id,
    () => {
        if (
            activeType.value === "shelf" &&
            !filteredAisleOptions.value.some(
                (aisle) => aisle.id === form.value.aisle_id,
            )
        ) {
            form.value.aisle_id = null;
        }
    },
);

const showError = (detail) => {
    toast.add({ severity: "error", summary: "Error", detail, life: 4200 });
};

const characterCounter = (value) =>
    String(value ?? "").length.toString().padStart(2, "0");

const searchForType = (type) => {
    const filters = {
        office: officeFilters,
        aisle: aisleFilters,
        shelf: shelfFilters,
    };

    return filters[type].value.global.value ?? "";
};

const catalogParams = () => ({
    office_page: tableState.value.office.page,
    office_per_page: tableState.value.office.perPage,
    office_search: searchForType("office"),
    aisle_page: tableState.value.aisle.page,
    aisle_per_page: tableState.value.aisle.perPage,
    aisle_search: searchForType("aisle"),
    shelf_page: tableState.value.shelf.page,
    shelf_per_page: tableState.value.shelf.perPage,
    shelf_search: searchForType("shelf"),
});

const applyPaginatedTable = (type, payload, target) => {
    target.value = Array.isArray(payload?.data) ? payload.data : [];
    tableState.value[type] = {
        page: payload?.meta?.current_page ?? tableState.value[type].page,
        perPage: payload?.meta?.per_page ?? tableState.value[type].perPage,
        total: payload?.meta?.total ?? 0,
    };
};

const fetchCatalog = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get("/api/physical-locations", {
            params: catalogParams(),
        });

        applyPaginatedTable("office", data.offices, offices);
        applyPaginatedTable("aisle", data.aisles, aisles);
        applyPaginatedTable("shelf", data.shelves, shelves);
    } catch (error) {
        showError(
            error.response?.data?.message ??
                "No se pudieron cargar las ubicaciones fisicas.",
        );
    } finally {
        loading.value = false;
    }
};

const fetchLocationOptions = async () => {
    try {
        const { data } = await axios.get("/api/physical-locations/options");
        optionOffices.value = Array.isArray(data.offices) ? data.offices : [];
        optionAisles.value = Array.isArray(data.aisles) ? data.aisles : [];
    } catch (error) {
        optionOffices.value = [];
        optionAisles.value = [];
    }
};

const resetForm = () => {
    form.value = {
        name: "",
        code: "",
        is_active: true,
        office_id: null,
        aisle_id: null,
    };
    editingId.value = null;
};

const openCreate = (type) => {
    activeType.value = type;
    resetForm();
    dialogVisible.value = true;
};

const openEdit = (type, item) => {
    activeType.value = type;
    editingId.value = item.id;
    form.value = {
        name: item.name,
        code: item.code,
        is_active: item.is_active,
        office_id: item.office_id ?? null,
        aisle_id: item.aisle_id ?? null,
    };
    dialogVisible.value = true;
};

const payloadForType = () => {
    const payload = {
        name: form.value.name.trim(),
        code: form.value.code.trim(),
        is_active: form.value.is_active,
    };

    if (activeType.value === "aisle") {
        payload.office_id = form.value.office_id;
    }

    if (activeType.value === "shelf") {
        payload.aisle_id = form.value.aisle_id;
    }

    return payload;
};

const validateForm = () => {
    if (!form.value.name.trim() || !form.value.code.trim()) {
        showError("Ingrese nombre y código.");
        return false;
    }

    if (activeType.value === "aisle" && !form.value.office_id) {
        showError("Seleccione la oficina del pasillo.");
        return false;
    }

    if (activeType.value === "shelf" && !form.value.aisle_id) {
        showError("Seleccione el pasillo del estante.");
        return false;
    }

    return true;
};

const saveLocation = async () => {
    if (!validateForm()) {
        return;
    }

    saving.value = true;

    try {
        const config = typeConfig[activeType.value];
        const payload = payloadForType();
        const request = editingId.value
            ? axios.put(`${config.endpoint}/${editingId.value}`, payload)
            : axios.post(config.endpoint, payload);
        const { data } = await request;

        toast.add({
            severity: "success",
            summary: "Guardado",
            detail: data.message ?? "Ubicación física guardada correctamente.",
            life: 3000,
        });
        dialogVisible.value = false;
        await Promise.all([fetchCatalog(), fetchLocationOptions()]);
    } catch (error) {
        showError(
            error.response?.data?.message ??
                "No se pudo guardar la ubicación física.",
        );
    } finally {
        saving.value = false;
    }
};

const deleteLocation = (type, item) => {
    pendingDelete.value = { type, item };
    deleteDialogVisible.value = true;
};

const closeDeleteDialog = () => {
    if (deleting.value) {
        return;
    }

    deleteDialogVisible.value = false;
    pendingDelete.value = null;
};

const confirmDeleteLocation = async () => {
    if (!pendingDelete.value) {
        return;
    }

    deleting.value = true;

    try {
        const { type, item } = pendingDelete.value;
        await axios.delete(`${typeConfig[type].endpoint}/${item.id}`);
        toast.add({
            severity: "success",
            summary: "Eliminado",
            detail: "Registro eliminado correctamente.",
            life: 2600,
        });
        deleteDialogVisible.value = false;
        pendingDelete.value = null;
        await Promise.all([fetchCatalog(), fetchLocationOptions()]);
    } catch (error) {
        showError(
            error.response?.data?.message ?? "No se pudo eliminar el registro.",
        );
    } finally {
        deleting.value = false;
    }
};

const onTablePage = (type, event) => {
    tableState.value[type] = {
        ...tableState.value[type],
        page: event.page + 1,
        perPage: event.rows,
    };
    fetchCatalog();
};

const tableFirst = (type) =>
    (tableState.value[type].page - 1) * tableState.value[type].perPage;

const queueSearch = (type) => {
    tableState.value[type] = {
        ...tableState.value[type],
        page: 1,
    };

    if (searchTimeout.value) {
        window.clearTimeout(searchTimeout.value);
    }

    searchTimeout.value = window.setTimeout(fetchCatalog, 350);
};

const clearLocationFilter = (filters, type) => {
    filters.global.value = null;
    queueSearch(type);
};

watch(() => officeFilters.value.global.value, () => queueSearch("office"));
watch(() => aisleFilters.value.global.value, () => queueSearch("aisle"));
watch(() => shelfFilters.value.global.value, () => queueSearch("shelf"));

onMounted(() => {
    fetchCatalog();
    fetchLocationOptions();
});

const goBack = () => {
    router.back();
};
</script>

<template>
    <div class="locations-page fadein animation-duration-500">
        <div class="mb-4">
            <section class="reports-hero shadow-1">
                <div class="reports-hero__nav">
                    <button
                        type="button"
                        class="reports-back-button"
                        @click="goBack"
                    >
                        <i class="pi pi-arrow-left"></i>
                        <span>Volver</span>
                    </button>
                </div>

                <div class="reports-hero__copy">
                    <h1 class="reports-hero__title m-0">
                        Gestión de Ubicaciones Físicas
                    </h1>
                    <p class="reports-hero__subtitle m-0">
                        Administre la estructura Oficina, Pasillo y Estante
                        usada para ubicar cajas.
                    </p>
                </div>
            </section>
        </div>

        <main class="locations-grid">
            <section class="locations-panel">
                <div class="locations-panel__head">
                    <div>
                        <h2>Oficinas</h2>
                        <span>{{ tableState.office.total }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('office')"
                    />
                </div>
                <div class="locations-search">
                    <span class="locations-search__field">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="officeFilters['global'].value"
                            placeholder="Buscar oficina..."
                        />
                    </span>
                    <Button
                        icon="pi pi-times"
                        label="Limpiar"
                        severity="secondary"
                        outlined
                        @click="clearLocationFilter(officeFilters, 'office')"
                    />
                </div>
                <DataTable
                    :value="offices"
                    :loading="loading"
                    :paginator="true"
                    lazy
                    :first="tableFirst('office')"
                    :rows="tableState.office.perPage"
                    :totalRecords="tableState.office.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    @page="onTablePage('office', $event)"
                >
                    <template #empty>
                        <div class="locations-empty">
                            Sin oficinas registradas
                        </div>
                    </template>
                    <Column field="name" header="Nombre" />
                    <Column field="code" header="Código" />
                    <Column header="Estado">
                        <template #body="{ data }">
                            <span
                                :class="
                                    data.is_active
                                        ? 'locations-status locations-status--active'
                                        : 'locations-status'
                                "
                            >
                                {{ data.is_active ? "Activo" : "Inactivo" }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Acciones" bodyClass="locations-actions">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" text rounded aria-label="Editar oficina" @click="openEdit('office', data)" />
                            <Button icon="pi pi-trash" rounded severity="danger" class="locations-delete-button" aria-label="Eliminar oficina" @click="deleteLocation('office', data)" />
                        </template>
                    </Column>
                </DataTable>
            </section>

            <section class="locations-panel">
                <div class="locations-panel__head">
                    <div>
                        <h2>Pasillos</h2>
                        <span>{{ tableState.aisle.total }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('aisle')"
                    />
                </div>
                <div class="locations-search">
                    <span class="locations-search__field">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="aisleFilters['global'].value"
                            placeholder="Buscar pasillo..."
                        />
                    </span>
                    <Button
                        icon="pi pi-times"
                        label="Limpiar"
                        severity="secondary"
                        outlined
                        @click="clearLocationFilter(aisleFilters, 'aisle')"
                    />
                </div>
                <DataTable
                    :value="aisles"
                    :loading="loading"
                    :paginator="true"
                    lazy
                    :first="tableFirst('aisle')"
                    :rows="tableState.aisle.perPage"
                    :totalRecords="tableState.aisle.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    @page="onTablePage('aisle', $event)"
                >
                    <template #empty>
                        <div class="locations-empty">
                            Sin pasillos registrados
                        </div>
                    </template>
                    <Column field="office_name" header="Oficina" />
                    <Column field="name" header="Nombre" />
                    <Column field="code" header="Código" />
                    <Column header="Estado">
                        <template #body="{ data }">
                            <span
                                :class="
                                    data.is_active
                                        ? 'locations-status locations-status--active'
                                        : 'locations-status'
                                "
                            >
                                {{ data.is_active ? "Activo" : "Inactivo" }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Acciones" bodyClass="locations-actions">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" text rounded aria-label="Editar pasillo" @click="openEdit('aisle', data)" />
                            <Button icon="pi pi-trash" rounded severity="danger" class="locations-delete-button" aria-label="Eliminar pasillo" @click="deleteLocation('aisle', data)" />
                        </template>
                    </Column>
                </DataTable>
            </section>

            <section class="locations-panel">
                <div class="locations-panel__head">
                    <div>
                        <h2>Estantes</h2>
                        <span>{{ tableState.shelf.total }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('shelf')"
                    />
                </div>
                <div class="locations-search">
                    <span class="locations-search__field">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="shelfFilters['global'].value"
                            placeholder="Buscar estante..."
                        />
                    </span>
                    <Button
                        icon="pi pi-times"
                        label="Limpiar"
                        severity="secondary"
                        outlined
                        @click="clearLocationFilter(shelfFilters, 'shelf')"
                    />
                </div>
                <DataTable
                    :value="shelves"
                    :loading="loading"
                    :paginator="true"
                    lazy
                    :first="tableFirst('shelf')"
                    :rows="tableState.shelf.perPage"
                    :totalRecords="tableState.shelf.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    @page="onTablePage('shelf', $event)"
                >
                    <template #empty>
                        <div class="locations-empty">
                            Sin estantes registrados
                        </div>
                    </template>
                    <Column field="office_name" header="Oficina" />
                    <Column field="aisle_name" header="Pasillo" />
                    <Column field="name" header="Nombre" />
                    <Column field="code" header="Código" />
                    <Column header="Estado">
                        <template #body="{ data }">
                            <span
                                :class="
                                    data.is_active
                                        ? 'locations-status locations-status--active'
                                        : 'locations-status'
                                "
                            >
                                {{ data.is_active ? "Activo" : "Inactivo" }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Acciones" bodyClass="locations-actions">
                        <template #body="{ data }">
                            <Button icon="pi pi-pencil" text rounded aria-label="Editar estante" @click="openEdit('shelf', data)" />
                            <Button icon="pi pi-trash" rounded severity="danger" class="locations-delete-button" aria-label="Eliminar estante" @click="deleteLocation('shelf', data)" />
                        </template>
                    </Column>
                </DataTable>
            </section>
        </main>

        <Dialog
            v-model:visible="dialogVisible"
            :header="dialogTitle"
            modal
            :style="{ width: '520px', maxWidth: '92vw' }"
        >
            <div class="locations-form">
                <label v-if="activeType === 'aisle' || activeType === 'shelf'">
                    <span>Oficina</span>
                    <Dropdown
                        v-model="form.office_id"
                        :options="officeOptions"
                        optionLabel="label"
                        optionValue="id"
                        placeholder="Seleccionar oficina"
                        class="w-full"
                    />
                </label>
                <label v-if="activeType === 'shelf'">
                    <span>Pasillo</span>
                    <Dropdown
                        v-model="form.aisle_id"
                        :options="filteredAisleOptions"
                        optionLabel="label"
                        optionValue="id"
                        placeholder="Seleccionar pasillo"
                        class="w-full"
                        :disabled="!form.office_id"
                    />
                </label>
                <label>
                    <span>Nombre</span>
                    <InputText
                        v-model="form.name"
                        class="w-full"
                        :maxlength="textFieldMaxLength"
                        placeholder="Nombre visible"
                    />
                    <small class="locations-field-counter">
                        {{ characterCounter(form.name) }}/{{ textFieldMaxLength }}
                    </small>
                </label>
                <label>
                    <span>Código</span>
                    <InputText
                        v-model="form.code"
                        class="w-full"
                        :maxlength="textFieldMaxLength"
                        placeholder="Código para ubicación"
                    />
                    <small class="locations-field-counter">
                        {{ characterCounter(form.code) }}/{{ textFieldMaxLength }}
                    </small>
                </label>
                <div class="locations-status-field">
                    <span class="locations-status-field__label">Estado</span>
                    <div
                        :class="[
                            'locations-status-toggle',
                            form.is_active ? 'is-active' : 'is-inactive',
                        ]"
                    >
                        <ToggleSwitch v-model="form.is_active" />
                        <span class="locations-status-toggle__text">
                            {{ form.is_active ? "Activo" : "Inactivo" }}
                        </span>
                    </div>
                </div>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="saving"
                    @click="dialogVisible = false"
                />
                <Button
                    icon="pi pi-save"
                    label="Guardar"
                    :loading="saving"
                    @click="saveLocation"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="deleteDialogVisible"
            modal
            :closable="!deleting"
            :dismissableMask="!deleting"
            :style="{ width: '440px', maxWidth: '92vw' }"
            class="locations-delete-dialog"
        >
            <template #header>
                <div class="locations-delete-dialog__header">
                    <span class="locations-delete-dialog__icon">
                        <i class="pi pi-exclamation-triangle"></i>
                    </span>
                    <span>Confirmar eliminacion</span>
                </div>
            </template>

            <div class="locations-delete-dialog__body" v-if="pendingDelete">
                <p>
                    Se eliminara la {{ deleteTargetTitle }}
                    <strong>"{{ pendingDelete.item.name }}"</strong>.
                </p>
                <p>Esta accion no se puede revertir.</p>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="deleting"
                    @click="closeDeleteDialog"
                />
                <Button
                    label="Aceptar"
                    severity="danger"
                    :loading="deleting"
                    @click="confirmDeleteLocation"
                />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.locations-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.locations-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.locations-eyebrow {
    color: #2563eb;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
}

.locations-header h1 {
    color: #12365f;
    font-size: 2rem;
    margin: 0.2rem 0;
}

.locations-header p {
    color: #53657d;
    margin: 0;
}

.locations-grid {
    display: grid;
    gap: 1.25rem;
}

.locations-panel {
    background: #ffffff;
    border: 1px solid #d8e1ed;
    border-radius: 8px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
    padding: 1.25rem;
}

.locations-panel__head {
    align-items: center;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
}

.locations-panel__head h2 {
    color: #0f172a;
    font-size: 1.15rem;
    margin: 0;
}

.locations-panel__head span {
    color: #64748b;
    font-size: 0.85rem;
}

.locations-search {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.locations-search__field {
    align-items: center;
    display: flex;
    flex: 1;
    min-width: 0;
    position: relative;
}

.locations-search__field i {
    color: #64748b;
    left: 0.9rem;
    position: absolute;
    z-index: 1;
}

.locations-search__field :deep(.p-inputtext) {
    padding-left: 2.35rem;
    width: 100%;
}

.locations-empty {
    color: #64748b;
    font-weight: 600;
    padding: 1rem;
    text-align: center;
}

.locations-status {
    background: #fee2e2;
    border-radius: 999px;
    color: #991b1b;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0.25rem 0.65rem;
}

.locations-status--active {
    background: #d1fae5;
    color: #047857;
}

:deep(.locations-actions) {
    white-space: nowrap;
}

:deep(.locations-actions .p-button + .p-button) {
    margin-left: 0.65rem;
}

.locations-delete-button,
.locations-delete-button :deep(.p-button-icon) {
    color: #ffffff !important;
}

.locations-delete-button {
    background: #dc2626 !important;
    border-color: #dc2626 !important;
    min-height: 2.5rem;
    min-width: 2.5rem;
}

.locations-delete-button:hover,
.locations-delete-button:focus-visible {
    background: #b91c1c !important;
    border-color: #b91c1c !important;
}

.locations-form {
    display: grid;
    gap: 1rem;
}

.locations-form label {
    display: grid;
    gap: 0.4rem;
}

.locations-form label > span {
    color: #1f2937;
    font-size: 0.9rem;
    font-weight: 700;
}

.locations-field-counter {
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 600;
    justify-self: end;
    line-height: 1;
}

.locations-status-field {
    display: grid;
    gap: 0.45rem;
}

.locations-status-field__label {
    color: #1f2937;
    font-size: 0.9rem;
    font-weight: 700;
}

.locations-status-toggle {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    display: flex;
    gap: 0.75rem;
    min-height: 2.75rem;
    padding: 0.65rem 0.85rem;
}

.locations-status-toggle__text {
    color: #475569;
    font-size: 0.92rem;
    font-weight: 700;
}

.locations-status-toggle.is-active .locations-status-toggle__text {
    color: #047857;
}

.locations-status-toggle.is-inactive .locations-status-toggle__text {
    color: #b91c1c;
}

.locations-delete-dialog__header {
    align-items: center;
    color: #111827;
    display: flex;
    font-weight: 700;
    gap: 0.75rem;
}

.locations-delete-dialog__icon {
    align-items: center;
    background: #fee2e2;
    border-radius: 999px;
    color: #dc2626;
    display: inline-flex;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.locations-delete-dialog__body {
    display: grid;
    gap: 0.65rem;
}

.locations-delete-dialog__body p {
    color: #475569;
    line-height: 1.5;
    margin: 0;
}

.locations-delete-dialog__body strong {
    color: #111827;
    font-weight: 700;
}

:deep(.locations-status-toggle.is-active .p-toggleswitch-slider) {
    background: #10b981;
}

:deep(.locations-status-toggle.is-inactive .p-toggleswitch-slider) {
    background: #cbd5e1;
}

@media (max-width: 768px) {
    .locations-panel {
        padding: 1rem;
    }

    .locations-search {
        align-items: stretch;
        flex-direction: column;
    }
}
</style>
