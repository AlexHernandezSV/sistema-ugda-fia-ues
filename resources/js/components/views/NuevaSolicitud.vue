<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';
import { getCurrentUser } from '../../services/authSession';

const router = useRouter();
const route = useRoute();
const toast = useToast();

const OBSERVATION_MAX_LENGTH = 1000;

const headerForm = ref({
    code: '',
    request_date: new Date(),
    unit_id: null,
    user_name: '',
    description: '',
});

const batchForm = ref({
    series: [],
    year_start: null,
    year_end: null,
    quantity: '',
});

const boxesList = ref([]);
const editObservations = ref([]);
const correctionNotes = ref('');
const seriesOptions = ref([]);
const seriesCatalog = ref([]);
const unitsList = ref([]);
const supportOptions = ref(['Físico', 'Digital', 'Mixto']);
const isLoading = ref(false);
const isSubmitting = ref(false);
const isRefreshingCode = ref(false);

const isModalOpen = ref(false);
const isImportModalOpen = ref(false);
const isConfirmModalOpen = ref(false);
const shouldRestoreDocsModalAfterImport = ref(false);
const isPdfPreviewOpen = ref(false);
const shouldRestoreDocsModalAfterPreview = ref(false);
const pdfPreviewTitle = ref('');
const pdfPreviewSource = ref(null);
const currentBoxIndex = ref(null);
const currentBoxData = ref({});
const tempDocuments = ref([]);
const excelFile = ref(null);
const isImporting = ref(false);
let modalSwapTimer = null;
const docForm = ref({
    series: null,
    name: '',
    code: '',
    date: null,
    pages: '',
    support: null,
    file: null,
    file_url: null,
});

const totalBoxes = computed(() => boxesList.value.length);
const isEditMode = computed(() => route.name === 'solicitudes-transferencia-editar');
const editNumber = computed(() => route.params.number);
const pageTitle = computed(() => isEditMode.value ? 'Editar Solicitud de Transferencia' : 'Solicitud de Transferencia');
const submitLabel = computed(() => isEditMode.value ? 'Reenviar solicitud corregida' : 'Enviar solicitud');
const confirmTitle = computed(() => isEditMode.value ? 'Confirmar Reenvío de Solicitud' : 'Confirmar Envio de Solicitud');
const confirmSubtitle = computed(() => isEditMode.value
    ? 'Revise el resumen antes de reenviar la transferencia'
    : 'Revise el resumen antes de enviar la transferencia');
const confirmButtonLabel = computed(() => isEditMode.value ? 'Confirmar y Reenviar' : 'Confirmar y Enviar');
const selectedUnitLabel = computed(() => unitsList.value.find((unit) => unit.value === headerForm.value.unit_id)?.label ?? 'No indicada');
const totalDocuments = computed(() => boxesList.value.reduce((total, box) => total + Number(box.documents_count || 0), 0));
const totalSeries = computed(() => new Set(boxesList.value.flatMap((box) => getBoxSeriesLabels(box))).size);

const requiresDigitalFileInput = computed(() => docForm.value.support === 'Digital' || docForm.value.support === 'Mixto');

const formatBoxNumber = (boxNumber) => String(Number(boxNumber) || boxNumber || 0).padStart(3, '0');

const buildBoxCode = (box = {}) => box.box_code || box.boxCode || `C-${headerForm.value.code || 'PENDIENTE'}-${formatBoxNumber(box.box_number)}`;

const parseApiDate = (value) => {
    if (!value) {
        return new Date();
    }

    const date = new Date(`${value}T00:00:00`);

    return Number.isNaN(date.getTime()) ? new Date() : date;
};

const formatDateForApi = (value) => {
    if (!value) {
        return '';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
};

const refreshNextTransferCode = async () => {
    if (isEditMode.value) {
        return;
    }

    const requestDate = formatDateForApi(headerForm.value.request_date);

    if (!requestDate) {
        headerForm.value.code = '';
        return;
    }

    isRefreshingCode.value = true;

    try {
        const { data } = await axios.get('/api/transfers/next-code', {
            params: { request_date: requestDate },
        });

        headerForm.value.code = data.code ?? '';
    } catch (error) {
        console.error('Error generando numero de transferencia:', error);
        showError('No se pudo generar el numero de transferencia.');
    } finally {
        isRefreshingCode.value = false;
    }
};

const getYearString = (value) => {
    if (!value) {
        return '';
    }

    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return String(value.getFullYear());
    }

    const year = String(value).match(/\d{4}/)?.[0] ?? '';

    return year;
};

const splitSeriesLabel = (value) => String(value ?? '')
    .split(';')
    .map((series) => series.trim())
    .filter(Boolean);

const getBoxSeriesLabels = (box) => (
    Array.isArray(box.series_list) && box.series_list.length > 0
        ? box.series_list
        : splitSeriesLabel(box.series)
);

const normalizeSeriesLabel = (value) => String(value ?? '')
    .trim()
    .replace(/\s+/g, ' ')
    .toLowerCase();

const isAssignedToUnit = (item, unitId) => (
    Array.isArray(item?.unit_ids)
    && item.unit_ids.some((assignedUnitId) => Number(assignedUnitId) === Number(unitId))
);

const buildSeriesOptionsForUnit = (seriesData, unitId) => (Array.isArray(seriesData) ? seriesData : [])
    .filter((series) => series.is_active && isAssignedToUnit(series, unitId))
    .flatMap((series) => {
        const activeSubseries = Array.isArray(series.subseries)
            ? series.subseries.filter((subseries) => subseries.is_active && isAssignedToUnit(subseries, unitId))
            : [];

        if (activeSubseries.length > 0) {
            return activeSubseries.map((subseries) => ({
                id: `subseries-${subseries.id}`,
                label: `${series.code} - ${series.name} / ${subseries.code} - ${subseries.name}`,
            }));
        }

        return [{
            id: `series-${series.id}`,
            label: `${series.code} - ${series.name}`,
        }];
    });

const isPdfFileName = (fileName) => String(fileName ?? '').toLowerCase().endsWith('.pdf');

const currentBoxDocumentSeriesOptions = computed(() => {
    const boxLabels = new Set(getBoxSeriesLabels(currentBoxData.value).map(normalizeSeriesLabel));

    return seriesOptions.value.filter((option) => boxLabels.has(normalizeSeriesLabel(option.label)));
});

const resetDocumentForm = (defaultSeries = null) => {
    docForm.value = {
        series: defaultSeries,
        name: '',
        code: '',
        date: null,
        pages: '',
        support: null,
        file: null,
        file_url: null,
    };
};

const clearDocumentFileInput = () => {
    const fileInput = document.getElementById('transferDocumentFile');

    if (fileInput) {
        fileInput.value = '';
    }
};

const isPdfFile = (file) => (
    file
    && (
        file.type === 'application/pdf'
        || String(file.name ?? '').toLowerCase().endsWith('.pdf')
    )
);

const createPdfPreviewUrl = (file) => URL.createObjectURL(new Blob([file], { type: 'application/pdf' }));

const waitForModalSwap = () => new Promise((resolve) => {
    window.setTimeout(resolve, 180);
});

const revokeFilePreviewUrl = (url) => {
    if (url && String(url).startsWith('blob:')) {
        URL.revokeObjectURL(url);
    }
};

const previewDocumentFile = async (document) => {
    clearModalSwapTimer();

    shouldRestoreDocsModalAfterPreview.value = isModalOpen.value;

    if (isModalOpen.value) {
        isModalOpen.value = false;
        await waitForModalSwap();
    }

    pdfPreviewTitle.value = document?.file_name || document?.file?.name || 'Documento PDF';
    pdfPreviewSource.value = document?.file
        ? { file: document.file }
        : { url: document?.file_url };
    isPdfPreviewOpen.value = true;
};

const restoreDocsModalAfterPreview = () => {
    pdfPreviewSource.value = null;

    if (!shouldRestoreDocsModalAfterPreview.value) {
        return;
    }

    shouldRestoreDocsModalAfterPreview.value = false;
    modalSwapTimer = window.setTimeout(() => {
        isModalOpen.value = true;
        modalSwapTimer = null;
    }, 180);
};

const showError = (detail) => {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail,
        life: 4200,
    });
};

onMounted(async () => {
    isLoading.value = true;

    try {
        const [userRes, seriesRes, transferRes] = await Promise.all([
            getCurrentUser(),
            axios.get('/api/documentary-series'),
            isEditMode.value
                ? axios.get(`/api/transfers/${editNumber.value}/edit-data`)
                : axios.get('/api/transfers/next-code', {
                    params: { request_date: formatDateForApi(headerForm.value.request_date) },
                }),
        ]);

        headerForm.value.user_name = [
            userRes.first_name,
            userRes.second_name,
            userRes.first_last_name,
            userRes.second_last_name,
        ].filter(Boolean).join(' ');
        headerForm.value.unit_id = userRes.unit_id ?? userRes.unit?.id ?? null;
        unitsList.value = Array.isArray(userRes.units)
            ? userRes.units.map((unit) => ({ label: unit.name, value: unit.id }))
            : [];
        seriesCatalog.value = Array.isArray(seriesRes.data) ? seriesRes.data : [];

        if (isEditMode.value) {
            const editTransfer = transferRes.data.transfer ?? {};

            headerForm.value.code = editTransfer.code ?? '';
            headerForm.value.request_date = parseApiDate(editTransfer.request_date);
            headerForm.value.unit_id = editTransfer.unit_id ?? headerForm.value.unit_id;
            headerForm.value.user_name = editTransfer.responsible || headerForm.value.user_name;
            headerForm.value.description = editTransfer.description ?? '';
            boxesList.value = Array.isArray(transferRes.data.boxes)
                ? transferRes.data.boxes.map((box) => ({
                    ...box,
                    series_list: getBoxSeriesLabels(box),
                }))
                : [];
            editObservations.value = Array.isArray(transferRes.data.observations)
                ? transferRes.data.observations
                : [];
        } else {
            headerForm.value.code = transferRes.data.code ?? '';
        }

        seriesOptions.value = buildSeriesOptionsForUnit(seriesCatalog.value, headerForm.value.unit_id);
    } catch (error) {
        console.error('Error cargando datos de transferencia:', error);
        showError('No se pudo cargar la información base de la solicitud.');
        if (isEditMode.value) {
            router.push('/solicitudes');
        }
    } finally {
        isLoading.value = false;
    }
});

watch(
    () => headerForm.value.request_date,
    () => {
        if (!isLoading.value) {
            refreshNextTransferCode();
        }
    }
);

watch(
    () => headerForm.value.unit_id,
    (unitId) => {
        seriesOptions.value = buildSeriesOptionsForUnit(seriesCatalog.value, unitId);
        batchForm.value.series = [];
    }
);

const addBatch = () => {
    const selectedSeries = Array.isArray(batchForm.value.series)
        ? batchForm.value.series.filter(Boolean)
        : [];
    const startYear = getYearString(batchForm.value.year_start);
    const endYear = getYearString(batchForm.value.year_end);
    const quantity = Number(batchForm.value.quantity);

    if (selectedSeries.length === 0 || !startYear || !endYear || !quantity) {
        showError('Complete los datos del detalle de documentos.');
        return;
    }

    if (!/^\d{4}$/.test(startYear) || !/^\d{4}$/.test(endYear)) {
        showError('Las fechas inicial y final deben ser años de 4 dígitos.');
        return;
    }

    // VALIDACIÓN: Años no mayores al actual (NUEVA)
    const currentYear = new Date().getFullYear();
    if (parseInt(startYear, 10) > currentYear || parseInt(endYear, 10) > currentYear) {
        showError(`Las fechas inicial y final no pueden ser mayores a ${currentYear}.`);
        return;
    }

    if (Number(endYear) < Number(startYear)) {
        showError('La fecha final no puede ser menor que la fecha inicial.');
        return;
    }

    if (!Number.isInteger(quantity) || quantity < 1) {
        showError('La cantidad de cajas debe ser mayor a cero.');
        return;
    }

    const nextBoxNumber = boxesList.value.length > 0
        ? Math.max(...boxesList.value.map((box) => Number(box.box_number))) + 1
        : 1;

    const selectedSeriesLabel = selectedSeries.map((series) => series.label).join('; ');
    const selectedSeriesLabels = selectedSeries.map((series) => series.label);
    const newBoxes = Array.from({ length: quantity }, (_, index) => ({
        id_temp: `${Date.now()}-${index}`,
        series: selectedSeriesLabel,
        series_list: selectedSeriesLabels,
        year_start: startYear,
        year_end: endYear,
        box_number: nextBoxNumber + index,
        documents_count: 0,
        documents_list: [],
    }));

    boxesList.value.push(...newBoxes);

    batchForm.value = {
        series: [],
        year_start: null,
        year_end: null,
        quantity: '',
    };

    toast.add({
        severity: 'success',
        summary: 'Cajas agregadas',
        detail: `${newBoxes.length} caja(s) agregada(s) a la solicitud.`,
        life: 2600,
    });
};

const removeBox = (index) => {
    boxesList.value.splice(index, 1);
};

const openDocsModal = (index) => {
    currentBoxIndex.value = index;
    currentBoxData.value = boxesList.value[index];
    tempDocuments.value = JSON.parse(JSON.stringify(boxesList.value[index].documents_list || []));
    const firstSeries = getBoxSeriesLabels(currentBoxData.value)[0] ?? currentBoxData.value.series;
    const defaultSeries = seriesOptions.value.find((option) => option.label === firstSeries) ?? null;
    resetDocumentForm(defaultSeries);
    isModalOpen.value = true;
};

const onFileSelect = (event) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    if (!isPdfFile(file)) {
        showError('Seleccione un archivo PDF.');
        event.target.value = '';
        return;
    }

    revokeFilePreviewUrl(docForm.value.file_url);
    docForm.value.file = file;
    docForm.value.file_url = createPdfPreviewUrl(file);
};

const onExcelFileSelect = (event) => {
    excelFile.value = event.target.files?.[0] ?? null;
};

const resetExcelFileInput = () => {
    const fileInput = document.getElementById('excelImportFile');
    if (fileInput) {
        fileInput.value = '';
    }
};

const clearModalSwapTimer = () => {
    if (modalSwapTimer) {
        window.clearTimeout(modalSwapTimer);
        modalSwapTimer = null;
    }
};

const openImportModal = () => {
    clearModalSwapTimer();
    shouldRestoreDocsModalAfterImport.value = isModalOpen.value;
    excelFile.value = null;
    resetExcelFileInput();

    if (isModalOpen.value) {
        isModalOpen.value = false;
        modalSwapTimer = window.setTimeout(() => {
            isImportModalOpen.value = true;
            modalSwapTimer = null;
        }, 180);
        return;
    }

    isImportModalOpen.value = true;
};

const closeImportModal = () => {
    excelFile.value = null;
    resetExcelFileInput();
    isImportModalOpen.value = false;
};

const restoreDocsModalAfterImport = () => {
    excelFile.value = null;
    resetExcelFileInput();

    if (!shouldRestoreDocsModalAfterImport.value) {
        return;
    }

    clearModalSwapTimer();
    shouldRestoreDocsModalAfterImport.value = false;
    modalSwapTimer = window.setTimeout(() => {
        isModalOpen.value = true;
        modalSwapTimer = null;
    }, 180);
};

const needsDigitalFile = (support) => support === 'Digital' || support === 'Mixto';

const downloadExcelTemplate = () => {
    window.location.href = '/api/transfers/document-import-template';
};

const normalizeDocumentValue = (value) => String(value ?? '')
    .trim()
    .replace(/\s+/g, ' ')
    .toLowerCase();

const getDocumentFingerprint = (document) => [
    document.series,
    document.name,
    document.code,
    document.support,
    document.date,
    document.pages,
].map(normalizeDocumentValue).join('|');

const getUniqueDocuments = (documents) => {
    const seen = new Set();

    return documents.filter((document) => {
        const fingerprint = getDocumentFingerprint(document);

        if (seen.has(fingerprint)) {
            return false;
        }

        seen.add(fingerprint);
        return true;
    });
};

const validateDocumentForBox = (document, box) => {
    const documentName = String(document?.name ?? 'documento').trim() || 'documento';
    const documentSeries = String(document?.series ?? '').trim();
    const normalizedDocumentSeries = normalizeSeriesLabel(documentSeries);
    const unitSeriesLabels = new Set(seriesOptions.value.map((option) => normalizeSeriesLabel(option.label)));
    const boxSeriesLabels = new Set(getBoxSeriesLabels(box).map(normalizeSeriesLabel));

    if (!normalizedDocumentSeries || !unitSeriesLabels.has(normalizedDocumentSeries)) {
        return `La serie del documento "${documentName}" no existe o no esta habilitada para la unidad productora.`;
    }

    if (!boxSeriesLabels.has(normalizedDocumentSeries)) {
        return `El documento "${documentName}" pertenece a una serie o subserie que no forma parte de la caja.`;
    }

    const documentYear = getYearString(document?.date);
    const startYear = Number(getYearString(box.year_start));
    const endYear = Number(getYearString(box.year_end));

    if (documentYear === '') {
        return `Debe indicar el anio del documento "${documentName}".`;
    }

    const yearNumber = Number(documentYear);

    if (!/^\d{4}$/.test(documentYear) || yearNumber < startYear || yearNumber > endYear) {
        return `El anio del documento "${documentName}" debe estar dentro del rango de la caja (${startYear} - ${endYear}).`;
    }

    if (document?.file && !isPdfFile(document.file)) {
        return `El archivo digital del documento "${documentName}" debe estar en formato PDF.`;
    }

    if (document?.file_name && !isPdfFileName(document.file_name)) {
        return `El archivo digital del documento "${documentName}" debe estar en formato PDF.`;
    }

    return '';
};

const validateDocumentForCurrentBox = (document) => validateDocumentForBox(document, currentBoxData.value);

const validateTempDocumentsForCurrentBox = (documents) => {
    for (const document of documents) {
        const message = validateDocumentForCurrentBox(document);

        if (message) {
            return message;
        }
    }

    return '';
};

const validateAllBoxesDocuments = () => {
    for (const box of boxesList.value) {
        for (const document of box.documents_list ?? []) {
            const message = validateDocumentForBox(document, box);

            if (message) {
                return message;
            }
        }
    }

    return '';
};

const hasDocumentFormInput = () => Boolean(
    String(docForm.value.name ?? '').trim()
    || String(docForm.value.code ?? '').trim()
    || getYearString(docForm.value.date)
    || String(docForm.value.pages ?? '').trim()
    || docForm.value.support
    || docForm.value.file
    || docForm.value.file_url
);

const hasRequiredDocumentFormFields = () => Boolean(
    (docForm.value.series?.label ?? currentBoxData.value.series ?? '')
    && String(docForm.value.name ?? '').trim()
    && String(docForm.value.code ?? '').trim()
    && getYearString(docForm.value.date)
    && docForm.value.support
);

const importDocumentsFromExcel = async () => {
    if (!excelFile.value) {
        showError('Seleccione un archivo Excel para importar.');
        return;
    }

    isImporting.value = true;

    try {
        const formData = new FormData();
        formData.append('file', excelFile.value);
        formData.append('current_box_series', getBoxSeriesLabels(currentBoxData.value).join('; '));
        formData.append('unit_id', headerForm.value.unit_id ?? '');
        formData.append('box_year_start', getYearString(currentBoxData.value.year_start));
        formData.append('box_year_end', getYearString(currentBoxData.value.year_end));

        const { data } = await axios.post('/api/transfers/import-documents', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const importedDocuments = Array.isArray(data.documents) ? data.documents : [];
        const importValidationMessage = validateTempDocumentsForCurrentBox(importedDocuments);

        if (importedDocuments.length === 0) {
            showError('El archivo no contiene filas con documentos.');
            return;
        }

        if (importValidationMessage) {
            showError(importValidationMessage);
            return;
        }

        const existingFingerprints = new Set(tempDocuments.value.map(getDocumentFingerprint));
        const importedFingerprints = new Set();
        const documentsToAdd = importedDocuments.filter((document) => {
            const fingerprint = getDocumentFingerprint(document);

            if (existingFingerprints.has(fingerprint) || importedFingerprints.has(fingerprint)) {
                return false;
            }

            importedFingerprints.add(fingerprint);
            return true;
        });
        const skippedDocuments = importedDocuments.length - documentsToAdd.length;

        if (documentsToAdd.length === 0) {
            showError('Los documentos de esta plantilla ya fueron cargados en la caja.');
            closeImportModal();
            return;
        }

        tempDocuments.value.push(...documentsToAdd.map((document, index) => ({
            id_doc: `${Date.now()}-${index}`,
            ...document,
            file_name: null,
            file_url: null,
        })));
        closeImportModal();

        toast.add({
            severity: 'success',
            summary: 'Documentos importados',
            detail: skippedDocuments > 0
                ? `${documentsToAdd.length} documento(s) agregado(s). ${skippedDocuments} duplicado(s) omitido(s).`
                : `${documentsToAdd.length} documento(s) agregado(s) al inventario de la caja.`,
            life: 3200,
        });
    } catch (error) {
        console.error('Error importando documentos:', error);
        showError(error.response?.data?.message || error.message || 'No se pudo procesar el archivo Excel.');
    } finally {
        isImporting.value = false;
    }
};

const addDocumentToTemp = () => {
    const documentSeries = docForm.value.series?.label ?? currentBoxData.value.series ?? '';
    const documentName = String(docForm.value.name ?? '').trim();
    const documentCode = String(docForm.value.code ?? '').trim();
    const documentDate = getYearString(docForm.value.date);
    const documentPages = String(docForm.value.pages ?? '').trim();

    if (!documentSeries || !documentName || !documentCode || !documentDate || !docForm.value.support) {
        showError('Complete los datos obligatorios del documento.');
        return;
    }

    const documentDraft = {
        series: documentSeries,
        name: documentName,
        code: documentCode,
        date: documentDate,
        pages: documentPages,
        support: docForm.value.support,
        file: docForm.value.file,
        file_name: docForm.value.file ? docForm.value.file.name : null,
    };

    const documentValidationMessage = validateDocumentForCurrentBox(documentDraft);

    if (documentValidationMessage) {
        showError(documentValidationMessage);
        return;
    }

    // NUEVA VALIDACIÓN: Año no mayor al actual (solo si se ingresó)
    if (documentDate !== '') {
        const currentYear = new Date().getFullYear();
        if (parseInt(documentDate, 10) > currentYear) {
            showError(`El año del documento no puede ser mayor a ${currentYear}.`);
            return;
        }
    }

    // NUEVA VALIDACIÓN: Páginas sea número positivo (solo si se ingresó)
    if (documentPages !== '') {
        const pagesNumber = Number(documentPages);
        if (isNaN(pagesNumber) || pagesNumber < 1 || !Number.isInteger(pagesNumber)) {
            showError('La cantidad de páginas debe ser un número entero positivo.');
            return;
        }
    }

    tempDocuments.value.push({
        id_doc: Date.now(),
        ...documentDraft,
        file: docForm.value.file,
        file_name: docForm.value.file ? docForm.value.file.name : null,
        file_url: docForm.value.file_url,
    });

    const defaultSeries = docForm.value.series;
    resetDocumentForm(defaultSeries);
    clearDocumentFileInput();
};

const removeTempDoc = (index) => {
    revokeFilePreviewUrl(tempDocuments.value[index]?.file_url);
    tempDocuments.value.splice(index, 1);
};

const onTempDocFileSelect = (index, event) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    if (!isPdfFile(file)) {
        showError('Seleccione un archivo PDF.');
        event.target.value = '';
        return;
    }

    revokeFilePreviewUrl(tempDocuments.value[index].file_url);
    tempDocuments.value[index].file = file;
    tempDocuments.value[index].file_name = file.name;
    tempDocuments.value[index].file_url = createPdfPreviewUrl(file);
};

const removeTempDocFile = (index) => {
    if (!isEditMode.value || !tempDocuments.value[index]) {
        return;
    }

    const currentDocument = tempDocuments.value[index];
    revokeFilePreviewUrl(currentDocument.file_url);

    tempDocuments.value.splice(index, 1, {
        ...currentDocument,
        file: null,
        file_name: null,
        file_url: null,
        file_removed: true,
    });

    const fileInput = document.getElementById(`transferTempDocumentFile-${index}`);
    if (fileInput) {
        fileInput.value = '';
    }
};

const getSupportClass = (support) => {
    const supportKey = {
        Físico: 'fisico',
        Digital: 'digital',
        Mixto: 'mixto',
    }[support] ?? 'fisico';

    return `transfer-docs__support-chip--${supportKey}`;
};

const saveBoxChanges = () => {
    if (currentBoxIndex.value === null) {
        return;
    }

    if (hasDocumentFormInput()) {
        if (!hasRequiredDocumentFormFields()) {
            showError('Complete los campos obligatorios del documento antes de guardar.');
            return;
        }

        showError('Agregue el documento a la lista antes de guardar.');
        return;
    }

    if (tempDocuments.value.length === 0) {
        showError('Complete los campos obligatorios del documento antes de guardar.');
        return;
    }

    const validationMessage = validateTempDocumentsForCurrentBox(tempDocuments.value);

    if (validationMessage) {
        showError(validationMessage);
        return;
    }

    const uniqueDocuments = getUniqueDocuments(tempDocuments.value);
    const skippedDocuments = tempDocuments.value.length - uniqueDocuments.length;

    boxesList.value[currentBoxIndex.value].documents_list = uniqueDocuments;
    boxesList.value[currentBoxIndex.value].documents_count = uniqueDocuments.length;
    isModalOpen.value = false;

    if (skippedDocuments > 0) {
        toast.add({
            severity: 'warn',
            summary: 'Documentos duplicados omitidos',
            detail: `${skippedDocuments} documento(s) duplicado(s) no se guardaron en la caja.`,
            life: 3600,
        });
    }
};

const cancelDocsModal = () => {
    revokeFilePreviewUrl(docForm.value.file_url);
    tempDocuments.value = [];
    currentBoxIndex.value = null;
    currentBoxData.value = {};
    resetDocumentForm();
    clearDocumentFileInput();
    isModalOpen.value = false;
};

const validateTransferDraft = () => {
    if (!headerForm.value.code || !formatDateForApi(headerForm.value.request_date) || !headerForm.value.unit_id) {
        showError('Complete los datos generales de la solicitud.');
        return false;
    }

    if (boxesList.value.length === 0) {
        showError('Debe agregar al menos una caja a la solicitud.');
        return false;
    }

    if (isEditMode.value && String(correctionNotes.value ?? '').trim() === '') {
        showError('Debe indicar como fueron atendidas las observaciones de revision fisica.');
        return false;
    }

    if (String(correctionNotes.value ?? '').trim().length > OBSERVATION_MAX_LENGTH) {
        showError(`Las aclaraciones no pueden superar ${OBSERVATION_MAX_LENGTH} caracteres.`);
        return false;
    }

    const emptyBoxes = boxesList.value.filter((box) => box.documents_count === 0);
    if (emptyBoxes.length > 0) {
        showError('Cada caja debe tener al menos un documento en su inventario.');
        return false;
    }

    const documentValidationMessage = validateAllBoxesDocuments();

    if (documentValidationMessage) {
        showError(documentValidationMessage);
        return false;
    }

    return true;
};

const openSubmitConfirmation = () => {
    if (!validateTransferDraft()) {
        return;
    }

    isConfirmModalOpen.value = true;
};

const submitTransfer = async () => {
    if (!validateTransferDraft()) {
        return;
    }

    isSubmitting.value = true;

    try {
        const payload = {
            code: headerForm.value.code,
            request_date: formatDateForApi(headerForm.value.request_date),
            unit_id: headerForm.value.unit_id,
            description: String(headerForm.value.description ?? '').trim() || null,
            boxes: boxesList.value,
            correction_notes: isEditMode.value ? String(correctionNotes.value ?? '').trim() : null,
        };

        const { data } = isEditMode.value
            ? await axios.put(`/api/transfers/${editNumber.value}/resubmit-correction`, payload)
            : await axios.post('/api/transfers', payload);

        toast.add({
            severity: 'success',
            summary: isEditMode.value ? 'Solicitud corregida exitosamente' : 'Solicitud creada exitosamente',
            detail: isEditMode.value
                ? `La solicitud #${data.number} ha sido corregida y reenviada a UGDA para revision.`
                : `La solicitud #${data.number} ha sido creada y enviada para autorización.`,
            life: 3200,
        });

        isConfirmModalOpen.value = false;

        router.push({
            path: `/solicitudes/transferencias/${data.number}`,
            query: { tab: 'transferencias' },
        });
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo registrar la solicitud de transferencia.');
    } finally {
        isSubmitting.value = false;
    }
};

const goBack = () => {
    router.go(-1);
};
</script>

<template>
    <section class="transfer-request">
        <header class="transfer-request__header">
            <Button icon="pi pi-arrow-left" text rounded class="transfer-request__back" aria-label="Volver" @click="goBack" />
            <h1>{{ pageTitle }}</h1>
        </header>

        <section v-if="isEditMode && editObservations.length" class="transfer-request__observations">
            <div class="transfer-request__observations-head">
                <div class="transfer-request__observations-icon">
                    <i class="pi pi-eye"></i>
                </div>
                <div>
                    <h2>Observaciones de la Solicitud</h2>
                    <p>Revise las observaciones registradas antes de guardar las correcciones.</p>
                </div>
            </div>

            <article v-for="item in editObservations" :key="`${item.actor}-${item.dateTime}`" class="transfer-request__observation-card">
                <div class="transfer-request__observation-meta">
                    <i class="pi pi-user"></i>
                    <strong>{{ item.actor }}</strong>
                    <span>{{ item.dateTime }}</span>
                </div>
                <p>{{ item.message }}</p>
            </article>

            <div class="transfer-request__correction-notes">
                <label for="correctionNotes">Aclaraciones a la UGDA <span>*</span></label>
                <p>Indique como fueron atendidas las observaciones de revisión física. Este texto será visible para UGDA al validar las correcciones.</p>
                <textarea
                    id="correctionNotes"
                    v-model="correctionNotes"
                    rows="5"
                    :maxlength="OBSERVATION_MAX_LENGTH"
                    placeholder="Describa las correcciones realizadas y aclaraciones pertinentes para UGDA..."
                ></textarea>
            </div>
        </section>

        <div class="transfer-request__card">
            <div class="transfer-request__section-title">Datos Generales</div>

            <div class="transfer-request__section-body">
                <div class="transfer-request__grid">
                    <div class="transfer-request__field">
                        <label for="transferCode">N° de transferencia documental <span>*</span></label>
                        <InputText id="transferCode" v-model="headerForm.code" :disabled="isLoading || isRefreshingCode" readonly />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferDate">Fecha de solicitud <span>*</span></label>
                        <DatePicker
                            id="transferDate"
                            v-model="headerForm.request_date"
                            :disabled="isLoading"
                            dateFormat="dd/mm/yy"
                            showIcon
                            iconDisplay="input"
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferUnit">Unidad productora <span>*</span></label>
                        <Dropdown
                            id="transferUnit"
                            v-model="headerForm.unit_id"
                            :options="unitsList"
                            :disabled="isLoading"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Seleccionar unidad"
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferResponsible">Responsable del envío <span>*</span></label>
                        <InputText
                            id="transferResponsible"
                            v-model="headerForm.user_name"
                            :disabled="isLoading"
                            placeholder="Nombre completo"
                            readonly
                        />
                    </div>
                </div>

                <div class="transfer-request__field transfer-request__description">
                    <label for="transferDescription">Descripción (opcional)</label>
                    <textarea
                        id="transferDescription"
                        v-model="headerForm.description"
                        :disabled="isLoading"
                        :maxlength="OBSERVATION_MAX_LENGTH"
                        rows="4"
                        placeholder="Ingrese una descripción breve de la documentación a transferir"
                    ></textarea>
                </div>

                <div class="transfer-request__section-title transfer-request__section-title--detached">Detalle de Documentos</div>

                <div class="transfer-request__detail-grid">
                    <div class="transfer-request__field">
                        <label for="transferSeries">Series / Subseries <span>*</span></label>
                        <MultiSelect
                            id="transferSeries"
                            v-model="batchForm.series"
                            :options="seriesOptions"
                            :disabled="isLoading"
                            optionLabel="label"
                            placeholder="Seleccionar series o subseries"
                            display="chip"
                            filter
                            :maxSelectedLabels="4"
                            selectedItemsLabel="{0} series seleccionadas"
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferStartYear">Fecha inicial <span>*</span></label>
                        <DatePicker
                            inputId="transferStartYear"
                            v-model="batchForm.year_start"
                            :disabled="isLoading"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            placeholder="Año (Ej: 2020)"
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferEndYear">Fecha final <span>*</span></label>
                        <DatePicker
                            inputId="transferEndYear"
                            v-model="batchForm.year_end"
                            :disabled="isLoading"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            placeholder="Año (Ej: 2024)"
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="transferQuantity">Cantidad de cajas <span>*</span></label>
                        <InputText
                            id="transferQuantity"
                            v-model="batchForm.quantity"
                            :disabled="isLoading"
                            type="number"
                            min="1"
                            placeholder="Ej: 2"
                        />
                    </div>

                    <Button
                        label="Agregar"
                        icon="pi pi-plus"
                        class="transfer-request__add"
                        :disabled="isLoading"
                        @click="addBatch"
                    />
                </div>

                <div v-if="boxesList.length === 0" class="transfer-request__empty">
                    No hay cajas agregadas. Complete el formulario y haga clic en "Agregar".
                </div>

                <DataTable v-else :value="boxesList" class="transfer-request__table" size="small" stripedRows>
                    <Column header="N°" style="width: 4rem">
                        <template #body="{ index }">{{ index + 1 }}</template>
                    </Column>
                    <Column header="Serie / Subserie">
                        <template #body="{ data }">
                            <div class="transfer-request__series-chips">
                                <span
                                    v-for="series in getBoxSeriesLabels(data)"
                                    :key="series"
                                    class="transfer-request__series-chip"
                                >
                                    {{ series }}
                                </span>
                            </div>
                        </template>
                    </Column>
                    <Column field="year_start" header="Fecha inicial" style="width: 9rem"></Column>
                    <Column field="year_end" header="Fecha final" style="width: 9rem"></Column>
                    <Column header="Codigo de caja" style="width: 12rem">
                        <template #body="{ data }">{{ buildBoxCode(data) }}</template>
                    </Column>
                    <Column header="Documentos" style="width: 10rem">
                        <template #body="{ data }">
                            <span class="transfer-request__docs-chip">{{ data.documents_count }} doc(s)</span>
                        </template>
                    </Column>
                    <Column header="Acciones" style="width: 9rem">
                        <template #body="{ index }">
                            <div class="transfer-request__row-actions">
                            <Button
                                icon="pi pi-cog"
                                text
                                rounded
                                size="small"
                                aria-label="Administrar documentos"
                                @click="openDocsModal(index)"
                            />
                            <Button icon="pi pi-trash" text rounded severity="danger" size="small" aria-label="Eliminar caja" @click="removeBox(index)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>

                <div class="transfer-request__actions">
                    <Button
                        :label="submitLabel"
                        class="transfer-request__submit"
                        :loading="isSubmitting"
                        :disabled="isLoading"
                        @click="openSubmitConfirmation"
                    />
                    <Button label="Cancelar" outlined class="transfer-request__cancel" :disabled="isSubmitting" @click="goBack" />
                </div>
            </div>
        </div>

        <Dialog
            v-model:visible="isConfirmModalOpen"
            modal
            :draggable="false"
            class="transfer-confirm-dialog"
            :style="{ width: 'min(768px, 94vw)' }"
        >
            <template #header>
                <div class="transfer-confirm__header">
                    <h2>{{ confirmTitle }}</h2>
                    <p>{{ confirmSubtitle }}</p>
                </div>
            </template>

            <div class="transfer-confirm">
                <section class="transfer-confirm__notice">
                    <i class="pi pi-info-circle"></i>
                    <span>
                        {{ isEditMode ? 'Al confirmar, esta solicitud corregida será reenviada a UGDA para su revisión.' : 'Al confirmar, esta solicitud sera enviada al Director/Jefe de la unidad para su autorización.' }}
                    </span>
                </section>

                <section v-if="isEditMode" class="transfer-confirm__correction">
                    <strong>Aclaraciones a la UGDA:</strong>
                    <p>{{ correctionNotes }}</p>
                </section>

                <section class="transfer-confirm__card">
                    <h3>Datos Generales</h3>
                    <div class="transfer-confirm__divider"></div>
                    <div class="transfer-confirm__general-grid">
                        <div>
                            <span>Unidad Productora</span>
                            <strong>{{ selectedUnitLabel }}</strong>
                        </div>
                        <div>
                            <span>Responsable</span>
                            <strong>{{ headerForm.user_name || 'No indicado' }}</strong>
                        </div>
                        <div>
                            <span>Fecha de solicitud</span>
                            <strong>{{ formatDateForApi(headerForm.request_date) }}</strong>
                        </div>
                        <div>
                            <span>N° de transferencia</span>
                            <strong>{{ headerForm.code }}</strong>
                        </div>
                    </div>
                </section>

                <section class="transfer-confirm__card">
                    <h3>Resumen de Transferencia</h3>
                    <div class="transfer-confirm__divider"></div>

                    <div class="transfer-confirm__metrics">
                        <div>
                            <span>Total de Cajas</span>
                            <strong>{{ totalBoxes }}</strong>
                        </div>
                        <div>
                            <span>Total de Documentos</span>
                            <strong>{{ totalDocuments }}</strong>
                        </div>
                        <div>
                            <span>Series Documentales</span>
                            <strong>{{ totalSeries }}</strong>
                        </div>
                    </div>

                    <div class="transfer-confirm__boxes">
                        <article v-for="box in boxesList" :key="box.id_temp" class="transfer-confirm__box">
                            <div class="transfer-confirm__box-main">
                                <span class="transfer-confirm__box-badge">{{ buildBoxCode(box) }}</span>
                                <div class="transfer-request__series-chips transfer-confirm__box-series-chips">
                                    <span
                                        v-for="series in getBoxSeriesLabels(box)"
                                        :key="`confirm-${box.id_temp}-${series}`"
                                        class="transfer-request__series-chip"
                                    >
                                        {{ series }}
                                    </span>
                                </div>
                                <p>Periodo: <strong>{{ box.year_start }} - {{ box.year_end }}</strong></p>
                            </div>
                            <strong class="transfer-confirm__box-docs">{{ box.documents_count }} documento(s)</strong>
                        </article>
                    </div>
                </section>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    outlined
                    class="transfer-docs__cancel"
                    :disabled="isSubmitting"
                    @click="isConfirmModalOpen = false"
                />
                <Button
                    :label="confirmButtonLabel"
                    class="transfer-confirm__save"
                    :loading="isSubmitting"
                    @click="submitTransfer"
                />
            </template>
        </Dialog>

        <Dialog v-model:visible="isModalOpen" modal :draggable="false" class="transfer-docs-dialog" :style="{ width: 'min(1024px, 94vw)' }">
            <template #header>
                <div class="transfer-docs__header">
                    <h2>Documentos de la Caja {{ buildBoxCode(currentBoxData) }}</h2>
                    <p>{{ currentBoxData.series }} ({{ currentBoxData.year_start }} - {{ currentBoxData.year_end }})</p>
                </div>
            </template>

            <div class="transfer-docs">
                <div class="transfer-docs__form">
                    <div class="transfer-docs__form-title">
                        <h3>Agregar Documento</h3>
                        <Button
                            label="Importar desde Excel"
                            icon="pi pi-file-excel"
                            class="transfer-docs__excel-button"
                            @click="openImportModal"
                        />
                    </div>

                    <div class="transfer-request__field transfer-docs__series">
                        <label for="documentSeries">Serie del documento <span>*</span></label>
                        <Dropdown
                            id="documentSeries"
                            v-model="docForm.series"
                            :options="currentBoxDocumentSeriesOptions"
                            optionLabel="label"
                            placeholder="Seleccionar"
                            filter
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="documentName">Nombre del documento <span>*</span></label>
                        <InputText id="documentName" v-model="docForm.name" placeholder="Ej: Acta de reunión" />
                    </div>

                    <div class="transfer-request__field">
                        <label for="documentCode">Código <span>*</span></label>
                        <InputText id="documentCode" v-model="docForm.code" placeholder="Ej: ACT-2024-001" />
                    </div>

                    <div class="transfer-request__field">
                        <label for="documentSupport">Soporte <span>*</span></label>
                        <Dropdown id="documentSupport" v-model="docForm.support" :options="supportOptions" placeholder="Seleccionar" fluid />
                    </div>

                    <div class="transfer-request__field">
                        <label for="documentDate">Año <span>*</span></label>
                        <DatePicker
                            inputId="documentDate"
                            v-model="docForm.date"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            placeholder="Ej: 2025"
                            required
                            fluid
                        />
                    </div>

                    <div class="transfer-request__field">
                        <label for="documentPages">Cantidad de páginas</label>
                        <InputText id="documentPages" v-model="docForm.pages" type="number" min="1" placeholder="Ej: 10" />
                    </div>

                    <div v-if="requiresDigitalFileInput" class="transfer-request__field transfer-docs__file">
                        <label for="transferDocumentFile">Archivo digital (opcional)</label>
                        <label
                            class="transfer-docs__file-trigger"
                            :class="{ 'transfer-docs__file-trigger--selected': docForm.file }"
                            for="transferDocumentFile"
                        >
                            <i class="pi pi-upload"></i>
                            <span v-if="docForm.file">Archivo seleccionado: {{ docForm.file.name }}</span>
                            <span v-else>Seleccionar archivo...</span>
                        </label>
                        <input id="transferDocumentFile" type="file" accept=".pdf" @change="onFileSelect" />
                        <a
                            v-if="docForm.file_url"
                            href="#"
                            class="transfer-docs__file-link"
                            @click.prevent="previewDocumentFile(docForm)"
                        >
                            {{ docForm.file.name }}
                        </a>
                        <small>Formatos permitidos: PDF</small>
                    </div>

                    <Button label="Agregar documento" icon="pi pi-plus" class="transfer-docs__add" @click="addDocumentToTemp" />
                </div>

                <h3 class="transfer-docs__list-title">Documentos agregados ({{ tempDocuments.length }})</h3>

                <div v-if="tempDocuments.length === 0" class="transfer-docs__empty">
                    No hay documentos agregados. Agregue documentos usando el formulario anterior.
                </div>

                <div v-else class="transfer-docs__list">
                    <article v-for="(document, index) in tempDocuments" :key="document.id_doc" class="transfer-docs__item">
                        <div class="transfer-docs__item-grid">
                            <div>
                                <span>Serie del documento</span>
                                <strong>{{ document.series }}</strong>
                            </div>
                            <div>
                                <span>Nombre</span>
                                <strong>{{ document.name }}</strong>
                            </div>
                            <div>
                                <span>Código</span>
                                <strong>{{ document.code }}</strong>
                            </div>
                            <div>
                                <span>Soporte</span>
                                <strong
                                    class="transfer-docs__support-chip"
                                    :class="getSupportClass(document.support)"
                                >
                                    {{ document.support }}
                                </strong>
                            </div>
                            <div>
                                <span>Año</span>
                                <strong>{{ document.date || 'No indicado' }}</strong>
                            </div>
                            <div>
                                <span>Cantidad de páginas</span>
                                <strong>{{ document.pages ? `${document.pages} págs` : 'No indicada' }}</strong>
                            </div>
                            <div v-if="needsDigitalFile(document.support)" class="transfer-docs__digital-link">
                                <span>Archivo digital</span>
                                <div v-if="document.file_url" class="transfer-docs__digital-file-row">
                                    <a href="#" @click.prevent="previewDocumentFile(document)">{{ document.file_name }}</a>
                                    <button
                                        v-if="isEditMode"
                                        type="button"
                                        class="transfer-docs__remove-file"
                                        aria-label="Quitar archivo digital"
                                        title="Quitar archivo digital"
                                        @click.prevent.stop="removeTempDocFile(index)"
                                    >
                                        <i class="pi pi-times"></i>
                                    </button>
                                </div>
                                <label
                                    v-else
                                    class="transfer-docs__inline-file-trigger"
                                    :for="`transferTempDocumentFile-${index}`"
                                >
                                    <i class="pi pi-upload"></i>
                                    Seleccionar archivo digital
                                </label>
                                <input
                                    :id="`transferTempDocumentFile-${index}`"
                                    class="transfer-docs__inline-file-input"
                                    type="file"
                                    accept=".pdf"
                                    @change="onTempDocFileSelect(index, $event)"
                                />
                                <small v-if="!document.file_url">Formatos permitidos: PDF</small>
                            </div>
                        </div>
                        <div class="transfer-docs__item-actions">
                            <Button icon="pi pi-trash" text rounded severity="danger" aria-label="Quitar documento" @click="removeTempDoc(index)" />
                        </div>
                    </article>
                </div>
            </div>

            <template #footer>
                <Button label="Cancelar" outlined class="transfer-docs__cancel" @click="cancelDocsModal" />
                <Button label="Guardar documentos" class="transfer-docs__save" @click="saveBoxChanges" />
            </template>
        </Dialog>

        <DocumentPreviewDialog
            v-model:visible="isPdfPreviewOpen"
            :title="pdfPreviewTitle"
            :source="pdfPreviewSource"
            @hide="restoreDocsModalAfterPreview"
        />

        <Dialog
            v-model:visible="isImportModalOpen"
            modal
            :draggable="false"
            class="transfer-import-dialog"
            :style="{ width: 'min(896px, 94vw)' }"
            @hide="restoreDocsModalAfterImport"
        >
            <template #header>
                <div class="transfer-docs__header">
                    <h2>Importación Masiva de Documentos</h2>
                    <p>Siga las instrucciones para importar documentos desde Excel</p>
                </div>
            </template>

            <div class="transfer-import">
                <section class="transfer-import__instructions">
                    <i class="pi pi-info-circle"></i>
                    <div>
                        <strong>Instrucciones importantes:</strong>
                        <ul>
                            <li>Descargue la plantilla de Excel y complete los datos solicitados</li>
                            <li>No modifique las cabeceras de las columnas</li>
                            <li>Los valores del campo "Serie del documento" deben coincidir con las series disponibles en el sistema</li>
                            <li>Los valores del campo "Soporte" deben ser exactamente: Físico, Digital o Mixto</li>
                            <li>Los archivos digitales no se cargan desde Excel; se agregan después desde el formulario del documento</li>
                        </ul>
                    </div>
                </section>

                <Button
                    label="Descargar Plantilla de Excel"
                    icon="pi pi-download"
                    class="transfer-import__download"
                    @click="downloadExcelTemplate"
                />
                <p class="transfer-import__hint">Complete la plantilla con los datos de sus documentos</p>

                <section class="transfer-import__dropzone">
                    <i class="pi pi-file"></i>
                    <strong>Cargar archivo Excel</strong>
                    <span>Seleccione el archivo Excel con los documentos a importar</span>
                    <label class="transfer-import__select" for="excelImportFile">
                        <i class="pi pi-upload"></i>
                        Seleccionar archivo Excel
                    </label>
                    <input id="excelImportFile" type="file" accept=".xlsx" @change="onExcelFileSelect" />
                    <a v-if="excelFile" class="transfer-import__file-name" href="#" @click.prevent>{{ excelFile.name }}</a>
                    <small>Formato permitido: .xlsx</small>
                </section>
            </div>

            <template #footer>
                <Button label="Cancelar" outlined class="transfer-docs__cancel" @click="closeImportModal" />
                <Button
                    label="Importar"
                    icon="pi pi-check"
                    class="transfer-docs__save"
                    :loading="isImporting"
                    :disabled="!excelFile"
                    @click="importDocumentsFromExcel"
                />
            </template>
        </Dialog>
    </section>
</template>

<style scoped>
.transfer-request {
    color: #1f2937;
}

.transfer-request__header {
    align-items: center;
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.transfer-request__header h1 {
    color: #2d6f9f;
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.15;
    margin: 0;
}

.transfer-request__back {
    color: #2d6f9f;
}

.transfer-request__back:hover,
.transfer-request__back:focus,
.transfer-request__back:focus-visible {
    background: transparent !important;
    border-color: transparent !important;
    box-shadow: none !important;
    color: #2d6f9f !important;
}

.transfer-request__observations {
    background: #fff3c4;
    border-left: 4px solid #f6ad3c;
    border-radius: 8px;
    color: #7a4b00;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1.35rem 1.5rem;
}

.transfer-request__observations-head {
    align-items: flex-start;
    display: flex;
    gap: 1rem;
}

.transfer-request__observations-icon {
    align-items: center;
    background: #f6ad3c;
    border-radius: 0.75rem;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    height: 2.4rem;
    justify-content: center;
    width: 2.4rem;
}

.transfer-request__observations h2,
.transfer-request__observations p {
    margin: 0;
}

.transfer-request__observations h2 {
    color: #7a4b00;
    font-size: 1.1rem;
    font-weight: 800;
}

.transfer-request__observations-head p {
    margin-top: 0.45rem;
}

.transfer-request__observation-card {
    background: #ffffff;
    border: 1px solid #f3d27a;
    border-radius: 8px;
    padding: 1rem;
}

.transfer-request__observation-meta {
    align-items: center;
    color: #9a5f00;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-bottom: 0.75rem;
}

.transfer-request__correction-notes {
    background: #ffffff;
    border: 1px solid #f3d27a;
    border-radius: 8px;
    padding: 1rem;
}

.transfer-request__correction-notes label {
    color: #7a4b00;
    display: block;
    font-weight: 800;
    margin-bottom: 0.55rem;
}

.transfer-request__correction-notes label span {
    color: #ef4444;
}

.transfer-request__correction-notes p {
    color: #7a4b00;
    font-size: 0.88rem;
    margin-bottom: 0.8rem;
}

.transfer-request__correction-notes textarea {
    border: 1px solid #f6ad3c;
    border-radius: 8px;
    color: #111827;
    font: inherit;
    min-height: 7rem;
    outline: none;
    padding: 1rem;
    resize: vertical;
    width: 100%;
}

.transfer-request__card {
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 1px 5px rgba(15, 23, 42, 0.16);
    overflow: hidden;
}

.transfer-request__section-title {
    background: #2d6f9f;
    color: #ffffff;
    font-size: 1.1rem;
    font-weight: 800;
    padding: 1rem 1.5rem;
}

.transfer-request__section-title--detached {
    border-radius: 8px;
    margin: 2rem -1.5rem 1.5rem;
}

.transfer-request__section-body {
    padding: 1.75rem 2rem 2rem;
}

.transfer-request__grid {
    display: grid;
    gap: 1.7rem 1.5rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.transfer-request__detail-grid {
    align-items: end;
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(14rem, 1fr) minmax(10rem, 0.75fr) minmax(10rem, 0.75fr) minmax(10rem, 0.75fr) 14rem;
}

.transfer-request__field {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    min-width: 0;
}

.transfer-request__field label {
    color: #111827;
    font-size: 0.95rem;
    font-weight: 500;
}

.transfer-request__field > label:not(.transfer-docs__file-trigger) span {
    color: #dc2626;
}

.transfer-request__field :deep(.p-inputtext),
.transfer-request__field :deep(.p-select),
.transfer-request__field :deep(.p-multiselect),
.transfer-request__field :deep(.p-datepicker-input) {
    border-color: #d7dde5;
    border-radius: 6px;
    min-height: 3.15rem;
    width: 100%;
}

.transfer-request__field :deep(.p-multiselect-label) {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    min-height: 3rem;
    white-space: normal;
}

.transfer-request__field :deep(.p-multiselect-token) {
    max-width: 100%;
}

.transfer-request__field :deep(.p-multiselect-token-label) {
    overflow: hidden;
    text-overflow: ellipsis;
}

.transfer-request__field textarea {
    border: 1px solid #d7dde5;
    border-radius: 6px;
    color: #111827;
    font: inherit;
    line-height: 1.5;
    min-height: 7rem;
    padding: 0.9rem 1rem;
    resize: vertical;
    width: 100%;
}

.transfer-request__field textarea:focus {
    border-color: #2d6f9f;
    box-shadow: 0 0 0 0.2rem rgba(45, 111, 159, 0.14);
    outline: 0;
}

.transfer-request__field :deep(.p-inputtext[readonly]) {
    background: #f8fafc;
    color: #111827;
}

.transfer-request__description {
    margin-top: 1.6rem;
}

.transfer-request__add,
.transfer-request__submit,
.transfer-docs__add,
.transfer-docs__save {
    background: var(--ugda-brand);
    border-color: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.transfer-request__add {
    min-height: 3.15rem;
}

.transfer-request__empty {
    align-items: center;
    border: 2px dashed #d9e2ec;
    border-radius: 8px;
    color: #4b6278;
    display: flex;
    justify-content: center;
    margin-top: 1.5rem;
    min-height: 7.8rem;
    padding: 1.5rem;
    text-align: center;
}

.transfer-request__table {
    margin-top: 1.5rem;
}

.transfer-request__table :deep(.p-datatable-table) {
    min-width: 720px;
}

.transfer-request__table :deep(.p-datatable-thead > tr > th) {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
    font-weight: var(--ugda-weight-medium);
}

.transfer-request__series-chips {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.transfer-request__series-chip,
.transfer-request__docs-chip {
    background: #f4f7fb;
    border-radius: 6px;
    color: #123354;
    display: inline-flex;
    font-size: 0.82rem;
    line-height: 1.2;
    padding: 0.35rem 0.55rem;
}

.transfer-request__docs-chip {
    background: #eef7ff;
    border-radius: 999px;
    color: #1f5f8e;
    font-weight: 700;
    justify-content: center;
    min-width: 4.25rem;
}

.transfer-request__row-actions {
    align-items: center;
    display: flex;
    gap: 0.45rem;
}

.transfer-request__actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.transfer-request__submit,
.transfer-request__cancel {
    min-height: 3.25rem;
    min-width: 10.5rem;
}

.transfer-request__submit:hover,
.transfer-request__submit:focus,
.transfer-request__submit:focus-visible {
    background: var(--ugda-brand) !important;
    border-color: var(--ugda-brand) !important;
    box-shadow: none !important;
    color: var(--ugda-text-inverse) !important;
}

.transfer-request__cancel {
    border-color: #2d6f9f;
    color: #1f5f8e;
}

.transfer-request__cancel:hover,
.transfer-request__cancel:focus,
.transfer-request__cancel:focus-visible {
    background: transparent !important;
    border-color: #2d6f9f !important;
    box-shadow: none !important;
    color: #1f5f8e !important;
}

.transfer-docs__cancel {
    border-color: #2d6f9f;
    color: #1f5f8e;
    min-width: 8rem;
}

.transfer-docs__cancel:hover,
.transfer-docs__cancel:focus,
.transfer-docs__cancel:focus-visible {
    background: transparent !important;
    border-color: #2d6f9f !important;
    box-shadow: none !important;
    color: #1f5f8e !important;
    outline: 0 !important;
}

.transfer-docs {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.transfer-docs__header h2,
.transfer-docs__header p {
    margin: 0;
}

.transfer-docs__header h2 {
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 800;
}

.transfer-docs__header p {
    color: #e6f2fb;
    font-size: 0.9rem;
    margin-top: 0.55rem;
}

:global(.transfer-confirm-dialog.p-dialog) {
    border-radius: 8px;
    overflow: hidden;
}

:global(.transfer-confirm-dialog .p-dialog-header) {
    background: #2f6f9f;
    border-bottom: 0;
    color: #ffffff;
    padding: 1.25rem 1.5rem;
}

:global(.transfer-confirm-dialog .p-dialog-header-close) {
    color: #ffffff;
}

:global(.transfer-confirm-dialog .p-dialog-content) {
    max-height: min(66vh, 620px);
    overflow-y: auto;
    padding: 1.5rem;
}

:global(.transfer-confirm-dialog .p-dialog-footer) {
    border-top: 1px solid #dbe3ed;
    padding: 1rem 1.5rem;
}

.transfer-confirm__header h2,
.transfer-confirm__header p {
    margin: 0;
}

.transfer-confirm__header h2 {
    color: #ffffff;
    font-size: 1.3rem;
    font-weight: 800;
}

.transfer-confirm__header p {
    color: #e6f2fb;
    font-size: 0.9rem;
    margin-top: 0.5rem;
}

.transfer-confirm {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.transfer-confirm__notice {
    align-items: center;
    background: #d5f2f7;
    border-left: 4px solid #16a3b6;
    border-radius: 8px;
    color: #155e6f;
    display: flex;
    font-weight: 700;
    gap: 0.85rem;
    padding: 1rem 1.15rem;
}

.transfer-confirm__notice i {
    font-size: 1.15rem;
}

.transfer-confirm__correction {
    background: #dbeafe;
    border-left: 4px solid #1d4ed8;
    border-radius: 8px;
    color: #123c84;
    padding: 1rem 1.15rem;
}

.transfer-confirm__correction strong {
    display: block;
    margin-bottom: 0.45rem;
}

.transfer-confirm__correction p {
    margin: 0;
}

.transfer-confirm__card {
    background: #ffffff;
    border: 1px solid #dbe3ed;
    border-radius: 8px;
    padding: 1.35rem 1.5rem;
}

.transfer-confirm__card:first-of-type {
    background: #f8fafc;
    border-color: transparent;
}

.transfer-confirm__card h3 {
    color: #111827;
    font-size: 1rem;
    font-weight: 800;
    margin: 0;
}

.transfer-confirm__divider {
    border-top: 1px solid #dbe3ed;
    margin: 0.8rem 0 1.1rem;
}

.transfer-confirm__general-grid {
    display: grid;
    gap: 1.25rem 1.5rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.transfer-confirm__general-grid span,
.transfer-confirm__metrics span {
    color: #64748b;
    display: block;
    font-size: 0.82rem;
    margin-bottom: 0.45rem;
}

.transfer-confirm__general-grid strong {
    color: #111827;
    display: block;
    overflow-wrap: anywhere;
}

.transfer-confirm__metrics {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 1.3rem;
}

.transfer-confirm__metrics > div {
    background: #eef6fd;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.transfer-confirm__metrics strong {
    color: #2f6f9f;
    display: block;
    font-size: 1.9rem;
    line-height: 1;
}

.transfer-confirm__boxes {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.transfer-confirm__box {
    align-items: center;
    border: 1px solid #dbe3ed;
    border-radius: 8px;
    display: flex;
    gap: 1.25rem;
    justify-content: space-between;
    padding: 1rem 1.1rem;
}

.transfer-confirm__box-main {
    flex: 1;
    min-width: 0;
}

.transfer-confirm__box-badge {
    background: #2f6f9f;
    border-radius: 999px;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 0.82rem;
    font-weight: 800;
    padding: 0.35rem 0.75rem;
}

.transfer-confirm__box-series-chips {
    margin-top: 0.75rem;
}

.transfer-confirm__box p {
    color: #64748b;
    margin: 0.35rem 0 0;
}

.transfer-confirm__box p strong {
    color: #111827;
}

.transfer-confirm__box-docs {
    color: #2f6f9f;
    flex-shrink: 0;
    line-height: 1.5;
    text-align: center;
    width: 7rem;
}

.transfer-confirm__save {
    background: #2f6f9f;
    border-color: #2f6f9f;
    min-width: 15.5rem;
}

.transfer-confirm__save:hover,
.transfer-confirm__save:focus {
    background: #245d88;
    border-color: #245d88;
}

:global(.transfer-docs-dialog.p-dialog),
:global(.transfer-import-dialog.p-dialog) {
    border-radius: 8px;
    overflow: hidden;
}

:global(.transfer-docs-dialog.p-dialog) {
    max-height: 92vh;
}

:global(.transfer-docs-dialog .p-dialog-header),
:global(.transfer-import-dialog .p-dialog-header) {
    background: #2d6f9f;
    border-bottom: 0;
    color: #ffffff;
    padding: 1.35rem 1.5rem;
}

:global(.transfer-docs-dialog .p-dialog-header-close),
:global(.transfer-import-dialog .p-dialog-header-close) {
    color: #ffffff;
}

:global(.transfer-docs-dialog .p-dialog-content),
:global(.transfer-import-dialog .p-dialog-content) {
    padding: 1.5rem;
}

:global(.transfer-docs-dialog .p-dialog-content) {
    max-height: calc(92vh - 11rem);
    overflow-y: auto;
}

:global(.transfer-docs-dialog .p-dialog-footer),
:global(.transfer-import-dialog .p-dialog-footer) {
    border-top: 1px solid #dbe3ed;
    padding: 1rem 1.5rem;
}

.transfer-docs__form {
    background: #f8fafc;
    border-radius: 8px;
    display: grid;
    gap: 1.1rem 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    padding: 1.1rem;
}

.transfer-docs__form-title,
.transfer-docs__series {
    grid-column: 1 / -1;
}

.transfer-docs__form-title {
    align-items: center;
    display: flex;
    justify-content: space-between;
}

.transfer-docs__form-title h3,
.transfer-docs__list-title {
    color: #111827;
    font-size: 1rem;
    font-weight: 800;
    margin: 0;
}

.transfer-docs__excel-button,
.transfer-import__download {
    background: #00a63e;
    border-color: #00a63e;
}

.transfer-docs__excel-button {
    min-height: 2.45rem;
}

.transfer-docs__file,
.transfer-docs__add {
    grid-column: 1 / -1;
}

.transfer-docs__add {
    justify-self: start;
    min-width: 13rem;
}

.transfer-docs__file input,
.transfer-docs__inline-file-input,
.transfer-import__dropzone input {
    display: none;
}

.transfer-docs__file-trigger {
    align-items: center;
    background: #ffffff;
    border: 1px solid #2d6f9f;
    border-radius: 6px;
    color: #48627c;
    cursor: pointer;
    display: flex;
    gap: 0.75rem;
    min-height: 2.65rem;
    padding: 0 1rem;
}

.transfer-docs__file-trigger i {
    color: #2d6f9f;
}

.transfer-docs__file-trigger--selected {
    background: #f8fbff;
    color: #1f5f8e;
    font-weight: 700;
}

.transfer-docs__file small,
.transfer-import__dropzone small {
    color: #48627c;
    font-size: 0.8rem;
}

.transfer-docs__file-link,
.transfer-docs__digital-link a,
.transfer-import__file-name {
    color: #1f5f8e;
    font-weight: 700;
    text-decoration: none;
}

.transfer-docs__file-link:hover,
.transfer-docs__digital-link a:hover,
.transfer-import__file-name:hover {
    text-decoration: underline;
}

.transfer-docs__empty {
    align-items: center;
    border: 1px dashed #d9e2ec;
    border-radius: 8px;
    color: #64748b;
    display: flex;
    justify-content: center;
    min-height: 5.4rem;
    padding: 1.25rem;
    text-align: center;
}

.transfer-docs__list {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    max-height: min(34vh, 26rem);
    overflow-y: auto;
}

.transfer-docs__item {
    align-items: flex-start;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem;
}

.transfer-docs__item + .transfer-docs__item {
    border-top: 1px solid #e5e7eb;
}

.transfer-docs__item-grid {
    display: grid;
    gap: 1rem 1.5rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    width: 100%;
}

.transfer-docs__item-grid strong,
.transfer-docs__item-grid span {
    display: block;
}

.transfer-docs__item-grid span {
    color: #64748b;
    font-size: 0.78rem;
    margin-bottom: 0.35rem;
}

.transfer-docs__item-grid strong {
    color: #111827;
    font-size: 0.9rem;
    overflow-wrap: anywhere;
}

.transfer-docs__support-chip {
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 0.35rem 0.75rem;
    width: fit-content;
}

.transfer-docs__support-chip--fisico {
    background: #eaf4ff;
    color: #1f5f8e;
}

.transfer-docs__support-chip--digital {
    background: #dcfce7;
    color: #166534;
}

.transfer-docs__support-chip--mixto {
    background: #f3e8ff;
    color: #7e22ce;
}

.transfer-docs__digital-link {
    grid-column: 1 / -1;
}

.transfer-docs__digital-file-row {
    align-items: center;
    display: flex;
    gap: 0.45rem;
    min-height: 2.25rem;
}

.transfer-docs__remove-file {
    align-items: center;
    background: transparent;
    border: 0;
    color: #ef4444;
    cursor: pointer;
    display: inline-flex;
    flex-shrink: 0;
    height: 2rem;
    justify-content: center;
    padding: 0;
    width: 2rem;
}

.transfer-docs__remove-file:hover {
    background: #fee2e2;
    border-radius: 999px;
}

.transfer-docs__inline-file-trigger {
    align-items: center;
    border: 1px solid #2d6f9f;
    border-radius: 6px;
    color: #1f5f8e;
    cursor: pointer;
    display: inline-flex;
    font-size: 0.88rem;
    font-weight: 700;
    gap: 0.5rem;
    min-height: 2.35rem;
    padding: 0 0.9rem;
    width: fit-content;
}

.transfer-docs__digital-link small {
    color: #48627c;
    display: block;
    font-size: 0.78rem;
    margin-top: 0.35rem;
}

.transfer-docs__item-actions {
    align-items: center;
    display: flex;
    flex-shrink: 0;
    gap: 0.25rem;
}

.transfer-import {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
}

.transfer-import__instructions {
    background: #eff6ff;
    border-left: 4px solid #2563eb;
    border-radius: 8px;
    color: #0b3b8f;
    display: flex;
    gap: 1rem;
    padding: 1.1rem 1.2rem;
}

.transfer-import__instructions > i {
    color: #2563eb;
    font-size: 1.25rem;
    margin-top: 0.05rem;
}

.transfer-import__instructions strong {
    display: block;
    margin-bottom: 0.7rem;
}

.transfer-import__instructions ul {
    margin: 0;
    padding-left: 1.25rem;
}

.transfer-import__instructions li + li {
    margin-top: 0.45rem;
}

.transfer-import__download {
    min-height: 3.4rem;
    width: 100%;
}

.transfer-import__hint {
    color: #48627c;
    font-size: 0.82rem;
    margin: -0.8rem 0 0;
    text-align: center;
}

.transfer-import__dropzone {
    align-items: center;
    border: 2px dashed #d9e2ec;
    border-radius: 8px;
    color: #48627c;
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 1.8rem 1.5rem;
    text-align: center;
}

.transfer-import__dropzone > i {
    color: #2d6f9f;
    font-size: 3.5rem;
}

.transfer-import__dropzone strong {
    color: #111827;
    font-size: 1rem;
}

.transfer-import__select {
    align-items: center;
    background: #2d6f9f;
    border-radius: 8px;
    color: #ffffff;
    cursor: pointer;
    display: flex;
    font-weight: 800;
    gap: 0.75rem;
    justify-content: center;
    min-height: 3rem;
    width: min(100%, 50rem);
}

@media (max-width: 1180px) {
    .transfer-request__detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .transfer-request__add {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .transfer-request__header h1 {
        font-size: 1.55rem;
    }

    .transfer-request__section-body {
        padding: 1.25rem;
    }

    .transfer-request__grid,
    .transfer-request__detail-grid,
    .transfer-confirm__general-grid,
    .transfer-confirm__metrics,
    .transfer-docs__form,
    .transfer-docs__item-grid {
        grid-template-columns: 1fr;
    }

    .transfer-confirm__box {
        align-items: flex-start;
        flex-direction: column;
    }

    .transfer-confirm__box-docs {
        text-align: left;
        width: auto;
    }

    .transfer-confirm__save {
        min-width: 0;
        width: 100%;
    }

    .transfer-docs__form-title {
        align-items: stretch;
        flex-direction: column;
        gap: 1rem;
    }

    .transfer-request__section-title--detached {
        margin-left: -1.25rem;
        margin-right: -1.25rem;
    }

    .transfer-request__actions {
        flex-direction: column;
    }

    .transfer-request__submit,
    .transfer-request__cancel {
        width: 100%;
    }
}
</style>
