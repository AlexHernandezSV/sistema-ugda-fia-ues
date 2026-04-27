<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

// --- VARIABLES DE ESTADO ---
const toast = useToast();
const units = ref([]);        
const loading = ref(true);    
const isSaving = ref(false); 

// Paginación
const rowsPerPageOptions = ref([5, 10, 25, 50]);

// Filtros
const filters = ref({
    global: { value: null, matchMode: 'contains' }
});

// --- VARIABLES DEL MODAL ---
const displayModal = ref(false);
const modalHeader = ref('');
const isEditing = ref(false);
const nameError = ref(''); 

const form = ref({
    id: null,
    name: '',
    is_active: true,
    parents: [] 
});

const availableUnits = ref([]); 
const selectedParent = ref(null); 

// --- 1. CARGAR DATOS ---
const fetchUnits = async () => {
    loading.value = true;
    try {
        const response = await axios.get('/api/units');
        units.value = response.data;
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar los datos', life: 3000 });
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
        nameError.value = 'No se permiten caracteres especiales ni emojis.';
        return false; 
    } else {
        nameError.value = ''; 
        return true; 
    }
};

// --- 3. MODALES ---
const openCreateModal = () => {
    isEditing.value = false;
    modalHeader.value = 'Agregar Nueva Unidad';
    form.value = { name: '', is_active: true, parents: [] };
    nameError.value = ''; 
    isSaving.value = false; 
    
    selectedParent.value = null;
    availableUnits.value = units.value.map(u => ({ label: u.name, value: u.id }));
    displayModal.value = true;
};

const openEditModal = (unit) => {
    isEditing.value = true;
    modalHeader.value = 'Editar Unidad';
    nameError.value = '';
    isSaving.value = false;

    form.value = {
        id: unit.id,
        name: unit.name,
        is_active: Boolean(unit.is_active),
        parents: unit.parents ? unit.parents.map(p => ({ label: p.name, value: p.id })) : []
    };
    selectedParent.value = null;
    availableUnits.value = units.value
        .filter(u => u.id !== unit.id)
        .map(u => ({ label: u.name, value: u.id }));
    displayModal.value = true;
};

// --- 4. DEPENDENCIAS ---
const addDependency = () => {
    if (!selectedParent.value) return;
    const exists = form.value.parents.some(p => p.value === selectedParent.value);
    
    if (!exists) {
        const parentObj = availableUnits.value.find(u => u.value === selectedParent.value);
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
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'El nombre es obligatorio', life: 3000 });
        return;
    }

    if (!validateName()) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Corrija los caracteres inválidos', life: 3000 });
        return;
    }

    isSaving.value = true; 

    const payload = {
        name: form.value.name,
        is_active: form.value.is_active,
        parents: form.value.parents.map(p => p.value)
    };

    try {
        if (isEditing.value) {
            await axios.put(`/api/units/${form.value.id}`, payload);
            toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Unidad editada correctamente', life: 3000 });
        } else {
            await axios.post('/api/units', payload);
            toast.add({ severity: 'success', summary: 'Creado', detail: 'Unidad creada correctamente', life: 3000 });
        }
        displayModal.value = false;
        fetchUnits(); 
    } catch (error) {
        console.error(error);
        if (error.response && error.response.status === 422) {
            const errors = error.response.data.errors;
            if (errors && errors.name) {
                // Si el error es por unicidad
                if (errors.name[0].includes('ya ha sido registrado') || errors.name[0].includes('taken')) {
                     nameError.value = 'Ya existe una unidad con este nombre.';
                } else if (errors.name[0].includes('must not be greater than')) {
                     // Si el error es por longitud (backup del backend)
                     nameError.value = 'El nombre no puede exceder los 100 caracteres.';
                } else {
                     nameError.value = errors.name[0];
                }
            }
        } else {
            toast.add({ severity: 'error', summary: 'Error', detail: 'Ocurrió un error al guardar', life: 3000 });
        }
    } finally {
        isSaving.value = false; 
    }
};

const clearFilter = () => {
    filters.value.global.value = null;
};
</script>

<template>
        <Toast />

        <div class="mb-4">
            <h1 class="text-2xl font-bold mobile-page-title" style="color: #1560bd;">Listado de unidades académicas y administrativas</h1>
        </div>

        <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
            <div class="filters-toolbar">
                <div class="relative w-full filters-toolbar__search">
                    <i class="pi pi-search absolute text-gray-500" 
                       style="top: 50%; left: 1rem; transform: translateY(-50%); z-index: 1;"></i>
                    
                    <InputText v-model="filters['global'].value" 
                               placeholder="Buscar..." 
                               class="w-full border-gray-300"
                               style="padding-left: 2.5rem;" /> 
                </div>

                <div class="filters-toolbar__actions">
                    <Button icon="pi pi-times" 
                            label="Limpiar"
                            severity="secondary" 
                            outlined 
                            class="w-auto px-3 flex-shrink-0 filters-toolbar__clear" 
                            @click="clearFilter" />

                    <Button label="Agregar" icon="pi pi-plus" 
                            class="w-auto px-4 font-bold border-none filters-toolbar__primary"
                            style="background-color: #1560bd;" 
                            @click="openCreateModal" />
                </div>
            </div>
        </div>

        <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
            <DataTable :value="units" :paginator="true" :rows="5" 
                       :rowsPerPageOptions="rowsPerPageOptions"
                       :loading="loading"
                       v-model:filters="filters"
                       :globalFilterFields="['name']"
                       tableStyle="min-width: 50rem"
                       rowHover
                       stripedRows>
                
                <template #empty>
                    <div class="text-center p-4 text-gray-500 font-medium">
                        Sin datos registrados
                    </div>
                </template>

                <!--<Column field="id" header="N°" style="width: 5%" headerClass="bg-primary-custom text-white"></Column>-->
                <Column field="name" header="Nombre de la Unidad/Escuela/Departamento" sortable style="width: 40%" headerClass="bg-primary-custom text-white font-semibold"></Column>
                
                <Column header="Dependencia jerárquica" style="width: 35%" headerClass="bg-primary-custom text-white font-semibold">
                    <template #body="slotProps">
                        <div v-if="slotProps.data.parents && slotProps.data.parents.length > 0" class="flex flex-wrap gap-2">
                            <span v-for="parent in slotProps.data.parents" :key="parent.id" 
                                  class="bg-blue-50 text-blue-600 px-2 py-1 border-round text-xs font-bold">
                                {{ parent.name }}
                            </span>
                        </div>
                        <span v-else class="text-sm text-gray-400 font-italic">Sin dependencias</span>
                    </template>
                </Column>
                
                <Column field="is_active" header="Estado" sortable style="width: 10%" headerClass="bg-primary-custom text-white font-semibold ugda-chip-header" bodyClass="ugda-chip-column">
                    <template #body="slotProps">
                        <div class="ugda-chip-cell">
                            <span :class="['px-3 py-1 border-round-2xl text-xs font-bold', slotProps.data.is_active ? 'bg-green-100 text-green-600' : 'bg-gray-200 text-gray-600']">
                                {{ slotProps.data.is_active ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                    </template>
                </Column>

                <Column header="Acciones" style="width: 10%; text-align: center" headerClass="bg-primary-custom text-white">
                    <template #body="slotProps">
                        <Button icon="pi pi-pencil" text rounded severity="secondary" 
                                class="text-gray-600 hover:text-blue-600"
                                @click="openEditModal(slotProps.data)" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="displayModal" 
                :modal="true" 
                :draggable="false" 
                :header="modalHeader" 
                :style="{ width: '500px' }" 
                class="p-fluid blue-header-dialog">
            
            <div class="field mt-3">
                <label for="name" class="font-medium" style="color: #1560bd;">Nombre de la Unidad/Escuela/Departamento *</label>
                
                <InputText id="name" v-model="form.name" 
                           @input="validateName" 
                           :class="{ 'p-invalid': nameError }"
                           class="w-full mt-1 bg-gray-50" 
                           maxlength="100"
                           placeholder="Ingrese el nombre" />
                
                <div class="flex justify-content-between mt-1">
                    <small class="p-error block">{{ nameError }}</small>
                    <small class="text-gray-500 text-xs">{{ form.name ? form.name.length : 0 }}/100</small>
                </div>
            </div>

            <div class="field mt-3">
                <label for="status" class="font-medium" style="color: #1560bd;">Estado *</label>
                <Dropdown id="status" v-model="form.is_active" 
                          :options="[{label: 'Activa', value: true}, {label: 'Inactiva', value: false}]" 
                          optionLabel="label" optionValue="value" 
                          class="w-full mt-1 bg-gray-50" />
            </div>

            <div class="field mt-3">
                <label class="font-medium" style="color: #1560bd;">Dependencias jerárquicas</label>
                <div class="flex gap-2 mt-1">
                    <Dropdown v-model="selectedParent" :options="availableUnits" 
                              optionLabel="label" optionValue="value" filter 
                              placeholder="Seleccione" class="w-full bg-gray-50" />
                    <Button icon="pi pi-plus" class="w-3rem flex-shrink-0 border-none" style="background-color: #1560bd;" @click="addDependency" />
                </div>
            </div>

            <div class="field mt-2">
                <div v-if="form.parents.length > 0" class="flex flex-column gap-2">
                    <div v-for="(parent, index) in form.parents" :key="index" 
                         class="flex align-items-center justify-content-between p-3 border-1 border-gray-200 border-round bg-white">
                        <span class="text-gray-800 text-sm font-medium">{{ parent.label }}</span>
                        <i class="pi pi-trash text-red-500 cursor-pointer hover:text-red-700" 
                           @click="removeDependency(index)"></i>
                    </div>
                </div>
                <div v-else class="text-gray-500 text-sm font-italic p-2">
                    No hay dependencias agregadas
                </div>
            </div>

            <template #footer>
                <div class="flex justify-content-between gap-3 mt-4 w-full">
                    <Button label="Cancelar" severity="secondary" outlined 
                            class="flex-1" 
                            :disabled="isSaving"
                            @click="displayModal = false" />

                    <Button label="Guardar" 
                            class="flex-1 border-none" 
                            style="background-color: #1560bd;" 
                            :loading="isSaving"
                            @click="saveUnit" />
                </div>
            </template>
        </Dialog>
</template>

<style>
/* Estilos globales para el modal */
.blue-header-dialog .p-dialog-header {
    border-bottom: none !important; 
    background-color: white !important;
    border-top-left-radius: 12px !important; 
    border-top-right-radius: 12px !important;
}

.blue-header-dialog .p-dialog-title {
    color: #1560bd !important; 
    font-weight: 700 !important;
    font-size: 1.25rem;
}

.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon {
    color: #6b7280 !important;
}
.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon:hover {
    background-color: #f3f4f6 !important;
    color: #1560bd !important;
}

.blue-header-dialog .p-dialog-content {
    border-bottom-left-radius: 12px !important;
    border-bottom-right-radius: 12px !important;
}
</style>

<style scoped>
:deep(.bg-primary-custom) {
    background-color: #1560bd !important; 
    border: none;
}
:deep(.p-datatable-thead > tr > th) {
    border-width: 0 0 1px 0;
    padding: 1rem;
}
.p-error {
    color: #e24c4c;
    font-size: 0.875rem;
}

.filters-toolbar {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.filters-toolbar__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
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
        grid-template-columns: minmax(0, 1fr) auto auto;
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
</style>
