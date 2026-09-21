<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import QRCode from 'qrcode';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';
import {
    isPdfFile,
    PDF_UPLOAD_HINT,
    PDF_TYPE_ERROR,
    validatePdfFile,
} from '../../services/pdfValidation';

const router = useRouter();
const toast = useToast();

const isLoading = ref(false);
const isSaving = ref(false);
const isImporting = ref(false);
const isSearchingDocuments = ref(false);

const units = ref([]);
const seriesOptions = ref([]);
const supportOptions = ref([
    { label: 'Físico', value: 'Fisico' },
    { label: 'Digital', value: 'Digital' },
    { label: 'Mixto', value: 'Mixto' },
]);
const locationOptions = ref({
    offices: [],
    aisles: [],
    shelves: [],
});

const form = ref({
    box_code: '',
    unit_ids: [],
    classification_ids: [],
    start_year: null,
    end_year: null,
    office_id: null,
    aisle_id: null,
    shelf_id: null,
});

const documents = ref([]);
const addDocumentDialogVisible = ref(false);
const importDialogVisible = ref(false);
const searchDialogVisible = ref(false);
const labelDialogVisible = ref(false);
const labelPreviewRef = ref(null);
const labelPreviewCard = ref(null);
const labelPreviewItem = ref(null);
const labelsBusy = ref(false);
const excelFile = ref(null);
const excelInputRef = ref(null);
const documentFileInputRef = ref(null);
const isExcelDragging = ref(false);
const documentSearch = ref('');
const documentSearchResults = ref([]);
const selectedExistingDocuments = ref([]);
const documentPreviewVisible = ref(false);
const documentPreviewTitle = ref('Documento PDF');
const documentPreviewSource = ref(null);
const shouldRestoreAddDocumentDialog = ref(false);

// Control de visibilidad de modales
watch(
    () => documentPreviewVisible.value,
    (newValue) => {
        if (newValue) {
            // Ocultar el modal del documento cuando se abre el preview del PDF
            shouldRestoreAddDocumentDialog.value = addDocumentDialogVisible.value;
            addDocumentDialogVisible.value = false;
        }
    },
);

const restoreAddDocumentDialog = () => {
    documentPreviewSource.value = null;

    if (shouldRestoreAddDocumentDialog.value) {
        shouldRestoreAddDocumentDialog.value = false;
        addDocumentDialogVisible.value = true;
    }
};
const documentDigitalFile = ref(null);
const documentDigitalFileName = ref('');
const labelSize = {
    widthCm: 10,
    heightCm: 18,
    widthMm: 100,
    heightMm: 180,
};
const currentYear = new Date().getFullYear();
const currentDate = new Date();
const minimumYearDate = new Date(1900, 0, 1);

const docForm = ref({
    classification_id: null,
    code: '',
    name: '',
    year: '',
    support: null,
    pages: '',
});

const selectedSeriesOptions = computed(() => seriesOptions.value.filter((item) => (
    form.value.classification_ids.includes(item.value)
)));

const selectedSeriesName = computed(() => selectedSeriesOptions.value
    .map((item) => item.label)
    .join('; '));

const requiresDigitalDocFile = computed(() => ['Digital', 'Mixto'].includes(docForm.value.support));
const normalizeSupportOptions = (options) => (Array.isArray(options) ? options : []).map((option) => {
    const value = typeof option === 'string' ? option : option.value;
    return { label: value === 'Fisico' ? 'Físico' : value, value };
});

watch(() => docForm.value.support, (support) => {
    if (support !== 'Fisico') {
        return;
    }

    documentDigitalFile.value = null;
    documentDigitalFileName.value = '';

    if (documentFileInputRef.value) {
        documentFileInputRef.value.value = '';
    }
});

const selectedUnitName = computed(() => (
    units.value
        .filter((item) => form.value.unit_ids.includes(item.value))
        .map((item) => item.label)
        .join('; ')
));

const allDocuments = computed(() => [
    ...selectedExistingDocuments.value.map((document) => ({
        ...document,
        source: 'existente',
    })),
    ...documents.value.map((document) => ({
        ...document,
        source: 'manual',
    })),
]);

const generatedLocationCode = computed(() => {
    const office = locationOptions.value.offices.find((item) => item.id === form.value.office_id);
    const aisle = locationOptions.value.aisles.find((item) => item.id === form.value.aisle_id);
    const shelf = locationOptions.value.shelves.find((item) => item.id === form.value.shelf_id);

    if (!office || !aisle || !shelf) {
        return '';
    }

    return `${office.code}-${aisle.code}-${shelf.code}`;
});

const filteredAisles = computed(() => (
    locationOptions.value.aisles.filter((item) => item.office_id === form.value.office_id)
));

const filteredShelves = computed(() => (
    locationOptions.value.shelves.filter((item) => item.aisle_id === form.value.aisle_id)
));

watch(() => form.value.office_id, () => {
    if (!filteredAisles.value.some((item) => item.id === form.value.aisle_id)) {
        form.value.aisle_id = null;
        form.value.shelf_id = null;
    }
});

watch(() => form.value.aisle_id, () => {
    if (!filteredShelves.value.some((item) => item.id === form.value.shelf_id)) {
        form.value.shelf_id = null;
    }
});

const selectedExcelFileName = computed(() => excelFile.value?.name ?? '');

const showError = (detail) => {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail,
        life: 4200,
    });
};

const onlyDigits = (value) => String(value ?? '').replace(/\D/g, '');

const sanitizeYear = (value) => {
    const digits = onlyDigits(value).slice(0, 4);

    if (digits.length < 4) {
        return digits;
    }

    return String(Math.min(Number(digits), currentYear));
};

const sanitizePositiveInteger = (value) => {
    const digits = onlyDigits(value);

    if (!digits) {
        return '';
    }

    const number = Number(digits);

    return number > 0 ? String(number) : '';
};

const onDocumentYearInput = (event) => {
    docForm.value.year = sanitizeYear(event.target.value);
};

const onDocumentPagesInput = (event) => {
    docForm.value.pages = sanitizePositiveInteger(event.target.value);
};

const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const currentDateLabel = () => {
    const now = new Date();
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();

    return `${day}/${month}/${year}`;
};

const getYearString = (value) => {
    if (!value) {
        return '';
    }

    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return String(value.getFullYear());
    }

    return String(value).match(/\d{4}/)?.[0] ?? '';
};

const normalizeDocumentValue = (value) => String(value ?? '')
    .trim()
    .replace(/[–—−]/g, '-')
    .replace(/\s+/g, ' ')
    .toLowerCase();

const getDocumentCodeKey = (document) => normalizeDocumentValue(document?.code);

const isPdfFileName = (fileName) => String(fileName ?? '').toLowerCase().endsWith('.pdf');

const createPdfPreviewUrl = (file) => URL.createObjectURL(new Blob([file], { type: 'application/pdf' }));

const findDuplicateDocumentCode = (documentList) => {
    const seen = new Set();

    for (const document of documentList) {
        const codeKey = getDocumentCodeKey(document);

        if (!codeKey) {
            continue;
        }

        if (seen.has(codeKey)) {
            return String(document.code ?? '').trim();
        }

        seen.add(codeKey);
    }

    return '';
};

const buildQrDataUrl = async (seed) => QRCode.toDataURL(seed, {
    errorCorrectionLevel: 'M',
    type: 'image/png',
    margin: 1,
    width: 180,
    color: {
        dark: '#111827',
        light: '#ffffff',
    },
});

const buildBoxLabelCode = () => form.value.box_code || 'NUEVA-CAJA';

const buildLabelSeed = () => JSON.stringify({
    type: 'archivo-general-box-preview',
    box: buildBoxLabelCode(),
    location: generatedLocationCode.value,
});

const buildLabelPreviewItem = async () => ({
    boxLabel: buildBoxLabelCode(),
    transferNumber: 'N/A',
    transferDate: currentDateLabel(),
    unit: selectedUnitName.value || 'Unidad no seleccionada',
    content: selectedSeriesName.value || 'Subserie documental no seleccionada',
    period: [getYearString(form.value.start_year), getYearString(form.value.end_year)].filter(Boolean).join(' - ') || 'Periodo no definido',
    locationCode: generatedLocationCode.value,
    labelCode: buildBoxLabelCode(),
    assignedBy: 'UGDA',
    assignedAt: currentDateLabel(),
    qrDataUrl: await buildQrDataUrl(buildLabelSeed()),
});

const resetDocForm = () => {
    docForm.value = {
        classification_id: form.value.classification_ids[0] ?? null,
        code: '',
        name: '',
        year: '',
        support: null,
        pages: '',
    };
    documentDigitalFile.value = null;
    documentDigitalFileName.value = '';

    if (documentFileInputRef.value) {
        documentFileInputRef.value.value = '';
    }
};

const fetchCatalog = async () => {
    isLoading.value = true;

    try {
        const { data } = await axios.get('/api/archive/catalog');
        units.value = Array.isArray(data.units) ? data.units : [];
        seriesOptions.value = Array.isArray(data.series) ? data.series : [];
        const normalizedSupportOptions = normalizeSupportOptions(data.support_types);
        supportOptions.value = normalizedSupportOptions.length ? normalizedSupportOptions : supportOptions.value;
        locationOptions.value = {
            offices: data.locations?.offices ?? [],
            aisles: data.locations?.aisles ?? [],
            shelves: data.locations?.shelves ?? [],
        };
        form.value.box_code = data.next_box_code || '';
    } catch (error) {
        console.error('Error cargando catalogo:', error);
        showError(error.response?.data?.message ?? 'No se pudo cargar la información para crear la caja.');
    } finally {
        isLoading.value = false;
    }
};

const openAddDocumentDialog = () => {
    resetDocForm();
    addDocumentDialogVisible.value = true;
};

const readFileAsBase64 = (file) => new Promise((resolve, reject) => {
    const reader = new FileReader();

    reader.onload = () => {
        const result = reader.result;
        const base64 = typeof result === 'string' && result.includes(',') ? result.split(',')[1] : '';
        resolve(base64);
    };

    reader.onerror = () => reject(new Error('No se pudo leer el archivo PDF.'));
    reader.readAsDataURL(file);
});

const onDocumentDigitalFileSelect = async (event) => {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
        documentDigitalFile.value = null;
        documentDigitalFileName.value = '';
        return;
    }

    const validationError = await validatePdfFile(file);

    if (validationError) {
        showError(validationError);
        if (event.target) {
            event.target.value = '';
        }
        documentDigitalFile.value = null;
        documentDigitalFileName.value = '';
        return;
    }

    documentDigitalFile.value = file;
    documentDigitalFileName.value = file.name;
};

const onNewDocumentFileSelect = async (documentEntry, event) => {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
        return;
    }

    const validationError = await validatePdfFile(file);

    if (validationError) {
        showError(validationError);
        event.target.value = '';
        return;
    }

    const documentIndex = documents.value.findIndex((document) => document.id === documentEntry.id);

    if (documentIndex < 0) {
        return;
    }

    if (documents.value[documentIndex].file_url) {
        URL.revokeObjectURL(documents.value[documentIndex].file_url);
    }

    documents.value[documentIndex] = {
        ...documents.value[documentIndex],
        file,
        file_name: file.name,
        digital_file_name: file.name,
        digital_file_base64: await readFileAsBase64(file),
        file_url: createPdfPreviewUrl(file),
    };
};

const openNewDocumentFilePicker = (documentId, context = 'main') => {
    document.getElementById(`newDocumentFile-${context}-${documentId}`)?.click();
};

const previewDocumentFile = () => {
    if (!documentDigitalFile.value) {
        showError('Adjunte primero un archivo PDF para visualizarlo.');
        return;
    }

    documentPreviewTitle.value = documentDigitalFileName.value || 'Documento PDF';
    documentPreviewSource.value = { file: documentDigitalFile.value };
    documentPreviewVisible.value = true;
};

const previewStoredDocument = (documentEntry) => {
    if (isPdfFile(documentEntry?.file)) {
        documentPreviewTitle.value = documentEntry.file_name || documentEntry.file.name || documentEntry.name || 'Documento PDF';
        documentPreviewSource.value = { file: documentEntry.file };
        documentPreviewVisible.value = true;
        return;
    }

    if (documentEntry?.file_url) {
        documentPreviewTitle.value = documentEntry.file_name || documentEntry.name || 'Documento PDF';
        documentPreviewSource.value = { url: documentEntry.file_url, fileName: documentEntry.file_name };
        documentPreviewVisible.value = true;
        return;
    }

    if (!documentEntry?.digital_file_base64) {
        showError('Este documento no tiene un archivo PDF adjunto.');
        return;
    }

    const binary = atob(documentEntry.digital_file_base64);
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    const blob = new Blob([bytes], { type: 'application/pdf' });
    const objectUrl = URL.createObjectURL(blob);

    documentPreviewTitle.value = documentEntry.digital_file_name || documentEntry.name || 'Documento PDF';
    documentPreviewSource.value = { url: objectUrl };
    documentPreviewVisible.value = true;
};

const validateDocumentForBox = (document, requireDigitalFile = true) => {
    const documentName = String(document?.name ?? 'documento').trim() || 'documento';
    const documentSeries = normalizeDocumentValue(document?.series);
    const boxSeries = new Set(selectedSeriesOptions.value.map((item) => normalizeDocumentValue(item.label)));

    if (!documentSeries || !boxSeries.has(documentSeries)) {
        return `La serie "${document.series || 'Sin serie'}" del documento "${documentName}" no existe o no forma parte de la caja.`;
    }

    const year = String(document?.year ?? document?.date ?? '').trim();
    const startYear = Number(getYearString(form.value.start_year));
    const endYear = Number(getYearString(form.value.end_year));

    if (!/^\d{4}$/.test(year) || Number(year) < startYear || Number(year) > endYear) {
        return `El año del documento "${documentName}" debe estar dentro del rango de la caja (${startYear} - ${endYear}).`;
    }

    const pages = String(document?.pages ?? '').trim();

    if (pages !== '' && (!/^\d+$/.test(pages) || Number(pages) < 1)) {
        return `La cantidad de páginas del documento "${documentName}" debe ser un entero positivo.`;
    }

    if (requireDigitalFile && ['Digital', 'Mixto'].includes(document?.support)
        && (!document?.digital_file_base64 || !isPdfFileName(document?.digital_file_name))) {
        return document?.digital_file_base64 ? PDF_TYPE_ERROR : `El documento "${documentName}" requiere un archivo PDF para el soporte ${document.support === 'Fisico' ? 'físico' : document.support}.`;
    }

    return '';
};

const validateDocumentCollection = (documentList, requireDigitalFile = true) => {
    for (const document of documentList) {
        const message = validateDocumentForBox(document, requireDigitalFile);

        if (message) {
            return message;
        }
    }

    const duplicateCode = findDuplicateDocumentCode(documentList);

    return duplicateCode
        ? `El código "${duplicateCode}" está repetido dentro de esta caja. Cada código debe ser único por caja.`
        : '';
};

const addDocument = async () => {
    const selectedDocSeries = seriesOptions.value.find((item) => item.value === docForm.value.classification_id);
    const documentYear = Number(docForm.value.year);
    const pages = docForm.value.pages === '' ? null : Number(docForm.value.pages);

    if (!docForm.value.classification_id || !docForm.value.name || !docForm.value.code || !docForm.value.year || !docForm.value.support) {
        showError('Complete los campos obligatorios del documento, incluyendo la serie o subserie documental.');
        return;
    }

    if (docForm.value.year.length !== 4 || !Number.isInteger(documentYear) || documentYear > currentYear) {
        showError(`El año del documento debe ser un número entero de 4 dígitos hasta ${currentYear}.`);
        return;
    }

    if (pages !== null && (!Number.isInteger(pages) || pages <= 0)) {
        showError('El número de páginas debe ser un entero positivo mayor que 0.');
        return;
    }

    if (requiresDigitalDocFile.value && (!documentDigitalFile.value || !isPdfFile(documentDigitalFile.value))) {
        showError('Adjunte un archivo PDF para documentos digitales o mixtos.');
        return;
    }

    let digitalFileBase64 = null;

    if (requiresDigitalDocFile.value && documentDigitalFile.value) {
        digitalFileBase64 = await readFileAsBase64(documentDigitalFile.value);
    }

    const documentEntry = {
        id: `manual-${Date.now()}`,
        series: selectedDocSeries?.label ?? selectedSeriesName.value,
        code: docForm.value.code,
        name: docForm.value.name,
        year: String(documentYear),
        support: docForm.value.support,
        pages: pages === null ? '' : String(pages),
        digital_file_name: documentDigitalFileName.value || null,
        digital_file_base64: digitalFileBase64,
        file: documentDigitalFile.value,
        file_name: documentDigitalFileName.value || null,
        file_url: documentDigitalFile.value ? createPdfPreviewUrl(documentDigitalFile.value) : null,
    };
    const validationMessage = validateDocumentCollection([...allDocuments.value, documentEntry], false);

    if (validationMessage) {
        showError(validationMessage);
        return;
    }

    documents.value.push(documentEntry);

    toast.add({
        severity: 'success',
        summary: 'Documento agregado',
        detail: 'El documento fue agregado a la caja.',
        life: 2600,
    });
    resetDocForm();
};

const removeDocument = (document) => {
    if (document.source === 'existente') {
        selectedExistingDocuments.value = selectedExistingDocuments.value.filter((item) => item.id !== document.id);
        return;
    }

    const documentToRemove = documents.value.find((item) => item.id === document.id);

    if (documentToRemove?.file_url) {
        URL.revokeObjectURL(documentToRemove.file_url);
    }

    documents.value = documents.value.filter((item) => item.id !== document.id);
};

const setExcelFile = (file) => {
    if (!file) {
        return;
    }

    if (!file.name.toLowerCase().endsWith('.xlsx')) {
        showError('Seleccione un archivo Excel con extension .xlsx.');
        return;
    }

    excelFile.value = file;
};

const openExcelFilePicker = () => {
    excelInputRef.value?.click();
};

const onExcelFileSelect = (event) => {
    setExcelFile(event.target.files?.[0] ?? null);
};

const onExcelDrop = (event) => {
    isExcelDragging.value = false;
    setExcelFile(event.dataTransfer?.files?.[0] ?? null);
};

const resetExcelInput = () => {
    if (excelInputRef.value) {
        excelInputRef.value.value = '';
    }
};

const downloadTemplate = () => {
    const params = new URLSearchParams();
    const yearStart = getYearString(form.value.start_year);
    const yearEnd = getYearString(form.value.end_year);

    if (selectedSeriesName.value) {
        params.set('series', selectedSeriesName.value);
    }

    if (yearStart) {
        params.set('year_start', yearStart);
    }

    if (yearEnd) {
        params.set('year_end', yearEnd);
    }

    const query = params.toString();
    window.location.href = `/api/transfers/document-import-template${query ? `?${query}` : ''}`;
};

const importDocuments = async () => {
    if (!excelFile.value) {
        showError('Seleccione un archivo Excel para importar.');
        return;
    }

    isImporting.value = true;

    try {
        const formData = new FormData();
        formData.append('file', excelFile.value);
        formData.append('current_box_series', selectedSeriesName.value);

        const { data } = await axios.post('/api/transfers/import-documents', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        const imported = Array.isArray(data.documents) ? data.documents : [];

        if (imported.length === 0) {
            showError('El archivo no contiene filas con documentos.');
            return;
        }

        const importedDocuments = imported.map((document, index) => ({
            id: `excel-${Date.now()}-${index}`,
            series: document.series || selectedSeriesName.value,
            code: String(document.code ?? '').trim(),
            name: String(document.name ?? '').trim(),
            year: String(document.date ?? '').trim(),
            support: document.support,
            pages: String(document.pages ?? '').trim(),
            digital_file_name: null,
            digital_file_base64: null,
            file: null,
            file_name: null,
            file_url: null,
        }));
        const importValidationMessage = validateDocumentCollection([
            ...allDocuments.value,
            ...importedDocuments,
        ], false);

        if (importValidationMessage) {
            showError(importValidationMessage);
            return;
        }

        documents.value.push(...importedDocuments);

        excelFile.value = null;
        resetExcelInput();
        importDialogVisible.value = false;
        toast.add({
            severity: 'success',
            summary: 'Importacion completa',
            detail: `${imported.length} documento(s) importado(s).`,
            life: 3000,
        });
    } catch (error) {
        showError(error.response?.data?.message ?? 'No se pudo importar el archivo Excel.');
    } finally {
        isImporting.value = false;
    }
};

const searchExistingDocuments = async () => {
    if (!documentSearch.value.trim()) {
        showError('Ingrese un criterio para buscar documentos existentes.');
        return;
    }

    isSearchingDocuments.value = true;

    try {
        const { data } = await axios.get('/api/archive/documents', {
            params: { q: documentSearch.value.trim() },
        });

        const selectedIds = new Set(selectedExistingDocuments.value.map((item) => item.id));
        documentSearchResults.value = (Array.isArray(data.results) ? data.results : [])
            .filter((document) => !selectedIds.has(document.id));
    } catch (error) {
        showError(error.response?.data?.message ?? 'No se pudo buscar documentos existentes.');
    } finally {
        isSearchingDocuments.value = false;
    }
};

const addExistingDocument = (document) => {
    selectedExistingDocuments.value.push({
        id: document.id,
        series: document.series ?? document.box?.series,
        code: document.code,
        name: document.title,
        year: document.year,
        support: document.support,
        pages: document.pages,
        current_box: document.box?.number,
        current_location: document.box?.location,
    });

    documentSearchResults.value = documentSearchResults.value.filter((item) => item.id !== document.id);
};

const previewLabel = async () => {
    if (!generatedLocationCode.value) {
        showError('Complete la ubicación física de la caja.');
        return;
    }

    labelPreviewCard.value = null;
    labelPreviewItem.value = await buildLabelPreviewItem();
    labelDialogVisible.value = true;

    requestAnimationFrame(() => {
        labelPreviewRef.value?.scrollTo({ top: 0, behavior: 'auto' });
    });
};

const buildLabelsDocument = () => {
    const label = labelPreviewItem.value;

    if (!label) {
        return '';
    }

    return `
        <!DOCTYPE html>
        <html lang="es">
            <head>
                <meta charset="utf-8" />
                <title>Etiqueta ${escapeHtml(label.labelCode)}</title>
                <style>
                    * { box-sizing: border-box; }
                    @page { size: ${labelSize.widthCm}cm ${labelSize.heightCm}cm; margin: 0; }
                    html, body { width: ${labelSize.widthCm}cm; min-height: ${labelSize.heightCm}cm; }
                    body { margin: 0; font-family: 'Instrument Sans', Arial, Helvetica, sans-serif; background: #ffffff; color: #0f172a; }
                    .print-label-card { width: ${labelSize.widthCm}cm; height: ${labelSize.heightCm}cm; min-height: ${labelSize.heightCm}cm; background: #ffffff; border: 2px solid #1f2937; border-radius: 0.5cm; display: flex; flex-direction: column; overflow: hidden; }
                    .print-label-card__header, .print-label-card__hero, .print-label-card__details { display: grid; gap: 0.35cm; }
                    .print-label-card__header { grid-template-columns: 1fr auto; align-items: flex-start; padding: 0.58cm 0.55cm 0.36cm; border-bottom: 1px solid #d9e2ec; }
                    .print-label-card__university { font-size: 0.44cm; font-weight: 700; line-height: 1.15; letter-spacing: 0; }
                    .print-label-card__faculty { margin-top: 0.12cm; color: #64748b; font-size: 0.28cm; line-height: 1.25; }
                    .print-label-card__date { min-width: 2.25cm; text-align: right; }
                    .print-label-card__date span, .print-label-card__box span, .print-label-card__details span, .print-label-card__assigned, .print-label-card__qr span { color: #64748b; font-size: 0.25cm; line-height: 1.25; }
                    .print-label-card__date strong { display: block; margin-top: 0.12cm; color: #0f172a; font-size: 0.34cm; line-height: 1.2; }
                    .print-label-card__hero { grid-template-columns: 1fr; align-items: center; padding: 0.42cm 0.55cm; border-bottom: 1px solid #d9e2ec; }
                    .print-label-card__qr { text-align: center; }
                    .print-label-card__qr img { width: 2.8cm; height: 2.8cm; display: block; margin: 0 auto 0.12cm; }
                    .print-label-card__location { background: #2f6f9f; border-radius: 0.32cm; padding: 0.34cm 0.36cm; text-align: center; color: #ffffff; }
                    .print-label-card__location span { display: block; font-size: 0.25cm; font-weight: 700; letter-spacing: 0; }
                    .print-label-card__location strong { display: block; margin-top: 0.14cm; font-size: 0.7cm; font-weight: 700; letter-spacing: 0; line-height: 1.05; }
                    .print-label-card__box { margin-top: 0.28cm; border-radius: 0.28cm; background: #edf3fa; padding: 0.3cm 0.36cm; }
                    .print-label-card__box strong { display: block; margin-top: 0.1cm; color: #0f172a; font-size: 0.58cm; line-height: 1.1; }
                    .print-label-card__details { grid-template-columns: 1fr; padding: 0.42cm 0.55cm 0.24cm; }
                    .print-label-card__details strong { display: block; margin-top: 0.08cm; font-size: 0.33cm; color: #0f172a; line-height: 1.25; }
                    .print-label-card__assigned { margin: 0 0.55cm; padding: 0.3cm 0; border-top: 1px solid #d9e2ec; }
                    .print-label-card__footer { margin-top: auto; background: #fff7db; padding: 0.28cm 0.55cm; color: #b45309; font-size: 0.24cm; font-weight: 700; line-height: 1.3; }
                </style>
            </head>
            <body>
                <article class="print-label-card">
                    <div class="print-label-card__header">
                        <div>
                            <div class="print-label-card__university">UNIVERSIDAD DE EL SALVADOR</div>
                            <div class="print-label-card__faculty">Facultad de Ingenieria y Arquitectura - UGDA</div>
                        </div>
                        <div class="print-label-card__date">
                            <span>Fecha de transferencia</span>
                            <strong>${escapeHtml(label.transferDate)}</strong>
                        </div>
                    </div>
                    <div class="print-label-card__hero">
                        <div class="print-label-card__qr">
                            <img src="${label.qrDataUrl}" alt="Código QR ${escapeHtml(label.labelCode)}" />
                            <span>Código: ${escapeHtml(label.labelCode)}</span>
                        </div>
                        <div class="print-label-card__hero-main">
                            <div class="print-label-card__location">
                                <span>CÓDIGO DE UBICACIÓN</span>
                                <strong>${escapeHtml(label.locationCode)}</strong>
                            </div>
                            <div class="print-label-card__box">
                                <span>N de caja</span>
                                <strong>${escapeHtml(label.boxLabel)}</strong>
                            </div>
                        </div>
                    </div>
                    <div class="print-label-card__details">
                        <div>
                            <span>N° de transferencia</span>
                            <strong>${escapeHtml(label.transferNumber)}</strong>
                        </div>
                        <div>
                            <span>Unidad productora</span>
                            <strong>${escapeHtml(label.unit)}</strong>
                        </div>
                        <div class="print-label-card__details--full">
                            <span>Contenido de la caja</span>
                            <strong>${escapeHtml(label.content)}</strong>
                        </div>
                    </div>
                    <div class="print-label-card__assigned">
                        Ubicación asignada por ${escapeHtml(label.assignedBy)} el ${escapeHtml(label.assignedAt)}
                    </div>
                    <div class="print-label-card__footer">
                        Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicación.
                    </div>
                </article>
            </body>
        </html>
    `;
};

const printLabels = () => {
    const popup = window.open('', '_blank', 'width=1200,height=900');

    if (!popup) {
        toast.add({
            severity: 'warn',
            summary: 'Ventana bloqueada',
            detail: 'Habilite las ventanas emergentes para imprimir o guardar el PDF.',
            life: 3500,
        });
        return;
    }

    popup.document.open();
    popup.document.write(buildLabelsDocument());
    popup.document.close();

    popup.onload = () => {
        popup.focus();
        popup.print();
    };
};

const downloadLabelsPdf = async () => {
    if (!labelPreviewCard.value || labelsBusy.value) {
        return;
    }

    labelsBusy.value = true;
    let exportHost = null;

    try {
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [labelSize.widthMm, labelSize.heightMm],
        });

        exportHost = document.createElement('div');
        exportHost.style.position = 'fixed';
        exportHost.style.left = '-10000px';
        exportHost.style.top = '0';
        exportHost.style.width = `${labelSize.widthCm}cm`;
        exportHost.style.pointerEvents = 'none';
        exportHost.style.zIndex = '-1';
        document.body.appendChild(exportHost);

        const exportCard = labelPreviewCard.value.cloneNode(true);
        exportCard.classList.add('transfer-label--export');
        exportHost.appendChild(exportCard);

        const exportImages = Array.from(exportCard.querySelectorAll('img'));
        await Promise.all(exportImages.map((image) => {
            if (image.complete) {
                return Promise.resolve();
            }

            return new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }));

        const canvas = await html2canvas(exportCard, {
            backgroundColor: '#ffffff',
            scale: 2,
            useCORS: true,
        });

        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, labelSize.widthMm, labelSize.heightMm, undefined, 'FAST');
        pdf.save(`etiqueta-caja-archivo-${form.value.box_code || 'nueva'}.pdf`);
    } catch (error) {
        console.error('Error generando PDF de etiqueta:', error);
        showError('Ocurrio un problema al generar el PDF de la etiqueta.');
    } finally {
        exportHost?.remove();
        document.querySelectorAll('.transfer-label--export').forEach((node) => node.remove());
        labelsBusy.value = false;
    }
};

const validateForm = () => {
    if (form.value.unit_ids.length === 0 || form.value.classification_ids.length === 0 || !form.value.start_year || !form.value.end_year) {
        showError('Complete la información obligatoria de la caja.');
        return false;
    }

    const startYear = getYearString(form.value.start_year);
    const endYear = getYearString(form.value.end_year);

    if (!/^\d{4}$/.test(startYear) || !/^\d{4}$/.test(endYear)) {
        showError('Los años de la caja deben tener 4 dígitos.');
        return false;
    }

    if (Number(startYear) > currentYear || Number(endYear) > currentYear) {
        showError(`El ano de la caja no puede ser mayor que ${currentYear}.`);
        return false;
    }

    if (Number(endYear) < Number(startYear)) {
        showError('El año final no puede ser menor que el año inicial.');
        return false;
    }

    const documentValidationMessage = validateDocumentCollection(allDocuments.value, false);

    if (documentValidationMessage) {
        showError(documentValidationMessage);
        return false;
    }

    const documentWithoutPdf = documents.value.find((document) => (
        ['Digital', 'Mixto'].includes(document.support)
        && (!document.digital_file_base64 || !isPdfFileName(document.digital_file_name))
    ));

    if (documentWithoutPdf) {
        showError(`El documento "${documentWithoutPdf.name}" requiere un archivo PDF para el soporte ${documentWithoutPdf.support}.`);
        return false;
    }

    if (!generatedLocationCode.value) {
        showError('Complete la ubicación física de la caja.');
        return false;
    }

    return true;
};

const submitBox = async () => {
    if (!validateForm()) {
        return;
    }

    isSaving.value = true;

    try {
        const payload = {
            unit_ids: form.value.unit_ids,
            classification_ids: form.value.classification_ids,
            start_year: Number(getYearString(form.value.start_year)),
            end_year: Number(getYearString(form.value.end_year)),
            office_id: form.value.office_id,
            aisle_id: form.value.aisle_id,
            shelf_id: form.value.shelf_id,
            existing_document_ids: selectedExistingDocuments.value.map((document) => document.id),
            documents: documents.value.map((document) => ({
                series: document.series,
                code: document.code,
                name: document.name,
                year: document.year,
                support: document.support,
                pages: document.pages,
                digital_file_name: document.digital_file_name ?? null,
                digital_file_base64: document.digital_file_base64 ?? null,
            })),
        };

        await axios.post('/api/archive/boxes', payload);

        toast.add({
            severity: 'success',
            summary: 'Caja creada',
            detail: 'La caja fue registrada correctamente.',
            life: 3200,
        });
        router.push('/archivo-general/gestion');
    } catch (error) {
        const validationErrors = Object.values(error.response?.data?.errors ?? {}).flat();
        showError(validationErrors[0] ?? error.response?.data?.message ?? 'No se pudo crear la caja.');
    } finally {
        isSaving.value = false;
    }
};

onMounted(fetchCatalog);
</script>

<template>
    <div class="create-box-page fadein animation-duration-500">
        <header class="create-box-header">
            <button type="button" class="create-box-back" @click="router.push('/archivo-general/gestion')">
                <i class="pi pi-arrow-left"></i>
                Volver
            </button>
            <span class="create-box-separator"></span>
            <h1 class="mobile-page-title">Crear Nueva Caja</h1>
        </header>

        <main class="create-box-main">
            <section class="create-box-section">
                <h2>Información de la Caja</h2>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Código de Caja (automático)</span>
                        <InputText
                            v-model="form.box_code"
                            type="text"
                            class="w-full"
                            disabled
                            placeholder="Se asigna al guardar"
                        />
                    </label>
                    <label>
                        <span>Unidades Productoras <b>*</b></span>
                        <MultiSelect
                            v-model="form.unit_ids"
                            :options="units"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Seleccionar unidades productoras"
                            display="chip"
                            filter
                            :maxSelectedLabels="2"
                            selectedItemsLabel="{0} unidades seleccionadas"
                            class="w-full"
                            :disabled="isLoading"
                        />
                    </label>
                </div>

                <label class="create-box-field">
                        <span>Serie / Subseries <b>*</b></span>
                    <MultiSelect
                        v-model="form.classification_ids"
                        :options="seriesOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Seleccionar subseries"
                        display="chip"
                        filter
                        :maxSelectedLabels="3"
                        selectedItemsLabel="{0} subseries seleccionadas"
                        class="w-full"
                        :disabled="isLoading"
                    />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
        <span>Año inicial <b>*</b></span>
                        <DatePicker
                            v-model="form.start_year"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            :minDate="minimumYearDate"
                            :maxDate="currentDate"
                            placeholder="Seleccionar año"
                            class="w-full"
                        />
                    </label>
                    <label>
        <span>Año final <b>*</b></span>
                        <DatePicker
                            v-model="form.end_year"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            :minDate="form.start_year || minimumYearDate"
                            :maxDate="currentDate"
                            placeholder="Seleccionar año"
                            class="w-full"
                        />
                    </label>
                </div>
            </section>

            <section class="create-box-section">
                <h2>Ubicación Física</h2>

                <div class="create-box-grid create-box-grid--three">
                    <label>
                        <span>Oficina <b>*</b></span>
                        <Dropdown
                            v-model="form.office_id"
                            :options="locationOptions.offices"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Seleccionar"
                            class="w-full"
                        />
                    </label>
                    <label>
                        <span>Pasillo <b>*</b></span>
                        <Dropdown
                            v-model="form.aisle_id"
                            :options="filteredAisles"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Seleccionar"
                            class="w-full"
                            :disabled="!form.office_id"
                        />
                    </label>
                    <label>
                        <span>Estante <b>*</b></span>
                        <Dropdown
                            v-model="form.shelf_id"
                            :options="filteredShelves"
                            optionLabel="name"
                            optionValue="id"
                            placeholder="Seleccionar"
                            class="w-full"
                            :disabled="!form.aisle_id"
                        />
                    </label>
                </div>

                <div v-if="generatedLocationCode" class="create-box-location">
                    <div>
                        <span>Código de Ubicación Generado:</span>
                        <strong>{{ generatedLocationCode }}</strong>
                    </div>
                    <button type="button" @click="previewLabel">
                        <i class="pi pi-print"></i>
                        Vista Previa de Etiqueta
                    </button>
                </div>
            </section>

            <section class="create-box-section">
                <div class="create-box-documents-head">
                    <h2>Documentos ({{ allDocuments.length }})</h2>
                    <div class="create-box-documents-actions">
                        <button type="button" class="create-box-outline" @click="searchDialogVisible = true">
                            <i class="pi pi-search"></i>
                            Buscar Documentos Existentes
                        </button>
                        <button type="button" class="create-box-outline" @click="importDialogVisible = true">
                            <i class="pi pi-file-excel"></i>
                            Importar desde Excel
                        </button>
                        <button type="button" class="create-box-primary" @click="openAddDocumentDialog">
                            <i class="pi pi-plus"></i>
                            Agregar Documento
                        </button>
                    </div>
                </div>

                <div class="create-box-info">
                    <i class="pi pi-info-circle"></i>
                    Puede crear la caja vacia, agregar documentos manualmente, importarlos desde Excel, o buscar y mover documentos existentes de otras cajas.
                </div>

                <div v-if="allDocuments.length === 0" class="create-box-empty">
                    No hay documentos agregados. Puede crear la caja vacia o agregar documentos.
                </div>

                <DataTable
                    v-else
                    :value="allDocuments"
                    responsiveLayout="scroll"
                    class="create-box-documents-table"
                >
                    <Column field="series" header="Serie Documental" />
                    <Column field="code" header="Código" />
                    <Column field="name" header="Nombre" />
                    <Column field="year" header="Año" />
                    <Column field="support" header="Soporte" />
                    <Column field="pages" header="N° Paginas" />
                    <Column header="Acciones">
                        <template #body="{ data }">
                            <div class="create-box-doc-actions">
                                <button
                                    v-if="(data.support === 'Digital' || data.support === 'Mixto') && data.digital_file_base64"
                                    type="button"
                                    class="create-box-outline"
                                    @click="previewStoredDocument(data)"
                                >
                                    <i class="pi pi-eye"></i>
                                </button>
                                <template v-if="(data.source !== 'existente' && (data.support === 'Digital' || data.support === 'Mixto'))">
                                    <button
                                        v-if="!data.digital_file_base64"
                                        type="button"
                                        class="create-box-outline"
                                        title="Adjuntar PDF"
                                        @click="openNewDocumentFilePicker(data.id, 'main')"
                                    >
                                        <i class="pi pi-upload"></i>
                                    </button>
                                    <input
                                        :id="`newDocumentFile-main-${data.id}`"
                                        type="file"
                                        accept=".pdf"
                                        class="archive-doc-form__file-input"
                                        @change="onNewDocumentFileSelect(data, $event)"
                                    />
                                </template>
                                <button type="button" class="create-box-remove" @click="removeDocument(data)">
                                    <i class="pi pi-trash"></i>
                                </button>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </section>

            <footer class="create-box-footer">
                <button type="button" class="create-box-cancel" :disabled="isSaving" @click="router.push('/archivo-general/gestion')">
                    Cancelar
                </button>
                <button type="button" class="create-box-primary create-box-primary--large" :disabled="isSaving" @click="submitBox">
                    {{ isSaving ? 'Creando...' : 'Crear Caja' }}
                </button>
            </footer>
        </main>

        <Dialog
            v-model:visible="addDocumentDialogVisible"
            modal
            :draggable="false"
            class="archive-doc-dialog"
            :style="{ width: 'min(1024px, 94vw)' }"
            :contentStyle="{ maxHeight: 'calc(90vh - 11rem)', overflowY: 'auto' }"
        >
            <template #header>
                <div>
                    <h2>Documentos de la Caja {{ form.box_code || '---' }}</h2>
                </div>
            </template>

            <section class="archive-doc-form">
                <h3>Agregar Documento</h3>

                <label class="create-box-field">
                    <span>Serie / Subseries <b>*</b></span>
                    <Dropdown
                        v-model="docForm.classification_id"
                        :options="selectedSeriesOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Seleccionar subserie documental"
                        class="w-full"
                    />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Código del Documento <b>*</b></span>
                        <InputText v-model="docForm.code" placeholder="Ej: EXP-2024-001" class="w-full" />
                    </label>
                    <label>
                        <span>Año <b>*</b></span>
                        <InputText
                            v-model="docForm.year"
                            inputmode="numeric"
                            maxlength="4"
                            :max="currentYear"
                            placeholder="Ej: 2024"
                            class="w-full"
                            @input="onDocumentYearInput"
                        />
                    </label>
                </div>

                <label class="create-box-field">
                    <span>Nombre del Documento <b>*</b></span>
                    <InputText v-model="docForm.name" placeholder="Ej: Expediente Academico de Juan Perez" class="w-full" />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Tipo de Soporte <b>*</b></span>
                        <Dropdown v-model="docForm.support" :options="supportOptions" optionLabel="label" optionValue="value" placeholder="Seleccionar" class="w-full" />
                    </label>
                    <label>
                        <span>N de Paginas <b>*</b></span>
                        <InputText
                            v-model="docForm.pages"
                            inputmode="numeric"
                            placeholder="Ej: 150"
                            class="w-full"
                            @input="onDocumentPagesInput"
                        />
                    </label>
                </div>

                <div v-if="requiresDigitalDocFile" class="archive-doc-form__digital-file">
                    <input
                        ref="documentFileInputRef"
                        type="file"
                        accept=".pdf"
                        class="archive-doc-form__file-input"
                        @change="onDocumentDigitalFileSelect"
                    />
                    <button
                        type="button"
                        class="archive-doc-form__file-button"
                        @click="documentFileInputRef?.click()"
                    >
                        <i class="pi pi-upload"></i>
                        <span>{{ documentDigitalFileName || 'Seleccionar PDF' }}</span>
                    </button>
                    <button
                        v-if="documentDigitalFile"
                        type="button"
                        class="archive-doc-form__file-button"
                        @click="previewDocumentFile"
                    >
                        <i class="pi pi-eye"></i>
                        <span>Ver PDF</span>
                    </button>
                    <small>{{ PDF_UPLOAD_HINT }}</small>
                </div>

                <div class="archive-doc-form__actions">
                    <button type="button" class="create-box-primary create-box-primary--full" @click="addDocument">
                        <i class="pi pi-plus"></i>
                        Agregar Documento
                    </button>
                </div>
            </section>

            <section v-if="documents.length > 0" class="archive-doc-added">
                <h3>Documentos Agregados ({{ documents.length }})</h3>

                <DataTable
                    :value="documents"
                    scrollable
                    scrollHeight="260px"
                    responsiveLayout="scroll"
                    class="archive-doc-added__table"
                >
                    <Column field="series" header="Serie" />
                    <Column field="code" header="Código" />
                    <Column field="name" header="Nombre" />
                    <Column field="year" header="Año" />
                    <Column field="support" header="Soporte" />
                    <Column field="pages" header="N.° de páginas" />
                    <Column header="Acciones">
                        <template #body="{ data }">
                            <div class="create-box-doc-actions">
                                <button
                                    v-if="(data.support === 'Digital' || data.support === 'Mixto') && data.digital_file_base64"
                                    type="button"
                                    class="create-box-outline"
                                    @click="previewStoredDocument(data)"
                                >
                                    <i class="pi pi-eye"></i>
                                </button>
                                <template v-if="data.support === 'Digital' || data.support === 'Mixto'">
                                    <button
                                        v-if="!data.digital_file_base64"
                                        type="button"
                                        class="create-box-outline"
                                        title="Adjuntar PDF"
                                        @click="openNewDocumentFilePicker(data.id, 'modal')"
                                    >
                                        <i class="pi pi-upload"></i>
                                    </button>
                                    <input
                                        :id="`newDocumentFile-modal-${data.id}`"
                                        type="file"
                                        accept=".pdf"
                                        class="archive-doc-form__file-input"
                                        @change="onNewDocumentFileSelect(data, $event)"
                                    />
                                </template>
                                <button type="button" class="create-box-remove" @click="removeDocument({ ...data, source: 'manual' })">
                                    <i class="pi pi-trash"></i>
                                </button>
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </section>

            <template #footer>
                <button type="button" class="create-box-cancel" @click="addDocumentDialogVisible = false">
                    Cancelar
                </button>
                <button type="button" class="create-box-primary" @click="addDocumentDialogVisible = false">
                    Guardar Documentos
                </button>
            </template>
        </Dialog>

        <Dialog v-model:visible="importDialogVisible" modal :draggable="false" header="Importar documentos desde Excel" :style="{ width: 'min(560px, 94vw)' }">
            <div class="archive-import-box">
                <p>Use la plantilla vigente para importar documentos a esta caja.</p>
                <div class="archive-import-validation">
                    <strong>Validaciones aplicadas:</strong>
                    <ul>
                        <li>Nombre, código, año y soporte son obligatorios.</li>
                        <li>La serie o subserie debe pertenecer a esta caja.</li>
                        <li>El año debe estar entre {{ getYearString(form.start_year) }} y {{ getYearString(form.end_year) }}.</li>
                        <li>No se permiten códigos repetidos dentro de la caja.</li>
                        <li>Los PDF de documentos Digitales o Mixtos se adjuntan después de importar.</li>
                    </ul>
                </div>
                <button type="button" class="create-box-outline" @click="downloadTemplate">
                    Descargar plantilla
                </button>
                <button
                    type="button"
                    :class="['archive-import-dropzone', { 'archive-import-dropzone--active': isExcelDragging, 'archive-import-dropzone--selected': selectedExcelFileName }]"
                    @click="openExcelFilePicker"
                    @dragenter.prevent="isExcelDragging = true"
                    @dragover.prevent="isExcelDragging = true"
                    @dragleave.prevent="isExcelDragging = false"
                    @drop.prevent="onExcelDrop"
                >
                    <span class="archive-import-dropzone__icon">
                        <i class="pi pi-file-excel"></i>
                    </span>
                    <span class="archive-import-dropzone__title">
                        {{ selectedExcelFileName || 'Arrastra aqui tu archivo Excel' }}
                    </span>
                    <span class="archive-import-dropzone__hint">
                        {{ selectedExcelFileName ? 'Archivo listo para importar' : 'o haz clic para seleccionarlo desde tu equipo' }}
                    </span>
                    <span class="archive-import-dropzone__format">Formato permitido: .xlsx</span>
                </button>
                <input
                    ref="excelInputRef"
                    id="archiveExcelImport"
                    type="file"
                    accept=".xlsx"
                    class="archive-import-input"
                    @change="onExcelFileSelect"
                />
            </div>

            <template #footer>
                <button type="button" class="create-box-cancel" @click="importDialogVisible = false">
                    Cancelar
                </button>
                <button type="button" class="create-box-primary" :disabled="isImporting" @click="importDocuments">
                    {{ isImporting ? 'Importando...' : 'Importar' }}
                </button>
            </template>
        </Dialog>

        <DocumentPreviewDialog
            v-model:visible="documentPreviewVisible"
            :title="documentPreviewTitle"
            :source="documentPreviewSource"
            @hide="restoreAddDocumentDialog"
        />

        <Dialog v-model:visible="searchDialogVisible" modal :draggable="false" header="Buscar documentos existentes" :style="{ width: 'min(900px, 94vw)' }">
            <form class="archive-existing-search" @submit.prevent="searchExistingDocuments">
                <InputText v-model="documentSearch" placeholder="Buscar por código, nombre, serie, caja o ubicación..." class="w-full" />
                <button type="submit" class="create-box-primary" :disabled="isSearchingDocuments">
                    {{ isSearchingDocuments ? 'Buscando...' : 'Buscar' }}
                </button>
                <button
                    type="button"
                    class="create-box-outline"
                    :disabled="isSearchingDocuments || (!documentSearch && documentSearchResults.length === 0)"
                    @click="documentSearch = ''; documentSearchResults = []"
                >
                    Limpiar
                </button>
            </form>

            <DataTable :value="documentSearchResults" responsiveLayout="scroll" class="archive-existing-table">
                <Column field="series" header="Serie" />
                <Column field="code" header="Código" />
                <Column field="title" header="Nombre" />
                <Column header="Caja Actual">
                    <template #body="{ data }">
                        #{{ data.box?.number ?? 'N/D' }} - {{ data.box?.location ?? 'Sin ubicación' }}
                    </template>
                </Column>
                <Column header="Acción">
                    <template #body="{ data }">
                        <button type="button" class="create-box-outline" @click="addExistingDocument(data)">
                            Mover
                        </button>
                    </template>
                </Column>
                <template #empty>
                    <div class="create-box-empty create-box-empty--small">No hay resultados para mostrar.</div>
                </template>
            </DataTable>
        </Dialog>

        <Dialog
            v-model:visible="labelDialogVisible"
            modal
            :draggable="false"
            :closable="false"
            :showHeader="false"
            :contentStyle="{ padding: '0', overflow: 'hidden' }"
            :style="{ width: 'min(900px, 96vw)', maxWidth: '900px', maxHeight: '80vh' }"
        >
            <div class="detalle-labels-modal">
                <div class="detalle-labels-modal__header">
                    <div class="flex align-items-start gap-3">
                        <div class="detalle-labels-modal__title-icon">
                            <i class="pi pi-box"></i>
                        </div>
                        <div>
                            <h2 class="detalle-labels-modal__title m-0">Etiquetas de Identificacion</h2>
                            <p class="detalle-labels-modal__subtitle m-0">
                                Vista previa de 1 etiqueta para la caja {{ form.box_code || '---' }}
                            </p>
                        </div>
                    </div>

                    <div class="detalle-labels-modal__actions">
                        <button type="button" class="detalle-labels-modal__button detalle-labels-modal__button--ghost" :disabled="labelsBusy" @click="downloadLabelsPdf">
                            <i class="pi pi-download"></i>
                            <span>{{ labelsBusy ? 'Generando PDF...' : 'Descargar PDF' }}</span>
                        </button>
                        <button type="button" class="detalle-labels-modal__button detalle-labels-modal__button--primary" @click="printLabels">
                            <i class="pi pi-print"></i>
                            <span>Imprimir</span>
                        </button>
                        <button type="button" class="detalle-labels-modal__close" @click="labelDialogVisible = false">
                            <i class="pi pi-times"></i>
                        </button>
                    </div>
                </div>

                <div class="detalle-labels-modal__body">
                    <div
                        ref="labelPreviewRef"
                        class="detalle-labels-preview"
                        :style="{ overflow: 'auto', maxHeight: 'calc(80vh - 140px)', padding: '1rem' }"
                    >
                        <article v-if="labelPreviewItem" ref="labelPreviewCard" class="transfer-label">
                            <div class="transfer-label__header">
                                <div>
                                    <div class="transfer-label__university">UNIVERSIDAD DE EL SALVADOR</div>
                                    <div class="transfer-label__faculty">Facultad de Ingenieria y Arquitectura - UGDA</div>
                                </div>
                                <div class="transfer-label__date">
                                    <span>Fecha de transferencia</span>
                                    <strong>{{ labelPreviewItem.transferDate }}</strong>
                                </div>
                            </div>

                            <div class="transfer-label__hero">
                                <div class="transfer-label__qr">
                                    <img :src="labelPreviewItem.qrDataUrl" :alt="`Código QR ${labelPreviewItem.labelCode}`" />
                                    <span>Código: {{ labelPreviewItem.labelCode }}</span>
                                </div>

                                <div class="transfer-label__hero-main">
                                    <div class="transfer-label__location">
                                        <span>CÓDIGO DE UBICACIÓN</span>
                                        <strong>{{ labelPreviewItem.locationCode }}</strong>
                                    </div>

                                    <div class="transfer-label__box">
                                        <span>N de caja</span>
                                        <strong>{{ labelPreviewItem.boxLabel }}</strong>
                                    </div>
                                </div>
                            </div>

                            <div class="transfer-label__details">
                                <div>
                                    <span>N° de transferencia</span>
                                    <strong>{{ labelPreviewItem.transferNumber }}</strong>
                                </div>
                                <div>
                                    <span>Unidad productora</span>
                                    <strong>{{ labelPreviewItem.unit }}</strong>
                                </div>
                                <div class="transfer-label__details--full">
                                    <span>Contenido de la caja</span>
                                    <strong>{{ labelPreviewItem.content }}</strong>
                                </div>
                            </div>

                            <div class="transfer-label__assigned">
                                Ubicación asignada por {{ labelPreviewItem.assignedBy }} el {{ labelPreviewItem.assignedAt }}
                            </div>

                            <div class="transfer-label__footer">
                                Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicación.
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        </Dialog>
    </div>
</template>

<style scoped>
.create-box-page {
    background: #f8fafc;
    color: #0f172a;
    margin: -1.5rem;
    min-height: 100vh;
}

.create-box-header {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid #dbe3ed;
    display: flex;
    gap: 1rem;
    min-height: 5.25rem;
    padding: 0 2rem;
}

.create-box-back {
    align-items: center;
    background: transparent;
    border: 0;
    color: #0f4c8f;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 0.55rem;
    padding: 0;
}

.create-box-separator {
    background: #dbe3ed;
    height: 1.6rem;
    width: 1px;
}

.create-box-header h1 {
    font-size: 1.55rem;
    font-weight: 600;
    margin: 0;
}

.create-box-main {
    margin: 0 auto;
    max-width: 1140px;
    padding: 2rem;
}

.create-box-section {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.65rem;
    margin-bottom: 1.5rem;
    padding: 2rem;
}

.create-box-section h2 {
    font-size: 1.28rem;
    margin: 0 0 1.8rem;
}

.create-box-grid {
    display: grid;
    gap: 1.5rem;
}

.create-box-grid--two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.create-box-grid--three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.create-box-grid--four {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.create-box-field,
.create-box-grid label {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.create-box-field {
    margin: 1.5rem 0;
}

.create-box-field span,
.create-box-grid span {
    color: #0f172a;
    font-size: 0.9rem;
}

.create-box-field b,
.create-box-grid b {
    color: #dc2626;
}

.create-box-location {
    align-items: center;
    background: #eff6ff;
    border: 1px solid #0f4c8f;
    border-radius: 0.45rem;
    display: flex;
    justify-content: space-between;
    margin-top: 1.5rem;
    padding: 1rem;
}

.create-box-location span {
    color: #526477;
    display: block;
    font-size: 0.88rem;
    margin-bottom: 0.45rem;
}

.create-box-location strong {
    color: #0f4c8f;
    font-size: 1.2rem;
}

.create-box-location button,
.create-box-primary,
.create-box-outline,
.create-box-cancel {
    align-items: center;
    border-radius: var(--ugda-radius-md);
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 0.55rem;
    justify-content: center;
    min-height: 2.7rem;
    padding: 0 1rem;
}

.create-box-primary,
.create-box-location button {
    background: var(--ugda-brand);
    border: 1px solid var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.create-box-primary--large {
    min-width: 7.6rem;
}

.create-box-primary--full {
    width: 100%;
}

.create-box-outline,
.create-box-cancel {
    background: var(--ugda-surface-card);
    border: 1px solid var(--ugda-border-strong);
    color: var(--ugda-text-secondary);
}

.create-box-cancel {
    border-color: var(--ugda-border);
    color: var(--ugda-text-primary);
}

.create-box-primary:disabled,
.create-box-cancel:disabled {
    cursor: not-allowed;
}

.create-box-documents-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.4rem;
}

.create-box-documents-head h2 {
    margin: 0;
}

.create-box-documents-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    justify-content: flex-end;
}

.create-box-doc-actions {
    align-items: center;
    display: inline-flex;
    flex-wrap: nowrap;
    gap: 0.5rem;
    justify-content: center;
    white-space: nowrap;
    width: max-content;
}

.create-box-doc-actions .create-box-outline,
.create-box-doc-actions .create-box-remove {
    flex: 0 0 auto;
}

.create-box-info {
    align-items: center;
    background: #eff6ff;
    border: 1px solid #b7d3f4;
    border-radius: 0.45rem;
    color: #526477;
    display: flex;
    gap: 0.8rem;
    margin-bottom: 1rem;
    padding: 0.9rem 1rem;
}

.create-box-info i {
    color: #0f4c8f;
}

.create-box-empty {
    align-items: center;
    border: 2px dashed #d8e1ec;
    border-radius: 0.55rem;
    color: #64748b;
    display: flex;
    justify-content: center;
    min-height: 7.8rem;
    padding: 1rem;
    text-align: center;
}

.create-box-empty--small {
    border: 0;
    min-height: 4rem;
}

.create-box-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    padding: 0 0 2rem;
}

.create-box-remove {
    align-items: center;
    background: transparent;
    border: 0;
    color: #dc2626;
    cursor: pointer;
    display: inline-flex;
    height: 2rem;
    justify-content: center;
    width: 2rem;
}

.archive-doc-dialog :deep(.p-dialog-header) {
    background: #2f6f9f;
    color: #ffffff;
}

.archive-doc-dialog :deep(.p-dialog-header h2) {
    color: #ffffff;
    font-size: 1.2rem;
    margin: 0;
}

.archive-doc-dialog :deep(.p-dialog-header p) {
    color: #ffffff;
    font-size: 0.9rem;
    margin: 0.45rem 0 0;
}

.archive-doc-form {
    background: #f8fafc;
    border-radius: 0.6rem;
    padding: 1rem;
}

.archive-doc-form h3 {
    margin: 0 0 1.2rem;
}

.archive-doc-form__digital-file {
    margin-top: 1rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.archive-doc-form__file-input {
    display: none;
}

.archive-doc-form__file-button {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: var(--ugda-radius-md);
    color: #0f172a;
    cursor: pointer;
    display: inline-flex;
    font-size: 0.95rem;
    gap: 0.5rem;
    justify-content: center;
    min-height: 2.7rem;
    max-width: 100%;
    padding: 0 1rem;
}

.archive-doc-form__file-button span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.archive-doc-form__file-button:hover {
    border-color: #0f4c8f;
    color: #0f4c8f;
}

.archive-doc-form__actions {
    margin-top: 1.25rem;
}

.archive-doc-added {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.6rem;
    margin-top: 1.5rem;
    overflow: hidden;
}

.archive-doc-added h3 {
    border-bottom: 1px solid #d8e1ec;
    font-size: 0.95rem;
    margin: 0;
    padding: 1rem;
}

.archive-doc-added__table :deep(.p-datatable-header) {
    display: none;
}

.archive-doc-added__table :deep(.p-datatable-thead > tr > th),
.archive-doc-added__table :deep(.p-datatable-tbody > tr > td) {
    font-size: 0.88rem;
    padding: 0.75rem 1rem;
}

.archive-doc-added__table :deep(.p-datatable-thead > tr > th) {
    background: #f8fafc;
    color: #0f172a;
    font-weight: 700;
}

.archive-import-box {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.archive-import-validation {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 0.6rem;
    color: #1e3a5f;
    font-size: 0.88rem;
    padding: 0.85rem 1rem;
}

.archive-import-validation strong {
    display: block;
    margin-bottom: 0.35rem;
}

.archive-import-validation ul {
    margin: 0;
    padding-left: 1.15rem;
}

.archive-import-validation li + li {
    margin-top: 0.2rem;
}

.archive-import-dropzone {
    align-items: center;
    background: #f8fbff;
    border: 2px dashed #b7d3f4;
    border-radius: 0.75rem;
    color: #334155;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    min-height: 11rem;
    padding: 1.5rem;
    text-align: center;
    transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

.archive-import-dropzone:hover,
.archive-import-dropzone--active {
    background: #eff6ff;
    border-color: #0f4c8f;
    box-shadow: 0 0 0 0.2rem rgba(15, 76, 143, 0.08);
}

.archive-import-dropzone--selected {
    background: #f0fdf4;
    border-color: #16a34a;
}

.archive-import-dropzone__icon {
    align-items: center;
    background: #e8f1fb;
    border-radius: 999px;
    color: #0f4c8f;
    display: inline-flex;
    font-size: 1.45rem;
    height: 3.25rem;
    justify-content: center;
    width: 3.25rem;
}

.archive-import-dropzone--selected .archive-import-dropzone__icon {
    background: #dcfce7;
    color: #15803d;
}

.archive-import-dropzone__title {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 700;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.archive-import-dropzone__hint {
    color: #526477;
    font-size: 0.9rem;
}

.archive-import-dropzone__format {
    color: #64748b;
    font-size: 0.78rem;
    margin-top: 0.2rem;
}

.archive-import-input {
    display: none;
}

.archive-existing-search {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(0, 1fr) auto;
    margin-bottom: 1rem;
}

.detalle-labels-modal {
    background: #ffffff;
}

.detalle-labels-modal__header {
    align-items: flex-start;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: 1.5rem 1.5rem 1rem;
}

.detalle-labels-modal__title-icon {
    align-items: center;
    background: #edf3fa;
    border-radius: 0.9rem;
    color: #2f6f9f;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.1rem;
    height: 2.75rem;
    justify-content: center;
    width: 2.75rem;
}

.detalle-labels-modal__title {
    color: #2f6f9f;
    font-size: 2rem;
    font-weight: 700;
}

.detalle-labels-modal__subtitle {
    color: #64748b;
    font-size: 1rem;
    margin-top: 0.7rem;
}

.detalle-labels-modal__actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.detalle-labels-modal__button,
.detalle-labels-modal__close {
    align-items: center;
    border-radius: 0.9rem;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 0.6rem;
    justify-content: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.detalle-labels-modal__button:hover,
.detalle-labels-modal__close:hover {
    transform: translateY(-1px);
}

.detalle-labels-modal__button {
    font-size: 0.98rem;
    font-weight: 700;
    min-height: 3rem;
    padding: 0.8rem 1.15rem;
}

.detalle-labels-modal__button:disabled {
    cursor: wait;
}

.detalle-labels-modal__button--ghost {
    background: #ffffff;
    border: 1px solid #2f6f9f;
    color: #2f6f9f;
}

.detalle-labels-modal__button--primary {
    background: var(--ugda-success);
    border: 1px solid var(--ugda-success);
    color: var(--ugda-text-inverse);
}

.detalle-labels-modal__button--primary:hover:not(:disabled) {
    background: var(--ugda-success-text);
    border-color: var(--ugda-success-text);
    color: var(--ugda-text-inverse);
}

.detalle-labels-modal__close {
    background: transparent;
    border: none;
    color: #64748b;
    font-size: 1rem;
    height: 2.6rem;
    width: 2.6rem;
}

.detalle-labels-modal__body {
    margin-bottom: 1.5rem;
    padding: 0 1.5rem 1.5rem;
}

.detalle-labels-preview {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-height: calc(100vh - 12.5rem);
    overflow-x: auto;
    overflow-y: auto;
    padding: 0.25rem 0 1rem;
}

.transfer-label {
    background: #ffffff;
    border: 2px solid #1f2937;
    border-radius: 0.5cm;
    display: flex;
    flex-direction: column;
    height: 18cm;
    margin-bottom: 0;
    max-width: none;
    min-height: 18cm;
    overflow: hidden;
    width: 10cm;
}

.transfer-label--export {
    height: 18cm !important;
    margin-bottom: 0 !important;
    max-width: none !important;
    min-height: 18cm !important;
    width: 10cm !important;
}

.transfer-label--export .transfer-label__footer {
    margin-top: auto;
}

.transfer-label__header,
.transfer-label__hero,
.transfer-label__details {
    display: grid;
    gap: 0.35cm;
}

.transfer-label__header {
    align-items: flex-start;
    border-bottom: 1px solid #dbe5ee;
    grid-template-columns: 1fr auto;
    padding: 0.58cm 0.55cm 0.36cm;
}

.transfer-label__university {
    color: #0f172a;
    font-size: 0.44cm;
    font-weight: 700;
    line-height: 1.15;
}

.transfer-label__faculty {
    color: #64748b;
    font-size: 0.28cm;
    line-height: 1.25;
    margin-top: 0.12cm;
}

.transfer-label__date {
    min-width: 2.25cm;
    text-align: right;
}

.transfer-label__date span,
.transfer-label__box span,
.transfer-label__details span,
.transfer-label__assigned,
.transfer-label__qr span {
    color: #64748b;
    font-size: 0.25cm;
    line-height: 1.25;
}

.transfer-label__date strong {
    color: #0f172a;
    display: block;
    font-size: 0.34cm;
    line-height: 1.2;
    margin-top: 0.12cm;
}

.transfer-label__hero {
    align-items: center;
    border-bottom: 1px solid #dbe5ee;
    grid-template-columns: 1fr;
    padding: 0.42cm 0.55cm;
}

.transfer-label__qr {
    text-align: center;
}

.transfer-label__qr img {
    display: block;
    height: 2.8cm;
    margin: 0 auto 0.12cm;
    width: 2.8cm;
}

.transfer-label__hero-main {
    min-width: 0;
}

.transfer-label__location {
    background: #2f6f9f;
    border-radius: 0.32cm;
    color: #ffffff;
    padding: 0.34cm 0.36cm;
    text-align: center;
}

.transfer-label__location span {
    display: block;
    font-size: 0.25cm;
    font-weight: 700;
}

.transfer-label__location strong {
    display: block;
    font-size: 0.7cm;
    font-weight: 700;
    letter-spacing: 0;
    line-height: 1.05;
    margin-top: 0.14cm;
}

.transfer-label__box {
    background: #edf3fa;
    border-radius: 0.28cm;
    margin-top: 0.28cm;
    padding: 0.3cm 0.36cm;
}

.transfer-label__box strong {
    color: #0f172a;
    display: block;
    font-size: 0.58cm;
    line-height: 1.1;
    margin-top: 0.1cm;
}

.transfer-label__details {
    grid-template-columns: 1fr;
    padding: 0.42cm 0.55cm 0.24cm;
}

.transfer-label__details strong {
    color: #0f172a;
    display: block;
    font-size: 0.33cm;
    line-height: 1.25;
    margin-top: 0.08cm;
}

.transfer-label__assigned {
    border-top: 1px solid #dbe5ee;
    margin: 0 0.55cm;
    padding: 0.3cm 0;
}

.transfer-label__footer {
    background: #fff7db;
    color: #b45309;
    font-size: 0.24cm;
    font-weight: 700;
    line-height: 1.3;
    margin-top: auto;
    padding: 0.28cm 0.55cm;
}

@media (max-width: 991px) {
    .create-box-page {
        margin: -1rem;
    }

    .create-box-header {
        display: none;
    }

    .create-box-main {
        padding: 1rem;
    }

    .create-box-section {
        padding: 1.1rem;
    }

    .create-box-grid--two,
    .create-box-grid--three,
    .create-box-grid--four,
    .archive-existing-search {
        grid-template-columns: 1fr;
    }

    .create-box-location,
    .create-box-documents-head {
        align-items: stretch;
        flex-direction: column;
    }

    .create-box-documents-actions,
    .create-box-documents-actions button,
    .create-box-location button,
    .create-box-footer,
    .create-box-footer button {
        width: 100%;
    }

    .detalle-labels-modal__header,
    .detalle-labels-modal__body {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-labels-modal__header {
        flex-direction: column;
    }

    .detalle-labels-modal__actions {
        width: 100%;
    }

    .detalle-labels-modal__button {
        flex: 1 1 13rem;
    }

    .transfer-label {
        height: 18cm;
        min-height: 18cm;
        width: 10cm;
    }
}

@media (max-width: 540px) {
    .detalle-labels-modal__title {
        font-size: 1.5rem;
    }

    .detalle-labels-modal__subtitle {
        font-size: 0.92rem;
    }

    .detalle-labels-modal__actions {
        gap: 0.6rem;
    }

    .detalle-labels-modal__button {
        width: 100%;
    }

    .transfer-label__university {
        font-size: 1.08rem;
    }

    .transfer-label__location strong {
        font-size: 1.5rem;
    }

    .transfer-label__box strong {
        font-size: 1.35rem;
    }
}
</style>
