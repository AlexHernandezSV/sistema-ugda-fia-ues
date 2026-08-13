<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const OBSERVATION_MAX_LENGTH = 1000;

const today = () => {
    const date = new Date();
    date.setHours(0, 0, 0, 0);

    return date;
};

const loan = ref(null);
const loading = ref(true);
const loanModalVisible = ref(false);
const returnModalVisible = ref(false);
const documentModificationsModalVisible = ref(false);
const decisionModalVisible = ref(false);
const searchStartModalVisible = ref(false);
const finishSearchModalVisible = ref(false);
const searchStartSubmitting = ref(false);
const finishSearchSubmitting = ref(false);
const documentModificationsSubmitting = ref(false);
const decisionSubmitting = ref(false);
const currentDecisionAction = ref(null);
const documentPreviewVisible = ref(false);
const documentPreviewTitle = ref('Documento PDF');
const documentPreviewSource = ref(null);

const loanForm = reactive({
    selectedIds: [],
    loanDate: today(),
    dueDate: null,
    receivedBy: '',
    observations: '',
});

const returnForm = reactive({
    selectedIds: [],
    returnDate: null,
    condition: '',
    observations: '',
});

const documentModificationsForm = reactive({
    items: [],
});

const decisionForm = reactive({
    comments: '',
});

const finishSearchForm = reactive({
    result: 'all',
    comments: '',
    selectedIds: [],
});

const goBack = () => {
    if (loan.value?.mode === 'unitAuthorization') {
        router.push('/solicitudes/prestamos/pendientes');
        return;
    }

    router.push({
        path: '/solicitudes',
        query: { tab: 'prestamos' },
    });
};

const editObservedLoan = () => {
    if (!loan.value?.observationsRegistered?.canCorrect) {
        return;
    }

    router.push(`/solicitudes/prestamos/${loan.value.number}/editar`);
};

const resetLoanForm = () => {
    loanForm.selectedIds = [];
    loanForm.loanDate = today();
    loanForm.dueDate = null;
    loanForm.receivedBy = '';
    loanForm.observations = '';
};

const resetReturnForm = () => {
    returnForm.selectedIds = [];
    returnForm.returnDate = null;
    returnForm.condition = loan.value?.returnRegistration?.defaultCondition ?? '';
    returnForm.observations = '';
};

const resetDocumentModificationsForm = () => {
    const registeredItems = loan.value?.documentModifications?.items ?? [];

    documentModificationsForm.items = registeredItems.map((item) => ({
        loanDocumentId: item.loanDocumentId,
        description: item.description,
    }));
};

const resetDecisionForm = () => {
    decisionForm.comments = '';
    currentDecisionAction.value = null;
};

const resetFinishSearchForm = () => {
    finishSearchForm.result = 'all';
    finishSearchForm.comments = '';
    finishSearchForm.selectedIds = searchResultDocuments.value.map((document) => document.id);
};

const documentModificationTitle = (id) => documentModificationDocuments.value
    .find((document) => document.id === id)?.title ?? 'Documento';

const toneClass = (tone) => {
    switch (tone) {
        case 'success':
            return 'detalle-chip detalle-chip--success';
        case 'danger':
            return 'detalle-chip detalle-chip--danger';
        case 'warning':
            return 'detalle-chip detalle-chip--warning';
        case 'info':
            return 'detalle-chip detalle-chip--info';
        default:
            return 'detalle-chip detalle-chip--neutral';
    }
};

const documentTypeClass = (color) => {
    switch (color) {
        case 'warning':
            return 'detalle-document-type detalle-document-type--warning';
        case 'success':
            return 'detalle-document-type detalle-document-type--success';
        default:
            return 'detalle-document-type detalle-document-type--info';
    }
};

const actionClass = (variant) => {
    switch (variant) {
        case 'success':
            return 'detalle-action detalle-action--success';
        case 'warning':
            return 'detalle-action detalle-action--warning';
        case 'danger':
            return 'detalle-action detalle-action--danger';
        case 'muted':
            return 'detalle-action detalle-action--muted';
        case 'secondary':
            return 'detalle-action detalle-action--secondary';
        default:
            return 'detalle-action detalle-action--primary';
    }
};

const formatSelectionSummary = (template, count, total) => template
    .replace('{count}', String(count))
    .replace('{total}', String(total));

const showError = (message) => {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail: message,
        life: 3600,
    });
};

const openLoanSummarySheetPreview = (url = loan.value?.loanSummarySheetCard?.url) => {
    if (!url) {
        return;
    }

    documentPreviewTitle.value = `Hoja de préstamo #${loan.value?.number ?? ''}`.trim();
    documentPreviewSource.value = { url };
    documentPreviewVisible.value = true;
};

const clearDocumentPreview = () => {
    documentPreviewSource.value = null;
};

const validateObservationLength = (value, label = 'Las observaciones') => {
    if (String(value ?? '').trim().length <= OBSERVATION_MAX_LENGTH) {
        return true;
    }

    showError(`${label} no pueden superar ${OBSERVATION_MAX_LENGTH} caracteres.`);
    return false;
};

const formatDateForRequest = (value) => {
    if (!value) {
        return '';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
        return '';
    }

    const day = String(value.getDate()).padStart(2, '0');
    const month = String(value.getMonth() + 1).padStart(2, '0');
    const year = value.getFullYear();

    return `${day}/${month}/${year}`;
};

const postAction = async (url, payload, successMessage) => {
    try {
        const { data } = await axios.post(url, payload);
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: data.message || successMessage,
            life: 2800,
        });
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || successMessage || 'No se pudo completar la accion.');
    }
};

const openDecisionModal = (actionKey) => {
    currentDecisionAction.value = actionKey;
    decisionForm.comments = '';
    decisionModalVisible.value = true;
};

const handleAction = async (action) => {
    switch (action.key) {
        case 'back':
            goBack();
            break;
        case 'authorize':
            openDecisionModal('authorize');
            break;
        case 'authorizeUnit':
            openDecisionModal('authorizeUnit');
            break;
        case 'startSearch':
            searchStartModalVisible.value = true;
            break;
        case 'finishSearch':
            resetFinishSearchForm();
            finishSearchModalVisible.value = true;
            break;
        case 'observe':
            openDecisionModal('observe');
            break;
        case 'deny':
            openDecisionModal('deny');
            break;
        case 'denyUnit':
            openDecisionModal('denyUnit');
            break;
        case 'registerLoan':
            resetLoanForm();
            loanModalVisible.value = true;
            break;
        case 'registerReturn':
            resetReturnForm();
            returnModalVisible.value = true;
            break;
    }
};

const toggleFinishSearchDocument = (id) => {
    if (finishSearchForm.selectedIds.includes(id)) {
        finishSearchForm.selectedIds = finishSearchForm.selectedIds.filter((item) => item !== id);
        return;
    }

    finishSearchForm.selectedIds = [...finishSearchForm.selectedIds, id];
};

const toggleFinishSearchDocuments = () => {
    const ids = searchResultDocuments.value.map((document) => document.id);

    finishSearchForm.selectedIds = finishSearchAllSelected.value ? [] : ids;
};

const submitStartSearch = async () => {
    if (!loan.value) {
        return;
    }

    searchStartSubmitting.value = true;

    try {
        const { data } = await axios.post(`/api/loans/${loan.value.number}/search/start`, {});
        toast.add({
            severity: 'success',
            summary: 'Búsqueda iniciada',
            detail: data.message || 'Búsqueda iniciada correctamente.',
            life: 2800,
        });
        searchStartModalVisible.value = false;
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo iniciar la búsqueda.');
    } finally {
        searchStartSubmitting.value = false;
    }
};

const submitFinishSearch = async () => {
    if (!loan.value) {
        return;
    }

    let documentIds = [];

    if (finishSearchForm.result === 'all') {
        documentIds = searchResultDocuments.value.map((document) => document.id);
    } else if (finishSearchForm.result === 'partial') {
        documentIds = [...finishSearchForm.selectedIds];

        if (documentIds.length === 0) {
        showError('Seleccione al menos un documento encontrado o cambie el resultado de la búsqueda.');
            return;
        }
    }

    if (!validateObservationLength(finishSearchForm.comments, 'Los comentarios')) {
        return;
    }

    finishSearchSubmitting.value = true;

    try {
        const { data } = await axios.post(`/api/loans/${loan.value.number}/search/finish`, {
            comments: finishSearchForm.comments,
            document_ids: documentIds,
        });

        toast.add({
            severity: 'success',
            summary: 'Búsqueda finalizada',
            detail: data.message || 'Búsqueda finalizada correctamente.',
            life: 3000,
        });

        finishSearchModalVisible.value = false;
        resetFinishSearchForm();
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo finalizar la búsqueda.');
    } finally {
        finishSearchSubmitting.value = false;
    }
};

const decisionModalConfig = computed(() => {
    switch (currentDecisionAction.value) {
        case 'authorize':
            return {
                icon: 'pi pi-check-circle',
                title: 'Autorizar préstamo',
                subtitle: loan.value?.mode === 'unitAuthorization'
                    ? 'La solicitud sera enviada a la UGDA para su gestión'
                    : 'Se iniciará el proceso de búsqueda de documentos.',
                label: 'Comentarios (opcional)',
                placeholder: 'Agregue comentarios sobre la autorización...',
                confirmLabel: 'Autorizar',
                cancelLabel: 'Cancelar',
                tone: 'success',
                required: false,
            };
        case 'authorizeUnit':
            return {
                icon: 'pi pi-check-circle',
                title: 'Autorizar préstamo',
                subtitle: 'La solicitud sera enviada a la UGDA para su gestión',
                label: 'Comentarios (opcional)',
                placeholder: 'Agregue comentarios sobre la autorización...',
                confirmLabel: 'Autorizar',
                cancelLabel: 'Cancelar',
                tone: 'success',
                required: false,
            };
        case 'observe':
            return {
                icon: 'pi pi-eye',
                title: 'Observar Solicitud',
                subtitle: 'La solicitud regresara al solicitante para correcciones',
                label: 'Observación',
                placeholder: 'Describa que correcciones se requieren...',
                confirmLabel: 'Observar',
                cancelLabel: 'Cancelar',
                tone: 'warning',
                required: true,
            };
        case 'deny':
            return {
                icon: 'pi pi-times-circle',
                title: 'Denegar préstamo',
                subtitle: 'Debe proporcionar un motivo para denegar esta solicitud',
                label: 'Motivo de denegación',
                placeholder: 'Describa el motivo por el cual se deniega este prestamo...',
                confirmLabel: 'Denegar',
                cancelLabel: 'Cancelar',
                tone: 'danger',
                required: true,
            };
        case 'denyUnit':
            return {
                icon: 'pi pi-times-circle',
                title: 'Denegar préstamo',
                subtitle: 'Debe proporcionar un motivo para denegar esta solicitud',
                label: 'Motivo de denegación',
                placeholder: 'Describa el motivo por el cual se deniega este prestamo...',
                confirmLabel: 'Denegar',
                cancelLabel: 'Cancelar',
                tone: 'danger',
                required: true,
            };
        default:
            return null;
    }
});

const submitDecisionAction = async () => {
    const config = decisionModalConfig.value;

    if (!config || !loan.value) {
        return;
    }

    const comments = decisionForm.comments.trim();

    if (!validateObservationLength(comments)) {
        return;
    }

    if (config.required && comments === '') {
        showError(currentDecisionAction.value === 'observe'
            ? 'Debe ingresar la observación de la solicitud.'
            : 'Debe ingresar el motivo de rechazo.');
        return;
    }

    let endpoint = '';
    let payload = {};
    let successMessage = '';

    switch (currentDecisionAction.value) {
        case 'authorize':
            endpoint = `/api/loans/${loan.value.number}/authorize`;
            payload = { comments };
            successMessage = 'Préstamo autorizado.';
            break;
        case 'authorizeUnit':
            endpoint = `/api/loans/${loan.value.number}/unit-authorize`;
            payload = { comments };
            successMessage = 'Préstamo autorizado.';
            break;
        case 'observe':
            endpoint = `/api/loans/${loan.value.number}/observe`;
            payload = { message: comments };
            successMessage = 'Solicitud observada.';
            break;
        case 'deny':
            endpoint = `/api/loans/${loan.value.number}/deny`;
            payload = { reason: comments };
            successMessage = 'Préstamo denegado.';
            break;
        case 'denyUnit':
            endpoint = `/api/loans/${loan.value.number}/unit-deny`;
            payload = { reason: comments };
            successMessage = 'Préstamo denegado.';
            break;
        default:
            return;
    }

    decisionSubmitting.value = true;

    try {
        const { data } = await axios.post(endpoint, payload);
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: data.message || successMessage,
            life: 2800,
        });
        decisionModalVisible.value = false;
        resetDecisionForm();
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo completar la accion.');
    } finally {
        decisionSubmitting.value = false;
    }
};

const toggleLoanDocument = (id) => {
    if (loanForm.selectedIds.includes(id)) {
        loanForm.selectedIds = loanForm.selectedIds.filter((item) => item !== id);
        return;
    }

    loanForm.selectedIds = [...loanForm.selectedIds, id];
};

const toggleLoanDocuments = () => {
    const ids = loanRegistrationDocuments.value.map((document) => document.id);
    loanForm.selectedIds = loanForm.selectedIds.length === ids.length ? [] : ids;
};

const toggleReturnDocument = (id) => {
    if (returnForm.selectedIds.includes(id)) {
        returnForm.selectedIds = returnForm.selectedIds.filter((item) => item !== id);
        return;
    }

    returnForm.selectedIds = [...returnForm.selectedIds, id];
};

const toggleReturnDocuments = () => {
    const ids = returnRegistrationDocuments.value.map((document) => document.id);
    returnForm.selectedIds = returnForm.selectedIds.length === ids.length ? [] : ids;
};

const submitLoanRegistration = async () => {
    if (!validateObservationLength(loanForm.observations)) {
        return;
    }

    if (loanForm.loanDate instanceof Date
        && loanForm.dueDate instanceof Date
        && loanForm.dueDate < loanForm.loanDate) {
        showError('La fecha de devolución programada debe ser igual o posterior a la fecha de préstamo.');
        return;
    }

    try {
        const { data } = await axios.post(`/api/loans/${loan.value.number}/register`, {
            selected_ids: loanForm.selectedIds,
            loan_date: formatDateForRequest(loanForm.loanDate),
            due_date: formatDateForRequest(loanForm.dueDate),
            received_by: loanForm.receivedBy,
            observations: loanForm.observations,
        });

        toast.add({
            severity: 'success',
            summary: 'Préstamo registrado',
            detail: data.message,
            life: 3000,
        });
        loanModalVisible.value = false;
        await fetchDetail();
        openLoanSummarySheetPreview(data.summarySheetUrl);
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo registrar el prestamo.');
    }
};

const openDocumentModificationsModal = () => {
    resetDocumentModificationsForm();
    documentModificationsModalVisible.value = true;
};

const addDocumentModification = () => {
    const usedIds = new Set(documentModificationsForm.items.map((item) => item.loanDocumentId));
    const firstAvailable = documentModificationDocuments.value.find((document) => !usedIds.has(document.id));

    documentModificationsForm.items.push({
        loanDocumentId: firstAvailable?.id ?? documentModificationDocuments.value[0]?.id ?? null,
        description: '',
    });
};

const removeDocumentModification = (index) => {
    documentModificationsForm.items = documentModificationsForm.items.filter((_, itemIndex) => itemIndex !== index);
};

const submitDocumentModifications = async () => {
    const modifications = documentModificationsForm.items
        .map((item) => ({
            loan_document_id: item.loanDocumentId,
            description: item.description.trim(),
        }))
        .filter((item) => item.loan_document_id && item.description !== '');

    if (modifications.length === 0) {
        showError('Agregue al menos una modificacion con su descripcion.');
        return;
    }

    if (modifications.some((item) => item.description.length > OBSERVATION_MAX_LENGTH)) {
        showError(`La descripcion de la modificacion no puede superar ${OBSERVATION_MAX_LENGTH} caracteres.`);
        return;
    }

    documentModificationsSubmitting.value = true;

    try {
        const { data } = await axios.post(`/api/loans/${loan.value.number}/document-modifications`, {
            modifications,
        });

        toast.add({
            severity: 'success',
            summary: 'Modificaciones registradas',
            detail: data.message,
            life: 3000,
        });
        documentModificationsModalVisible.value = false;
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudieron registrar las modificaciones.');
    } finally {
        documentModificationsSubmitting.value = false;
    }
};

const submitReturnRegistration = async () => {
    if (!validateObservationLength(returnForm.observations)) {
        return;
    }

    try {
        const { data } = await axios.post(`/api/loans/${loan.value.number}/return`, {
            selected_ids: returnForm.selectedIds,
            return_date: formatDateForRequest(returnForm.returnDate),
            condition: returnForm.condition,
            observations: returnForm.observations,
        });

        toast.add({
            severity: 'success',
            summary: 'Devolución registrada',
            detail: data.message,
            life: 3000,
        });
        returnModalVisible.value = false;
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo registrar la devolución.');
    }
};

const fetchDetail = async () => {
    try {
        const { data } = await axios.get(`/api/loans/${route.params.number}`);
        loan.value = data;
        resetReturnForm();
    } catch (error) {
        console.error('Error cargando detalle de prestamo:', error);
        goBack();
    } finally {
        loading.value = false;
    }
};

const isManageMode = computed(() => loan.value?.mode === 'manage');
const showsActions = computed(() => ['manage', 'unitAuthorization'].includes(loan.value?.mode));
const pageTitle = computed(() => loan.value?.pageTitle ?? (loan.value ? `Préstamo #${loan.value.number}` : 'Préstamo'));
const pageSubtitle = computed(() => loan.value?.pageSubtitle ?? 'Detalle de la solicitud');
const loanRegistrationDocuments = computed(() => loan.value?.loanRegistration?.documents ?? []);
const returnRegistrationDocuments = computed(() => loan.value?.returnRegistration?.documents ?? []);
const documentModificationDocuments = computed(() => loan.value?.documentModifications?.documents ?? []);
const canSaveDocumentModifications = computed(() => documentModificationsForm.items
    .some((item) => item.loanDocumentId && item.description.trim() !== ''));
const searchResultDocuments = computed(() => (loan.value?.documentGroups ?? [])
    .flatMap((group) => (group.items ?? []).map((item) => ({
        ...item,
        groupTitle: group.title,
    }))));
const loanAllSelected = computed(() => loanRegistrationDocuments.value.length > 0
    && loanForm.selectedIds.length === loanRegistrationDocuments.value.length);
const returnAllSelected = computed(() => returnRegistrationDocuments.value.length > 0
    && returnForm.selectedIds.length === returnRegistrationDocuments.value.length);
const finishSearchAllSelected = computed(() => searchResultDocuments.value.length > 0
    && finishSearchForm.selectedIds.length === searchResultDocuments.value.length);

onMounted(fetchDetail);

watch(
    () => route.params.number,
    async (newNumber, oldNumber) => {
        if (!oldNumber || newNumber === oldNumber) {
            return;
        }

        loading.value = true;
        loan.value = null;
        await fetchDetail();
    }
);
</script>

<template>
    <div v-if="loading" class="surface-card border-round-xl shadow-1 p-4 text-center text-gray-500">
        Cargando detalle de prestamo...
    </div>

    <div v-else-if="loan" class="flex flex-column gap-4">
        <div class="mobile-page-title mb-1">
            <div class="flex align-items-center gap-3 mb-2">
                <button type="button" class="detalle-back-button" @click="goBack">
                    <i class="pi pi-arrow-left"></i>
                </button>
                <div>
                    <h1 class="detalle-title m-0">{{ pageTitle }}</h1>
                    <span class="detalle-subtitle">{{ pageSubtitle }}</span>
                </div>
            </div>
        </div>

        <div v-if="loan.observationsRegistered" class="detalle-banner detalle-banner--warning">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--warning">
                    <i class="pi pi-eye"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.observationsRegistered.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.observationsRegistered.description }}</p>
                </div>
            </div>

            <div class="flex flex-column gap-3">
                <div v-for="item in loan.observationsRegistered.items" :key="`${item.actor}-${item.dateTime}`" class="detalle-observation-card">
                    <div class="detalle-observation-card__meta">
                        <i class="pi pi-user"></i>
                        <span class="font-semibold">{{ item.actor }}</span>
                        <span>{{ item.dateTime }}</span>
                    </div>
                    <p class="detalle-observation-card__message m-0">{{ item.message }}</p>
                </div>
            </div>

            <div v-if="loan.observationsRegistered.canCorrect" class="detalle-banner__actions">
                <button type="button" class="detalle-action detalle-action--warning" @click="editObservedLoan">
                    <i class="pi pi-file-edit"></i>
                    <span>{{ loan.observationsRegistered.actionLabel }}</span>
                </button>
            </div>
        </div>

        <div v-if="loan.subsanationSummary" class="detalle-banner detalle-banner--blue">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--blue">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.subsanationSummary.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.subsanationSummary.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <p class="m-0">{{ loan.subsanationSummary.message }}</p>
                <div class="detalle-banner__divider"></div>
                <div class="font-semibold mb-2 text-900">{{ loan.subsanationSummary.notesLabel }}</div>
                <div class="detalle-banner__notes">{{ loan.subsanationSummary.notes }}</div>
            </div>
        </div>

        <div v-if="loan.workflowNotice" class="detalle-notice">
            <div class="detalle-notice__icon">
                <i class="pi pi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="detalle-notice__title">{{ loan.workflowNotice.title }}</div>
                <p class="detalle-notice__text m-0">{{ loan.workflowNotice.description }}</p>
            </div>
        </div>

        <div v-if="loan.searchProgress" class="detalle-banner detalle-banner--warning">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--warning">
                    <i class="pi pi-search"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.searchProgress.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.searchProgress.description }}</p>
                </div>
            </div>
        </div>

        <div
            v-if="loan.searchCompleted"
            :class="[
                'detalle-banner',
                'detalle-search-completed',
                loan.searchCompleted.tone === 'danger' ? 'detalle-banner--danger' : 'detalle-banner--warning',
                loan.searchCompleted.tone === 'danger' ? 'detalle-search-completed--danger' : ''
            ]"
        >
            <div class="detalle-banner__head">
                <div
                    :class="[
                        'detalle-banner__icon',
                        loan.searchCompleted.tone === 'danger' ? 'detalle-banner__icon--danger' : 'detalle-banner__icon--warning'
                    ]"
                >
                    <i class="pi pi-search"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.searchCompleted.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.searchCompleted.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel detalle-search-completed__panel">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label">Completado por:</div>
                        <div class="detalle-small-value">{{ loan.searchCompleted.completedBy }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label">Fecha:</div>
                        <div class="detalle-small-value">{{ loan.searchCompleted.completedAt }}</div>
                    </div>
                </div>

                <template v-if="loan.searchCompleted.comments">
                    <div class="detalle-divider"></div>

                    <div class="detalle-small-label mb-2">Comentarios:</div>
                    <p class="m-0 detalle-note-text">{{ loan.searchCompleted.comments }}</p>
                </template>

                <template v-if="(loan.searchCompleted.groups?.length ?? 0) > 0">
                    <div class="detalle-divider"></div>

                    <div class="detalle-search-completed__documents-title">Documentos encontrados:</div>
                    <div class="flex flex-column gap-3">
                        <div v-for="group in loan.searchCompleted.groups" :key="group.title" class="detalle-summary-findings">
                            <div class="detalle-summary-findings__title">
                                <i class="pi pi-file"></i>
                                <span>{{ group.title }}</span>
                            </div>
                            <div v-for="item in group.items" :key="`${group.title}-${item.title}`" class="detalle-summary-findings__item">
                                <i class="pi pi-check-circle"></i>
                                <span class="font-semibold">{{ item.title }}</span>
                                <span>{{ item.meta }}</span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div v-if="loan.readyForPickup" class="detalle-banner detalle-banner--success">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--success">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.readyForPickup.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.readyForPickup.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="detalle-banner__instructions">
                    <div class="font-semibold mb-3 text-900">Instrucciones para el retiro:</div>
                    <ul class="detalle-banner__instructions-list">
                        <li v-for="(instruction, idx) in loan.readyForPickup.instructions" :key="idx">
                            {{ instruction }}
                        </li>
                    </ul>
                </div>
            </div>

        </div>

        <div v-if="loan.loanSummary" class="detalle-banner detalle-banner--success detalle-loan-summary-banner">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--success">
                    <i class="pi pi-box"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.loanSummary.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.loanSummary.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label"><i class="pi pi-calendar mr-2"></i>Fecha de prestamo</div>
                        <div class="detalle-small-value">{{ loan.loanSummary.loanDate }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label"><i class="pi pi-calendar mr-2"></i>Fecha de devolución programada</div>
                        <div class="detalle-small-value">{{ loan.loanSummary.dueDate }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label"><i class="pi pi-user mr-2"></i>Persona responsable</div>
                        <div class="detalle-small-value">{{ loan.loanSummary.responsible }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label"><i class="pi pi-user mr-2"></i>Recibido por</div>
                        <div class="detalle-small-value">{{ loan.loanSummary.receivedBy }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label"><i class="pi pi-user mr-2"></i>Entregado por</div>
                        <div class="detalle-small-value">{{ loan.loanSummary.deliveredBy }}</div>
                    </div>
                </div>

                <div class="detalle-divider"></div>
                <div class="font-semibold text-900 mb-3">Documentos prestados:</div>

                <div class="flex flex-column gap-3">
                    <div v-for="group in loan.loanSummary.groups" :key="group.title" class="detalle-loan-summary-group">
                        <div class="detalle-loan-summary-group__title">
                            <i class="pi pi-file"></i>
                            <span>{{ group.title }}</span>
                        </div>
                        <div v-for="item in group.items" :key="`${group.title}-${item.title}`" class="detalle-loan-summary-group__item">
                            <i class="pi pi-check-circle"></i>
                            <span class="font-semibold">{{ item.title }}</span>
                            <span>{{ item.meta }}</span>
                            <span :class="documentTypeClass(item.documentTypeColor)">{{ item.documentType }}</span>
                        </div>
                    </div>
                </div>

                <div class="detalle-divider"></div>
                <div class="detalle-small-label mb-2">Observaciones</div>
                <p class="m-0 detalle-note-text">{{ loan.loanSummary.observations }}</p>
            </div>
        </div>

        <div v-if="loan.loanSummarySheetCard" class="surface-card shadow-1 detalle-loan-sheet-card">
            <div class="detalle-loan-sheet-card__content">
                <div class="detalle-loan-sheet-card__main">
                    <div class="detalle-loan-sheet-card__icon">
                        <i class="pi pi-file-pdf"></i>
                    </div>
                    <div>
                        <h3>{{ loan.loanSummarySheetCard.title }}</h3>
                        <p>{{ loan.loanSummarySheetCard.description }}</p>
                    </div>
                </div>
                <Button
                    :label="loan.loanSummarySheetCard.buttonLabel"
                    icon="pi pi-eye"
                    class="detalle-loan-sheet-card__button"
                    @click="openLoanSummarySheetPreview()"
                />
            </div>
        </div>

        <div v-if="loan.returnSummary" class="detalle-banner detalle-banner--success">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--success">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.returnSummary.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.returnSummary.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label">Fecha de devolución</div>
                        <div class="detalle-small-value">{{ loan.returnSummary.returnDate }} • {{ loan.returnSummary.returnTime }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="detalle-small-label">Recibido por</div>
                        <div class="detalle-small-value">{{ loan.returnSummary.receivedBy }}</div>
                    </div>
                    <div class="col-12">
                        <div class="detalle-small-label">Condicion de los documentos</div>
                        <div class="detalle-small-value">{{ loan.returnSummary.condition }}</div>
                    </div>
                </div>

                <div class="detalle-divider"></div>
                <div class="detalle-small-label mb-2">Observaciones</div>
                <p class="m-0 detalle-note-text">{{ loan.returnSummary.observations }}</p>
            </div>
        </div>

        <div v-if="loan.documentModifications?.showInDetail" class="detalle-document-modifications">
            <div class="detalle-document-modifications__head">
                <div class="detalle-document-modifications__intro">
                    <div class="detalle-document-modifications__icon">
                        <i class="pi pi-file-edit"></i>
                    </div>
                    <div>
                        <div class="detalle-document-modifications__title">
                            <span>{{ loan.documentModifications.title }}</span>
                            <span v-if="loan.documentModifications.count > 0" class="detalle-document-modifications__badge">
                                {{ loan.documentModifications.count }}
                            </span>
                        </div>
                        <p class="detalle-document-modifications__text m-0">{{ loan.documentModifications.description }}</p>
                    </div>
                </div>

                <button
                    v-if="loan.documentModifications.canEdit"
                    type="button"
                    class="detalle-document-modifications__button"
                    @click="openDocumentModificationsModal"
                >
                    <i class="pi pi-file-edit"></i>
                    <span>{{ loan.documentModifications.buttonLabel }}</span>
                </button>
            </div>

            <div v-if="(loan.documentModifications.items?.length ?? 0) > 0" class="detalle-document-modifications__list">
                <div
                    v-for="item in loan.documentModifications.items"
                    :key="item.id"
                    class="detalle-document-modifications__item"
                >
                    <div class="detalle-document-modifications__item-title">
                        <i class="pi pi-file-edit"></i>
                        <span>{{ item.documentTitle }}</span>
                    </div>
                    <p class="detalle-document-modifications__item-text m-0">{{ item.description }}</p>
                    <div class="detalle-document-modifications__item-meta">
                        Registrado por {{ item.registeredBy || 'Usuario' }} - {{ item.registeredAt }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="loan.decisionCard" class="detalle-banner detalle-banner--danger">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--danger">
                    <i :class="loan.decisionCard.icon"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ loan.decisionCard.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ loan.decisionCard.subtitle }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel detalle-banner__panel--danger">
                <div class="detalle-observation-card__meta detalle-observation-card__meta--danger">
                    <i class="pi pi-user"></i>
                    <span class="font-semibold">{{ loan.decisionCard.actor }}</span>
                    <span>{{ loan.decisionCard.dateTime }}</span>
                </div>
                <div class="detalle-banner__reason">
                    <div class="font-semibold mb-2">{{ loan.decisionCard.reasonLabel }}</div>
                    <p class="m-0">{{ loan.decisionCard.reason }}</p>
                </div>
            </div>
        </div>

        <div v-if="isManageMode" class="detalle-panel">
            <div class="detalle-panel__header detalle-panel__header--split">
                <span>{{ loan.generalSectionTitle }}</span>
                <div class="detalle-panel__chips">
                    <span v-for="chip in loan.chips" :key="chip.label" :class="toneClass(chip.tone)">{{ chip.label }}</span>
                </div>
            </div>
            <div class="detalle-panel__body">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <div class="flex flex-column gap-4">
                            <div>
                                <span class="detalle-label">N° de solicitud</span>
                                <div class="detalle-value">{{ loan.number }}</div>
                            </div>
                            <div>
                                <span class="detalle-label">Unidad solicitante</span>
                                <div class="detalle-value">{{ loan.unit }}</div>
                            </div>
                            <div v-if="loan.authorizedBy">
                                <span class="detalle-label">Autorizado por</span>
                                <div class="detalle-value">{{ loan.authorizedBy }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 md:col-6">
                        <div class="flex flex-column gap-4">
                            <div>
                                <span class="detalle-label">Fecha de solicitud</span>
                                <div class="detalle-value">{{ loan.requestedAt }}</div>
                            </div>
                            <div>
                                <span class="detalle-label">Solicitante</span>
                                <div class="detalle-value">{{ loan.requestedBy ?? loan.applicant }}</div>
                            </div>
                            <div v-if="loan.authorizedAt">
                                <span class="detalle-label">Fecha de autorización</span>
                                <div class="detalle-value">{{ loan.authorizedAt }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="surface-card shadow-1 border-round-xl p-4">
            <div class="flex flex-column md:flex-row md:justify-content-between md:align-items-start gap-3 mb-4">
                <h3 class="m-0 text-900 text-2xl font-bold">{{ loan.generalSectionTitle }}</h3>
                <div class="flex gap-2 flex-wrap">
                    <span v-for="chip in loan.chips" :key="chip.label" :class="toneClass(chip.tone)">{{ chip.label }}</span>
                </div>
            </div>

            <div class="grid">
                <div class="col-12 md:col-6">
                    <div class="flex flex-column gap-4">
                        <div>
                            <span class="detalle-label">N° de prestamo documental</span>
                            <div class="detalle-value">{{ loan.number }}</div>
                        </div>
                        <div>
                            <span class="detalle-label">Unidad solicitante</span>
                            <div class="detalle-value">{{ loan.unit }}</div>
                        </div>
                        <div v-if="loan.authorizedBy">
                            <span class="detalle-label">Autorizado por</span>
                            <div class="detalle-value">{{ loan.authorizedBy }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-12 md:col-6">
                    <div class="flex flex-column gap-4">
                        <div>
                            <span class="detalle-label">Fecha de solicitud</span>
                            <div class="detalle-value">{{ loan.requestedAt }}</div>
                        </div>
                        <div>
                            <span class="detalle-label">Solicitante</span>
                            <div class="detalle-value">{{ loan.requestedBy ?? loan.applicant }}</div>
                        </div>
                        <div v-if="loan.authorizedAt">
                            <span class="detalle-label">Fecha de autorización</span>
                            <div class="detalle-value">{{ loan.authorizedAt }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div :class="isManageMode ? 'detalle-panel' : 'surface-card shadow-1 border-round-xl p-4'">
            <template v-if="isManageMode">
                <div class="detalle-panel__header">{{ loan.documentsSectionTitle }}</div>
                <div class="detalle-panel__body">
                    <div v-if="(loan.documentGroups?.length ?? 0) > 0" class="flex flex-column gap-4">
                        <div v-for="group in loan.documentGroups" :key="group.title">
                            <h4 class="detalle-group-title">{{ group.title }}</h4>
                            <div class="flex flex-column gap-3">
                                <div
                                    v-for="item in group.items"
                                    :key="`${group.title}-${item.title}`"
                                    :class="group.kind === 'additional' ? 'detalle-document-card detalle-document-card--additional' : 'detalle-document-card'"
                                >
                                    <div :class="group.kind === 'additional' ? 'detalle-document-card__icon detalle-document-card__icon--box' : 'detalle-document-card__icon'">
                                        <i :class="group.kind === 'additional' ? 'pi pi-box' : 'pi pi-file'"></i>
                                    </div>

                                    <div class="grid flex-1">
                                        <div class="col-12 lg:col-6">
                                            <div class="detalle-document-card__title">{{ item.title }}</div>
                                            <template v-if="group.kind === 'system'">
                                                <div class="detalle-document-card__meta">Serie: {{ item.series }}</div>
                                                <div class="detalle-document-card__meta">Caja: {{ item.box }}</div>
                                            </template>
                                            <template v-else>
                                                <div class="detalle-document-card__meta">Año: {{ item.year }}</div>
                                                <div class="detalle-document-card__meta">Unidad Productora: {{ item.producerUnit }}</div>
                                            </template>
                                        </div>

                                        <div class="col-12 lg:col-6">
                                            <template v-if="group.kind === 'system'">
                                                <div class="detalle-document-card__meta">Año: {{ item.year }}</div>
                                            </template>
                                            <template v-else>
                                                <div class="detalle-document-card__meta">Cantidad: {{ item.quantity }}</div>
                                            </template>

                                            <div class="detalle-document-card__meta">
                                                Tipo:
                                                <span :class="documentTypeClass(item.documentTypeColor)">{{ item.documentType }}</span>
                                            </div>

                                            <div v-if="group.kind === 'additional' && item.note" class="detalle-document-card__note">{{ item.note }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="detalle-empty-documents">
                        No hay documentos agregados a esta solicitud
                    </div>
                </div>
            </template>

            <template v-else>
                <h3 class="m-0 text-900 text-2xl font-bold mb-4">{{ loan.documentsSectionTitle }}</h3>

                <div v-if="(loan.documentGroups?.length ?? 0) > 0" class="flex flex-column gap-4">
                    <div v-for="group in loan.documentGroups" :key="group.title">
                        <h4 class="detalle-group-title">{{ group.title }}</h4>
                        <div class="flex flex-column gap-3">
                            <div
                                v-for="item in group.items"
                                :key="`${group.title}-${item.title}`"
                                :class="group.kind === 'additional' ? 'detalle-document-card detalle-document-card--additional' : 'detalle-document-card'"
                            >
                                <div :class="group.kind === 'additional' ? 'detalle-document-card__icon detalle-document-card__icon--box' : 'detalle-document-card__icon'">
                                    <i :class="group.kind === 'additional' ? 'pi pi-box' : 'pi pi-file'"></i>
                                </div>

                                <div class="grid flex-1">
                                    <div class="col-12 lg:col-6">
                                        <div class="detalle-document-card__title">{{ item.title }}</div>
                                        <template v-if="group.kind === 'system'">
                                            <div class="detalle-document-card__meta">Serie: {{ item.series }}</div>
                                            <div class="detalle-document-card__meta">Año: {{ item.year }}</div>
                                        </template>
                                        <template v-else>
                                            <div class="detalle-document-card__meta">Año: {{ item.year }}</div>
                                            <div class="detalle-document-card__meta">Unidad Productora: {{ item.unit || 'No especificada' }}</div>
                                        </template>
                                    </div>

                                    <div class="col-12 lg:col-6">
                                        <template v-if="group.kind === 'system'">
                                            <div class="detalle-document-card__meta">Caja: {{ item.box }}</div>
                                        </template>
                                        <template v-else>
                                            <div class="detalle-document-card__meta">Cantidad: {{ item.quantity }}</div>
                                        </template>

                                        <div class="detalle-document-card__meta">
                                            Tipo:
                                            <span :class="documentTypeClass(item.documentTypeColor)">{{ item.documentType }}</span>
                                        </div>

                                        <div v-if="group.kind === 'additional' && item.note" class="detalle-document-card__note"><span class="font-semibold">Observación:</span> {{ item.note }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="detalle-empty-documents">
                    No hay documentos agregados a esta solicitud
                </div>
            </template>
        </div>

        <div v-if="showsActions && (loan.actions?.length ?? 0) > 0" class="detalle-actions">
            <button
                v-for="action in loan.actions"
                :key="action.key"
                type="button"
                :class="actionClass(action.variant)"
                @click="handleAction(action)"
            >
                <i v-if="action.icon" :class="action.icon"></i>
                <span>{{ action.label }}</span>
            </button>
        </div>

        <div v-if="(loan.history?.length ?? 0) > 0" class="surface-card shadow-1 border-round-xl p-4">
            <h3 class="m-0 text-900 text-2xl font-bold mb-4">Historial de Estados</h3>

            <div class="detalle-history">
                <div v-for="(item, index) in loan.history" :key="`${item.status}-${item.dateTime}`" class="detalle-history__item">
                    <div class="detalle-history__icon">
                        <i :class="item.icon"></i>
                    </div>
                    <div class="detalle-history__content">
                        <div class="flex flex-wrap align-items-center gap-3 mb-2">
                            <span class="detalle-history__status">{{ item.status }}</span>
                            <span class="detalle-history__date">{{ item.dateTime }}</span>
                        </div>
                        <p class="m-0 detalle-history__description">{{ item.description }}</p>
                    </div>
                    <div v-if="index !== loan.history.length - 1" class="detalle-history__line"></div>
                </div>
            </div>
        </div>
    </div>

    <DocumentPreviewDialog
        v-model:visible="documentPreviewVisible"
        :title="documentPreviewTitle"
        :source="documentPreviewSource"
        @hide="clearDocumentPreview"
    />

    <Dialog
        v-model:visible="searchStartModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(560px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div class="detalle-decision-modal">
            <div class="detalle-decision-modal__body">
                <div class="detalle-decision-modal__icon detalle-decision-modal__icon--primary">
                    <i class="pi pi-search"></i>
                </div>

                <h2 class="detalle-decision-modal__title detalle-decision-modal__title--primary m-0">
                    Iniciar búsqueda de documentos
                </h2>

                <p class="detalle-decision-modal__subtitle m-0">
                    Se marcará esta solicitud como «Búsqueda en proceso» mientras localiza los documentos solicitados en el archivo.
                </p>

                <div class="detalle-decision-modal__actions">
                    <button
                        type="button"
                        class="detalle-decision-modal__button detalle-decision-modal__button--cancel-primary"
                        :disabled="searchStartSubmitting"
                        @click="searchStartModalVisible = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="detalle-decision-modal__button detalle-decision-modal__button--primary"
                        :disabled="searchStartSubmitting"
                        @click="submitStartSearch"
                    >
                        {{ searchStartSubmitting ? 'Procesando...' : 'Iniciar búsqueda' }}
                    </button>
                </div>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="decisionModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(560px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div v-if="decisionModalConfig" class="detalle-decision-modal">
            <div class="detalle-decision-modal__body">
                <div
                    class="detalle-decision-modal__icon"
                    :class="`detalle-decision-modal__icon--${decisionModalConfig.tone}`"
                >
                    <i :class="decisionModalConfig.icon"></i>
                </div>

                <h2
                    class="detalle-decision-modal__title m-0"
                    :class="`detalle-decision-modal__title--${decisionModalConfig.tone}`"
                >
                    {{ decisionModalConfig.title }}
                </h2>

                <p class="detalle-decision-modal__subtitle m-0">
                    {{ decisionModalConfig.subtitle }}
                </p>

                <div class="detalle-decision-modal__field">
                    <label class="detalle-decision-modal__label">
                        {{ decisionModalConfig.label }}
                        <span v-if="decisionModalConfig.required">*</span>
                    </label>
                    <textarea
                        v-model="decisionForm.comments"
                        rows="5"
                        class="detalle-decision-modal__textarea"
                        :maxlength="OBSERVATION_MAX_LENGTH"
                        :placeholder="decisionModalConfig.placeholder"
                    ></textarea>
                </div>

                <div class="detalle-decision-modal__actions">
                    <button
                        type="button"
                        class="detalle-decision-modal__button detalle-decision-modal__button--cancel"
                        :class="`detalle-decision-modal__button--cancel-${decisionModalConfig.tone}`"
                        :disabled="decisionSubmitting"
                        @click="decisionModalVisible = false; resetDecisionForm()"
                    >
                        {{ decisionModalConfig.cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="detalle-decision-modal__button"
                        :class="`detalle-decision-modal__button--${decisionModalConfig.tone}`"
                        :disabled="decisionSubmitting"
                        @click="submitDecisionAction"
                    >
                        {{ decisionSubmitting ? 'Procesando...' : decisionModalConfig.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="finishSearchModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(940px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div class="detalle-form-modal">
            <div class="detalle-form-modal__header detalle-form-modal__header--primary">
                <div class="detalle-form-modal__icon">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="m-0 text-xl font-bold">Finalizar búsqueda de documentos</h2>
                    <p class="m-0 mt-2">Indique el resultado de la búsqueda de documentos.</p>
                </div>
            </div>

            <div class="detalle-form-modal__body">
                <div class="mb-3">
                    <label class="detalle-form-label">Resultado de la búsqueda <span>*</span></label>
                    <select v-model="finishSearchForm.result" class="detalle-input">
                        <option value="all">✓ Todos los documentos fueron encontrados</option>
                        <option value="partial">● Solo algunos documentos fueron encontrados</option>
                        <option value="none">✕ No se encontraron documentos disponibles</option>
                    </select>
                </div>

                <div v-if="finishSearchForm.result === 'partial'" class="detalle-form-section detalle-form-section--primary mb-3">
                    <div class="detalle-loan-selection__top">
                        <div class="detalle-loan-selection__heading">
                            <div class="detalle-form-section__icon detalle-form-section__icon--primary">
                                <i class="pi pi-file-check"></i>
                            </div>
                            <div>
                                <div class="detalle-form-section__title">Documentos encontrados</div>
                                <div class="detalle-form-section__text">Seleccione los documentos que si fueron localizados.</div>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="detalle-mini-action detalle-mini-action--primary"
                            :disabled="searchResultDocuments.length === 0"
                            @click="toggleFinishSearchDocuments"
                        >
                            {{ finishSearchAllSelected ? 'Deseleccionar todos' : 'Seleccionar todos' }}
                        </button>
                    </div>

                    <div class="flex flex-column gap-3">
                        <label
                            v-for="document in searchResultDocuments"
                            :key="document.id"
                            :class="[
                                'detalle-select-card',
                                'detalle-select-card--primary',
                                finishSearchForm.selectedIds.includes(document.id) ? 'detalle-select-card--selected' : ''
                            ]"
                        >
                            <input
                                type="checkbox"
                                class="detalle-select-card__checkbox"
                                :checked="finishSearchForm.selectedIds.includes(document.id)"
                                @change="toggleFinishSearchDocument(document.id)"
                            />
                            <div class="detalle-select-card__content">
                                <div class="detalle-document-card__title">{{ document.title }}</div>
                                <div class="detalle-select-card__meta">
                                    <span>{{ document.groupTitle }}</span>
                                    <span v-if="document.series">Serie: {{ document.series }}</span>
                                    <span v-if="document.box">Caja: {{ document.box }}</span>
                                    <span v-if="document.year">Año: {{ document.year }}</span>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="detalle-select-summary detalle-select-summary--primary mt-3">
                        Documentos marcados como encontrados: {{ finishSearchForm.selectedIds.length }} de {{ searchResultDocuments.length }}
                    </div>
                </div>

                <div>
                    <label class="detalle-form-label">Comentarios (opcional)</label>
                    <textarea
                        v-model="finishSearchForm.comments"
                        class="detalle-textarea"
                        rows="5"
                        :maxlength="OBSERVATION_MAX_LENGTH"
                        placeholder="Agregue comentarios sobre el resultado de la búsqueda..."
                    ></textarea>
                </div>
            </div>

            <div class="detalle-form-modal__footer">
                <button
                    type="button"
                    class="detalle-action detalle-action--secondary"
                    :disabled="finishSearchSubmitting"
                    @click="finishSearchModalVisible = false"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="detalle-action detalle-action--primary"
                    :disabled="finishSearchSubmitting"
                    @click="submitFinishSearch"
                >
                    <i class="pi pi-check-circle"></i>
                    <span>{{ finishSearchSubmitting ? 'Procesando...' : 'Finalizar búsqueda' }}</span>
                </button>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="loanModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(920px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div class="detalle-form-modal">
            <div class="detalle-form-modal__header detalle-form-modal__header--success">
                <div class="detalle-form-modal__icon">
                    <i class="pi pi-box"></i>
                </div>
                <div>
                    <h2 class="m-0">{{ loan.loanRegistration?.title }}</h2>
                    <p class="m-0 mt-2">{{ loan.loanRegistration?.subtitle }}</p>
                </div>
            </div>

            <div class="detalle-form-modal__body">
                <div class="detalle-form-section detalle-form-section--success detalle-loan-selection">
                    <div class="detalle-loan-selection__top">
                        <div class="detalle-loan-selection__heading">
                            <div class="detalle-form-section__icon detalle-form-section__icon--success">
                                <i class="pi pi-file"></i>
                            </div>
                            <div>
                                <div class="detalle-form-section__title">{{ loan.loanRegistration?.helperTitle }}</div>
                            </div>
                        </div>
                        <button type="button" class="detalle-mini-action detalle-mini-action--success" @click="toggleLoanDocuments">
                            {{ loanAllSelected ? 'Deseleccionar todos' : loan.loanRegistration?.selectAllLabel }}
                        </button>
                    </div>

                    <div class="detalle-form-section__text detalle-loan-selection__help">{{ loan.loanRegistration?.helperText }}</div>

                    <div class="detalle-loan-selection__group-title">
                        <i class="pi pi-file"></i>
                        <span>{{ loan.loanRegistration?.documentsTitle }}</span>
                    </div>

                    <div class="flex flex-column gap-3">
                        <label
                            v-for="document in loanRegistrationDocuments"
                            :key="document.id"
                            :class="[
                                'detalle-select-card',
                                'detalle-select-card--loan',
                                loanForm.selectedIds.includes(document.id) ? 'detalle-select-card--selected' : ''
                            ]"
                        >
                            <input
                                :checked="loanForm.selectedIds.includes(document.id)"
                                type="checkbox"
                                class="detalle-select-card__checkbox"
                                @change="toggleLoanDocument(document.id)"
                            />
                            <div class="detalle-select-card__content">
                                <div class="detalle-document-card__title detalle-select-card__title">{{ document.title }}</div>
                                <div class="detalle-select-card__meta">
                                    <span><strong>Serie:</strong> {{ document.series || '-' }}</span>
                                    <span><strong>Caja:</strong> {{ document.box || '-' }}</span>
                                    <span><strong>Año:</strong> {{ document.year || '-' }}</span>
                                    <span :class="documentTypeClass(document.documentTypeColor)">{{ document.documentType }}</span>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="detalle-select-summary mt-3">
                        {{
                            formatSelectionSummary(
                                loan.loanRegistration?.selectionSummary ?? '',
                                loanForm.selectedIds.length,
                                loanRegistrationDocuments.length,
                            )
                        }}
                    </div>
                </div>

                <div class="grid mt-4">
                    <div class="col-12 md:col-6">
                        <label class="detalle-form-label">Fecha de prestamo <span>*</span></label>
                        <DatePicker
                            v-model="loanForm.loanDate"
                            showIcon
                            iconDisplay="input"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="detalle-datepicker"
                        />
                    </div>
                    <div class="col-12 md:col-6">
                        <label class="detalle-form-label">Fecha de devolución programada <span>*</span></label>
                        <DatePicker
                            v-model="loanForm.dueDate"
                            showIcon
                            iconDisplay="input"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            :minDate="loanForm.loanDate || undefined"
                            class="detalle-datepicker"
                        />
                    </div>
                    <div class="col-12">
                        <label class="detalle-form-label">Nombre de quien recibe <span>*</span></label>
                        <input v-model="loanForm.receivedBy" type="text" class="detalle-input" placeholder="Nombre completo de la persona que recibe el prestamo" />
                    </div>
                    <div class="col-12">
                        <label class="detalle-form-label">Observaciones (opcional)</label>
                        <textarea v-model="loanForm.observations" rows="4" class="detalle-textarea" :maxlength="OBSERVATION_MAX_LENGTH" placeholder="Agregue observaciones sobre el prestamo..."></textarea>
                    </div>
                </div>
            </div>

            <div class="detalle-form-modal__footer">
                <button type="button" class="detalle-action detalle-action--secondary" @click="loanModalVisible = false">Cancelar</button>
                <button type="button" class="detalle-action detalle-action--success" @click="submitLoanRegistration">
                    <i class="pi pi-box"></i>
                    <span>Registrar préstamo</span>
                </button>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="documentModificationsModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(720px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div class="detalle-modifications-modal">
            <div class="detalle-modifications-modal__header">
                <div class="detalle-modifications-modal__title-wrap">
                    <div class="detalle-modifications-modal__icon">
                        <i class="pi pi-file-edit"></i>
                    </div>
                    <div>
                        <h2 class="m-0">Registrar Modificaciones</h2>
                        <p class="m-0 mt-1">Solicitud #{{ loan.number }}</p>
                    </div>
                </div>
                <button type="button" class="detalle-modifications-modal__close" @click="documentModificationsModalVisible = false">
                    <i class="pi pi-times"></i>
                </button>
            </div>

            <div class="detalle-modifications-modal__body">
                <div class="detalle-modifications-modal__notice">
                    <i class="pi pi-exclamation-triangle"></i>
                    <span>Indique que modificaciones se han realizado a los documentos prestados. Esta información estara visible para la UGDA al momento de registrar la devolución.</span>
                </div>

                <div v-if="documentModificationsForm.items.length === 0" class="detalle-modifications-modal__empty">
                    No hay modificaciones registradas. Haga clic en "Agregar documento" para comenzar.
                </div>

                <div class="detalle-modifications-modal__items">
                    <div
                        v-for="(item, index) in documentModificationsForm.items"
                        :key="index"
                        class="detalle-modifications-modal__item"
                    >
                        <div class="detalle-modifications-modal__field-row">
                            <div class="detalle-modifications-modal__field">
                                <label class="detalle-form-label">Documento <span>*</span></label>
                                <select v-model="item.loanDocumentId" class="detalle-input">
                                    <option
                                        v-for="document in documentModificationDocuments"
                                        :key="document.id"
                                        :value="document.id"
                                        :disabled="documentModificationsForm.items.some((entry, entryIndex) => entryIndex !== index && entry.loanDocumentId === document.id)"
                                    >
                                        {{ document.title }}
                                    </option>
                                </select>
                            </div>
                            <button
                                type="button"
                                class="detalle-modifications-modal__remove"
                                :aria-label="`Quitar modificacion de ${documentModificationTitle(item.loanDocumentId)}`"
                                @click="removeDocumentModification(index)"
                            >
                                <i class="pi pi-trash"></i>
                            </button>
                        </div>

                        <div>
                            <label class="detalle-form-label">Descripcion de la modificacion <span>*</span></label>
                            <textarea
                                v-model="item.description"
                                rows="4"
                                class="detalle-textarea"
                                :maxlength="OBSERVATION_MAX_LENGTH"
                                placeholder="Describa que modificacion se realizo a este documento..."
                            ></textarea>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="detalle-modifications-modal__add"
                    :disabled="documentModificationsForm.items.length >= documentModificationDocuments.length"
                    @click="addDocumentModification"
                >
                    <i class="pi pi-plus"></i>
                    <span>Agregar documento</span>
                </button>
            </div>

            <div class="detalle-modifications-modal__footer">
                <button
                    type="button"
                    class="detalle-action detalle-action--secondary"
                    :disabled="documentModificationsSubmitting"
                    @click="documentModificationsModalVisible = false"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="detalle-action detalle-action--warning"
                    :disabled="documentModificationsSubmitting || !canSaveDocumentModifications"
                    @click="submitDocumentModifications"
                >
                    <i class="pi pi-check-circle"></i>
                    <span>{{ documentModificationsSubmitting ? 'Guardando...' : 'Guardar modificaciones' }}</span>
                </button>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="returnModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(920px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div class="detalle-form-modal">
            <div class="detalle-form-modal__header detalle-form-modal__header--muted">
                <div class="detalle-form-modal__icon detalle-form-modal__icon--muted">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="m-0">{{ loan.returnRegistration?.title }}</h2>
                    <p class="m-0 mt-2">{{ loan.returnRegistration?.subtitle }}</p>
                </div>
            </div>

            <div class="detalle-form-modal__body">
                <div class="detalle-form-section detalle-form-section--muted">
                    <div class="flex flex-column md:flex-row md:justify-content-between gap-3 mb-4">
                        <div class="flex align-items-center gap-3">
                            <div class="detalle-form-section__icon detalle-form-section__icon--muted">
                                <i class="pi pi-file"></i>
                            </div>
                            <div>
                                <div class="detalle-form-section__title">{{ loan.returnRegistration?.helperTitle }}</div>
                                <div class="detalle-form-section__text">{{ loan.returnRegistration?.helperText }}</div>
                            </div>
                        </div>
                        <button type="button" class="detalle-mini-action detalle-mini-action--muted" @click="toggleReturnDocuments">
                            {{ returnAllSelected ? 'Deseleccionar todos' : loan.returnRegistration?.selectAllLabel }}
                        </button>
                    </div>

                    <div class="font-semibold text-900 mb-3">{{ loan.returnRegistration?.documentsTitle }}</div>

                    <div class="flex flex-column gap-3">
                        <label
                            v-for="document in returnRegistrationDocuments"
                            :key="document.id"
                            class="detalle-select-card"
                        >
                            <input
                                :checked="returnForm.selectedIds.includes(document.id)"
                                type="checkbox"
                                class="detalle-select-card__checkbox"
                                @change="toggleReturnDocument(document.id)"
                            />
                            <div class="detalle-select-card__content">
                                <div class="detalle-document-card__title mb-2">{{ document.title }}</div>
                                <div class="detalle-select-card__meta">
                                    <span><strong>Serie:</strong> {{ document.series }}</span>
                                    <span><strong>Caja:</strong> {{ document.box }}</span>
                                    <span><strong>Año:</strong> {{ document.year }}</span>
                                    <span :class="documentTypeClass(document.documentTypeColor)">{{ document.documentType }}</span>
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="detalle-select-summary mt-3">
                        {{
                            formatSelectionSummary(
                                loan.returnRegistration?.selectionSummary ?? '',
                                returnForm.selectedIds.length,
                                returnRegistrationDocuments.length,
                            )
                        }}
                    </div>
                </div>

                <div
                    v-if="(loan.documentModifications?.items?.length ?? 0) > 0"
                    class="detalle-return-modifications"
                >
                    <div class="detalle-return-modifications__title">
                        <i class="pi pi-exclamation-triangle"></i>
                        <span>{{ loan.documentModifications.returnTitle }}</span>
                        <span class="detalle-return-modifications__count">({{ loan.documentModifications.count }})</span>
                    </div>
                    <p class="detalle-return-modifications__text m-0">{{ loan.documentModifications.returnDescription }}</p>

                    <div class="detalle-return-modifications__list">
                        <div
                            v-for="item in loan.documentModifications.items"
                            :key="item.id"
                            class="detalle-return-modifications__item"
                        >
                            <div class="detalle-return-modifications__item-title">
                                <i class="pi pi-file-edit"></i>
                                <span>{{ item.documentTitle }}</span>
                            </div>
                            <p class="detalle-return-modifications__item-text m-0">{{ item.description }}</p>
                            <div class="detalle-return-modifications__item-meta">
                                Registrado por {{ item.registeredBy || 'Usuario' }} - {{ item.registeredAt }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid mt-4">
                    <div class="col-12">
                        <label class="detalle-form-label">Fecha de devolución <span>*</span></label>
                        <DatePicker
                            v-model="returnForm.returnDate"
                            showIcon
                            iconDisplay="input"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="detalle-datepicker"
                        />
                    </div>
                    <div class="col-12">
                        <label class="detalle-form-label">Condición de los documentos <span>*</span></label>
                        <input v-model="returnForm.condition" type="text" class="detalle-input" placeholder="Condición de los documentos" />
                    </div>
                    <div class="col-12">
                        <label class="detalle-form-label">Observaciones (opcional)</label>
                        <textarea v-model="returnForm.observations" rows="4" class="detalle-textarea" :maxlength="OBSERVATION_MAX_LENGTH" placeholder="Agregue observaciones sobre la devolución..."></textarea>
                    </div>
                </div>
            </div>

            <div class="detalle-form-modal__footer">
                <button type="button" class="detalle-action detalle-action--secondary" @click="returnModalVisible = false">Cancelar</button>
                <button type="button" class="detalle-action detalle-action--muted" @click="submitReturnRegistration">
                    <i class="pi pi-check-circle"></i>
                    <span>Registrar Devolución</span>
                </button>
            </div>
        </div>
    </Dialog>
</template>

<style scoped>
.detalle-back-button {
    width: 2.75rem;
    height: 2.75rem;
    border: none;
    background: transparent;
    color: #2f6f9f;
    border-radius: 0.85rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.detalle-title {
    color: #2f6f9f;
    font-size: 2.3rem;
    line-height: 1.1;
    font-weight: 700;
}

.detalle-subtitle {
    display: block;
    margin-top: 0.25rem;
    color: #64748b;
    font-size: 1rem;
    line-height: 1.35;
}

.detalle-decision-modal {
    background: #ffffff;
}

.detalle-decision-modal__body {
    padding: 2.25rem 2rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.detalle-decision-modal__icon {
    width: 3.9rem;
    height: 3.9rem;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.65rem;
    margin-bottom: 1.5rem;
}

.detalle-decision-modal__icon--success {
    background: #ebf8ee;
    color: #56b657;
}

.detalle-decision-modal__icon--primary {
    background: #e8f2ff;
    color: #2f6fae;
}

.detalle-decision-modal__icon--warning {
    background: #fff3e3;
    color: #f0a53a;
}

.detalle-decision-modal__icon--danger {
    background: #fde8e8;
    color: #e6554f;
}

.detalle-decision-modal__title {
    font-size: 1.15rem;
    font-weight: 700;
}

.detalle-decision-modal__title--success {
    color: #56b657;
}

.detalle-decision-modal__title--primary {
    color: #2f6fae;
}

.detalle-decision-modal__title--warning {
    color: #f0a53a;
}

.detalle-decision-modal__title--danger {
    color: #e6554f;
}

.detalle-decision-modal__subtitle {
    margin-top: 1rem;
    color: #64748b;
    font-size: 0.95rem;
    line-height: 1.45;
    max-width: 24rem;
}

.detalle-decision-modal__field {
    width: 100%;
    margin-top: 1.5rem;
    text-align: left;
}

.detalle-decision-modal__label {
    display: block;
    color: #1f2937;
    font-size: 0.95rem;
    margin-bottom: 0.7rem;
}

.detalle-decision-modal__label span {
    color: #ef4444;
    margin-left: 0.2rem;
}

.detalle-decision-modal__textarea {
    width: 100%;
    min-height: 7rem;
    border: 1px solid #d7dee8;
    border-radius: 0.65rem;
    padding: 1rem;
    resize: none;
    font: inherit;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__textarea:focus {
    border-color: #94a3b8;
    box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.18);
}

.detalle-decision-modal__actions {
    width: 100%;
    display: flex;
    justify-content: center;
    gap: 0.85rem;
    margin-top: 1.55rem;
}

.detalle-decision-modal__button {
    min-width: 10rem;
    border-radius: 0.75rem;
    padding: 0.95rem 1.35rem;
    font-weight: 700;
    font-size: 0.98rem;
    cursor: pointer;
    border: 1px solid transparent;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__button:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.detalle-decision-modal__button:disabled {
    cursor: not-allowed;
}

.detalle-decision-modal__button--success {
    background: #56b657;
    color: #ffffff;
}

.detalle-decision-modal__button--primary {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.detalle-decision-modal__button--warning {
    background: #f59e0b;
    color: #ffffff;
}

.detalle-decision-modal__button--warning:hover:not(:disabled) {
    background: #d97706;
}

.detalle-decision-modal__button--danger {
    background: #ef4444;
    color: #ffffff;
}

.detalle-decision-modal__button--danger:hover:not(:disabled) {
    background: #dc2626;
}

.detalle-decision-modal__button--cancel {
    background: #ffffff;
}

.detalle-decision-modal__button--cancel-success {
    border-color: #7a8ea5;
    color: #64748b;
}

.detalle-decision-modal__button--cancel-primary {
    border-color: var(--ugda-border-strong);
    color: var(--ugda-text-secondary);
}

.detalle-decision-modal__button--cancel-warning {
    border-color: #f0a53a;
    color: #f0a53a;
}

.detalle-decision-modal__button--cancel-danger {
    border-color: #fb5a55;
    color: #fb5a55;
}

.detalle-banner {
    border-radius: 1rem;
    padding: 1.5rem;
}

.detalle-banner--success {
    background: #d7f8e8;
    border-left: 4px solid #28b04b;
}

.detalle-banner--warning {
    background: #fff3c4;
    border-left: 4px solid #f6ad3c;
}

.detalle-banner--blue {
    background: #dbeafe;
    border-left: 4px solid #1d4ed8;
}

.detalle-banner--danger {
    background: #fee2e2;
    border-left: 4px solid #ef4444;
}

.detalle-banner__head {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.detalle-banner__icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 0.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    flex-shrink: 0;
    font-size: 1.15rem;
}

.detalle-banner__icon--success {
    background: #28b04b;
}

.detalle-banner__icon--warning {
    background: #f6ad3c;
}

.detalle-banner__icon--blue {
    background: #1d4ed8;
}

.detalle-banner__icon--danger {
    background: #ef4444;
}

.detalle-banner__title {
    color: #0f172a;
    font-size: 1.2rem;
    font-weight: 700;
}

.detalle-banner__text {
    color: #5b6576;
    margin-top: 0.35rem;
}

.detalle-banner__panel {
    background: #ffffff;
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}

.detalle-banner__divider {
    border-top: 1px solid #c7d2fe;
    margin: 1rem 0;
}

.detalle-banner__notes {
    background: #edf4ff;
    border-radius: 0.7rem;
    color: #0f3f91;
    padding: 0.9rem 1rem;
}

.detalle-banner__panel--danger {
    border: 1px solid #f7b5b5;
}

.detalle-search-completed {
    color: #7a4d0b;
}

.detalle-search-completed .detalle-banner__head {
    margin-bottom: 0.75rem;
}

.detalle-search-completed .detalle-banner__title {
    color: #6f4300;
    font-size: 1.15rem;
}

.detalle-search-completed .detalle-banner__text {
    color: #7a4d0b;
}

.detalle-search-completed__panel {
    background: transparent;
    border-top: 1px solid rgba(154, 103, 0, 0.18);
    border-radius: 0;
    padding: 0.85rem 0 0;
}

.detalle-search-completed .detalle-small-label {
    color: #7a4d0b;
    font-weight: 700;
}

.detalle-search-completed .detalle-small-value {
    color: #7a4d0b;
    font-weight: 500;
}

.detalle-search-completed .detalle-divider {
    border-top-color: rgba(154, 103, 0, 0.18);
}

.detalle-search-completed .detalle-note-text {
    color: #7a4d0b;
    font-style: italic;
}

.detalle-search-completed__documents-title {
    color: #7a4d0b;
    font-weight: 800;
    margin-bottom: 0.85rem;
}

.detalle-search-completed .detalle-summary-findings {
    background: rgba(255, 253, 243, 0.68);
    border-color: rgba(246, 173, 60, 0.55);
}

.detalle-search-completed .detalle-summary-findings__title {
    color: #8a5800;
}

.detalle-search-completed .detalle-summary-findings__item {
    color: #7a4d0b;
}

.detalle-search-completed--danger {
    color: #8a0f1d;
}

.detalle-search-completed--danger .detalle-banner__title,
.detalle-search-completed--danger .detalle-banner__text,
.detalle-search-completed--danger .detalle-small-label,
.detalle-search-completed--danger .detalle-small-value,
.detalle-search-completed--danger .detalle-note-text {
    color: #8a0f1d;
}

.detalle-search-completed--danger .detalle-search-completed__panel,
.detalle-search-completed--danger .detalle-divider {
    border-top-color: rgba(185, 28, 28, 0.18);
}

.detalle-banner__reason {
    border-top: 1px solid #f7b5b5;
    padding-top: 1rem;
    margin-top: 1rem;
    color: #991b1b;
}

.detalle-small-label {
    color: #0f6848;
    font-size: 0.92rem;
    margin-bottom: 0.35rem;
}

.detalle-small-value {
    color: #0f6848;
    font-size: 1.05rem;
    font-weight: 700;
}

.detalle-divider {
    margin: 1rem 0;
    border-top: 1px solid #dbe7df;
}

.detalle-note-text {
    color: #9a6700;
    line-height: 1.6;
}

.detalle-summary-findings {
    border: 1px solid #f3d68a;
    background: #fffdf3;
    border-radius: 0.9rem;
    padding: 0.9rem 1rem;
}

.detalle-summary-findings__title,
.detalle-loan-summary-group__title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    color: #9a6700;
    font-weight: 700;
}

.detalle-summary-findings__item,
.detalle-loan-summary-group__item {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
    margin-top: 0.75rem;
    color: #475569;
}

.detalle-summary-findings__item .pi,
.detalle-loan-summary-group__item .pi {
    color: #16a34a;
}

.detalle-loan-summary-group {
    border: 1px solid #bfe8cc;
    background: #ffffff;
    border-radius: 0.9rem;
    padding: 0.9rem 1rem;
}

.detalle-loan-sheet-card {
    background: #fff7d6;
    border: 2px solid #e0a619;
    border-radius: 8px;
    box-shadow: none;
    overflow: hidden;
}

.detalle-loan-sheet-card__content {
    align-items: center;
    display: flex;
    gap: 1.5rem;
    justify-content: space-between;
    padding: 1.35rem 1.5rem;
}

.detalle-loan-sheet-card__main {
    align-items: center;
    display: flex;
    gap: 1rem;
    min-width: 0;
}

.detalle-loan-sheet-card__icon {
    align-items: center;
    background: #d89614;
    border-radius: 8px;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.35rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.detalle-loan-sheet-card h3 {
    color: #3d2a05;
    font-size: 1.12rem;
    font-weight: 800;
    margin: 0;
}

.detalle-loan-sheet-card p {
    color: #5d480f;
    font-size: 0.95rem;
    line-height: 1.45;
    margin: 0.35rem 0 0;
}

.detalle-loan-sheet-card__button {
    background: #b7790b !important;
    border: 1px solid #b7790b !important;
    border-radius: 8px;
    color: #ffffff !important;
    flex-shrink: 0;
    font-weight: 800;
    min-height: 2.8rem;
    min-width: 10.5rem;
}

.detalle-loan-sheet-card__button:hover:not(:disabled) {
    background: #8f5c06 !important;
    border-color: #8f5c06 !important;
}

.detalle-loan-summary-banner .detalle-banner__title {
    color: #004d2a;
}

.detalle-loan-summary-banner .detalle-banner__text {
    color: #164e37;
}

.detalle-loan-summary-banner .detalle-banner__panel {
    border-radius: 0.95rem;
}

.detalle-loan-summary-banner .detalle-small-label,
.detalle-loan-summary-banner .detalle-small-label .pi {
    color: #006b3d;
}

.detalle-loan-summary-banner .detalle-small-value {
    color: #004d2a;
}

.detalle-loan-summary-banner .detalle-divider {
    border-top-color: #cfe1d8;
}

.detalle-loan-summary-banner .detalle-loan-summary-group__title,
.detalle-loan-summary-banner .detalle-loan-summary-group__title .pi,
.detalle-loan-summary-banner .detalle-note-text {
    color: #006b3d;
}

.detalle-loan-summary-banner .detalle-loan-summary-group__item {
    color: #334155;
}

.detalle-loan-summary-banner .detalle-loan-summary-group__item .font-semibold {
    color: #004d2a;
}

.detalle-loan-summary-banner .detalle-document-type--warning {
    background: #fee2e2;
    border-radius: 999px;
    color: #dc2626;
    padding: 0.12rem 0.45rem;
}

.detalle-observation-card {
    background: #ffffff;
    border-radius: 0.95rem;
    padding: 1rem 1.1rem;
}

.detalle-observation-card__meta {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    color: #9a6700;
    font-size: 0.92rem;
    flex-wrap: wrap;
}

.detalle-observation-card__meta--danger {
    color: #b91c1c;
}

.detalle-observation-card__message {
    color: #6b4b0f;
    margin-top: 0.85rem;
    line-height: 1.6;
}

.detalle-notice {
    display: flex;
    align-items: flex-start;
    gap: 0.9rem;
    background: #fff3c4;
    border-left: 4px solid #f6ad3c;
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}

.detalle-notice__icon {
    color: #f59e0b;
    font-size: 1.1rem;
    line-height: 1;
    margin-top: 0.1rem;
}

.detalle-notice__title {
    color: #9a6700;
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.detalle-notice__text {
    color: #7c5d15;
}

.detalle-panel {
    overflow: hidden;
    border-radius: 1rem;
    background: #ffffff;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
}

.detalle-panel__header {
    background: #ffffff;
    color: #0f172a;
    font-size: 1.25rem;
    font-weight: 600;
    padding: 1.75rem 2rem 0;
}

.detalle-panel__header--split {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.detalle-panel__chips {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.7rem;
    justify-content: flex-end;
}

.detalle-panel__body {
    padding: 1.4rem 2rem 2rem;
}

.detalle-label {
    display: block;
    color: #64748b;
    font-size: 0.96rem;
    margin-bottom: 0.6rem;
}

.detalle-value {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.5;
}

.detalle-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.9rem;
    min-width: 6rem;
    padding: 0.28rem 0.9rem;
    border-radius: 9999px;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
}

.detalle-chip--success {
    background: #c9f2e8;
    color: #0c8a66;
}

.detalle-chip--danger {
    background: #fee2e2;
    color: #dc2626;
}

.detalle-chip--warning {
    background: #fde9a9;
    color: #b45309;
}

.detalle-chip--neutral {
    background: #d9dee7;
    color: #64748b;
}

.detalle-chip--info {
    background: #dbeafe;
    color: #2563eb;
}

.detalle-group-title {
    color: #64748b;
    font-size: 1.35rem;
    font-weight: 600;
    margin: 0 0 1rem;
}

.detalle-empty-documents {
    min-height: 7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #475569;
    text-align: center;
    padding: 1.5rem;
}

.detalle-document-card {
    border: 1px solid #d7dde6;
    border-radius: 1rem;
    background: #ffffff;
    padding: 1.25rem 1.35rem;
    display: flex;
    gap: 1rem;
}

.detalle-document-card--additional {
    background: #fffdf3;
}

.detalle-document-card__icon {
    width: 2.35rem;
    height: 2.35rem;
    border-radius: 0.8rem;
    background: #eff6ff;
    color: #2f6f9f;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.detalle-document-card__icon--box {
    background: #eef5ff;
}

.detalle-document-card__title {
    color: #0f172a;
    font-size: 1.15rem;
    font-weight: 700;
}

.detalle-document-card__meta {
    color: #475569;
    font-size: 0.98rem;
    margin-bottom: 0.45rem;
}

.detalle-document-card__note {
    color: #64748b;
    font-size: 0.92rem;
    font-style: italic;
    margin-top: 0.4rem;
}

.detalle-document-type {
    font-weight: 600;
    margin-left: 0.35rem;
}

.detalle-document-type--info {
    color: #2563eb;
}

.detalle-document-type--warning {
    color: #9a6700;
}

.detalle-document-type--success {
    color: #0c8a66;
}

.detalle-banner__actions {
    margin-top: 15px;
}

.detalle-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.detalle-action {
    border: none;
    border-radius: 0.9rem;
    padding: 0.95rem 1.35rem;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    justify-content: center;
    white-space: nowrap;
    min-height: 3.15rem;
}

.detalle-action--primary {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.detalle-action--success {
    background: #28b04b;
    color: #ffffff;
}

.detalle-action--warning {
    background: #f6ad3c;
    color: #ffffff;
}

.detalle-action--danger {
    background: #e35151;
    color: #ffffff;
}

.detalle-action--muted {
    background: #6b7280;
    color: #ffffff;
}

.detalle-action--secondary {
    background: #ffffff;
    color: #2f6f9f;
    border: 1px solid #2f6f9f;
}

.detalle-history {
    position: relative;
}

.detalle-history__item {
    position: relative;
    display: flex;
    gap: 1rem;
    padding: 0.35rem 0 1.4rem;
}

.detalle-history__icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    z-index: 1;
}

.detalle-history__content {
    flex: 1;
}

.detalle-history__status {
    color: #0f172a;
    font-size: 1.15rem;
    font-weight: 700;
}

.detalle-history__date {
    color: #64748b;
    font-size: 0.92rem;
}

.detalle-history__description {
    color: #475569;
    font-size: 1rem;
    line-height: 1.6;
}

.detalle-history__line {
    position: absolute;
    top: 2.7rem;
    left: 1.15rem;
    width: 2px;
    height: calc(100% - 1rem);
    background: #d7dde6;
}

.detalle-form-modal {
    display: flex;
    flex-direction: column;
    max-height: min(92vh, 860px);
    overflow: hidden;
    background: #ffffff;
}

.detalle-form-modal__header {
    padding: 1.3rem 1.5rem;
    color: #ffffff;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.detalle-form-modal__header h2,
.detalle-form-modal__header p {
    color: #ffffff !important;
}

.detalle-form-modal__header--success {
    background: #28b04b;
}

.detalle-form-modal__header--primary {
    background: #2f6fae;
}

.detalle-form-modal__header--muted {
    background: #6b7280;
}

.detalle-form-modal__icon {
    width: 3rem;
    height: 3rem;
    border-radius: 9999px;
    background: rgba(255, 255, 255, 0.22);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}

.detalle-form-modal__body {
    padding: 1.4rem 1.5rem;
    overflow-y: auto;
    min-height: 0;
}

.detalle-form-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.85rem;
    padding: 1rem 1.5rem 1.45rem;
    border-top: 1px solid #e2e8f0;
    flex-shrink: 0;
    background: #ffffff;
}

.detalle-form-section {
    border-radius: 1rem;
    padding: 1rem;
    border: 1px solid #d7dde6;
}

.detalle-form-section--success {
    border-color: #28b04b;
    background: #f6fffa;
}

.detalle-form-section--primary {
    border-color: #9cc4f0;
    background: #f5f9ff;
}

.detalle-form-section--muted {
    border-color: #94a3b8;
    background: #ffffff;
}

.detalle-form-section__icon {
    width: 2.35rem;
    height: 2.35rem;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    flex-shrink: 0;
}

.detalle-form-section__icon--success {
    background: #28b04b;
}

.detalle-form-section__icon--primary {
    background: #2f6fae;
}

.detalle-form-section__icon--muted {
    background: #6b7280;
}

.detalle-form-section__title {
    color: #0f172a;
    font-size: 1.1rem;
    font-weight: 700;
}

.detalle-form-section__text {
    color: #475569;
    margin-top: 0.3rem;
}

.detalle-mini-action {
    border: none;
    border-radius: 0.85rem;
    padding: 0.8rem 1.05rem;
    color: #ffffff;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
    min-height: 2.75rem;
}

.detalle-mini-action--success {
    background: #28b04b;
}

.detalle-mini-action--primary {
    background: #2f6fae;
}

.detalle-mini-action--muted {
    background: #6b7280;
}

.detalle-mini-action:disabled {
    cursor: not-allowed;
}

.detalle-select-card {
    border: 1px solid #d7dde6;
    border-radius: 0.95rem;
    padding: 0.9rem 1rem;
    display: flex;
    align-items: flex-start;
    gap: 0.9rem;
    cursor: pointer;
    background: #ffffff;
}

.detalle-select-card--loan {
    border-color: #d5deea;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.02);
}

.detalle-select-card--loan.detalle-select-card--selected {
    border-color: #c8d5e4;
    background: #ffffff;
}

.detalle-select-card--primary.detalle-select-card--selected {
    background: #eff6ff;
    border-color: #2f6fae;
    box-shadow: 0 0 0 1px rgba(47, 111, 174, 0.18);
}

.detalle-select-card__checkbox {
    width: 1.25rem;
    height: 1.25rem;
    margin-top: 0.05rem;
    accent-color: #1d79d3;
}

.detalle-select-card__content {
    flex: 1;
    min-width: 0;
}

.detalle-select-card__title {
    margin-bottom: 0.45rem;
}

.detalle-select-card__meta {
    display: flex;
    gap: 1.15rem;
    flex-wrap: wrap;
    color: #64748b;
    align-items: center;
    font-size: 0.92rem;
}

.detalle-select-summary {
    border: 1px solid #94a3b8;
    border-radius: 0.9rem;
    padding: 0.9rem 1rem;
    color: #334155;
    background: #ffffff;
}

.detalle-select-summary--primary {
    border-color: #bfdbfe;
    color: #1d4ed8;
    background: #eff6ff;
    font-weight: 700;
}

.detalle-loan-selection {
    padding: 1rem;
}

.detalle-loan-selection__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.9rem;
}

.detalle-loan-selection__heading {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    min-width: 0;
}

.detalle-loan-selection__help {
    margin: 0 0 1rem;
    color: #0f5132;
}

.detalle-loan-selection__group-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #0f172a;
    font-weight: 700;
    margin-bottom: 0.85rem;
}

.detalle-loan-selection__group-title .pi {
    color: #2f6fae;
    font-size: 0.95rem;
}

.detalle-form-label {
    display: inline-block;
    color: #0f172a;
    font-weight: 600;
    margin-bottom: 0.55rem;
}

.detalle-form-label span {
    color: #ef4444;
}

.detalle-input,
.detalle-textarea {
    width: 100%;
    border: 1px solid #d7dde6;
    border-radius: 0.85rem;
    padding: 0.95rem 1rem;
    color: #334155;
    font: inherit;
}

.detalle-textarea {
    resize: vertical;
}

:deep(.detalle-datepicker),
:deep(.detalle-datepicker .p-datepicker),
:deep(.detalle-datepicker .p-inputtext) {
    width: 100%;
}

.detalle-document-modifications {
    background: #ffffff;
    border: 2px solid #f6ad3c;
    border-radius: 0.75rem;
    padding: 1.5rem;
}

.detalle-document-modifications__head {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.detalle-document-modifications__intro {
    align-items: flex-start;
    display: flex;
    gap: 1rem;
    min-width: 0;
}

.detalle-document-modifications__icon {
    align-items: center;
    background: #f6ad3c;
    border-radius: 0.7rem;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.35rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.detalle-document-modifications__title {
    align-items: center;
    color: #0f172a;
    display: flex;
    flex-wrap: wrap;
    font-size: 1.25rem;
    font-weight: 800;
    gap: 0.6rem;
    line-height: 1.25;
    margin-bottom: 0.45rem;
}

.detalle-document-modifications__badge {
    align-items: center;
    background: #f6ad3c;
    border-radius: 999px;
    color: #ffffff;
    display: inline-flex;
    font-size: 0.82rem;
    height: 1.45rem;
    justify-content: center;
    min-width: 1.45rem;
    padding: 0 0.45rem;
}

.detalle-document-modifications__text {
    color: #475569;
    line-height: 1.55;
    max-width: 42rem;
}

.detalle-document-modifications__button {
    align-items: center;
    background: #f6ad3c;
    border: none;
    border-radius: 0.65rem;
    color: #ffffff;
    cursor: pointer;
    display: inline-flex;
    flex-shrink: 0;
    font-weight: 800;
    gap: 0.65rem;
    justify-content: center;
    min-height: 3rem;
    padding: 0.85rem 1.35rem;
    white-space: nowrap;
}

.detalle-document-modifications__list {
    border-top: 1px solid #f5d39b;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    margin-top: 1.25rem;
    padding-top: 1rem;
}

.detalle-document-modifications__item {
    background: #fff9e8;
    border: 1px solid #ffd48a;
    border-radius: 0.65rem;
    padding: 0.9rem 1rem;
}

.detalle-document-modifications__item-title {
    align-items: center;
    color: #9a4b00;
    display: flex;
    gap: 0.55rem;
    font-weight: 800;
    margin-bottom: 0.45rem;
}

.detalle-document-modifications__item-text {
    color: #7a4d0b;
    line-height: 1.5;
}

.detalle-document-modifications__item-meta {
    color: #b45309;
    font-size: 0.85rem;
    margin-top: 0.55rem;
}

.detalle-return-modifications {
    background: #fff9e8;
    border: 2px solid #f6ad3c;
    border-radius: 0.75rem;
    margin-top: 1.5rem;
    padding: 1rem;
}

.detalle-return-modifications__title {
    align-items: center;
    color: #9a4b00;
    display: flex;
    flex-wrap: wrap;
    font-size: 1.05rem;
    font-weight: 800;
    gap: 0.45rem;
    margin-bottom: 0.8rem;
}

.detalle-return-modifications__count {
    color: #9a4b00;
}

.detalle-return-modifications__text {
    color: #9a4b00;
    line-height: 1.5;
    margin-bottom: 0.9rem;
}

.detalle-return-modifications__list {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    margin-top: 0.9rem;
}

.detalle-return-modifications__item {
    background: #ffffff;
    border: 1px solid #ffd48a;
    border-radius: 0.65rem;
    padding: 0.85rem 1rem;
}

.detalle-return-modifications__item-title {
    align-items: center;
    color: #9a4b00;
    display: flex;
    gap: 0.55rem;
    font-weight: 800;
    margin-bottom: 0.45rem;
}

.detalle-return-modifications__item-text {
    color: #7a4d0b;
    line-height: 1.5;
}

.detalle-return-modifications__item-meta {
    color: #b45309;
    font-size: 0.85rem;
    margin-top: 0.55rem;
}

.detalle-modifications-modal {
    background: #ffffff;
    display: flex;
    flex-direction: column;
    max-height: min(92vh, 760px);
    overflow: hidden;
}

.detalle-modifications-modal__header {
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    padding: 1.4rem 1.5rem;
}

.detalle-modifications-modal__title-wrap {
    align-items: center;
    display: flex;
    gap: 0.9rem;
}

.detalle-modifications-modal__title-wrap h2 {
    color: #0f172a;
    font-size: 1.25rem;
}

.detalle-modifications-modal__title-wrap p {
    color: #64748b;
}

.detalle-modifications-modal__icon {
    align-items: center;
    background: #fff3df;
    border-radius: 0.7rem;
    color: #d97706;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.2rem;
    height: 2.4rem;
    justify-content: center;
    width: 2.4rem;
}

.detalle-modifications-modal__close {
    align-items: center;
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
    display: inline-flex;
    font-size: 1.15rem;
    height: 2.35rem;
    justify-content: center;
    width: 2.35rem;
}

.detalle-modifications-modal__body {
    overflow-y: auto;
    padding: 1rem 1.5rem;
}

.detalle-modifications-modal__notice {
    align-items: flex-start;
    background: #fff8ed;
    border: 1px solid #f6ad3c;
    border-radius: 0.7rem;
    color: #9a4b00;
    display: flex;
    gap: 0.75rem;
    line-height: 1.55;
    padding: 0.95rem 1rem;
}

.detalle-modifications-modal__notice .pi {
    margin-top: 0.2rem;
}

.detalle-modifications-modal__empty {
    color: #64748b;
    padding: 3rem 1rem 2.5rem;
    text-align: center;
}

.detalle-modifications-modal__items {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-top: 1rem;
}

.detalle-modifications-modal__item {
    background: #f8fafc;
    border: 1px solid #d7dde6;
    border-radius: 0.85rem;
    padding: 1rem;
}

.detalle-modifications-modal__field-row {
    align-items: flex-end;
    display: flex;
    gap: 0.85rem;
    margin-bottom: 0.85rem;
}

.detalle-modifications-modal__field {
    flex: 1;
    min-width: 0;
}

.detalle-modifications-modal__remove {
    align-items: center;
    background: transparent;
    border: none;
    color: #ef4444;
    cursor: pointer;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1rem;
    height: 2.75rem;
    justify-content: center;
    width: 2.75rem;
}

.detalle-modifications-modal__add {
    align-items: center;
    background: #ffffff;
    border: 2px dashed #9cc4f0;
    border-radius: 0.75rem;
    color: #2f6fae;
    cursor: pointer;
    display: flex;
    font-weight: 800;
    gap: 0.65rem;
    justify-content: center;
    margin-top: 1rem;
    min-height: 3rem;
    width: 100%;
}

.detalle-modifications-modal__add:disabled {
    cursor: not-allowed;
}

.detalle-modifications-modal__footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    display: flex;
    flex-shrink: 0;
    gap: 0.85rem;
    justify-content: flex-end;
    padding: 1rem 1.5rem;
}

@media (max-width: 991px) {
    .detalle-title {
        font-size: 1.8rem;
    }

    .detalle-panel__header,
    .detalle-panel__body,
    .detalle-banner,
    .detalle-form-modal__body,
    .detalle-form-modal__footer,
    .detalle-form-modal__header {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-panel__header--split {
        align-items: flex-start;
        flex-direction: column;
    }

    .detalle-panel__chips {
        justify-content: flex-start;
    }

    .detalle-action {
        width: 100%;
        justify-content: center;
    }

    .detalle-form-modal__footer {
        flex-direction: column-reverse;
    }

    .detalle-document-modifications__head {
        align-items: stretch;
        flex-direction: column;
    }

    .detalle-document-modifications__button {
        width: 100%;
    }

    .detalle-loan-sheet-card__content {
        align-items: stretch;
        flex-direction: column;
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-loan-sheet-card__button {
        min-width: 0;
        width: 100%;
    }
}

@media (max-width: 640px) {
    .detalle-back-button {
        width: 2.35rem;
        height: 2.35rem;
    }

    .detalle-title {
        font-size: 1.45rem;
    }

    .detalle-subtitle {
        display: inline-block;
        margin-top: 0.2rem;
    }

    .detalle-chip {
        min-width: auto;
    }

    .detalle-document-card,
    .detalle-banner__head,
    .detalle-form-modal__header,
    .detalle-select-card {
        flex-direction: column;
    }

    .detalle-loan-selection__top,
    .detalle-loan-selection__heading {
        align-items: flex-start;
    }

    .detalle-loan-selection__top {
        flex-direction: column;
    }

    .detalle-loan-selection__top .detalle-mini-action {
        width: 100%;
    }

    .detalle-select-card__checkbox {
        margin-top: 0;
    }

    .detalle-select-card__meta {
        gap: 0.55rem;
        flex-direction: column;
    }

    .detalle-document-modifications,
    .detalle-modifications-modal__body,
    .detalle-modifications-modal__footer,
    .detalle-modifications-modal__header {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-modifications-modal__field-row,
    .detalle-modifications-modal__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .detalle-modifications-modal__remove {
        align-self: flex-end;
    }
}

.detalle-banner__instructions {
    background: linear-gradient(135deg, rgba(40, 176, 75, 0.08), rgba(40, 176, 75, 0.04));
    border: 1px solid #d1f0d4;
    border-radius: 0.75rem;
    padding: 1.25rem;
}

.detalle-banner__instructions-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detalle-banner__instructions-list li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    color: #1f7043;
    font-size: 0.95rem;
    line-height: 1.5;
}

.detalle-banner__instructions-list li::before {
    content: '•';
    flex-shrink: 0;
    font-weight: bold;
    color: #28b04b;
}
</style>
