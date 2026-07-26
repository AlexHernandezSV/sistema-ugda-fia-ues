<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

const router = useRouter();
const toast = useToast();

const OBSERVATION_MAX_LENGTH = 1000;

const loading = ref(true);
const submitting = ref(false);
const confirmVisible = ref(false);
const search = ref('');
const pendingSelectedIds = ref([]);
const selectedSystemIds = ref([]);
const systemDocumentTypes = ref({});
const showAdditionalForm = ref(false);
const availableDocuments = ref([]);
const documentTypeOptions = ['Copia', 'Original'];

const requester = reactive({
    name: '',
    unitName: '',
});

const additionalForm = reactive({
    name: '',
    year: '',
    quantity: '',
    unit: '',
    observation: '',
    documentType: 'Copia',
});

const additionalDocuments = ref([]);

const filteredDocuments = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return availableDocuments.value;
    }

    return availableDocuments.value.filter((document) => [
        document.title,
        document.series,
        document.box,
        document.year,
        document.transferNumber,
    ].some((value) => String(value ?? '').toLowerCase().includes(term)));
});

const pendingDocuments = computed(() =>
    availableDocuments.value.filter((document) => pendingSelectedIds.value.includes(document.id))
);

const selectedSystemDocuments = computed(() =>
    availableDocuments.value
        .filter((document) => selectedSystemIds.value.includes(document.id))
        .map((document) => ({
            ...document,
            documentType: systemDocumentTypes.value[document.id] ?? 'Copia',
        }))
);

const totalDocuments = computed(() => selectedSystemIds.value.length + additionalDocuments.value.length);
const canSubmit = computed(() =>
    requester.name.trim() !== ''
    && requester.unitName.trim() !== ''
    && totalDocuments.value > 0
);

const goBack = () => {
    router.push({
        path: '/solicitudes',
        query: { tab: 'prestamos' },
    });
};

const fetchCatalog = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get('/api/loans/create/catalog');
        requester.name = data.requester?.name ?? '';
        requester.unitName = data.requester?.unit_name ?? '';
        availableDocuments.value = data.documents ?? [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'No se pudo cargar el catalogo de documentos.',
            life: 3600,
        });
    } finally {
        loading.value = false;
    }
};

const togglePendingDocument = (id) => {
    if (pendingSelectedIds.value.includes(id)) {
        pendingSelectedIds.value = pendingSelectedIds.value.filter((item) => item !== id);
        return;
    }

    pendingSelectedIds.value = [...pendingSelectedIds.value, id];
};

const addSelectedDocuments = () => {
    const nextSelectedIds = [...new Set([...selectedSystemIds.value, ...pendingSelectedIds.value])];
    const nextTypes = { ...systemDocumentTypes.value };

    nextSelectedIds.forEach((id) => {
        nextTypes[id] = nextTypes[id] ?? 'Copia';
    });

    selectedSystemIds.value = nextSelectedIds;
    systemDocumentTypes.value = nextTypes;
    pendingSelectedIds.value = [];
};

const removeSystemDocument = (id) => {
    selectedSystemIds.value = selectedSystemIds.value.filter((item) => item !== id);
    const { [id]: _removed, ...nextTypes } = systemDocumentTypes.value;
    systemDocumentTypes.value = nextTypes;
};

const resetAdditionalForm = () => {
    additionalForm.name = '';
    additionalForm.year = '';
    additionalForm.quantity = '';
    additionalForm.unit = '';
    additionalForm.observation = '';
    additionalForm.documentType = 'Copia';
};

const addAdditionalDocument = () => {
    if (additionalForm.name.trim() === '') {
        toast.add({
            severity: 'warn',
            summary: 'Documento requerido',
            detail: 'Ingrese el nombre del documento adicional.',
            life: 2800,
        });
        return;
    }

    if (additionalForm.observation.trim().length > OBSERVATION_MAX_LENGTH) {
        toast.add({
            severity: 'warn',
            summary: 'Observación inválida',
            detail: `La observación no puede superar ${OBSERVATION_MAX_LENGTH} caracteres.`,
            life: 2800,
        });
        return;
    }

    // NUEVA VALIDACIÓN: año si fue ingresado
    const yearValue = additionalForm.year ? additionalForm.year.trim() : '';
    
    if (yearValue !== '') {
        // Validar formato 4 dígitos
        if (!/^\d{4}$/.test(yearValue)) {
            toast.add({
                severity: 'warn',
                summary: 'Año inválido',
                detail: 'El año debe tener 4 dígitos (ej: 2024).',
                life: 2800,
            });
            return;
        }
        
        // Validar que no sea mayor al año actual
        const currentYear = new Date().getFullYear();
        if (parseInt(yearValue, 10) > currentYear) {
            toast.add({
                severity: 'warn',
                summary: 'Año inválido',
                detail: `El año no puede ser mayor a ${currentYear}.`,
                life: 2800,
            });
            return;
        }
    }

    additionalDocuments.value = [
        ...additionalDocuments.value,
        {
            id: Date.now(),
            name: additionalForm.name.trim(),
            year: additionalForm.year.trim(),
            quantity: additionalForm.quantity.trim(),
            unit: additionalForm.unit.trim(),
            observation: additionalForm.observation.trim(),
            documentType: additionalForm.documentType,
        },
    ];
    resetAdditionalForm();
};

const removeAdditionalDocument = (id) => {
    additionalDocuments.value = additionalDocuments.value.filter((document) => document.id !== id);
};

const openConfirm = () => {
    if (!canSubmit.value) {
        toast.add({
            severity: 'warn',
            summary: 'Solicitud incompleta',
            detail: 'Complete los datos del solicitante y agregue al menos un documento.',
            life: 3200,
        });
        return;
    }

    confirmVisible.value = true;
};

const submitLoanRequest = async () => {
    submitting.value = true;

    try {
        const { data } = await axios.post('/api/loans', {
            requester_name: requester.name,
            unit_name: requester.unitName,
            system_documents: selectedSystemIds.value.map((id) => ({
                id,
                document_type: systemDocumentTypes.value[id] ?? 'Copia',
            })),
            additional_documents: additionalDocuments.value.map((document) => ({
                name: document.name,
                year: document.year,
                quantity: document.quantity,
                unit: document.unit,
                observation: document.observation,
                document_type: document.documentType,
            })),
        });

        toast.add({
            severity: 'success',
            summary: 'Solicitud enviada',
            detail: data.message,
            life: 3000,
        });
        confirmVisible.value = false;
        router.push(`/solicitudes/prestamos/${data.number}`);
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'No se pudo enviar la solicitud de prestamo.',
            life: 3600,
        });
    } finally {
        submitting.value = false;
    }
};

onMounted(fetchCatalog);
</script>

<template>
    <div class="prestamo-page flex flex-column gap-4">
        <div class="prestamo-header mobile-page-title">
            <button type="button" class="prestamo-back" @click="goBack">
                <i class="pi pi-arrow-left"></i>
            </button>
            <div>
                <h1 class="prestamo-title m-0">Solicitud de Prestamo</h1>
                <span class="prestamo-subtitle">Solicita documentos que ya se encuentran en custodia de la UGDA</span>
            </div>
        </div>

        <div class="prestamo-info">
            <i class="pi pi-info-circle"></i>
            <div>
                <strong>Información importante</strong>
                <p>Esta solicitud es para documentos que ya fueron transferidos y se encuentran bajo custodia de la UGDA. El prestamo debe ser autorizado por el Director/Jefe y luego sera revisado por el personal de archivo.</p>
            </div>
        </div>

        <div v-if="loading" class="surface-card shadow-1 border-round-xl p-4 text-center text-gray-500">
            Cargando catalogo de documentos...
        </div>

        <template v-else>
            <section class="surface-card shadow-1 border-round-xl p-4 prestamo-section">
                <h2>1. Datos del Solicitante</h2>
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <label class="prestamo-label">Nombre del Solicitante <span>*</span></label>
                        <InputText v-model="requester.name" class="w-full mt-2" placeholder="Nombre completo del solicitante" readonly/>
                    </div>
                    <div class="col-12 md:col-6">
                        <label class="prestamo-label">Unidad Solicitante <span>*</span></label>
                        <InputText v-model="requester.unitName" class="w-full mt-2" placeholder="Ej: Escuela de Ingenieria Civil" readonly/>
                    </div>
                </div>
            </section>

            <section class="surface-card shadow-1 border-round-xl p-4 prestamo-section">
                <div class="prestamo-section-head">
                    <h2>2. Documentos a Solicitar</h2>
                    <button
                        v-if="pendingDocuments.length > 0"
                        type="button"
                        class="prestamo-primary"
                        @click="addSelectedDocuments"
                    >
                        <i class="pi pi-plus"></i>
                        <span>Agregar Seleccionados ({{ pendingDocuments.length }})</span>
                    </button>
                </div>

                <label class="prestamo-label">Buscar documentos transferidos</label>
                <div class="prestamo-search mt-2">
                    <i class="pi pi-search"></i>
                    <input v-model="search" type="text" placeholder="Buscar por nombre, serie, caja, transferencia o ano..." />
                </div>

                <div class="prestamo-documents-head">
                    <strong>Documentos Disponibles ({{ filteredDocuments.length }})</strong>
                </div>

                <div class="prestamo-document-list">
                    <button
                        v-for="document in filteredDocuments"
                        :key="document.id"
                        type="button"
                        class="prestamo-document"
                        :class="{ 'prestamo-document--selected': pendingSelectedIds.includes(document.id) || selectedSystemIds.includes(document.id) }"
                        @click="togglePendingDocument(document.id)"
                    >
                        <div class="prestamo-document__icon">
                            <i class="pi pi-file"></i>
                        </div>
                        <div class="prestamo-document__body">
                            <span class="prestamo-document-title">{{ document.title }}</span>
                            <div class="prestamo-document__grid">
                                <span>Serie: {{ document.series || 'Sin serie' }}</span>
                                <span>Caja: {{ document.box }}</span>
                                <span>Año: {{ document.year || 'N/D' }}</span>
                                <span>Transferido: {{ document.transferredAt || 'N/D' }}</span>
                            </div>
                        </div>
                        <span class="prestamo-document__check">
                            <i v-if="pendingSelectedIds.includes(document.id) || selectedSystemIds.includes(document.id)" class="pi pi-check"></i>
                        </span>
                    </button>

                    <div v-if="filteredDocuments.length === 0" class="prestamo-empty">
                        No hay documentos transferidos disponibles para este usuario.
                    </div>
                </div>

                <div v-if="selectedSystemDocuments.length > 0" class="prestamo-selected-box">
                    <h3>Documentos del sistema agregados ({{ selectedSystemDocuments.length }})</h3>
                    <div class="prestamo-selected-list">
                        <div v-for="document in selectedSystemDocuments" :key="document.id" class="prestamo-selected-item">
                            <div>
                                <span class="prestamo-document-title">{{ document.title }}</span>
                                <span>{{ document.series || 'Sin serie' }} - {{ document.box }} - {{ document.year || 'N/D' }}</span>
                            </div>
                            <div class="prestamo-selected-item__actions">
                                <Dropdown v-model="systemDocumentTypes[document.id]" :options="documentTypeOptions" class="prestamo-document-type" />
                                <button type="button" aria-label="Quitar documento" @click="removeSystemDocument(document.id)">
                                    <i class="pi pi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="prestamo-divider"></div>

                <label class="prestamo-checkbox">
                    <input v-model="showAdditionalForm" type="checkbox" />
                    <span>¿No encontro el documento que busca?</span>
                </label>

                <div v-if="showAdditionalForm" class="prestamo-additional-form">
                    <div class="prestamo-additional-form__title">
                        <i class="pi pi-box"></i>
                        <strong>Agregar documentos a la solicitud</strong>
                    </div>
                    <p>Complete la información del documento que desea solicitar:</p>

                    <div class="grid">
                        <div class="col-12">
                            <label class="prestamo-label">Nombre del Documento</label>
                            <InputText v-model="additionalForm.name" class="w-full mt-2" placeholder="Ej: Expedientes de Graduacion 2015" />
                        </div>
                        <div class="col-12 md:col-6">
                            <label class="prestamo-label">Año</label>
                            <InputText v-model="additionalForm.year" class="w-full mt-2" placeholder="Ej: 2015" />
                        </div>
                        <div class="col-12 md:col-6">
                            <label class="prestamo-label">Cantidad de Documentos</label>
                            <InputText v-model="additionalForm.quantity" class="w-full mt-2" placeholder="Ej: 3 cajas, 15 expedientes" />
                        </div>
                        <div class="col-12 md:col-8">
                            <label class="prestamo-label">Unidad Productora</label>
                            <InputText v-model="additionalForm.unit" class="w-full mt-2" placeholder="Ej: Escuela de Ingenieria Civil" />
                        </div>
                        <div class="col-12 md:col-4">
                            <label class="prestamo-label">Tipo</label>
                            <Dropdown v-model="additionalForm.documentType" :options="documentTypeOptions" class="w-full mt-2" />
                        </div>
                        <div class="col-12">
                            <label class="prestamo-label">Observación Adicional</label>
                            <textarea v-model="additionalForm.observation" rows="4" class="prestamo-textarea mt-2" :maxlength="OBSERVATION_MAX_LENGTH" placeholder="Ej: Se transfirieron el 10 de marzo de 2022"></textarea>
                        </div>
                    </div>

                    <button type="button" class="prestamo-primary mt-3" @click="addAdditionalDocument">
                        <i class="pi pi-plus"></i>
                        <span>Agregar Documento</span>
                    </button>
                </div>

                <div v-if="additionalDocuments.length > 0" class="prestamo-added-box">
                    <h3>Documentos Adicionales Agregados ({{ additionalDocuments.length }})</h3>
                    <div class="prestamo-selected-list">
                        <div v-for="document in additionalDocuments" :key="document.id" class="prestamo-selected-item">
                            <div>
                                <span class="prestamo-document-title">{{ document.name }}</span>
                                <span>Año: {{ document.year || 'N/D' }} - Cantidad: {{ document.quantity || 'N/D' }}</span>
                                <em v-if="document.observation">Obs.: {{ document.observation }}</em>
                            </div>
                            <div class="prestamo-selected-item__actions">
                                <Dropdown v-model="document.documentType" :options="documentTypeOptions" class="prestamo-document-type" />
                                <button type="button" aria-label="Quitar documento" @click="removeAdditionalDocument(document.id)">
                                    <i class="pi pi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="prestamo-actions">
                <button type="button" class="prestamo-secondary" @click="goBack">Cancelar</button>
                <button type="button" class="prestamo-primary" :disabled="!canSubmit" @click="openConfirm">
                    Enviar Solicitud
                </button>
            </div>
        </template>

        <Dialog v-model:visible="confirmVisible" modal :closable="false" :style="{ width: 'min(56rem, calc(100vw - 2rem))' }" class="prestamo-confirm-dialog" contentClass="p-0">
            <div class="prestamo-confirm">
                <div class="prestamo-confirm__header">
                    <div>
                        <h2>Confirmar Envio de Solicitud de Préstamo</h2>
                        <p>Revise el resumen antes de enviar la solicitud</p>
                    </div>
                    <button type="button" @click="confirmVisible = false">
                        <i class="pi pi-times"></i>
                    </button>
                </div>

                <div class="prestamo-confirm__body">
                    <div class="prestamo-confirm__notice">
                        <i class="pi pi-info-circle"></i>
                        <strong>Al confirmar, esta solicitud será enviada para autorización del Director/Jefe y luego sera revisada por el personal de UGDA</strong>
                    </div>

                    <div class="prestamo-confirm__section">
                        <h3>Datos del Solicitante</h3>
                        <div class="prestamo-confirm__grid">
                            <div>
                                <span>Nombre del Solicitante</span>
                                <strong>{{ requester.name }}</strong>
                            </div>
                            <div>
                                <span>Unidad Solicitante</span>
                                <strong>{{ requester.unitName }}</strong>
                            </div>
                        </div>
                    </div>

                    <div v-if="selectedSystemDocuments.length > 0" class="prestamo-confirm__section">
                        <h3>Documentos del Sistema ({{ selectedSystemDocuments.length }})</h3>
                        <div class="prestamo-confirm__items">
                            <div v-for="document in selectedSystemDocuments" :key="document.id">
                                <span class="prestamo-document-title">{{ document.title }}</span>
                                <span>{{ document.series || 'Sin serie' }} - {{ document.box }} - {{ document.year || 'N/D' }} - Tipo: {{ document.documentType }}</span>
                            </div>
                        </div>
                    </div>

                    <div v-if="additionalDocuments.length > 0" class="prestamo-confirm__section">
                        <h3>Documentos Adicionales ({{ additionalDocuments.length }})</h3>
                        <div class="prestamo-confirm__items">
                            <div v-for="document in additionalDocuments" :key="document.id">
                                <span class="prestamo-document-title">{{ document.name }}</span>
                                <span>Año: {{ document.year || 'N/D' }} - Cantidad: {{ document.quantity || 'N/D' }} - Tipo: {{ document.documentType }}</span>
                                <em v-if="document.observation">Observación: {{ document.observation }}</em>
                            </div>
                        </div>
                    </div>

                    <div class="prestamo-confirm__totals">
                        <div>
                            <span>Total Documentos del Sistema</span>
                            <strong>{{ selectedSystemDocuments.length }}</strong>
                        </div>
                        <div>
                            <span>Total Documentos Adicionales</span>
                            <strong>{{ additionalDocuments.length }}</strong>
                        </div>
                    </div>
                </div>

                <div class="prestamo-confirm__footer">
                    <button type="button" class="prestamo-secondary" :disabled="submitting" @click="confirmVisible = false">Cancelar</button>
                    <button type="button" class="prestamo-primary" :disabled="submitting" @click="submitLoanRequest">
                        {{ submitting ? 'Enviando...' : 'Confirmar y Enviar' }}
                    </button>
                </div>
            </div>
        </Dialog>
    </div>
</template>

<style scoped>
.prestamo-header {
    align-items: center;
    display: flex;
    gap: 1rem;
}

.prestamo-back {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 0.75rem;
    color: #2f6f9f;
    cursor: pointer;
    display: inline-flex;
    font-size: 1.05rem;
    height: 2.5rem;
    justify-content: center;
    width: 2.5rem;
}

.prestamo-title,
.prestamo-section h2 {
    color: #2f6f9f;
    font-weight: var(--ugda-weight-medium);
}

.prestamo-title {
    font-size: 2rem;
    line-height: 1.15;
}

.prestamo-subtitle {
    color: #64748b;
    display: block;
    margin-top: 0.35rem;
}

.prestamo-info {
    align-items: flex-start;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 0.9rem;
    color: #0644b8;
    display: flex;
    gap: 0.85rem;
    padding: 1rem 1.1rem;
}

.prestamo-info p {
    margin: 0.45rem 0 0;
}

.prestamo-section {
    overflow: hidden;
}

.prestamo-section h2 {
    font-size: 1.25rem;
    margin: 0 0 1.6rem;
}

.prestamo-section-head {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.prestamo-label {
    color: #155c9d;
    display: inline-block;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.prestamo-label span {
    color: #ef4444;
}

.prestamo-search {
    align-items: center;
    border: 1px solid #cbd5e1;
    border-radius: 0.75rem;
    display: flex;
    gap: 0.65rem;
    padding: 0 0.9rem;
}

.prestamo-search i {
    color: #64748b;
}

.prestamo-search input {
    background: transparent;
    border: 0;
    color: #0f172a;
    flex: 1;
    font: inherit;
    min-height: 2.9rem;
    outline: none;
}

.prestamo-documents-head {
    color: #475569;
    margin: 1.7rem 0 0.8rem;
}

.prestamo-document-list {
    border: 1px solid #d7dde6;
    border-radius: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    max-height: 20rem;
    overflow-y: auto;
    padding: 0.8rem;
}

.prestamo-document {
    align-items: flex-start;
    background: #ffffff;
    border: 1px solid #d7dde6;
    border-radius: 0.75rem;
    color: #0f172a;
    cursor: pointer;
    display: flex;
    font: inherit;
    gap: 0.85rem;
    padding: 1rem;
    text-align: left;
    width: 100%;
}

.prestamo-document--selected {
    background: #eff6ff;
    border-color: #2f6f9f;
}

.prestamo-document__icon {
    color: #2f6f9f;
    flex-shrink: 0;
    margin-top: 0.15rem;
}

.prestamo-document__body {
    flex: 1;
    min-width: 0;
}

.prestamo-document-title {
    color: #0f172a;
    display: block;
    font-weight: 400;
}

.prestamo-document__grid {
    color: #475569;
    display: grid;
    font-size: 0.86rem;
    gap: 0.45rem 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 0.65rem;
}

.prestamo-document__check {
    align-items: center;
    border: 2px solid #cbd5e1;
    border-radius: 0.35rem;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    height: 1.25rem;
    justify-content: center;
    width: 1.25rem;
}

.prestamo-document--selected .prestamo-document__check {
    background: #2f6f9f;
    border-color: #2f6f9f;
}

.prestamo-empty {
    color: #64748b;
    padding: 1.5rem;
    text-align: center;
}

.prestamo-divider {
    border-top: 1px solid #e2e8f0;
    margin: 1.8rem 0 1.2rem;
}

.prestamo-checkbox {
    align-items: center;
    color: #0f172a;
    display: inline-flex;
    font-weight: 800;
    gap: 0.65rem;
}

.prestamo-checkbox input {
    height: 1.05rem;
    width: 1.05rem;
}

.prestamo-additional-form {
    background: #fffbea;
    border: 1px solid #facc15;
    border-radius: 0.85rem;
    margin-top: 1rem;
    padding: 1.2rem;
}

.prestamo-additional-form__title {
    align-items: center;
    color: #0f172a;
    display: flex;
    gap: 0.65rem;
}

.prestamo-additional-form__title i {
    color: #2f6f9f;
}

.prestamo-textarea {
    border: 1px solid #cbd5e1;
    border-radius: 0.75rem;
    color: #0f172a;
    font: inherit;
    padding: 0.85rem 1rem;
    resize: vertical;
    width: 100%;
}

.prestamo-selected-box,
.prestamo-added-box {
    border: 1px solid #2f6f9f;
    border-radius: 0.85rem;
    margin-top: 1.6rem;
    padding: 1rem;
}

.prestamo-selected-box h3,
.prestamo-added-box h3 {
    font-size: 0.98rem;
    margin: 0 0 0.9rem;
}

.prestamo-selected-list {
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
}

.prestamo-selected-item {
    align-items: center;
    border: 1px solid #d7dde6;
    border-radius: 0.75rem;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: 0.85rem 1rem;
}

.prestamo-selected-item__actions {
    align-items: center;
    display: flex;
    flex-shrink: 0;
    gap: 0.75rem;
}

.prestamo-document-type {
    width: 8rem;
}

.prestamo-selected-item span,
.prestamo-selected-item em {
    color: #64748b;
    display: block;
    font-size: 0.86rem;
    margin-top: 0.35rem;
}

.prestamo-selected-item .prestamo-document-title,
.prestamo-confirm__items .prestamo-document-title {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 400;
    margin-top: 0;
}

.prestamo-selected-item button {
    background: transparent;
    border: 0;
    color: #ef4444;
    cursor: pointer;
    font-size: 1rem;
}

@media (max-width: 640px) {
    .prestamo-selected-item {
        align-items: stretch;
        flex-direction: column;
    }

    .prestamo-selected-item__actions {
        justify-content: flex-end;
    }
}

.prestamo-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.prestamo-primary,
.prestamo-secondary {
    align-items: center;
    border-radius: 0.75rem;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    font-weight: 800;
    gap: 0.55rem;
    justify-content: center;
    min-height: 2.8rem;
    min-width: 9rem;
    padding: 0 1.2rem;
}

.prestamo-primary {
    background: var(--ugda-brand);
    border: 1px solid var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.prestamo-primary:disabled {
    cursor: not-allowed;
}

.prestamo-secondary {
    background: var(--ugda-surface-card);
    border: 1px solid var(--ugda-border-strong);
    color: var(--ugda-text-secondary);
}

:global(.prestamo-confirm-dialog .p-dialog-header) {
    display: none;
}

.prestamo-confirm {
    background: #ffffff;
}

.prestamo-confirm__header {
    align-items: flex-start;
    background: #2f6f9f;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    padding: 1.35rem 1.5rem;
}

.prestamo-confirm__header h2,
.prestamo-confirm__header p {
    margin: 0;
}

.prestamo-confirm__header h2 {
    color: #ffffff;
    font-size: 1.3rem;
    font-weight: 800;
}

.prestamo-confirm__header p {
    margin-top: 0.45rem;
}

.prestamo-confirm__header button {
    background: transparent;
    border: 0;
    color: #ffffff;
    cursor: pointer;
    font-size: 1.15rem;
}

.prestamo-confirm__body {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-height: 68vh;
    overflow-y: auto;
    padding: 1.5rem;
}

.prestamo-confirm__notice {
    align-items: center;
    background: #cffafe;
    border-left: 4px solid #06b6d4;
    border-radius: 0.75rem;
    color: #155e75;
    display: flex;
    gap: 0.8rem;
    padding: 1rem;
}

.prestamo-confirm__section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.85rem;
    padding: 1rem;
}

.prestamo-confirm__section h3 {
    border-bottom: 1px solid #dbe3ed;
    margin: 0 0 1rem;
    padding-bottom: 0.75rem;
}

.prestamo-confirm__grid,
.prestamo-confirm__totals {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.prestamo-confirm__grid span,
.prestamo-confirm__totals span,
.prestamo-confirm__items span,
.prestamo-confirm__items em {
    color: #64748b;
    display: block;
    font-size: 0.88rem;
    margin-top: 0.35rem;
}

.prestamo-confirm__items {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.prestamo-confirm__items > div,
.prestamo-confirm__totals > div {
    background: #ffffff;
    border: 1px solid #dbe3ed;
    border-radius: 0.75rem;
    padding: 0.9rem 1rem;
}

.prestamo-confirm__totals strong {
    color: #2f6f9f;
    display: block;
    font-size: 1.85rem;
    margin-top: 0.5rem;
    text-align: center;
}

.prestamo-confirm__footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    padding: 1rem 1.5rem;
}

@media (max-width: 991px) {
    .prestamo-title {
        font-size: 1.55rem;
    }

    .prestamo-section-head,
    .prestamo-actions,
    .prestamo-confirm__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .prestamo-document__grid,
    .prestamo-confirm__grid,
    .prestamo-confirm__totals {
        grid-template-columns: 1fr;
    }

    .prestamo-primary,
    .prestamo-secondary {
        width: 100%;
    }
}

@media (max-width: 640px) {
    .prestamo-header,
    .prestamo-info,
    .prestamo-document,
    .prestamo-selected-item,
    .prestamo-confirm__notice {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
