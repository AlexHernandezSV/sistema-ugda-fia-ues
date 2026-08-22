<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import { useToast } from "primevue/usetoast";

// --- VARIABLES DE ESTADO ---
const router = useRouter();
const toast = useToast();
const units = ref([]);
const loading = ref(true);
const isSaving = ref(false);

const isImporting = ref(false);
const importFileInput = ref(null);

// Paginación
const rowsPerPageOptions = ref([5, 10, 25, 50]);

// Filtros
const filters = ref({
    global: { value: null, matchMode: "contains" },
});

// --- VARIABLES DEL MODAL ---
const displayModal = ref(false);
const modalHeader = ref("");
const isEditing = ref(false);
const nameError = ref("");

const form = ref({
    id: null,
    name: "",
    is_active: true,
    parents: [],
});

const availableUnits = ref([]);
const selectedParent = ref(null);

// --- 1. CARGAR DATOS ---
const fetchUnits = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/units");
        units.value = response.data;
    } catch (error) {
        if ([401, 419].includes(error.response?.status)) {
            return;
        }

        toast.add({
            severity: "error",
            summary: "Error",
            detail: "No se pudieron cargar los datos",
            life: 3000,
        });
    } finally {
        loading.value = false;
    }
};

onMounted(fetchUnits);

// --- 2. VALIDACIÓN VISUAL ---
const validateName = () => {
    const inputValue = form.value.name;
    const invalidChars = /[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑüÜ]/g;

    if (invalidChars.test(inputValue)) {
        nameError.value = "No se permiten caracteres especiales ni emojis.";
        return false;
    } else {
        nameError.value = "";
        return true;
    }
};

// --- 3. MODALES ---
const openCreateModal = () => {
    isEditing.value = false;
    modalHeader.value = "Agregar Nueva Unidad";
    form.value = { name: "", is_active: true, parents: [] };
    nameError.value = "";
    isSaving.value = false;

    selectedParent.value = null;
    availableUnits.value = units.value.map((u) => ({
        label: u.name,
        value: u.id,
    }));
    displayModal.value = true;
};

const openEditModal = (unit) => {
    isEditing.value = true;
    modalHeader.value = "Editar Unidad";
    nameError.value = "";
    isSaving.value = false;

    form.value = {
        id: unit.id,
        name: unit.name,
        is_active: Boolean(unit.is_active),
        parents: unit.parents
            ? unit.parents.map((p) => ({ label: p.name, value: p.id }))
            : [],
    };
    selectedParent.value = null;
    availableUnits.value = units.value
        .filter((u) => u.id !== unit.id)
        .map((u) => ({ label: u.name, value: u.id }));
    displayModal.value = true;
};

const goBack = () => {
    router.back();
};

// --- 4. DEPENDENCIAS ---
const addDependency = () => {
    if (!selectedParent.value) return;
    const exists = form.value.parents.some(
        (p) => p.value === selectedParent.value,
    );

    if (!exists) {
        const parentObj = availableUnits.value.find(
            (u) => u.value === selectedParent.value,
        );
        if (parentObj) {
            form.value.parents.push(parentObj);
        }
    }
    selectedParent.value = null;
};

const removeDependency = (index) => {
    form.value.parents.splice(index, 1);
};

// --- 5. GUARDAR ---
const saveUnit = async () => {
    if (isSaving.value) return;

    if (!form.value.name.trim()) {
        toast.add({
            severity: "warn",
            summary: "Atención",
            detail: "El nombre es obligatorio",
            life: 3000,
        });
        return;
    }

    if (!validateName()) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Corrija los caracteres inválidos",
            life: 3000,
        });
        return;
    }

    isSaving.value = true;

    const payload = {
        name: form.value.name,
        is_active: form.value.is_active,
        parents: form.value.parents.map((p) => p.value),
    };

    try {
        if (isEditing.value) {
            await axios.put(`/api/units/${form.value.id}`, payload);
            toast.add({
                severity: "success",
                summary: "Actualizado",
                detail: "Unidad editada correctamente",
                life: 3000,
            });
        } else {
            await axios.post("/api/units", payload);
            toast.add({
                severity: "success",
                summary: "Creado",
                detail: "Unidad creada correctamente",
                life: 3000,
            });
        }
        displayModal.value = false;
        fetchUnits();
    } catch (error) {
        console.error(error);
        if (error.response && error.response.status === 422) {
            const errors = error.response.data.errors;
            if (errors && errors.name) {
                // Si el error es por unicidad
                if (
                    errors.name[0].includes("ya ha sido registrado") ||
                    errors.name[0].includes("taken")
                ) {
                    nameError.value = "Ya existe una unidad con este nombre.";
                } else if (
                    errors.name[0].includes("must not be greater than")
                ) {
                    // Si el error es por longitud (backup del backend)
                    nameError.value =
                        "El nombre no puede exceder los 100 caracteres.";
                } else {
                    nameError.value = errors.name[0];
                }
            }
        } else {
            toast.add({
                severity: "error",
                summary: "Error",
                detail: "Ocurrió un error al guardar",
                life: 3000,
            });
        }
    } finally {
        isSaving.value = false;
    }
};

const downloadTemplate = () => {
    window.location.href = "/api/units/import-template";
};

const openImportFilePicker = () => {
    if (!isImporting.value) {
        importFileInput.value?.click();
    }
};

const importUnits = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    isImporting.value = true;
    const formData = new FormData();
    formData.append("file", file);

    try {
        const { data } = await axios.post("/api/units/import", formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        toast.add({
            severity: "success",
            summary: "Carga completada",
            detail: data.message,
            life: 5000,
        });
        await fetchUnits();
    } catch (error) {
        toast.add({
            severity: "error",
            summary: "No se pudo cargar el archivo",
            detail: error.response?.data?.message || "Revise la plantilla e intente nuevamente.",
            life: 7000,
        });
    } finally {
        isImporting.value = false;
    }
};

const clearFilter = () => {
    filters.value.global.value = null;
};
</script>

<template>
    <Toast />

    <input
        ref="importFileInput"
        type="file"
        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        class="units-import-input"
        @change="importUnits"
    />

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
                    Gestión de Unidades Productoras
                </h1>
                <p class="reports-hero__subtitle m-0">
                    Administrar de las unidades productoras de documentos, sus
                    dependencias y estado
                </p>
            </div>
        </section>
    </div>

    <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
        <div class="filters-toolbar">
            <div class="relative w-full filters-toolbar__search">
                <i
                    class="pi pi-search absolute text-gray-500"
                    style="
                        top: 50%;
                        left: 1rem;
                        transform: translateY(-50%);
                        z-index: 1;
                    "
                ></i>

                <InputText
                    v-model="filters['global'].value"
                    placeholder="Buscar..."
                    class="w-full border-gray-300"
                    style="padding-left: 2.5rem"
                />
            </div>

            <div class="filters-toolbar__actions">
                <Button
                    icon="pi pi-times"
                    label="Limpiar"
                    severity="secondary"
                    outlined
                    class="w-auto px-3 flex-shrink-0 filters-toolbar__clear"
                    @click="clearFilter"
                />

                <Button
                    label="Descargar plantilla"
                    icon="pi pi-download"
                    severity="secondary"
                    outlined
                    class="w-auto px-3 flex-shrink-0 filters-toolbar__template"
                    @click="downloadTemplate"
                />

                <Button
                    label="Cargar registros"
                    icon="pi pi-upload"
                    severity="secondary"
                    outlined
                    class="w-auto px-3 flex-shrink-0 filters-toolbar__import"
                    :loading="isImporting"
                    @click="openImportFilePicker"
                />

                <Button
                    label="Agregar"
                    icon="pi pi-plus"
                    class="w-auto px-4 border-none filters-toolbar__primary"
                    @click="openCreateModal"
                />
            </div>
        </div>
    </div>

    <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
        <DataTable
            :value="units"
            :paginator="true"
            :rows="5"
            :rowsPerPageOptions="rowsPerPageOptions"
            :loading="loading"
            v-model:filters="filters"
            :globalFilterFields="['name']"
            tableStyle="min-width: 50rem"
            rowHover
            stripedRows
        >
            <template #empty>
                <div class="text-center p-4 text-gray-500 font-medium">
                    Sin datos registrados
                </div>
            </template>

            <!--<Column field="id" header="N°" style="width: 5%" headerClass="bg-primary-custom text-white"></Column>-->
            <Column
                field="name"
                header="Nombre de la Unidad/Escuela/Departamento"
                sortable
                style="width: 40%"
                headerClass="bg-primary-custom text-white font-semibold"
            ></Column>

            <Column
                header="Dependencia jerárquica"
                style="width: 35%"
                headerClass="bg-primary-custom text-white font-semibold"
            >
                <template #body="slotProps">
                    <div
                        v-if="
                            slotProps.data.parents &&
                            slotProps.data.parents.length > 0
                        "
                        class="flex flex-wrap gap-2"
                    >
                        <span
                            v-for="parent in slotProps.data.parents"
                            :key="parent.id"
                            class="ugda-chip ugda-chip--info"
                        >
                            {{ parent.name }}
                        </span>
                    </div>
                    <span v-else class="text-sm text-gray-400 font-italic"
                        >Sin dependencias</span
                    >
                </template>
            </Column>

            <Column
                field="is_active"
                header="Estado"
                sortable
                style="width: 10%"
                headerClass="bg-primary-custom text-white font-semibold ugda-chip-header"
                bodyClass="ugda-chip-column"
            >
                <template #body="slotProps">
                    <div class="ugda-chip-cell">
                        <span
                            :class="[
                                'ugda-chip',
                                slotProps.data.is_active
                                    ? 'ugda-chip--success'
                                    : 'ugda-chip--neutral',
                            ]"
                        >
                            {{
                                slotProps.data.is_active ? "Activa" : "Inactiva"
                            }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column
                header="Acciones"
                style="width: 10%; text-align: center"
                headerClass="bg-primary-custom text-white"
            >
                <template #body="slotProps">
                    <Button
                        icon="pi pi-pencil"
                        text
                        rounded
                        severity="secondary"
                        class="text-gray-600 hover:text-blue-600"
                        @click="openEditModal(slotProps.data)"
                    />
                </template>
            </Column>
        </DataTable>
    </div>

    <Dialog
        v-model:visible="displayModal"
        :modal="true"
        :draggable="false"
        :header="modalHeader"
        :style="{ width: '500px' }"
        class="p-fluid blue-header-dialog"
    >
        <div class="field mt-3">
            <label for="name" class="form-label"
                >Nombre de la Unidad/Escuela/Departamento *</label
            >

            <InputText
                id="name"
                v-model="form.name"
                @input="validateName"
                :class="{ 'p-invalid': nameError }"
                class="w-full mt-1 bg-gray-50"
                maxlength="100"
                placeholder="Ingrese el nombre"
            />

            <div class="flex justify-content-between mt-1">
                <small class="p-error block">{{ nameError }}</small>
                <small class="text-gray-500 text-xs"
                    >{{ form.name ? form.name.length : 0 }}/100</small
                >
            </div>
        </div>

        <div class="field mt-3">
            <label for="status" class="form-label">Estado *</label>
            <Dropdown
                id="status"
                v-model="form.is_active"
                :options="[
                    { label: 'Activa', value: true },
                    { label: 'Inactiva', value: false },
                ]"
                optionLabel="label"
                optionValue="value"
                class="w-full mt-1 bg-gray-50"
            />
        </div>

        <div class="field mt-3">
            <label class="form-label">Dependencias jerárquicas</label>
            <div class="flex gap-2 mt-1">
                <Dropdown
                    v-model="selectedParent"
                    :options="availableUnits"
                    optionLabel="label"
                    optionValue="value"
                    filter
                    placeholder="Seleccione"
                    class="w-full bg-gray-50"
                />
                <Button
                    icon="pi pi-plus"
                    class="w-3rem flex-shrink-0 border-none"
                    @click="addDependency"
                />
            </div>
        </div>

        <div class="field mt-2">
            <div v-if="form.parents.length > 0" class="flex flex-column gap-2">
                <div
                    v-for="(parent, index) in form.parents"
                    :key="index"
                    class="flex align-items-center justify-content-between p-3 border-1 border-gray-200 border-round bg-white"
                >
                    <span class="text-gray-800 text-sm font-medium">{{
                        parent.label
                    }}</span>
                    <i
                        class="pi pi-trash text-red-500 cursor-pointer hover:text-red-700"
                        @click="removeDependency(index)"
                    ></i>
                </div>
            </div>
            <div v-else class="text-gray-500 text-sm font-italic p-2">
                No hay dependencias agregadas
            </div>
        </div>

        <template #footer>
            <div class="flex justify-content-between gap-3 mt-4 w-full">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="flex-1"
                    :disabled="isSaving"
                    @click="displayModal = false"
                />

                <Button
                    label="Guardar"
                    class="flex-1 border-none"
                    :loading="isSaving"
                    @click="saveUnit"
                />
            </div>
        </template>
    </Dialog>
</template>

<style>
/* Estilos globales para el modal */
.blue-header-dialog .p-dialog-header {
    border-bottom: none !important;
    background-color: var(--ugda-surface-card) !important;
    border-top-left-radius: var(--ugda-radius-lg) !important;
    border-top-right-radius: var(--ugda-radius-lg) !important;
}

.blue-header-dialog .p-dialog-title {
    color: var(--ugda-brand) !important;
    font-weight: var(--ugda-weight-semibold) !important;
    font-size: var(--ugda-font-h3);
}

.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon {
    color: var(--ugda-text-secondary) !important;
}
.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon:hover {
    background-color: var(--ugda-surface-soft) !important;
    color: var(--ugda-brand) !important;
}

.blue-header-dialog .p-dialog-content {
    border-bottom-left-radius: var(--ugda-radius-lg) !important;
    border-bottom-right-radius: var(--ugda-radius-lg) !important;
}
</style>

<style scoped>
:deep(.bg-primary-custom) {
    background-color: var(--ugda-brand) !important;
    border: none;
}
:deep(.p-datatable-thead > tr > th) {
    border-width: 0 0 1px 0;
    padding: 1rem;
}
.p-error {
    color: var(--ugda-error);
    font-size: var(--ugda-font-helper);
}

.ugda-chip {
    border-radius: 999px;
    display: inline-flex;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
    line-height: 1.2;
    padding: var(--ugda-space-xs) var(--ugda-space-sm);
}

.ugda-chip--info {
    background: var(--ugda-info-soft);
    color: var(--ugda-info-text);
}

.ugda-chip--success {
    background: var(--ugda-success-soft);
    color: var(--ugda-success-text);
}

.ugda-chip--neutral {
    background: var(--ugda-surface-soft);
    color: var(--ugda-text-secondary);
}

.filters-toolbar {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.filters-toolbar__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    justify-content: space-between;
}

.filters-toolbar__primary {
    margin-left: auto;
}

@media (max-width: 991px) {
    .filters-toolbar__actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        width: 100%;
    }

    .filters-toolbar__clear,
    .filters-toolbar__primary {
        width: 100%;
        margin-left: 0;
        justify-content: center;
    }
}

@media (min-width: 992px) {
    .filters-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) repeat(4, auto);
        align-items: center;
        gap: 0.75rem;
    }

    .filters-toolbar__actions {
        display: contents;
    }

    .filters-toolbar__primary {
        margin-left: 0;
    }
}
.units-import-input {
    display: none;
}

</style>
