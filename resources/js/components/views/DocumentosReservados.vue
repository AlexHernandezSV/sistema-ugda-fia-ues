<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const reservedDocuments = ref([]);
const reservedQuery = ref('');
const loadingReserved = ref(false);
const addDialogVisible = ref(false);
const availableDocuments = ref([]);
const availableQuery = ref('');
const loadingAvailable = ref(false);
const selectedDocument = ref(null);
const submitting = ref(false);
const removingId = ref(null);
const removeDialogVisible = ref(false);
const documentPendingRemoval = ref(null);
const removeForm = ref({
    current_password: '',
});
let availableSearchTimer = null;
let reservedSearchTimer = null;

const reservedCount = computed(() => reservedDocuments.value.length);
const alreadyReservedIds = computed(() => new Set(reservedDocuments.value.map((document) => document.id)));
const availableResults = computed(() =>
    availableDocuments.value.filter((document) => !alreadyReservedIds.value.has(document.id) && !document.is_reserved)
);
const canSubmit = computed(() => selectedDocument.value !== null && !submitting.value);

const goBack = () => {
    router.push('/archivo-general/gestion');
};

const supportTone = (support) => {
    const value = String(support ?? '').toLowerCase();

    if (value.includes('digital')) {
        return 'reserved-support-badge--digital';
    }

    if (value.includes('mixto')) {
        return 'reserved-support-badge--mixed';
    }

    return 'reserved-support-badge--physical';
};

const currentBox = (document) => document?.current_box ?? document?.box ?? {};

const boxCode = (document) => {
    const box = currentBox(document);

    return box.code
        || box.box_code
        || box.boxCode
        || document?.box_code
        || null;
};

const boxLabel = (document) => {
    return boxCode(document) || 'Código de caja no disponible';
};

const locationLabel = (document) => {
    const box = currentBox(document);

    return box.location
        ?? box.location_code
        ?? document?.box_location
        ?? 'Ubicación pendiente';
};

const documentMetadata = (document) => [
    document.series ? `Serie: ${document.series}` : null,
    document.unit?.name ? `Unidad: ${document.unit.name}` : null,
    document.year ? `Año: ${document.year}` : null,
    `Caja actual: ${boxLabel(document)}`,
].filter(Boolean);

const fetchReservedDocuments = async () => {
    loadingReserved.value = true;

    try {
        const { data } = await axios.get('/api/archive/reserved-documents', {
            params: reservedQuery.value.trim() ? { q: reservedQuery.value.trim() } : {},
        });
        reservedDocuments.value = Array.isArray(data.documents) ? data.documents : [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudieron cargar los documentos reservados.',
            life: 4000,
        });
    } finally {
        loadingReserved.value = false;
    }
};

const fetchAvailableDocuments = async () => {
    loadingAvailable.value = true;

    try {
        const params = availableQuery.value.trim()
            ? { q: availableQuery.value.trim() }
            : { all: 1 };
        const { data } = await axios.get('/api/archive/documents', { params });
        availableDocuments.value = Array.isArray(data.results) ? data.results : [];
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudieron cargar los documentos del archivo.',
            life: 4000,
        });
    } finally {
        loadingAvailable.value = false;
    }
};

const openAddDialog = async () => {
    selectedDocument.value = null;
    availableQuery.value = '';
    addDialogVisible.value = true;
    await fetchAvailableDocuments();
};

const closeAddDialog = () => {
    if (submitting.value) {
        return;
    }

    addDialogVisible.value = false;
    selectedDocument.value = null;
};

const selectDocument = (document) => {
    selectedDocument.value = document;
};

const addReservedDocument = async () => {
    if (!canSubmit.value) {
        toast.add({
            severity: 'warn',
            summary: 'Documento requerido',
            detail: 'Seleccione un documento para marcarlo como reservado.',
            life: 3000,
        });
        return;
    }

    submitting.value = true;

    try {
        const { data } = await axios.post('/api/archive/reserved-documents', {
            document_id: selectedDocument.value.id,
        });

        toast.add({
            severity: 'success',
            summary: 'Documento reservado',
            detail: data.message ?? `El documento "${selectedDocument.value.title}" fue agregado a reservados.`,
            life: 3500,
        });
        addDialogVisible.value = false;
        selectedDocument.value = null;
        await fetchReservedDocuments();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudo agregar el documento a reservados.',
            life: 4200,
        });
    } finally {
        submitting.value = false;
    }
};

const removeReservedDocument = (document) => {
    documentPendingRemoval.value = document;
    removeForm.value.current_password = '';
    removeDialogVisible.value = true;
};

const closeRemoveDialog = () => {
    if (removingId.value) {
        return;
    }

    removeDialogVisible.value = false;
    documentPendingRemoval.value = null;
    removeForm.value.current_password = '';
};

const confirmRemoveReservedDocument = async () => {
    if (!documentPendingRemoval.value) {
        return;
    }

    if (!removeForm.value.current_password) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmacion requerida',
            detail: 'Ingrese su contraseña para confirmar la eliminación.',
            life: 3200,
        });
        return;
    }

    const document = documentPendingRemoval.value;
    removingId.value = document.id;

    try {
        const { data } = await axios.delete(`/api/archive/reserved-documents/${document.id}`, {
            data: {
                current_password: removeForm.value.current_password,
            },
        });
        toast.add({
            severity: 'success',
            summary: 'Documento removido',
            detail: data.message ?? `El documento "${document.title}" fue removido de reservados.`,
            life: 3200,
        });
        removeDialogVisible.value = false;
        documentPendingRemoval.value = null;
        removeForm.value.current_password = '';
        await fetchReservedDocuments();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudo remover el documento de reservados.',
            life: 4200,
        });
    } finally {
        removingId.value = null;
    }
};

watch(availableQuery, () => {
    clearTimeout(availableSearchTimer);
    availableSearchTimer = setTimeout(fetchAvailableDocuments, 250);
});

watch(reservedQuery, () => {
    clearTimeout(reservedSearchTimer);
    reservedSearchTimer = setTimeout(fetchReservedDocuments, 250);
});

onMounted(fetchReservedDocuments);

onBeforeUnmount(() => {
    clearTimeout(availableSearchTimer);
    clearTimeout(reservedSearchTimer);
});
</script>

<template>
    <div class="reserved-page fadein animation-duration-500">
        <header class="reserved-header">
            <button type="button" class="reserved-back-button" @click="goBack">
                <i class="pi pi-arrow-left"></i>
                Volver
            </button>
            <span class="reserved-header-separator"></span>
            <h1 class="mobile-page-title">Documentos Reservados</h1>
        </header>

        <main class="reserved-main">
            <section class="reserved-warning">
                <i class="pi pi-exclamation-triangle"></i>
                <div>
                        <strong>Sobre los documentos reservados</strong>
                    <p>
                        Los documentos marcados como «reservados» no pueden ser prestados bajo ninguna circunstancia.
                        Esta clasificación se utiliza para documentos de alta sensibilidad, confidenciales o de valor
                        histórico irreemplazable.
                    </p>
                </div>
            </section>

            <section class="reserved-card">
                <div class="reserved-card__header">
                    <div class="reserved-title">
                        <i class="pi pi-shield"></i>
                        <h2>Documentos Reservados ({{ reservedCount }})</h2>
                    </div>

                    <div class="reserved-toolbar">
                        <label class="reserved-search">
                            <i class="pi pi-search"></i>
                            <InputText
                                v-model="reservedQuery"
                                type="text"
                                placeholder="Buscar por código, nombre, serie o unidad..."
                            />
                        </label>
                        <Button
                            type="button"
                            icon="pi pi-plus"
                            label="Agregar documento"
                            class="reserved-add-button"
                            @click="openAddDialog"
                        />
                    </div>
                </div>

                <div v-if="loadingReserved" class="reserved-state">
                    <i class="pi pi-spin pi-spinner"></i>
                    <span>Cargando documentos reservados...</span>
                </div>

                <div v-else-if="reservedDocuments.length === 0" class="reserved-empty">
                    <i class="pi pi-shield"></i>
                                <span>No hay documentos reservados.</span>
                </div>

                <div v-else class="reserved-list" role="list">
                    <article
                        v-for="document in reservedDocuments"
                        :key="document.id"
                        class="reserved-row"
                        role="listitem"
                    >
                        <div class="reserved-row__content">
                            <div class="reserved-row__topline">
                                <strong>{{ document.code }}</strong>
                                <span class="reserved-support-badge" :class="supportTone(document.support)">
                                    {{ document.support === 'Fisico' ? 'Físico' : (document.support ?? 'Sin soporte') }}
                                </span>
                            </div>
                            <h3>{{ document.title }}</h3>
                            <div class="reserved-row__meta">
                                <span v-for="item in documentMetadata(document)" :key="item">{{ item }}</span>
                            </div>
                            <small>
                                Ubicación actual: {{ locationLabel(document) }}
                                <template v-if="document.reserved_at_label">
                                    · Reservado: {{ document.reserved_at_label }}
                                </template>
                            </small>
                        </div>
                        <Button
                            type="button"
                            icon="pi pi-times"
                            severity="secondary"
                            text
                            rounded
                            title="Quitar de reservados"
                            :loading="removingId === document.id"
                            @click="removeReservedDocument(document)"
                        />
                    </article>
                </div>
            </section>
        </main>

        <Dialog
            v-model:visible="addDialogVisible"
            modal
            :draggable="false"
            :closable="false"
            class="reserved-dialog"
            :style="{ width: 'min(900px, 94vw)' }"
        >
            <template #container>
                <div class="reserved-modal">
                    <header class="reserved-modal__header">
                        <div>
                            <h2>Agregar documento a reservados</h2>
                            <p>Busca y selecciona el documento que deseas marcar como reservado.</p>
                        </div>
                        <button type="button" class="reserved-modal__close" @click="closeAddDialog">
                            <i class="pi pi-arrow-left"></i>
                        </button>
                    </header>

                    <div class="reserved-modal__body">
                        <section class="reserved-warning reserved-warning--modal">
                            <i class="pi pi-exclamation-triangle"></i>
                            <div>
                                <strong>Importante</strong>
                                <p>
                                    Al agregar un documento a la lista de reservados, este <b>no podrá ser prestado</b>
                                    hasta que sea removido de esta lista.
                                </p>
                            </div>
                        </section>

                        <label class="reserved-search reserved-search--wide">
                            <i class="pi pi-search"></i>
                            <InputText
                                v-model="availableQuery"
                                type="text"
                                placeholder="Buscar por código, nombre, serie o unidad productora..."
                            />
                        </label>

                        <div class="reserved-picker" role="list">
                            <div v-if="loadingAvailable" class="reserved-picker__state">
                                <i class="pi pi-spin pi-spinner"></i>
                                Cargando documentos...
                            </div>
                            <template v-else>
                                <button
                                    v-for="document in availableResults"
                                    :key="document.id"
                                    type="button"
                                    class="reserved-option"
                                    :class="{ 'reserved-option--selected': selectedDocument?.id === document.id }"
                                    @click="selectDocument(document)"
                                >
                                    <div>
                                        <div class="reserved-row__topline">
                                            <strong>{{ document.code }}</strong>
                                            <span class="reserved-support-badge" :class="supportTone(document.support)">
                                                {{ document.support === 'Fisico' ? 'Físico' : (document.support ?? 'Sin soporte') }}
                                            </span>
                                        </div>
                                        <h3>{{ document.title }}</h3>
                                        <div class="reserved-row__meta">
                                            <span v-for="item in documentMetadata(document)" :key="item">{{ item }}</span>
                                        </div>
                                    </div>
                                    <i v-if="selectedDocument?.id === document.id" class="pi pi-check"></i>
                                </button>
                            </template>
                            <div
                                v-if="!loadingAvailable && availableResults.length === 0"
                                class="reserved-picker__state"
                            >
                                No hay documentos disponibles con ese criterio.
                            </div>
                        </div>
                    </div>

                    <footer class="reserved-modal__footer">
                        <Button
                            type="button"
                            label="Cancelar"
                            severity="secondary"
                            outlined
                            :disabled="submitting"
                            @click="closeAddDialog"
                        />
                        <Button
                            type="button"
                            icon="pi pi-shield"
                            label="Agregar a Reservados"
                            :disabled="!canSubmit"
                            :loading="submitting"
                            @click="addReservedDocument"
                        />
                    </footer>
                </div>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="removeDialogVisible"
            modal
            :closable="!removingId"
            :dismissableMask="!removingId"
            :style="{ width: '440px', maxWidth: '92vw' }"
            class="reserved-remove-dialog"
        >
            <template #header>
                <div class="reserved-remove-dialog__header">
                    <span class="reserved-remove-dialog__icon">
                        <i class="pi pi-exclamation-triangle"></i>
                    </span>
                    <span>Confirmar accion</span>
                </div>
            </template>

            <div class="reserved-remove-dialog__body" v-if="documentPendingRemoval">
                <p>
                    Se quitara el documento
                    <strong>"{{ documentPendingRemoval.title }}"</strong>
                    de documentos reservados.
                </p>
                <p>Esta accion no se puede revertir.</p>
                <label class="reserved-remove-dialog__password">
                    <span>Ingrese su contraseña para confirmar</span>
                    <InputText
                        v-model="removeForm.current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Ingrese su contraseña"
                        :disabled="!!removingId"
                        class="w-full"
                        @keyup.enter="confirmRemoveReservedDocument"
                    />
                </label>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="!!removingId"
                    @click="closeRemoveDialog"
                />
                <Button
                    label="Aceptar"
                    severity="danger"
                    :loading="!!removingId"
                    @click="confirmRemoveReservedDocument"
                />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.reserved-page {
    background: var(--ugda-surface-page);
    color: var(--ugda-text-primary);
    font-family: var(--ugda-font-family);
    font-size: var(--ugda-font-body);
    margin: -1.5rem;
    min-height: 100vh;
}

.reserved-header {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid var(--ugda-border);
    display: flex;
    gap: 1rem;
    min-height: 5.25rem;
    padding: 0 var(--ugda-space-xl);
}

.reserved-back-button,
.reserved-modal__close {
    align-items: center;
    background: transparent;
    border: 0;
    color: var(--ugda-brand-hover);
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 0.55rem;
    padding: 0;
}

.reserved-header-separator {
    background: var(--ugda-border);
    height: 1.6rem;
    width: 1px;
}

.reserved-header h1 {
    color: var(--ugda-brand) !important;
    font-size: 1.55rem !important;
    font-weight: var(--ugda-weight-semibold) !important;
    line-height: 1.15;
    margin: 0;
}

.reserved-main {
    display: grid;
    gap: var(--ugda-space-lg);
    padding: var(--ugda-space-xl);
}

.reserved-warning {
    align-items: flex-start;
    background: var(--ugda-warning-soft);
    border: 1px solid var(--ugda-warning);
    border-radius: var(--ugda-radius-md);
    color: var(--ugda-warning-text);
    display: flex;
    font-size: var(--ugda-font-body);
    gap: 0.75rem;
    padding: 0.85rem 1rem;
}

.reserved-warning i {
    color: var(--ugda-warning);
    margin-top: 0.1rem;
}

.reserved-warning strong {
    display: block;
    font-size: var(--ugda-font-body);
    margin-bottom: 0.35rem;
}

.reserved-warning p {
    line-height: 1.45;
    margin: 0;
}

.reserved-card {
    background: var(--ugda-surface-card);
    border: 1px solid var(--ugda-border);
    border-radius: var(--ugda-radius-lg);
    overflow: hidden;
}

.reserved-card__header {
    align-items: center;
    border-bottom: 1px solid var(--ugda-border);
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: var(--ugda-space-md) var(--ugda-space-lg);
}

.reserved-title {
    align-items: center;
    display: flex;
    gap: 0.8rem;
}

.reserved-title i {
    color: var(--ugda-brand-hover);
    font-size: 1.2rem;
}

.reserved-title h2 {
    color: var(--ugda-text-primary) !important;
    font-size: var(--ugda-font-card-title) !important;
    font-weight: var(--ugda-weight-semibold) !important;
    line-height: 1.25;
    margin: 0;
}

.reserved-toolbar {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    min-width: min(38rem, 100%);
}

.reserved-search {
    align-items: center;
    border: 1px solid var(--ugda-border-strong);
    border-radius: var(--ugda-radius-md);
    display: flex;
    flex: 1;
    gap: 0.65rem;
    min-width: 16rem;
    padding: 0 0.75rem;
}

.reserved-search i {
    color: var(--ugda-text-muted);
}

.reserved-search :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    font-size: var(--ugda-font-body) !important;
    min-height: var(--ugda-control-height);
    padding-left: 0;
    width: 100%;
}

.reserved-search--wide {
    flex: none;
    width: 100%;
}

.reserved-add-button {
    flex: 0 0 auto;
}

.reserved-empty,
.reserved-state {
    align-items: center;
    color: var(--ugda-text-muted);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    min-height: 11rem;
    justify-content: center;
    padding: 2rem;
}

.reserved-empty i {
    color: #cbd5e1;
    font-size: 2.6rem;
}

.reserved-list {
    display: grid;
}

.reserved-row,
.reserved-option {
    align-items: center;
    background: var(--ugda-surface-card);
    border: 0;
    border-bottom: 1px solid var(--ugda-border);
    color: inherit;
    display: flex;
    gap: 0.85rem;
    justify-content: space-between;
    padding: var(--ugda-space-sm) var(--ugda-space-lg);
    text-align: left;
    width: 100%;
}

.reserved-row:last-child,
.reserved-option:last-child {
    border-bottom: 0;
}

.reserved-row__content {
    min-width: 0;
}

.reserved-row__topline {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-bottom: 0.3rem;
}

.reserved-row__topline strong {
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-helper);
}

.reserved-row h3,
.reserved-option h3 {
    color: var(--ugda-text-primary) !important;
    font-size: var(--ugda-font-body) !important;
    font-weight: var(--ugda-weight-semibold) !important;
    line-height: 1.25;
    margin: 0 0 0.35rem;
}

.reserved-row__meta {
    color: var(--ugda-text-secondary);
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.85rem;
    font-size: var(--ugda-font-helper);
}

.reserved-row small {
    color: var(--ugda-text-muted);
    display: block;
    margin-top: 0.5rem;
}

.reserved-support-badge {
    border-radius: 999px;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-semibold);
    padding: 0.16rem 0.48rem;
}

.reserved-support-badge--physical {
    background: var(--ugda-surface-soft);
    color: var(--ugda-text-secondary);
}

.reserved-support-badge--digital {
    background: var(--ugda-success-soft);
    color: var(--ugda-success-text);
}

.reserved-support-badge--mixed {
    background: #f3e8ff;
    color: #7e22ce;
}

.reserved-dialog :deep(.p-dialog-content) {
    padding: 0;
}

.reserved-modal {
    background: var(--ugda-surface-card);
    border-radius: var(--ugda-radius-lg);
    overflow: hidden;
}

.reserved-modal__header {
    align-items: center;
    background: var(--ugda-brand-hover);
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    padding: var(--ugda-space-lg);
}

.reserved-modal__header h2 {
    color: var(--ugda-text-inverse) !important;
    font-size: var(--ugda-font-h3) !important;
    font-weight: var(--ugda-weight-semibold) !important;
    line-height: 1.25;
    margin: 0 0 0.25rem;
}

.reserved-modal__header p {
    color: var(--ugda-text-inverse);
    font-size: var(--ugda-font-secondary);
    line-height: 1.35;
    margin: 0;
}

.reserved-modal__close {
    color: var(--ugda-text-inverse);
    font-size: 1.1rem;
    height: 2.2rem;
    justify-content: center;
    width: 2.2rem;
}

.reserved-modal__body {
    display: grid;
    gap: var(--ugda-space-md);
    padding: var(--ugda-space-lg);
}

.reserved-warning--modal {
    padding: 0.8rem 0.9rem;
}

.reserved-picker {
    border: 1px solid var(--ugda-border);
    border-radius: var(--ugda-radius-md);
    max-height: 21.8rem;
    overflow: auto;
}

.reserved-option {
    cursor: pointer;
}

.reserved-option:hover {
    background: var(--ugda-surface-page);
}

.reserved-option--selected {
    background: var(--ugda-brand-soft);
    box-shadow: inset 4px 0 0 var(--ugda-brand-hover);
}

.reserved-option > i {
    align-items: center;
    background: var(--ugda-brand-hover);
    border-radius: 999px;
    color: #ffffff;
    display: inline-flex;
    flex: 0 0 auto;
    height: 1.5rem;
    justify-content: center;
    width: 1.5rem;
}

.reserved-picker__state {
    color: var(--ugda-text-muted);
    padding: 1.25rem;
    text-align: center;
}

.reserved-modal__footer {
    align-items: center;
    border-top: 1px solid var(--ugda-border);
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    padding: var(--ugda-space-sm) var(--ugda-space-lg);
}

.reserved-remove-dialog__header {
    align-items: center;
    color: #111827;
    display: flex;
    font-weight: 700;
    gap: 0.75rem;
}

.reserved-remove-dialog__icon {
    align-items: center;
    background: #fee2e2;
    border-radius: 999px;
    color: #dc2626;
    display: inline-flex;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.reserved-remove-dialog__body {
    display: grid;
    gap: 0.65rem;
}

.reserved-remove-dialog__body p {
    color: #475569;
    line-height: 1.5;
    margin: 0;
}

.reserved-remove-dialog__body strong {
    color: #111827;
    font-weight: 700;
}

.reserved-remove-dialog__password {
    display: grid;
    gap: 0.4rem;
    margin-top: 0.35rem;
}

.reserved-remove-dialog__password span {
    color: #334155;
    font-size: 0.9rem;
    font-weight: 600;
}

@media (max-width: 768px) {
    .reserved-page {
        margin: -1rem;
    }

    .reserved-header {
        display: none;
    }

    .reserved-main {
        padding: 1rem;
    }

    .reserved-card__header,
    .reserved-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .reserved-search {
        min-width: 0;
    }

    .reserved-row,
    .reserved-option {
        align-items: flex-start;
    }

    .reserved-modal__footer {
        flex-direction: column-reverse;
    }

    .reserved-modal__footer :deep(.p-button) {
        width: 100%;
    }
}
</style>
