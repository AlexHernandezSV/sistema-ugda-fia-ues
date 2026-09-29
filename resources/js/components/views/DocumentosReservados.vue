<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const reservedDocuments = ref([]);
const selectedReservedDocuments = ref([]);
const reservedQuery = ref('');
const loadingReserved = ref(false);
const addDialogVisible = ref(false);
const availableDocuments = ref([]);
const availableResultsCount = ref(0);
const availablePagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});
const availableQuery = ref('');
const loadingAvailable = ref(false);
const classificationOptions = ref([]);
const selectedClassification = ref(null);
const selectedDocuments = ref([]);
const loadingClassifications = ref(false);
const submitting = ref(false);
const removingId = ref(null);
const removeDialogVisible = ref(false);
const documentPendingRemoval = ref(null);
const removeForm = ref({
    current_password: '',
});
const removeClassificationDialogVisible = ref(false);
const selectedRemovalClassification = ref(null);
const removalClassificationCount = ref(null);
const checkingRemovalClassification = ref(false);
const removingClassification = ref(false);
const removeClassificationForm = ref({
    current_password: '',
});
const removeSelectedDialogVisible = ref(false);
const removingSelectedDocuments = ref(false);
const removeSelectedForm = ref({
    current_password: '',
});
const rowsPerPageOptions = [5, 10, 25, 50];
const reservedPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});
let reservedSearchTimer = null;

const reservedCount = computed(() => reservedPagination.value.total);
const reservedFirst = computed(() => (
    (reservedPagination.value.current_page - 1) * reservedPagination.value.per_page
));
const alreadyReservedIds = computed(() => new Set(reservedDocuments.value.map((document) => document.id)));
const availableResults = computed(() =>
    availableDocuments.value.filter((document) => !alreadyReservedIds.value.has(document.id) && !document.is_reserved)
);
const selectedDocumentIds = computed(() => new Set(selectedDocuments.value.map((document) => document.id)));
const selectedClassificationOption = computed(() => (
    classificationOptions.value.find((option) => option.value === selectedClassification.value) ?? null
));
const selectedRemovalClassificationOption = computed(() => (
    classificationOptions.value.find((option) => option.value === selectedRemovalClassification.value) ?? null
));
const allVisibleSelected = computed(() => (
    availableResults.value.length > 0
    && availableResults.value.every((document) => selectedDocumentIds.value.has(document.id))
));
const availablePageLabel = computed(() => {
    if (availablePagination.value.last_page <= 1 || !availablePagination.value.total) {
        return '';
    }

    return `Mostrando ${availablePagination.value.from ?? 0}-${availablePagination.value.to ?? 0}`
        + ` de ${availablePagination.value.total} documentos.`;
});
const availableVisiblePages = computed(() => {
    const totalPages = availablePagination.value.last_page || 1;
    const currentPage = availablePagination.value.current_page || 1;
    const start = Math.max(1, currentPage - 1);
    const end = Math.min(totalPages, start + 2);
    const normalizedStart = Math.max(1, end - 2);
    const pages = [];

    for (let page = normalizedStart; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});
const canSubmit = computed(() => (
    ((selectedClassification.value !== null && availableResultsCount.value > 0)
        || selectedDocuments.value.length > 0)
    && !loadingAvailable.value
    && !submitting.value
));
const canRemoveClassification = computed(() => (
    selectedRemovalClassification.value !== null
    && removalClassificationCount.value > 0
    && removeClassificationForm.value.current_password !== ''
    && !checkingRemovalClassification.value
    && !removingClassification.value
));
const canRemoveSelectedDocuments = computed(() => (
    selectedReservedDocuments.value.length > 0
    && removeSelectedForm.value.current_password !== ''
    && !removingSelectedDocuments.value
));
const selectionSummary = computed(() => {
    if (selectedClassificationOption.value) {
        if (loadingAvailable.value) {
            return `Consultando documentos disponibles de ${selectedClassificationOption.value.label}...`;
        }

        if (availableResultsCount.value === 0) {
            return `No hay documentos disponibles de ${selectedClassificationOption.value.label}.`;
        }

        const countLabel = availableResultsCount.value === 1
            ? '1 documento disponible'
            : `${availableResultsCount.value} documentos disponibles`;

        return `Se reservarán todos los documentos de ${selectedClassificationOption.value.label} (${countLabel}).`;
    }

    if (selectedDocuments.value.length > 0) {
        return `${selectedDocuments.value.length} documento(s) seleccionado(s).`;
    }

    return 'Seleccione uno o más documentos, o una subserie completa.';
});

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

const fetchReservedDocuments = async (
    page = 1,
    perPage = reservedPagination.value.per_page,
) => {
    loadingReserved.value = true;

    try {
        const { data } = await axios.get('/api/archive/reserved-documents', {
            params: {
                page,
                per_page: perPage,
                ...(reservedQuery.value.trim() ? { q: reservedQuery.value.trim() } : {}),
            },
        });
        reservedDocuments.value = Array.isArray(data.documents ?? data.data)
            ? (data.documents ?? data.data)
            : [];
        reservedPagination.value = data.meta ?? reservedPagination.value;
    } catch (error) {
        reservedDocuments.value = [];
        reservedPagination.value = {
            ...reservedPagination.value,
            current_page: 1,
            last_page: 1,
            total: 0,
            from: null,
            to: null,
        };
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

const onReservedPage = (event) => {
    fetchReservedDocuments(event.page + 1, event.rows);
};

const resetAvailablePagination = () => {
    availablePagination.value = {
        ...availablePagination.value,
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
    };
};

const fetchAvailableDocuments = async (
    page = 1,
    perPage = availablePagination.value.per_page,
) => {
    const searchTerm = availableQuery.value.trim();

    if (!selectedClassification.value && !searchTerm) {
        availableDocuments.value = [];
        availableResultsCount.value = 0;
        resetAvailablePagination();
        loadingAvailable.value = false;
        return;
    }

    loadingAvailable.value = true;

    try {
        const params = {
            available_for_reservation: 1,
            page,
            per_page: perPage,
            ...(selectedClassification.value
                ? { classification_id: selectedClassification.value }
                : {}),
            ...(searchTerm ? { q: searchTerm } : {}),
            ...(!searchTerm && selectedClassification.value ? { all: 1 } : {}),
        };
        const { data } = await axios.get('/api/archive/documents', { params });
        availableDocuments.value = Array.isArray(data.results) ? data.results : [];
        availableResultsCount.value = Number(data.results_count ?? data.meta?.total ?? availableDocuments.value.length);
        availablePagination.value = data.meta ?? {
            ...availablePagination.value,
            current_page: page,
            per_page: perPage,
            last_page: 1,
            total: availableResultsCount.value,
            from: availableDocuments.value.length > 0 ? 1 : null,
            to: availableDocuments.value.length,
        };
    } catch (error) {
        availableDocuments.value = [];
        availableResultsCount.value = 0;
        resetAvailablePagination();
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

const fetchClassificationOptions = async () => {
    loadingClassifications.value = true;

    try {
        const { data } = await axios.get('/api/archive/catalog');
        const subseries = Array.isArray(data.series) ? data.series : [];

        classificationOptions.value = subseries.map((option) => ({
            value: option.value,
            label: option.label,
        }));
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudieron cargar las subseries documentales.',
            life: 4000,
        });
    } finally {
        loadingClassifications.value = false;
    }
};

const openAddDialog = async () => {
    selectedClassification.value = null;
    selectedDocuments.value = [];
    availableDocuments.value = [];
    availableResultsCount.value = 0;
    resetAvailablePagination();
    availableQuery.value = '';
    addDialogVisible.value = true;
    await fetchClassificationOptions();
};

const closeAddDialog = () => {
    if (submitting.value) {
        return;
    }

    addDialogVisible.value = false;
    selectedClassification.value = null;
    selectedDocuments.value = [];
    availableDocuments.value = [];
    availableResultsCount.value = 0;
    resetAvailablePagination();
    availableQuery.value = '';
};

const selectDocument = (document) => {
    if (selectedClassification.value) {
        return;
    }

    selectedDocuments.value = selectedDocumentIds.value.has(document.id)
        ? selectedDocuments.value.filter((item) => item.id !== document.id)
        : [...selectedDocuments.value, document];
};

const toggleAllVisible = () => {
    if (selectedClassification.value) {
        return;
    }

    const visibleIds = new Set(availableResults.value.map((document) => document.id));

    selectedDocuments.value = allVisibleSelected.value
        ? selectedDocuments.value.filter((document) => !visibleIds.has(document.id))
        : [
            ...selectedDocuments.value,
            ...availableResults.value.filter((document) => !selectedDocumentIds.value.has(document.id)),
        ];
};

const clearSelectedDocuments = () => {
    selectedDocuments.value = [];
};

const onClassificationChange = async () => {
    selectedDocuments.value = [];
    availableQuery.value = '';

    await fetchAvailableDocuments(1);
};

const searchAvailableDocuments = async () => {
    await fetchAvailableDocuments(1);
};

const onAvailablePage = (page) => {
    if (page < 1 || page > availablePagination.value.last_page
        || page === availablePagination.value.current_page || loadingAvailable.value) {
        return;
    }

    fetchAvailableDocuments(page);
};

const changeAvailablePageSize = () => {
    fetchAvailableDocuments(1, availablePagination.value.per_page);
};

const clearAvailableSearch = async () => {
    availableQuery.value = '';
    selectedDocuments.value = [];

    if (selectedClassification.value) {
        await fetchAvailableDocuments(1);
        return;
    }

    availableDocuments.value = [];
    availableResultsCount.value = 0;
    resetAvailablePagination();
};

const addReservedDocument = async () => {
    if (!canSubmit.value) {
        toast.add({
            severity: 'warn',
            summary: 'Documento requerido',
            detail: 'Seleccione uno o más documentos, o una subserie completa, para reservar.',
            life: 3000,
        });
        return;
    }

    submitting.value = true;

    try {
        const payload = selectedClassification.value
            ? { classification_id: selectedClassification.value }
            : { document_ids: selectedDocuments.value.map((document) => document.id) };
        const { data } = await axios.post('/api/archive/reserved-documents', payload);

        toast.add({
            severity: 'success',
            summary: 'Documento reservado',
            detail: data.message ?? 'Los documentos seleccionados fueron agregados a reservados.',
            life: 3500,
        });
        addDialogVisible.value = false;
        selectedClassification.value = null;
        selectedDocuments.value = [];
        await fetchReservedDocuments(1);
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

const openRemoveClassificationDialog = async () => {
    selectedRemovalClassification.value = null;
    removalClassificationCount.value = null;
    checkingRemovalClassification.value = false;
    removeClassificationForm.value.current_password = '';
    removeClassificationDialogVisible.value = true;

    if (classificationOptions.value.length === 0) {
        await fetchClassificationOptions();
    }
};

const closeRemoveClassificationDialog = () => {
    if (removingClassification.value) {
        return;
    }

    removeClassificationDialogVisible.value = false;
    selectedRemovalClassification.value = null;
    removalClassificationCount.value = null;
    checkingRemovalClassification.value = false;
    removeClassificationForm.value.current_password = '';
};

const checkRemovalClassification = async () => {
    removalClassificationCount.value = null;
    removeClassificationForm.value.current_password = '';

    if (!selectedRemovalClassification.value) {
        return;
    }

    checkingRemovalClassification.value = true;

    try {
        const { data } = await axios.get('/api/archive/reserved-documents', {
            params: {
                classification_id: selectedRemovalClassification.value,
                page: 1,
                per_page: 5,
            },
        });

        removalClassificationCount.value = Number(data.meta?.total ?? data.count ?? 0);
    } catch (error) {
        removalClassificationCount.value = null;
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message
                ?? 'No se pudo verificar si la subserie tiene documentos reservados.',
            life: 4200,
        });
    } finally {
        checkingRemovalClassification.value = false;
    }
};

const confirmRemoveClassification = async () => {
    if (removalClassificationCount.value === 0) {
        toast.add({
            severity: 'warn',
            summary: 'Sin documentos reservados',
            detail: 'No hay documentos reservados para esta serie/subserie.',
            life: 3200,
        });
        return;
    }

    if (!selectedRemovalClassification.value
        || removalClassificationCount.value === null
        || !removeClassificationForm.value.current_password) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmación requerida',
            detail: 'Seleccione una subserie e ingrese su contraseña para continuar.',
            life: 3200,
        });
        return;
    }

    removingClassification.value = true;

    try {
        const { data } = await axios.delete('/api/archive/reserved-documents', {
            data: {
                classification_id: selectedRemovalClassification.value,
                current_password: removeClassificationForm.value.current_password,
            },
        });

        toast.add({
            severity: 'success',
            summary: 'Subserie removida',
            detail: data.message ?? 'Los documentos de la subserie fueron removidos de reservados.',
            life: 3800,
        });
        removeClassificationDialogVisible.value = false;
        selectedRemovalClassification.value = null;
        removalClassificationCount.value = null;
        removeClassificationForm.value.current_password = '';
        selectedReservedDocuments.value = [];
        await fetchReservedDocuments(1);
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message
                ?? 'No se pudieron quitar los documentos de la subserie de reservados.',
            life: 4200,
        });
    } finally {
        removingClassification.value = false;
    }
};

const openRemoveSelectedDialog = () => {
    if (selectedReservedDocuments.value.length === 0) {
        return;
    }

    removeSelectedForm.value.current_password = '';
    removeSelectedDialogVisible.value = true;
};

const closeRemoveSelectedDialog = () => {
    if (removingSelectedDocuments.value) {
        return;
    }

    removeSelectedDialogVisible.value = false;
    removeSelectedForm.value.current_password = '';
};

const clearSelectedReservedDocuments = () => {
    selectedReservedDocuments.value = [];
};

const confirmRemoveSelectedDocuments = async () => {
    if (!canRemoveSelectedDocuments.value) {
        toast.add({
            severity: 'warn',
            summary: 'Confirmación requerida',
            detail: 'Seleccione al menos un documento e ingrese su contraseña para continuar.',
            life: 3200,
        });
        return;
    }

    const selectedIds = selectedReservedDocuments.value.map((document) => document.id);
    const visibleIds = new Set(reservedDocuments.value.map((document) => document.id));
    const removedFromCurrentPage = selectedIds.filter((id) => visibleIds.has(id)).length;
    const targetPage = removedFromCurrentPage === reservedDocuments.value.length
        && reservedPagination.value.current_page > 1
        ? reservedPagination.value.current_page - 1
        : reservedPagination.value.current_page;

    removingSelectedDocuments.value = true;

    try {
        const { data } = await axios.delete('/api/archive/reserved-documents/selected', {
            data: {
                document_ids: selectedIds,
                current_password: removeSelectedForm.value.current_password,
            },
        });

        toast.add({
            severity: 'success',
            summary: 'Documentos removidos',
            detail: data.message ?? 'Los documentos seleccionados fueron removidos de reservados.',
            life: 3800,
        });
        removeSelectedDialogVisible.value = false;
        removeSelectedForm.value.current_password = '';
        selectedReservedDocuments.value = [];
        await fetchReservedDocuments(targetPage);
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message
                ?? 'No se pudieron quitar los documentos seleccionados de reservados.',
            life: 4200,
        });
    } finally {
        removingSelectedDocuments.value = false;
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
        selectedReservedDocuments.value = selectedReservedDocuments.value
            .filter((selectedDocument) => selectedDocument.id !== document.id);
        const targetPage = reservedDocuments.value.length === 1
            && reservedPagination.value.current_page > 1
            ? reservedPagination.value.current_page - 1
            : reservedPagination.value.current_page;
        await fetchReservedDocuments(targetPage);
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

watch(reservedQuery, () => {
    clearTimeout(reservedSearchTimer);
    reservedSearchTimer = setTimeout(() => fetchReservedDocuments(1), 250);
});

onMounted(fetchReservedDocuments);

onBeforeUnmount(() => {
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
                        <div class="reserved-toolbar__actions">
                            <Button
                                type="button"
                                icon="pi pi-trash"
                                label="Quitar subserie"
                                severity="danger"
                                outlined
                                :disabled="reservedCount === 0"
                                @click="openRemoveClassificationDialog"
                            />
                            <Button
                                type="button"
                                icon="pi pi-plus"
                                label="Agregar documento"
                                class="reserved-add-button"
                                @click="openAddDialog"
                            />
                        </div>
                    </div>
                </div>

                <div v-if="selectedReservedDocuments.length > 0" class="reserved-table-selection">
                    <span>
                        <i class="pi pi-check-square" aria-hidden="true"></i>
                        {{ selectedReservedDocuments.length }}
                        {{ selectedReservedDocuments.length === 1 ? 'documento seleccionado' : 'documentos seleccionados' }}
                    </span>
                    <div class="reserved-table-selection__actions">
                        <Button
                            type="button"
                            label="Limpiar selección"
                            severity="secondary"
                            text
                            size="small"
                            @click="clearSelectedReservedDocuments"
                        />
                        <Button
                            type="button"
                            label="Quitar seleccionados"
                            icon="pi pi-trash"
                            severity="danger"
                            size="small"
                            @click="openRemoveSelectedDialog"
                        />
                    </div>
                </div>

                <DataTable
                    v-model:selection="selectedReservedDocuments"
                    :value="reservedDocuments"
                    :loading="loadingReserved"
                    :paginator="true"
                    lazy
                    :first="reservedFirst"
                    :rows="reservedPagination.per_page"
                    :totalRecords="reservedPagination.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    dataKey="id"
                    rowHover
                    class="reserved-table"
                    @page="onReservedPage"
                >
                    <template #empty>
                        <div class="reserved-table__empty">
                            <i class="pi pi-shield"></i>
                            <span>No hay documentos reservados.</span>
                        </div>
                    </template>

                    <Column selectionMode="multiple" headerStyle="width: 3rem" />
                    <Column header="Código" style="min-width: 9rem">
                        <template #body="{ data }">
                            <div class="reserved-table__code">
                                <strong>{{ data.code }}</strong>
                            </div>
                        </template>
                    </Column>
                    <Column header="Soporte" style="min-width: 8rem">
                        <template #body="{ data }">
                            <span class="reserved-support-badge" :class="supportTone(data.support)">
                                {{ data.support === 'Fisico' ? 'Físico' : (data.support ?? 'Sin soporte') }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Documento" style="min-width: 15rem">
                        <template #body="{ data }">
                            <strong class="reserved-table__document">{{ data.title }}</strong>
                        </template>
                    </Column>
                    <Column header="Serie / Subserie" style="min-width: 18rem">
                        <template #body="{ data }">
                            {{ data.series || 'No disponible' }}
                        </template>
                    </Column>
                    <Column header="Unidad" style="min-width: 13rem">
                        <template #body="{ data }">
                            {{ data.unit?.name || 'No disponible' }}
                        </template>
                    </Column>
                    <Column header="Año" style="min-width: 6rem">
                        <template #body="{ data }">
                            {{ data.year || 'No disponible' }}
                        </template>
                    </Column>
                    <Column header="Caja actual" style="min-width: 11rem">
                        <template #body="{ data }">
                            {{ boxLabel(data) }}
                        </template>
                    </Column>
                    <Column header="Ubicación actual" style="min-width: 12rem">
                        <template #body="{ data }">
                            {{ locationLabel(data) }}
                        </template>
                    </Column>
                    <Column header="Reservado" style="min-width: 11rem">
                        <template #body="{ data }">
                            {{ data.reserved_at_label || 'No disponible' }}
                        </template>
                    </Column>
                    <Column header="Acción" style="width: 5rem; text-align: center">
                        <template #body="{ data }">
                            <Button
                                type="button"
                                icon="pi pi-times"
                                severity="secondary"
                                text
                                rounded
                                title="Quitar de reservados"
                                :loading="removingId === data.id"
                                @click="removeReservedDocument(data)"
                            />
                        </template>
                    </Column>
                </DataTable>
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
                            <h2>Agregar documentos a reservados</h2>
                            <p>Seleccione varios documentos o una subserie completa para reservar.</p>
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

                        <div class="reserved-search-group reserved-search-group--wide">
                            <label class="reserved-search reserved-search--wide">
                                <i class="pi pi-search"></i>
                                <InputText
                                    v-model="availableQuery"
                                    type="text"
                                    placeholder="Buscar por código, nombre, serie o unidad productora..."
                                />
                            </label>
                            <div class="reserved-search-actions">
                                <Button
                                    type="button"
                                    label="Buscar"
                                    icon="pi pi-search"
                                    :disabled="!availableQuery.trim() || loadingAvailable"
                                    :loading="loadingAvailable"
                                    @click="searchAvailableDocuments"
                                />
                                <Button
                                    type="button"
                                    label="Limpiar"
                                    icon="pi pi-times"
                                    severity="secondary"
                                    outlined
                                    :disabled="!availableQuery.trim() && selectedDocuments.length === 0"
                                    @click="clearAvailableSearch"
                                />
                            </div>
                        </div>

                        <div class="reserved-bulk-selection">
                            <label class="reserved-field-label" for="reserved-classification">
                                Serie / Subseries
                            </label>
                            <Dropdown
                                id="reserved-classification"
                                v-model="selectedClassification"
                                :options="classificationOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Seleccionar subserie"
                                :loading="loadingClassifications"
                                showClear
                                filter
                                class="w-full"
                                @change="onClassificationChange"
                            />
                        </div>

                        <div class="reserved-selection-toolbar">
                            <span class="reserved-selection-summary">{{ selectionSummary }}</span>
                            <div v-if="!selectedClassification" class="reserved-selection-actions">
                                <Button
                                    v-if="!allVisibleSelected"
                                    type="button"
                                    severity="secondary"
                                    text
                                    size="small"
                                    label="Seleccionar resultados"
                                    :disabled="availableResults.length === 0"
                                    @click="toggleAllVisible"
                                />
                                <Button
                                    v-if="selectedDocuments.length > 0"
                                    type="button"
                                    severity="secondary"
                                    text
                                    size="small"
                                    label="Limpiar selección"
                                    @click="clearSelectedDocuments"
                                />
                            </div>
                        </div>

                        <p v-if="availablePageLabel" class="reserved-picker-pagination__notice">
                            {{ availablePageLabel }}
                        </p>

                        <div class="reserved-picker" role="list">
                            <div v-if="loadingAvailable" class="reserved-picker__state">
                                <i class="pi pi-spin pi-spinner"></i>
                                Cargando documentos...
                            </div>
                            <template v-else-if="selectedClassification || availableQuery.trim()">
                                <button
                                    v-for="document in availableResults"
                                    :key="document.id"
                                    type="button"
                                    class="reserved-option"
                                    :class="{ 'reserved-option--selected': selectedDocumentIds.has(document.id) }"
                                    :disabled="!!selectedClassification"
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
                                    <i v-if="selectedDocumentIds.has(document.id)" class="pi pi-check"></i>
                                </button>
                            <div
                                v-if="!loadingAvailable && availableResults.length === 0"
                                class="reserved-picker__state"
                            >
                                No hay documentos disponibles con ese criterio.
                            </div>
                            </template>
                            <div v-else class="reserved-picker__state">
                                Realice una búsqueda o seleccione una subserie para consultar documentos.
                            </div>
                        </div>

                        <div v-if="availablePagination.last_page > 1" class="reserved-picker-pagination">
                            <div class="reserved-picker-pagination__controls">
                                <div class="reserved-picker-pagination__pages">
                                    <button
                                        type="button"
                                        class="reserved-picker-pagination__icon"
                                        :disabled="availablePagination.current_page <= 1 || loadingAvailable"
                                        aria-label="Primera página"
                                        @click="onAvailablePage(1)"
                                    >
                                        <i class="pi pi-angle-double-left"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="reserved-picker-pagination__icon"
                                        :disabled="availablePagination.current_page <= 1 || loadingAvailable"
                                        aria-label="Página anterior"
                                        @click="onAvailablePage(availablePagination.current_page - 1)"
                                    >
                                        <i class="pi pi-angle-left"></i>
                                    </button>

                                    <button
                                        v-for="page in availableVisiblePages"
                                        :key="page"
                                        type="button"
                                        class="reserved-picker-pagination__number"
                                        :class="{ 'reserved-picker-pagination__number--active': page === availablePagination.current_page }"
                                        :disabled="loadingAvailable"
                                        @click="onAvailablePage(page)"
                                    >
                                        {{ page }}
                                    </button>

                                    <button
                                        type="button"
                                        class="reserved-picker-pagination__icon"
                                        :disabled="availablePagination.current_page >= availablePagination.last_page || loadingAvailable"
                                        aria-label="Página siguiente"
                                        @click="onAvailablePage(availablePagination.current_page + 1)"
                                    >
                                        <i class="pi pi-angle-right"></i>
                                    </button>
                                    <button
                                        type="button"
                                        class="reserved-picker-pagination__icon"
                                        :disabled="availablePagination.current_page >= availablePagination.last_page || loadingAvailable"
                                        aria-label="Última página"
                                        @click="onAvailablePage(availablePagination.last_page)"
                                    >
                                        <i class="pi pi-angle-double-right"></i>
                                    </button>
                                </div>

                                <Dropdown
                                    v-model="availablePagination.per_page"
                                    :options="rowsPerPageOptions"
                                    class="reserved-picker-pagination__size"
                                    :disabled="loadingAvailable"
                                    aria-label="Resultados por página"
                                    @change="changeAvailablePageSize"
                                />
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
                            :label="selectedClassification ? 'Reservar subserie completa' : 'Agregar seleccionados a Reservados'"
                            :disabled="!canSubmit"
                            :loading="submitting"
                            @click="addReservedDocument"
                        />
                    </footer>
                </div>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="removeClassificationDialogVisible"
            modal
            :closable="!removingClassification"
            :dismissableMask="!removingClassification"
            :style="{ width: '560px', maxWidth: '94vw' }"
            class="reserved-remove-dialog"
        >
            <template #header>
                <div class="reserved-remove-dialog__header">
                    <span class="reserved-remove-dialog__icon">
                        <i class="pi pi-exclamation-triangle"></i>
                    </span>
                    <span>Quitar subserie de reservados</span>
                </div>
            </template>

            <div class="reserved-remove-dialog__body">
                <p>
                    Seleccione la subserie cuyos documentos desea quitar de la lista de reservados.
                </p>
                <label class="reserved-remove-dialog__field">
                    <span>Serie / Subseries</span>
                    <Dropdown
                        v-model="selectedRemovalClassification"
                        :options="classificationOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Seleccionar subserie"
                        :loading="loadingClassifications"
                        :disabled="removingClassification || checkingRemovalClassification"
                        showClear
                        filter
                        class="w-full"
                        @change="checkRemovalClassification"
                    />
                </label>
                <p v-if="checkingRemovalClassification" class="reserved-remove-dialog__status">
                    <i class="pi pi-spin pi-spinner" aria-hidden="true"></i>
                    Verificando documentos reservados...
                </p>
                <p
                    v-else-if="selectedRemovalClassificationOption && removalClassificationCount === 0"
                    class="reserved-remove-dialog__empty"
                >
                    No hay documentos reservados para
                    <strong>{{ selectedRemovalClassificationOption.label }}</strong>.
                </p>
                <p
                    v-else-if="selectedRemovalClassificationOption && removalClassificationCount > 0"
                    class="reserved-remove-dialog__warning"
                >
                    Se quitarán {{ removalClassificationCount }}
                    {{ removalClassificationCount === 1 ? 'documento reservado' : 'documentos reservados' }} de
                    <strong>{{ selectedRemovalClassificationOption.label }}</strong>.
                </p>
                <label v-if="removalClassificationCount > 0" class="reserved-remove-dialog__password">
                    <span>Ingrese su contraseña para confirmar</span>
                    <InputText
                        v-model="removeClassificationForm.current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Ingrese su contraseña"
                        :disabled="removingClassification"
                        class="w-full"
                        @keyup.enter="confirmRemoveClassification"
                    />
                </label>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="removingClassification"
                    @click="closeRemoveClassificationDialog"
                />
                <Button
                    label="Quitar documentos"
                    icon="pi pi-trash"
                    severity="danger"
                    :disabled="!canRemoveClassification"
                    :loading="removingClassification"
                    @click="confirmRemoveClassification"
                />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="removeSelectedDialogVisible"
            modal
            :closable="!removingSelectedDocuments"
            :dismissableMask="!removingSelectedDocuments"
            :style="{ width: '500px', maxWidth: '94vw' }"
            class="reserved-remove-dialog"
        >
            <template #header>
                <div class="reserved-remove-dialog__header">
                    <span class="reserved-remove-dialog__icon">
                        <i class="pi pi-exclamation-triangle"></i>
                    </span>
                    <span>Quitar documentos seleccionados</span>
                </div>
            </template>

            <div class="reserved-remove-dialog__body">
                <p class="reserved-remove-dialog__warning">
                    Se quitarán {{ selectedReservedDocuments.length }}
                    {{ selectedReservedDocuments.length === 1 ? 'documento seleccionado' : 'documentos seleccionados' }}
                    de la lista de reservados.
                </p>
                <label class="reserved-remove-dialog__password">
                    <span>Ingrese su contraseña para confirmar</span>
                    <InputText
                        v-model="removeSelectedForm.current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Ingrese su contraseña"
                        :disabled="removingSelectedDocuments"
                        class="w-full"
                        @keyup.enter="confirmRemoveSelectedDocuments"
                    />
                </label>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="removingSelectedDocuments"
                    @click="closeRemoveSelectedDialog"
                />
                <Button
                    label="Quitar seleccionados"
                    icon="pi pi-trash"
                    severity="danger"
                    :disabled="!canRemoveSelectedDocuments"
                    :loading="removingSelectedDocuments"
                    @click="confirmRemoveSelectedDocuments"
                />
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
    min-width: 28rem;
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

.reserved-search-group {
    align-items: center;
    display: flex;
    gap: var(--ugda-space-xs);
    width: 100%;
}

.reserved-search-group--wide {
    flex: none;
}

.reserved-search-group .reserved-search--wide {
    flex: 1 1 auto;
    min-width: 0;
    width: auto;
}

.reserved-search-actions {
    align-items: center;
    display: flex;
    flex: 0 0 auto;
    gap: var(--ugda-space-xs);
}

.reserved-bulk-selection {
    display: grid;
    gap: var(--ugda-space-xs);
}

.reserved-field-label {
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-secondary);
    font-weight: var(--ugda-weight-medium);
}

.reserved-selection-toolbar {
    align-items: center;
    display: flex;
    gap: var(--ugda-space-sm);
    justify-content: space-between;
    min-height: var(--ugda-control-height-sm);
}

.reserved-selection-summary {
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-helper);
}

.reserved-selection-actions {
    align-items: center;
    display: flex;
    flex-shrink: 0;
    gap: var(--ugda-space-xs);
}

.reserved-toolbar__actions {
    align-items: center;
    display: flex;
    flex: 0 0 auto;
    gap: var(--ugda-space-xs);
}

.reserved-add-button {
    flex: 0 0 auto;
}

.reserved-table-selection {
    align-items: center;
    background: var(--ugda-brand-soft);
    border-bottom: 1px solid var(--ugda-border);
    color: var(--ugda-text-primary);
    display: flex;
    font-size: var(--ugda-font-body);
    gap: var(--ugda-space-sm);
    justify-content: space-between;
    padding: 0.65rem 0.9rem;
}

.reserved-table-selection > span,
.reserved-table-selection__actions {
    align-items: center;
    display: flex;
    gap: var(--ugda-space-xs);
}

.reserved-table :deep(.p-datatable-table-container) {
    overflow-x: auto;
}

.reserved-table {
    font-family: var(--ugda-font-family);
}

.reserved-table :deep(.p-datatable-thead > tr > th) {
    background: var(--ugda-surface-soft);
    border-color: var(--ugda-border);
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body) !important;
    font-weight: var(--ugda-weight-medium) !important;
    padding: 0.75rem 0.9rem;
    white-space: nowrap;
}

.reserved-table :deep(.p-datatable-tbody > tr > td) {
    border-color: var(--ugda-border);
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-body) !important;
    font-weight: var(--ugda-weight-regular);
    line-height: 1.45;
    padding: 0.75rem 0.9rem;
    vertical-align: top;
}

.reserved-table :deep(.p-datatable-tbody > tr:hover) {
    background: var(--ugda-surface-page);
}

.reserved-table :deep(.p-paginator) {
    border-top: 1px solid var(--ugda-border);
    border-radius: 0 0 var(--ugda-radius-lg) var(--ugda-radius-lg);
}

.reserved-table__empty {
    align-items: center;
    color: var(--ugda-text-muted);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    justify-content: center;
    min-height: 9rem;
}

.reserved-table__empty i {
    color: #cbd5e1;
    font-size: 2.3rem;
}

.reserved-table__code {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}

.reserved-table__code strong,
.reserved-table__document {
    color: var(--ugda-text-primary);
    font-weight: var(--ugda-weight-semibold);
}

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

.reserved-option:last-child {
    border-bottom: 0;
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

.reserved-support-badge {
    border-radius: 999px;
    font-size: var(--ugda-font-secondary);
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
    min-height: 0;
    overflow: hidden;
    padding: 0;
}

.reserved-modal {
    background: var(--ugda-surface-card);
    border-radius: var(--ugda-radius-lg);
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 2rem);
    max-height: calc(100dvh - 2rem);
    min-height: 0;
    overflow: hidden;
}

.reserved-modal__header {
    align-items: center;
    background: var(--ugda-brand-hover);
    color: #ffffff;
    display: flex;
    flex: 0 0 auto;
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
    flex: 1 1 auto;
    gap: var(--ugda-space-md);
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: var(--ugda-space-lg);
    scrollbar-gutter: stable;
}

.reserved-warning--modal {
    padding: 0.8rem 0.9rem;
}

.reserved-picker {
    border: 1px solid var(--ugda-border);
    border-radius: var(--ugda-radius-md);
    overflow: visible;
}

.reserved-option {
    cursor: pointer;
    font-family: var(--ugda-font-family);
}

.reserved-option:disabled {
    cursor: default;
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

.reserved-picker-pagination {
    align-items: center;
    background: var(--ugda-surface-page);
    border-top: 1px solid var(--ugda-border);
    display: flex;
    justify-content: center;
    margin: 0;
    padding: 0.75rem 0.5rem;
}

.reserved-picker-pagination__notice {
    background: var(--ugda-surface-soft);
    border-radius: var(--ugda-radius-sm);
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-secondary);
    margin: 0;
    padding: 0.65rem 0.75rem;
}

.reserved-picker-pagination__controls,
.reserved-picker-pagination__pages {
    align-items: center;
    display: flex;
}

.reserved-picker-pagination__controls {
    gap: 0.75rem;
}

.reserved-picker-pagination__pages {
    gap: 0.35rem;
}

.reserved-picker-pagination button {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 999px;
    color: var(--ugda-text-secondary);
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    font-weight: var(--ugda-weight-semibold);
    justify-content: center;
    min-height: 2.2rem;
    min-width: 2.2rem;
    padding: 0 0.5rem;
}

.reserved-picker-pagination button:hover:not(:disabled) {
    background: var(--ugda-brand-soft);
}

.reserved-picker-pagination__icon {
    color: var(--ugda-text-muted) !important;
}

.reserved-picker-pagination__number--active {
    background: var(--ugda-brand-soft) !important;
    color: var(--ugda-brand-hover) !important;
}

.reserved-picker-pagination button:disabled {
    cursor: not-allowed;
    color: var(--ugda-border-strong);
}

:deep(.reserved-picker-pagination__size) {
    min-width: 5.2rem;
}

:deep(.reserved-picker-pagination__size .p-dropdown-label) {
    font-size: var(--ugda-font-secondary);
    font-weight: var(--ugda-weight-semibold);
    padding-bottom: 0.5rem;
    padding-top: 0.5rem;
}

.reserved-modal__footer {
    align-items: center;
    background: var(--ugda-surface-card);
    border-top: 1px solid var(--ugda-border);
    display: flex;
    flex: 0 0 auto;
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

.reserved-remove-dialog__field {
    display: grid;
    gap: 0.4rem;
    margin-top: 0.35rem;
}

.reserved-remove-dialog__field > span {
    color: #334155;
    font-size: 0.9rem;
    font-weight: 600;
}

.reserved-remove-dialog__warning {
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: var(--ugda-radius-md);
    color: #9f1239 !important;
    padding: 0.75rem;
}

.reserved-remove-dialog__warning strong {
    color: inherit;
}

.reserved-remove-dialog__status,
.reserved-remove-dialog__empty {
    align-items: center;
    border-radius: var(--ugda-radius-md);
    display: flex;
    gap: 0.55rem;
    padding: 0.75rem;
}

.reserved-remove-dialog__status {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    color: #475569 !important;
}

.reserved-remove-dialog__empty {
    background: #fffbeb;
    border: 1px solid #fbbf24;
    color: #92400e !important;
}

.reserved-remove-dialog__empty strong {
    color: inherit;
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

    .reserved-search-group {
        align-items: stretch;
        flex-direction: column;
    }

    .reserved-search-group .reserved-search--wide {
        width: 100%;
    }

    .reserved-search-actions {
        width: 100%;
    }

    .reserved-search-actions :deep(.p-button) {
        flex: 1 1 0;
    }

    .reserved-option {
        align-items: flex-start;
    }

    .reserved-selection-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .reserved-selection-actions {
        width: 100%;
    }

    .reserved-toolbar__actions {
        width: 100%;
    }

    .reserved-toolbar__actions :deep(.p-button) {
        flex: 1 1 0;
    }

    .reserved-table-selection {
        align-items: stretch;
        flex-direction: column;
    }

    .reserved-table-selection__actions :deep(.p-button) {
        flex: 1 1 0;
    }

    .reserved-picker-pagination {
        align-items: stretch;
        flex-direction: column;
    }

    .reserved-picker-pagination__pages {
        justify-content: center;
    }

    .reserved-picker-pagination__controls {
        align-items: stretch;
        flex-direction: column;
        width: 100%;
    }

    :deep(.reserved-picker-pagination__size) {
        width: 100%;
    }

    .reserved-modal__footer {
        flex-direction: column-reverse;
    }

    .reserved-modal__footer :deep(.p-button) {
        width: 100%;
    }
}
</style>
