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
const dialogVisible = ref(false);
const activeType = ref("office");
const editingId = ref(null);
const rowsPerPageOptions = ref([5, 10, 25, 50]);

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

const officeOptions = computed(() =>
    offices.value.map((office) => ({
        ...office,
        label: `${office.name} (${office.code})`,
    })),
);

const aisleOptions = computed(() =>
    aisles.value.map((aisle) => ({
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

const fetchCatalog = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get("/api/physical-locations");
        offices.value = Array.isArray(data.offices) ? data.offices : [];
        aisles.value = Array.isArray(data.aisles) ? data.aisles : [];
        shelves.value = Array.isArray(data.shelves) ? data.shelves : [];
    } catch (error) {
        showError(
            error.response?.data?.message ??
                "No se pudieron cargar las ubicaciones fisicas.",
        );
    } finally {
        loading.value = false;
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
            detail: data.message ?? "Ubicacion fisica guardada correctamente.",
            life: 3000,
        });
        dialogVisible.value = false;
        await fetchCatalog();
    } catch (error) {
        showError(
            error.response?.data?.message ??
                "No se pudo guardar la ubicacion fisica.",
        );
    } finally {
        saving.value = false;
    }
};

const deleteLocation = async (type, item) => {
    if (!window.confirm(`Eliminar ${item.name}?`)) {
        return;
    }

    try {
        await axios.delete(`${typeConfig[type].endpoint}/${item.id}`);
        toast.add({
            severity: "success",
            summary: "Eliminado",
            detail: "Registro eliminado correctamente.",
            life: 2600,
        });
        await fetchCatalog();
    } catch (error) {
        showError(
            error.response?.data?.message ?? "No se pudo eliminar el registro.",
        );
    }
};

onMounted(fetchCatalog);

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
                        <span>{{ offices.length }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('office')"
                    />
                </div>
                <DataTable
                    :value="offices"
                    :loading="loading"
                    responsiveLayout="scroll"
                    stripedRows
                >
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
                        <span>{{ aisles.length }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('aisle')"
                    />
                </div>
                <DataTable
                    :value="aisles"
                    :loading="loading"
                    responsiveLayout="scroll"
                    stripedRows
                >
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
                        <span>{{ shelves.length }} registro(s)</span>
                    </div>
                    <Button
                        icon="pi pi-plus"
                        label="Agregar"
                        @click="openCreate('shelf')"
                    />
                </div>
                <DataTable
                    :value="shelves"
                    :loading="loading"
                    :paginator="true"
                    :rows="10"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                >
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
                        placeholder="Nombre visible"
                    />
                </label>
                <label>
                    <span>Código</span>
                    <InputText
                        v-model="form.code"
                        class="w-full"
                        placeholder="Código para ubicacion"
                    />
                </label>
                <label class="locations-check">
                    <Checkbox v-model="form.is_active" binary />
                    <span>Activo</span>
                </label>
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

.locations-actions {
    display: inline-flex;
    gap: 10px;
    white-space: nowrap;
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

.locations-check {
    align-items: center;
    display: flex !important;
    gap: 0.6rem !important;
}

@media (max-width: 768px) {
    .locations-panel {
        padding: 1rem;
    }
}
</style>
