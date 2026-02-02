<script setup>
import { ref, onMounted } from 'vue';
import AdminLayout from '../layout/AdminLayout.vue';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

// --- VARIABLES ---
const toast = useToast();
const users = ref([]);
const unitsList = ref([]); 
const loading = ref(true);
const isSaving = ref(false);

const rolesList = ref([
    { label: 'Administrador', value: 'Administrador' },
    { label: 'Docente', value: 'Docente' },
    { label: 'Estudiante', value: 'Estudiante' },
    { label: 'Jefe de Unidad', value: 'Jefe de Unidad' },
    { label: 'Secretaria', value: 'Secretaria' }
]);

const rowsPerPageOptions = ref([5, 10, 25, 50]);
const filters = ref({
    global: { value: null, matchMode: 'contains' }
});

// Modal
const displayModal = ref(false);
const modalHeader = ref('');
const isEditing = ref(false);

// --- ERRORES DE VALIDACIÓN ---
const emailError = ref(''); 
const emailFormatError = ref(''); 
const carnetError = ref(''); // Nuevo error para Carnet
const nameErrors = ref({}); 

const form = ref({
    id: null,
    first_name: '',
    second_name: '',
    first_last_name: '',
    second_last_name: '',
    carnet: '',
    email: '',
    unit_id: null,
    role: null,
    is_active: true
});

// --- CARGAR DATOS ---
const fetchData = async () => {
    loading.value = true;
    try {
        const [usersRes, unitsRes] = await Promise.all([
            axios.get('/api/users'),
            axios.get('/api/units')
        ]);
        users.value = usersRes.data;
        unitsList.value = unitsRes.data.map(u => ({ label: u.name, value: u.id }));
    } catch (error) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudieron cargar los datos', life: 3000 });
    } finally {
        loading.value = false;
    }
};

onMounted(fetchData);

// --- VALIDACIONES EN TIEMPO REAL ---

// 1. CARNET: Mayúsculas automáticas + Validación de caracteres prohibidos
const validateCarnet = () => {
    if (!form.value.carnet) {
        carnetError.value = '';
        return;
    }
    
    // Convertimos a mayúsculas para ayudar al usuario, pero NO borramos caracteres especiales
    form.value.carnet = form.value.carnet.toUpperCase();

    // Regex: Busca cualquier cosa que NO sea letra (A-Z) ni número (0-9)
    const invalidCharsRegex = /[^A-Z0-9]/;

    if (invalidCharsRegex.test(form.value.carnet)) {
        carnetError.value = 'Caracteres no válidos detectados. Por favor bórrelos.';
    } else {
        carnetError.value = '';
    }
};

// 2. NOMBRES: Solo letras y espacios (Validación visual)
const validateNameField = (field) => {
    const value = form.value[field];
    if (!value) {
        nameErrors.value[field] = '';
        return;
    }
    // Regex: Permite letras a-z, A-Z, tildes, ñ/Ñ y espacios.
    const validNameRegex = /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/;
    
    if (!validNameRegex.test(value)) {
        nameErrors.value[field] = 'Solo se permiten letras y espacios.';
    } else {
        nameErrors.value[field] = '';
    }
};

// 3. CORREO: Formato válido
const validateEmailFormat = () => {
    const email = form.value.email;
    if (!email) {
        emailFormatError.value = '';
        return true;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (!emailRegex.test(email)) {
        emailFormatError.value = 'El formato del correo no es válido.';
        return false;
    } else {
        emailFormatError.value = '';
        return true;
    }
};

// --- MODALES ---
const openCreateModal = () => {
    isEditing.value = false;
    modalHeader.value = 'Crear Nuevo Usuario';
    emailError.value = '';
    emailFormatError.value = '';
    carnetError.value = '';
    nameErrors.value = {}; 
    
    form.value = { 
        first_name: '', second_name: '', 
        first_last_name: '', second_last_name: '', 
        carnet: '', email: '', unit_id: null, role: null, is_active: true 
    };
    displayModal.value = true;
};

const openEditModal = (user) => {
    isEditing.value = true;
    modalHeader.value = 'Editar Usuario';
    emailError.value = '';
    emailFormatError.value = '';
    carnetError.value = '';
    nameErrors.value = {};

    form.value = {
        id: user.id,
        first_name: user.first_name,
        second_name: user.second_name,
        first_last_name: user.first_last_name,
        second_last_name: user.second_last_name,
        carnet: user.carnet,
        email: user.email,
        unit_id: user.unit_id,
        role: user.role,
        is_active: Boolean(user.is_active)
    };
    displayModal.value = true;
};

// --- GUARDAR ---
const saveUser = async () => {
    if (isSaving.value) return;

    // 1. Validar Campos Obligatorios
    if (!form.value.first_name || !form.value.first_last_name || !form.value.email || !form.value.unit_id || !form.value.role) {
        toast.add({ severity: 'warn', summary: 'Atención', detail: 'Complete los campos obligatorios (*)', life: 3000 });
        return;
    }

    // 2. Validar Carnet (Si hay error, no guardar)
    if (carnetError.value) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Corrija el Carnet (solo letras y números)', life: 3000 });
        return;
    }

    // 3. Validar Formato de Correo
    if (!validateEmailFormat()) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Corrija el formato del correo', life: 3000 });
        return;
    }

    // 4. Verificar si hay errores en los nombres
    const hasNameErrors = Object.values(nameErrors.value).some(error => error !== '');
    if (hasNameErrors) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Hay caracteres inválidos en los nombres', life: 3000 });
        return;
    }

    isSaving.value = true;
    emailError.value = '';

    try {
        if (isEditing.value) {
            await axios.put(`/api/users/${form.value.id}`, form.value);
            toast.add({ severity: 'success', summary: 'Actualizado', detail: 'Usuario actualizado correctamente', life: 3000 });
        } else {
            await axios.post('/api/users', form.value);
            toast.add({ severity: 'success', summary: 'Creado', detail: 'Usuario creado y credenciales enviadas', life: 3000 });
        }
        displayModal.value = false;
        fetchData(); 
    } catch (error) {
        if (error.response && error.response.status === 422) {
            const errors = error.response.data.errors;
            if (errors.email) {
                emailError.value = 'Este correo electrónico ya está registrado.';
                toast.add({ severity: 'error', summary: 'Error', detail: 'El correo ya existe', life: 3000 });
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
    <AdminLayout>
        <Toast />
        <div class="mb-4">
            <h1 class="text-2xl font-bold" style="color: #1560bd;">Gestión de Usuarios</h1>
        </div>

        <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
            <div class="flex flex-column md:flex-row justify-content-between gap-3 align-items-center">
                <div class="flex w-full md:w-8 gap-2">
                    <div class="relative w-full">
                        <i class="pi pi-search absolute text-gray-500" style="top: 50%; left: 1rem; transform: translateY(-50%); z-index: 1;"></i>
                        <InputText v-model="filters['global'].value" placeholder="Buscar usuario..." class="w-full border-gray-300" style="padding-left: 2.5rem;" /> 
                    </div>
                    <Button icon="pi pi-times" label="Limpiar" severity="secondary" outlined class="w-auto px-3 flex-shrink-0" @click="clearFilter" />
                </div>
                <div class="flex w-full md:w-auto justify-content-end">
                    <Button label="Nuevo Usuario" icon="pi pi-user-plus" class="w-auto px-4 font-bold border-none" style="background-color: #1560bd;" @click="openCreateModal" />
                </div>
            </div>
        </div>

        <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
            <DataTable :value="users" :paginator="true" :rows="5" :rowsPerPageOptions="rowsPerPageOptions" :loading="loading" 
                       v-model:filters="filters" :globalFilterFields="['first_name', 'first_last_name', 'email', 'carnet']" 
                       tableStyle="min-width: 60rem" rowHover stripedRows>
                
                <template #empty>
                    <div class="text-center p-4 text-gray-500 font-medium">Sin usuarios registrados</div>
                </template>

                <Column field="carnet" header="Carnet" style="width: 10%" headerClass="bg-primary-custom text-white font-semibold">
                    <template #body="slotProps">{{ slotProps.data.carnet || 'N/A' }}</template>
                </Column>

                <Column header="Nombre Completo" sortable field="first_name" style="width: 25%" headerClass="bg-primary-custom text-white font-semibold">
                    <template #body="slotProps">
                        <div class="font-medium text-gray-900">
                            {{ slotProps.data.first_name }} {{ slotProps.data.first_last_name }}
                        </div>
                        <small class="text-gray-500">{{ slotProps.data.email }}</small>
                    </template>
                </Column>

                <Column header="Unidad / Rol" style="width: 25%" headerClass="bg-primary-custom text-white font-semibold">
                    <template #body="slotProps">
                        <div class="flex flex-column gap-1">
                            <span class="text-sm font-bold text-gray-700">{{ slotProps.data.unit ? slotProps.data.unit.name : 'Sin Unidad' }}</span>
                            <span class="bg-blue-50 text-blue-600 px-2 py-1 border-round text-xs font-bold w-max">
                                {{ slotProps.data.role }}
                            </span>
                        </div>
                    </template>
                </Column>
                
                <Column field="is_active" header="Estado" style="width: 10%" headerClass="bg-primary-custom text-white font-semibold">
                    <template #body="slotProps">
                        <span :class="['px-3 py-1 border-round-2xl text-xs font-bold', slotProps.data.is_active ? 'bg-green-100 text-green-600' : 'bg-gray-200 text-gray-600']">
                            {{ slotProps.data.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </template>
                </Column>

                <Column header="Acciones" style="width: 10%; text-align: center" headerClass="bg-primary-custom text-white">
                    <template #body="slotProps">
                        <Button icon="pi pi-pencil" text rounded severity="secondary" class="text-gray-600 hover:text-blue-600" @click="openEditModal(slotProps.data)" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="displayModal" :modal="true" :draggable="false" :header="modalHeader" :style="{ width: '700px' }" class="p-fluid blue-header-dialog">
            
            <div class="grid formgrid p-2">
                
                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Primer Nombre *</label>
                    <InputText v-model="form.first_name" 
                               @input="validateNameField('first_name')" 
                               :class="{ 'p-invalid': nameErrors.first_name }"
                               class="w-full bg-gray-50" placeholder="Ej: Juan" maxlength="30" />
                    <div class="flex justify-content-between">
                        <small v-if="nameErrors.first_name" class="p-error block">{{ nameErrors.first_name }}</small>
                        <small class="text-gray-500 text-xs">{{ form.first_name ? form.first_name.length : 0 }}/30</small>
                    </div>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Segundo Nombre</label>
                    <InputText v-model="form.second_name" 
                               @input="validateNameField('second_name')"
                               :class="{ 'p-invalid': nameErrors.second_name }"
                               class="w-full bg-gray-50" placeholder="Ej: Carlos" maxlength="30" />
                    <div class="flex justify-content-between">
                         <small v-if="nameErrors.second_name" class="p-error block">{{ nameErrors.second_name }}</small>
                         <small class="text-gray-500 text-xs">{{ form.second_name ? form.second_name.length : 0 }}/30</small>
                    </div>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Primer Apellido *</label>
                    <InputText v-model="form.first_last_name" 
                               @input="validateNameField('first_last_name')"
                               :class="{ 'p-invalid': nameErrors.first_last_name }"
                               class="w-full bg-gray-50" placeholder="Ej: Pérez" maxlength="30" />
                    <div class="flex justify-content-between">
                        <small v-if="nameErrors.first_last_name" class="p-error block">{{ nameErrors.first_last_name }}</small>
                        <small class="text-gray-500 text-xs">{{ form.first_last_name ? form.first_last_name.length : 0 }}/30</small>
                    </div>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Segundo Apellido</label>
                    <InputText v-model="form.second_last_name" 
                               @input="validateNameField('second_last_name')"
                               :class="{ 'p-invalid': nameErrors.second_last_name }"
                               class="w-full bg-gray-50" placeholder="Ej: López" maxlength="30" />
                    <div class="flex justify-content-between">
                        <small v-if="nameErrors.second_last_name" class="p-error block">{{ nameErrors.second_last_name }}</small>
                        <small class="text-gray-500 text-xs">{{ form.second_last_name ? form.second_last_name.length : 0 }}/30</small>
                    </div>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Carnet</label>
                    <InputText v-model="form.carnet" 
                               @input="validateCarnet"
                               :class="{ 'p-invalid': carnetError }"
                               class="w-full bg-gray-50" placeholder="Ej: AA12345" maxlength="20" />
                    <small v-if="carnetError" class="p-error block">{{ carnetError }}</small>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Correo Electrónico *</label>
                    <InputText v-model="form.email" 
                               @input="validateEmailFormat"
                               :class="{ 'p-invalid': emailError || emailFormatError }"
                               class="w-full bg-gray-50" placeholder="correo@ues.edu.sv" />
                    
                    <small v-if="emailFormatError" class="p-error block">{{ emailFormatError }}</small>
                    <small v-if="emailError" class="p-error block">{{ emailError }}</small>
                    
                    <small v-if="!isEditing && !emailFormatError && !emailError" class="text-gray-500 text-xs block mt-1">
                        Se enviarán las credenciales a este correo.
                    </small>
                </div>

                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Unidad *</label>
                    <Dropdown v-model="form.unit_id" :options="unitsList" optionLabel="label" optionValue="value" filter placeholder="Seleccione Unidad" class="w-full bg-gray-50" />
                </div>
                <div class="col-12 md:col-6 field mb-3">
                    <label class="font-medium" style="color: #1560bd;">Rol de Usuario *</label>
                    <Dropdown v-model="form.role" :options="rolesList" optionLabel="label" optionValue="value" placeholder="Seleccione Rol" class="w-full bg-gray-50" />
                </div>

                <div class="col-12 field mb-3" v-if="isEditing">
                    <label class="font-medium" style="color: #1560bd;">Estado</label>
                    <Dropdown v-model="form.is_active" :options="[{label:'Activo', value:true}, {label:'Inactivo', value:false}]" optionLabel="label" optionValue="value" class="w-full bg-gray-50" />
                </div>
            </div>

            <template #footer>
                <div class="flex justify-content-between gap-3 mt-2 w-full">
                    <Button label="Cancelar" severity="secondary" outlined class="flex-1" :disabled="isSaving" @click="displayModal = false" />
                    <Button label="Guardar Usuario" class="flex-1 border-none" style="background-color: #1560bd;" :loading="isSaving" @click="saveUser" />
                </div>
            </template>
        </Dialog>
    </AdminLayout>
</template>

<style>
/* Estilos Globales para el Modal */
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
    background-color: #1560bd !important; border: none;
}
:deep(.p-datatable-thead > tr > th) {
    border-width: 0 0 1px 0; padding: 1rem;
}
.p-error { color: #e24c4c; font-size: 0.875rem; }
</style>