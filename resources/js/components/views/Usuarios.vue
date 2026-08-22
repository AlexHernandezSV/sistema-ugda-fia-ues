<script setup>
import { computed, ref, onMounted } from "vue";
import axios from "axios";
import { useRouter } from "vue-router";
import { useToast } from "primevue/usetoast";

// --- VARIABLES ---
const router = useRouter();
const toast = useToast();
const users = ref([]);
const unitsList = ref([]);
const profilesList = ref([]);
const loading = ref(true);
const isSaving = ref(false);
const isResettingPassword = ref(false);
const isImporting = ref(false);
const formSubmitted = ref(false);
const importFileInput = ref(null);

const rowsPerPageOptions = ref([5, 10, 25, 50]);
const filters = ref({
    global: { value: null, matchMode: "contains" },
});
const selectedUnitId = ref(null);

// Modal
const displayModal = ref(false);
const modalHeader = ref("");
const isEditing = ref(false);
const resetPasswordModal = ref(false);
const selectedUserForReset = ref(null);
const adminPasswordForReset = ref("");

// --- ERRORES DE VALIDACIÓN ---
const emailError = ref("");
const emailFormatError = ref("");
const carnetError = ref(""); // Nuevo error para Carnet
const nameErrors = ref({});

const form = ref({
    id: null,
    first_name: "",
    second_name: "",
    first_last_name: "",
    second_last_name: "",
    carnet: "",
    email: "",
    unit_ids: [],
    role: null,
    is_active: true,
});

const selectedUnits = computed(() =>
    (Array.isArray(form.value.unit_ids) ? form.value.unit_ids : [])
        .map((unitId) => unitsList.value.find((unit) => unit.value === unitId))
        .filter(Boolean),
);
const roleMissing = computed(() => !String(form.value.role ?? "").trim());
const unitsMissing = computed(() => selectedUnits.value.length === 0);

// --- CARGAR DATOS ---
const fetchData = async () => {
    loading.value = true;
    try {
        const [usersRes, unitsRes, profilesRes] = await Promise.all([
            axios.get("/api/users"),
            axios.get("/api/units"),
            axios.get("/api/profiles"),
        ]);
        users.value = usersRes.data;
        unitsList.value = unitsRes.data.map((u) => ({
            label: u.name,
            value: u.id,
        }));
        profilesList.value = profilesRes.data.map((profile) => ({
            label: profile.name,
            value: profile.name,
        }));
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

onMounted(fetchData);

// --- VALIDACIONES EN TIEMPO REAL ---

// 1. CARNET: Mayúsculas automáticas + Validación de caracteres prohibidos
const validateCarnet = () => {
    if (!form.value.carnet) {
        carnetError.value = "";
        return;
    }

    // Convertimos a mayúsculas para ayudar al usuario, pero NO borramos caracteres especiales
    form.value.carnet = form.value.carnet.toUpperCase();

    // Regex: Busca cualquier cosa que NO sea letra (A-Z) ni número (0-9)
    const invalidCharsRegex = /[^A-Z0-9]/;

    if (invalidCharsRegex.test(form.value.carnet)) {
        carnetError.value =
            "Caracteres no válidos detectados. Por favor bórrelos.";
    } else {
        carnetError.value = "";
    }
};

// 2. NOMBRES: Solo letras y espacios (Validación visual)
const validateNameField = (field) => {
    const value = form.value[field];
    if (!value) {
        nameErrors.value[field] = "";
        return;
    }
    // Regex: Permite letras a-z, A-Z, tildes, ñ/Ñ y espacios.
    const validNameRegex = /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/;

    if (!validNameRegex.test(value)) {
        nameErrors.value[field] = "Solo se permiten letras y espacios.";
    } else {
        nameErrors.value[field] = "";
    }
};

// 3. CORREO: Formato válido
const validateEmailFormat = () => {
    const email = form.value.email;
    if (!email) {
        emailFormatError.value = "";
        return true;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    if (!emailRegex.test(email)) {
        emailFormatError.value = "El formato del correo no es válido.";
        return false;
    } else {
        emailFormatError.value = "";
        return true;
    }
};

// --- MODALES ---
const openCreateModal = () => {
    isEditing.value = false;
    modalHeader.value = "Crear Nuevo Usuario";
    emailError.value = "";
    emailFormatError.value = "";
    carnetError.value = "";
    nameErrors.value = {};
    formSubmitted.value = false;

    form.value = {
        first_name: "",
        second_name: "",
        first_last_name: "",
        second_last_name: "",
        carnet: "",
        email: "",
        unit_ids: [],
        role: null,
        is_active: true,
    };
    selectedUnitId.value = null;
    displayModal.value = true;
};

const openEditModal = (user) => {
    isEditing.value = true;
    modalHeader.value = "Editar Usuario";
    emailError.value = "";
    emailFormatError.value = "";
    carnetError.value = "";
    nameErrors.value = {};
    formSubmitted.value = false;

    form.value = {
        id: user.id,
        first_name: user.first_name,
        second_name: user.second_name,
        first_last_name: user.first_last_name,
        second_last_name: user.second_last_name,
        carnet: user.carnet,
        email: user.email,
        unit_ids: Array.isArray(user.unit_ids)
            ? [...user.unit_ids]
            : user.unit_id
              ? [user.unit_id]
              : [],
        role: user.role,
        is_active: Boolean(user.is_active),
    };
    selectedUnitId.value = null;
    displayModal.value = true;
};

const openResetPasswordModal = (user) => {
    if (!user.is_active) {
        toast.add({
            severity: "warn",
            summary: "Usuario inactivo",
            detail: "El usuario no se encuentra activo",
            life: 3500,
        });
        return;
    }

    selectedUserForReset.value = user;
    adminPasswordForReset.value = "";
    resetPasswordModal.value = true;
};

const closeResetPasswordModal = () => {
    resetPasswordModal.value = false;
    adminPasswordForReset.value = "";
    selectedUserForReset.value = null;
};

const addUnitToForm = () => {
    if (!selectedUnitId.value) {
        return;
    }

    if (!form.value.unit_ids.includes(selectedUnitId.value)) {
        form.value.unit_ids.push(selectedUnitId.value);
    }

    selectedUnitId.value = null;
};

const removeUnitFromForm = (unitId) => {
    form.value.unit_ids = form.value.unit_ids.filter((id) => id !== unitId);
};

// --- GUARDAR ---
const saveUser = async () => {
    if (isSaving.value) return;

    formSubmitted.value = true;

    // 1. Validar Campos Obligatorios
    if (
        !form.value.first_name ||
        !form.value.first_last_name ||
        !form.value.email ||
        unitsMissing.value ||
        roleMissing.value
    ) {
        toast.add({
            severity: "warn",
            summary: "Atención",
            detail: "Complete los campos obligatorios (*)",
            life: 3000,
        });
        return;
    }

    // 2. Validar Carnet (Si hay error, no guardar)
    if (carnetError.value) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Corrija el Carnet (solo letras y números)",
            life: 3000,
        });
        return;
    }

    // 3. Validar Formato de Correo
    if (!validateEmailFormat()) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Corrija el formato del correo",
            life: 3000,
        });
        return;
    }

    // 4. Verificar si hay errores en los nombres
    const hasNameErrors = Object.values(nameErrors.value).some(
        (error) => error !== "",
    );
    if (hasNameErrors) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "Hay caracteres inválidos en los nombres",
            life: 3000,
        });
        return;
    }

    isSaving.value = true;
    emailError.value = "";
    carnetError.value = "";

    try {
        if (isEditing.value) {
            await axios.put(`/api/users/${form.value.id}`, form.value);
            toast.add({
                severity: "success",
                summary: "Actualizado",
                detail: "Usuario actualizado correctamente",
                life: 3000,
            });
        } else {
            await axios.post("/api/users", form.value);
            toast.add({
                severity: "success",
                summary: "Creado",
                detail: "Usuario creado y credenciales enviadas",
                life: 3000,
            });
        }
        displayModal.value = false;
        formSubmitted.value = false;
        fetchData();
    } catch (error) {
        if (error.response && error.response.status === 422) {
            const errors = error.response.data.errors;
            if (errors.email) {
                emailError.value =
                    "Este correo electrónico ya está registrado.";
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "El correo ya existe",
                    life: 3000,
                });
            }
            if (errors.carnet) {
                carnetError.value =
                    errors.carnet[0] || "Este carnet ya esta registrado.";
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail: "El carnet ya existe",
                    life: 3000,
                });
            }
            if (errors.unit_ids) {
                toast.add({
                    severity: "error",
                    summary: "Error",
                    detail:
                        errors.unit_ids[0] ||
                        "Seleccione al menos una unidad",
                    life: 3000,
                });
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
    window.location.href = "/api/users/import-template";
};

const openImportFilePicker = () => {
    if (!isImporting.value) {
        importFileInput.value?.click();
    }
};

const importUsers = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = "";

    if (!file) return;

    isImporting.value = true;
    const formData = new FormData();
    formData.append("file", file);

    try {
        const { data } = await axios.post("/api/users/import", formData, {
            headers: { "Content-Type": "multipart/form-data" },
        });

        toast.add({
            severity: "success",
            summary: "Carga completada",
            detail: data.message,
            life: 5000,
        });
        await fetchData();
    } catch (error) {
        toast.add({
            severity: "error",
            summary: "No se pudo cargar el archivo",
            detail:
                error.response?.data?.message ||
                "Revise la plantilla e intente nuevamente.",
            life: 7000,
        });
    } finally {
        isImporting.value = false;
    }
};

const resetUserPassword = async () => {
    if (!selectedUserForReset.value || !adminPasswordForReset.value) {
        toast.add({
            severity: "warn",
            summary: "Atencion",
            detail: "Ingrese su contrasena para confirmar.",
            life: 3000,
        });
        return;
    }

    isResettingPassword.value = true;

    try {
        await axios.post(
            `/api/users/${selectedUserForReset.value.id}/reset-password`,
            {
                admin_password: adminPasswordForReset.value,
            },
        );
        toast.add({
            severity: "success",
            summary: "Contraseña enviada",
            detail: "Se envio una contrasena temporal al correo del usuario.",
            life: 3500,
        });
        resetPasswordModal.value = false;
        adminPasswordForReset.value = "";
        selectedUserForReset.value = null;
    } catch (error) {
        const detail =
            error.response?.data?.message ||
            "No se pudo restablecer la contrasena.";
        toast.add({ severity: "error", summary: "Error", detail, life: 4000 });
    } finally {
        isResettingPassword.value = false;
    }
};

const clearFilter = () => {
    filters.value.global.value = null;
};

const goBack = () => {
    router.back();
};
</script>

<template>
    <Toast />

    <input
        ref="importFileInput"
        type="file"
        accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        class="users-import-input"
        @change="importUsers"
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
                <h1 class="reports-hero__title m-0">Gestión de Usuarios</h1>
                <p class="reports-hero__subtitle m-0">
                    Administrar los usuarios del sistema, asignar roles y
                    unidades.
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
                    placeholder="Buscar usuario..."
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
                    label="Nuevo Usuario"
                    icon="pi pi-user-plus"
                    class="w-auto px-4 border-none filters-toolbar__primary"
                    @click="openCreateModal"
                />
            </div>
        </div>
    </div>

    <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
        <DataTable
            :value="users"
            :paginator="true"
            :rows="5"
            :rowsPerPageOptions="rowsPerPageOptions"
            :loading="loading"
            v-model:filters="filters"
            :globalFilterFields="[
                'first_name',
                'first_last_name',
                'email',
                'carnet',
            ]"
            tableStyle="min-width: 60rem"
            rowHover
            stripedRows
        >
            <template #empty>
                <div class="text-center p-4 text-gray-500 font-medium">
                    Sin usuarios registrados
                </div>
            </template>

            <Column
                field="carnet"
                header="Carnet"
                style="width: 10%"
                headerClass="bg-primary-custom text-white font-semibold"
            >
                <template #body="slotProps">{{
                    slotProps.data.carnet || "N/A"
                }}</template>
            </Column>

            <Column
                header="Nombre Completo"
                sortable
                field="first_name"
                style="width: 25%"
                headerClass="bg-primary-custom text-white font-semibold"
            >
                <template #body="slotProps">
                    <div class="font-medium text-gray-900">
                        {{ slotProps.data.first_name }}
                        {{ slotProps.data.first_last_name }}
                    </div>
                    <small class="text-gray-500">{{
                        slotProps.data.email
                    }}</small>
                </template>
            </Column>

            <Column
                header="Unidad / Rol"
                style="width: 25%"
                headerClass="bg-primary-custom text-white font-semibold"
            >
                <template #body="slotProps">
                    <div class="flex flex-column gap-1">
                        <span class="user-table__unit">
                            {{
                                slotProps.data.units?.length
                                    ? slotProps.data.units
                                          .map((unit) => unit.name)
                                          .join(", ")
                                    : "Sin Unidad"
                            }}
                        </span>
                        <span class="ugda-chip ugda-chip--info w-max">
                            {{ slotProps.data.role }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column
                field="is_active"
                header="Estado"
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
                                    : 'ugda-chip--danger',
                            ]"
                        >
                            {{
                                slotProps.data.is_active ? "Activo" : "Inactivo"
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
                    <div class="usuarios-actions">
                        <Button
                            icon="pi pi-key"
                            text
                            rounded
                            severity="secondary"
                            class="text-gray-600 hover:text-blue-600"
                            title="Restablecer contrasena"
                            @click="openResetPasswordModal(slotProps.data)"
                        />
                        <Button
                            icon="pi pi-pencil"
                            text
                            rounded
                            severity="secondary"
                            class="text-gray-600 hover:text-blue-600"
                            title="Editar usuario"
                            @click="openEditModal(slotProps.data)"
                        />
                    </div>
                </template>
            </Column>
        </DataTable>
    </div>

    <Dialog
        v-model:visible="displayModal"
        :modal="true"
        :draggable="false"
        :header="modalHeader"
        :style="{ width: '700px' }"
        class="p-fluid blue-header-dialog"
    >
        <div class="grid formgrid p-2">
            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Primer Nombre *</label>
                <InputText
                    v-model="form.first_name"
                    @input="validateNameField('first_name')"
                    :class="{ 'p-invalid': nameErrors.first_name }"
                    class="w-full bg-gray-50"
                    placeholder="Ej: Juan"
                    maxlength="30"
                />
                <div class="flex justify-content-between">
                    <small v-if="nameErrors.first_name" class="p-error block">{{
                        nameErrors.first_name
                    }}</small>
                    <small class="text-gray-500 text-xs"
                        >{{
                            form.first_name ? form.first_name.length : 0
                        }}/30</small
                    >
                </div>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Segundo Nombre</label>
                <InputText
                    v-model="form.second_name"
                    @input="validateNameField('second_name')"
                    :class="{ 'p-invalid': nameErrors.second_name }"
                    class="w-full bg-gray-50"
                    placeholder="Ej: Carlos"
                    maxlength="30"
                />
                <div class="flex justify-content-between">
                    <small
                        v-if="nameErrors.second_name"
                        class="p-error block"
                        >{{ nameErrors.second_name }}</small
                    >
                    <small class="text-gray-500 text-xs"
                        >{{
                            form.second_name ? form.second_name.length : 0
                        }}/30</small
                    >
                </div>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Primer Apellido *</label>
                <InputText
                    v-model="form.first_last_name"
                    @input="validateNameField('first_last_name')"
                    :class="{ 'p-invalid': nameErrors.first_last_name }"
                    class="w-full bg-gray-50"
                    placeholder="Ej: Pérez"
                    maxlength="30"
                />
                <div class="flex justify-content-between">
                    <small
                        v-if="nameErrors.first_last_name"
                        class="p-error block"
                        >{{ nameErrors.first_last_name }}</small
                    >
                    <small class="text-gray-500 text-xs"
                        >{{
                            form.first_last_name
                                ? form.first_last_name.length
                                : 0
                        }}/30</small
                    >
                </div>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Segundo Apellido</label>
                <InputText
                    v-model="form.second_last_name"
                    @input="validateNameField('second_last_name')"
                    :class="{ 'p-invalid': nameErrors.second_last_name }"
                    class="w-full bg-gray-50"
                    placeholder="Ej: López"
                    maxlength="30"
                />
                <div class="flex justify-content-between">
                    <small
                        v-if="nameErrors.second_last_name"
                        class="p-error block"
                        >{{ nameErrors.second_last_name }}</small
                    >
                    <small class="text-gray-500 text-xs"
                        >{{
                            form.second_last_name
                                ? form.second_last_name.length
                                : 0
                        }}/30</small
                    >
                </div>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Carnet</label>
                <InputText
                    v-model="form.carnet"
                    @input="validateCarnet"
                    :class="{ 'p-invalid': carnetError }"
                    class="w-full bg-gray-50"
                    placeholder="Ej: AA12345"
                    maxlength="20"
                />
                <small v-if="carnetError" class="p-error block">{{
                    carnetError
                }}</small>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Correo Electrónico *</label>
                <InputText
                    v-model="form.email"
                    @input="validateEmailFormat"
                    :class="{ 'p-invalid': emailError || emailFormatError }"
                    class="w-full bg-gray-50"
                    placeholder="correo@ues.edu.sv"
                />

                <small v-if="emailFormatError" class="p-error block">{{
                    emailFormatError
                }}</small>
                <small v-if="emailError" class="p-error block">{{
                    emailError
                }}</small>

                <small
                    v-if="!isEditing && !emailFormatError && !emailError"
                    class="text-gray-500 text-xs block mt-1"
                >
                    Se enviarán las credenciales a este correo.
                </small>
            </div>

            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Unidades *</label>
                <div class="flex gap-2 mt-1 user-units-selector-row">
                    <Dropdown
                        v-model="selectedUnitId"
                        :options="unitsList"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        placeholder="Seleccione una o más unidades"
                        class="w-full bg-gray-50 user-units-selector-dropdown"
                        :class="{ 'p-invalid': formSubmitted && unitsMissing }"
                    />
                    <Button
                        icon="pi pi-plus"
                        class="w-3rem flex-shrink-0 border-none user-units-selector-button"
                        @click="addUnitToForm"
                    />
                </div>
                <small class="text-gray-500 text-xs block mt-1">
                    La primera unidad seleccionada se usará como unidad
                    principal del usuario.
                </small>
                <small
                    v-if="formSubmitted && unitsMissing"
                    class="p-error text-sm block mt-1"
                >
                    Seleccione al menos una unidad para el usuario.
                </small>
                <div
                    v-if="selectedUnits.length > 0"
                    class="flex flex-column gap-2 mt-3 user-selected-units-list"
                >
                    <div
                        v-for="(unit, index) in selectedUnits"
                        :key="unit.value"
                        class="flex align-items-center justify-content-between p-3 border-1 border-gray-200 border-round bg-white"
                    >
                        <div class="flex flex-column">
                            <span class="text-gray-800 text-sm">{{
                                unit.label
                            }}</span>
                            <small v-if="index === 0" class="user-primary-unit"
                                >Unidad principal</small
                            >
                        </div>
                        <i
                            class="pi pi-trash text-red-500 cursor-pointer hover:text-red-700"
                            @click="removeUnitFromForm(unit.value)"
                        ></i>
                    </div>
                </div>
                <div v-else class="text-gray-500 text-sm font-italic p-2 mt-2">
                    No hay unidades agregadas
                </div>
            </div>
            <div class="col-12 md:col-6 field mb-3">
                <label class="form-label">Rol de Usuario *</label>
                <Dropdown
                    v-model="form.role"
                    :options="profilesList"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Seleccione Rol"
                    class="w-full bg-gray-50"
                    :class="{ 'p-invalid': formSubmitted && roleMissing }"
                />
                <small
                    v-if="formSubmitted && roleMissing"
                    class="p-error text-sm block mt-1"
                >
                    Seleccione un rol para el usuario.
                </small>
            </div>

            <div class="col-12 field mb-3" v-if="isEditing">
                <div
                    :class="[
                        'user-status-toggle',
                        form.is_active ? 'is-active' : 'is-inactive',
                    ]"
                >
                    <ToggleSwitch v-model="form.is_active" />
                    <span class="user-status-toggle__text">
                        {{ form.is_active ? "Activo" : "Inactivo" }}
                    </span>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-content-between gap-3 mt-2 w-full">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="flex-1"
                    :disabled="isSaving"
                    @click="displayModal = false"
                />
                <Button
                    label="Guardar Usuario"
                    class="flex-1 border-none"
                    :loading="isSaving"
                    @click="saveUser"
                />
            </div>
        </template>
    </Dialog>

    <Dialog
        v-model:visible="resetPasswordModal"
        :modal="true"
        :draggable="false"
        header="Restablecer contrasena"
        :style="{ width: '520px' }"
        class="p-fluid blue-header-dialog"
        @hide="closeResetPasswordModal"
    >
        <div class="reset-password-content">
            <div class="reset-password-icon">
                <i class="pi pi-key"></i>
            </div>
            <div>
                <h3>Enviar contrasena temporal</h3>
                <p>
                    Se generara una contrasena temporal para
                    <strong>{{ selectedUserForReset?.email }}</strong
                    >. Al iniciar sesion, el usuario debera cambiarla.
                </p>
            </div>
        </div>

        <div class="field mt-3">
            <label class="form-label">Su contrasena *</label>
            <InputText
                v-model="adminPasswordForReset"
                type="password"
                name="admin-reset-password-confirmation"
                autocomplete="new-password"
                data-lpignore="true"
                data-1p-ignore="true"
                class="w-full bg-gray-50"
                placeholder="Confirme su contrasena de administrador"
                @keyup.enter="resetUserPassword"
            />
        </div>

        <template #footer>
            <div
                class="flex justify-content-between gap-3 mt-2 w-full reset-password-footer"
            >
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    class="flex-1"
                    :disabled="isResettingPassword"
                    @click="closeResetPasswordModal"
                />
                <Button
                    label="Enviar temporal"
                    icon="pi pi-send"
                    class="flex-1 border-none"
                    :loading="isResettingPassword"
                    @click="resetUserPassword"
                />
            </div>
        </template>
    </Dialog>
</template>

<style>
/* Quitamos 'scoped' para que estas clases afecten al Modal que vive en el body */

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

/* Icono de cerrar */
.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon {
    color: var(--ugda-text-secondary) !important;
}

/* Hover del icono de cerrar */
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
/* Estilos solo para la tabla o botones internos */
:deep(.bg-primary-custom) {
    background-color: var(--ugda-brand) !important;
    border: none;
}

.user-units-selector-row {
    align-items: center;
}

:deep(.user-units-selector-dropdown) {
    flex: 1 1 auto;
    min-width: 0;
}

.user-units-selector-button {
    height: var(--ugda-control-height);
}

.user-selected-units-list {
    max-height: 17rem;
    overflow-y: auto;
    padding-right: 0.25rem;
    scrollbar-gutter: stable;
}

.user-selected-units-list::-webkit-scrollbar {
    width: 0.45rem;
}

.user-selected-units-list::-webkit-scrollbar-thumb {
    background: var(--ugda-border-strong);
    border-radius: 999px;
}

.user-selected-units-list::-webkit-scrollbar-track {
    background: var(--ugda-surface-soft);
    border-radius: 999px;
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

.usuarios-actions {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.25rem;
}

.user-status-toggle {
    align-items: center;
    display: inline-flex;
    gap: var(--ugda-space-sm);
    min-height: var(--ugda-control-height);
}

.user-status-toggle__text {
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

.user-status-toggle.is-active .user-status-toggle__text {
    color: var(--ugda-success-text);
}

.user-status-toggle.is-inactive .user-status-toggle__text {
    color: var(--ugda-error);
}

:deep(.user-status-toggle.is-active .p-toggleswitch-slider) {
    background: var(--ugda-success) !important;
}

:deep(.user-status-toggle.is-active .p-toggleswitch.p-focus .p-toggleswitch-slider) {
    box-shadow: 0 0 0 0.2rem rgba(22, 163, 74, 0.2) !important;
}

:deep(.user-status-toggle.is-inactive .p-toggleswitch-slider) {
    background: var(--ugda-error) !important;
}

:deep(.user-status-toggle.is-inactive .p-toggleswitch.p-focus .p-toggleswitch-slider) {
    box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.18) !important;
}

.reset-password-content {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--ugda-space-md);
    align-items: flex-start;
    padding: var(--ugda-space-xs) 0;
}

.reset-password-content h3 {
    margin: 0 0 var(--ugda-space-xs);
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-medium);
}

.reset-password-content p {
    margin: 0;
    color: var(--ugda-text-secondary);
    line-height: 1.6;
}

.reset-password-icon {
    width: var(--ugda-control-height-lg);
    height: var(--ugda-control-height-lg);
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--ugda-radius-lg);
    background: var(--ugda-brand-soft);
    color: var(--ugda-brand);
    flex: 0 0 auto;
}

.user-table__unit {
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body);
}

.user-primary-unit {
    color: var(--ugda-brand);
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
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

.ugda-chip--danger {
    background: var(--ugda-error-soft);
    color: var(--ugda-error);
}

.ugda-chip--neutral {
    background: var(--ugda-surface-soft);
    color: var(--ugda-text-secondary);
}

@media (max-width: 991px) {
    .filters-toolbar__actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        width: 100%;
    }

    .filters-toolbar__clear,
    .filters-toolbar__template,
    .filters-toolbar__import,
    .filters-toolbar__primary {
        width: 100%;
        margin-left: 0;
        justify-content: center;
    }

    .user-units-selector-button {
        width: 3rem;
        min-width: 3rem;
    }
}

@media (max-width: 640px) {
    .reset-password-content {
        grid-template-columns: 1fr;
    }

    .reset-password-footer {
        flex-direction: column;
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

.users-import-input {
    display: none;
}
</style>
