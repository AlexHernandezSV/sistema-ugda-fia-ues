<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import QRCode from 'qrcode';

const router = useRouter();
const toast = useToast();

const reassignmentType = ref('box');
const boxSearch = ref('');
const documentSearch = ref('');
const destinationBoxSearch = ref('');
const boxes = ref([]);
const documents = ref([]);
const destinationBoxes = ref([]);
const selectedBoxId = ref(null);
const selectedBoxRecord = ref(null);
const selectedDocumentId = ref(null);
const selectedDocumentRecord = ref(null);
const selectedDestinationBoxId = ref(null);
const selectedDestinationBoxRecord = ref(null);
const confirmBoxDialogVisible = ref(false);
const confirmDocumentDialogVisible = ref(false);
const labelDialogVisible = ref(false);
const reassignedLabel = ref(null);
const isLoading = ref(false);
const isSearching = ref(false);
const isSearchingDocuments = ref(false);
const isSearchingDestinationBoxes = ref(false);
const isSaving = ref(false);
const rowsPerPageOptions = [5, 10, 25, 50];
const boxPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});
const documentPagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});
const locations = ref({
    offices: [],
    aisles: [],
    shelves: [],
});
const locationForm = ref({
    office_id: null,
    aisle_id: null,
    shelf_id: null,
});

const selectedBox = computed(() => selectedBoxRecord.value ?? boxes.value.find((box) => box.id === selectedBoxId.value) ?? null);
const selectedDocument = computed(() => selectedDocumentRecord.value ?? documents.value.find((document) => document.id === selectedDocumentId.value) ?? null);
const availableDestinationBoxes = computed(() => (
    destinationBoxes.value.filter((box) => box.id !== selectedDocument.value?.box?.id)
));
const selectedDestinationBox = computed(() => (
    selectedDestinationBoxRecord.value ?? availableDestinationBoxes.value.find((box) => box.id === selectedDestinationBoxId.value) ?? null
));
const filteredAisles = computed(() => (
    locations.value.aisles.filter((item) => item.office_id === locationForm.value.office_id)
));
const filteredShelves = computed(() => (
    locations.value.shelves.filter((item) => item.aisle_id === locationForm.value.aisle_id)
));
const generatedLocationCode = computed(() => {
    const office = locations.value.offices.find((item) => item.id === locationForm.value.office_id);
    const aisle = locations.value.aisles.find((item) => item.id === locationForm.value.aisle_id);
    const shelf = locations.value.shelves.find((item) => item.id === locationForm.value.shelf_id);

    return [office?.code, aisle?.code, shelf?.code].filter(Boolean).join('-');
});
const getVisiblePages = (pagination) => {
    const totalPages = pagination.last_page || 1;
    const currentPage = pagination.current_page || 1;
    const start = Math.max(1, currentPage - 1);
    const end = Math.min(totalPages, start + 2);
    const normalizedStart = Math.max(1, end - 2);
    const pages = [];

    for (let page = normalizedStart; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
};
const visibleBoxPages = computed(() => getVisiblePages(boxPagination.value));
const visibleDocumentPages = computed(() => getVisiblePages(documentPagination.value));

const getBoxCode = (box) => box?.box_code || box?.boxCode || box?.code || null;
const displayBoxCode = (box) => getBoxCode(box) || (box?.number ? `Caja #${box.number}` : '---');

let boxSearchTimeout = null;
let documentSearchTimeout = null;
let destinationBoxSearchTimeout = null;

watch(() => locationForm.value.office_id, () => {
    if (!filteredAisles.value.some((item) => item.id === locationForm.value.aisle_id)) {
        locationForm.value.aisle_id = null;
        locationForm.value.shelf_id = null;
    }
});

watch(() => locationForm.value.aisle_id, () => {
    if (!filteredShelves.value.some((item) => item.id === locationForm.value.shelf_id)) {
        locationForm.value.shelf_id = null;
    }
});

watch(reassignmentType, () => {
    resetBoxReassignment();
    resetDocumentReassignment();
});

const showToast = (severity, summary, detail) => {
    toast.add({ severity, summary, detail, life: severity === 'error' ? 4200 : 3000 });
};

const currentDateLabel = () => {
    const now = new Date();
    const day = String(now.getDate()).padStart(2, '0');
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const year = now.getFullYear();

    return `${day}/${month}/${year}`;
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

const buildReassignedLabel = async (box) => {
    const boxLabel = getBoxCode(box)
        || getBoxCode(selectedBox.value)
        || `C-AG-${String(Number(box.box_number ?? box.number) || box.number || 0).padStart(3, '0')}`;
    const locationCode = box.location ?? generatedLocationCode.value;

    return {
        boxLabel,
        labelCode: boxLabel,
        transferNumber: 'Archivo General',
        transferDate: currentDateLabel(),
        unit: box.units_label ?? box.unit?.name ?? selectedBox.value?.units_label ?? selectedBox.value?.unit?.name ?? 'Unidad no registrada',
        content: box.series ?? selectedBox.value?.series ?? 'Serie documental no registrada',
        period: box.period ?? selectedBox.value?.period ?? 'Periodo no definido',
        locationCode,
        assignedBy: 'UGDA',
        assignedAt: currentDateLabel(),
        qrDataUrl: await buildQrDataUrl(JSON.stringify({
            type: 'archivo-general-box-reassigned',
            box: boxLabel,
            location: locationCode,
        })),
    };
};

const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const printReassignedLabel = () => {
    if (!reassignedLabel.value) {
        return;
    }

    const label = reassignedLabel.value;
    const popup = window.open('', '_blank', 'width=720,height=900');

    if (!popup) {
        showToast('warn', 'Ventana bloqueada', 'Permita ventanas emergentes para imprimir la etiqueta.');
        return;
    }

    popup.document.open();
    popup.document.write(`
        <!DOCTYPE html>
        <html lang="es">
            <head>
                <meta charset="utf-8" />
                <title>Etiqueta ${escapeHtml(label.labelCode)}</title>
                <style>
                    * { box-sizing: border-box; }
                    @page { size: 10cm 18cm; margin: 0; }
                    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #0f172a; }
                    .transfer-label { background: #ffffff; border: 2px solid #1f2937; border-radius: 0.5cm; display: flex; flex-direction: column; height: 18cm; overflow: hidden; width: 10cm; }
                    .transfer-label__header { align-items: flex-start; border-bottom: 1px solid #dbe5ee; display: grid; grid-template-columns: 1fr auto; padding: 0.58cm 0.55cm 0.36cm; }
                    .transfer-label__university { font-size: 0.44cm; font-weight: 700; line-height: 1.15; }
                    .transfer-label__faculty, .transfer-label__date span, .transfer-label__box span, .transfer-label__details span, .transfer-label__assigned, .transfer-label__qr span { color: #64748b; font-size: 0.25cm; line-height: 1.25; }
                    .transfer-label__date { min-width: 2.25cm; text-align: right; }
                    .transfer-label__date strong { display: block; font-size: 0.34cm; margin-top: 0.12cm; }
                    .transfer-label__hero { align-items: center; border-bottom: 1px solid #dbe5ee; display: grid; grid-template-columns: 1fr; padding: 0.42cm 0.55cm; }
                    .transfer-label__qr { text-align: center; }
                    .transfer-label__qr img { display: block; height: 2.8cm; margin: 0 auto 0.12cm; width: 2.8cm; }
                    .transfer-label__location { background: #2f6f9f; border-radius: 0.32cm; color: #ffffff; padding: 0.34cm 0.36cm; text-align: center; }
                    .transfer-label__location span { display: block; font-size: 0.25cm; font-weight: 700; }
                    .transfer-label__location strong { display: block; font-size: 0.7cm; line-height: 1.05; margin-top: 0.14cm; }
                    .transfer-label__box { background: #edf3fa; border-radius: 0.28cm; margin-top: 0.28cm; padding: 0.3cm 0.36cm; }
                    .transfer-label__box strong { display: block; font-size: 0.58cm; line-height: 1.1; margin-top: 0.1cm; }
                    .transfer-label__details { display: grid; gap: 0.35cm; padding: 0.42cm 0.55cm 0.24cm; }
                    .transfer-label__details strong { display: block; font-size: 0.33cm; line-height: 1.25; margin-top: 0.08cm; }
                    .transfer-label__assigned { border-top: 1px solid #dbe5ee; margin: 0 0.55cm; padding: 0.3cm 0; }
                    .transfer-label__footer { background: #fff7db; color: #b45309; font-size: 0.24cm; font-weight: 700; line-height: 1.3; margin-top: auto; padding: 0.28cm 0.55cm; }
                </style>
            </head>
            <body>
                <article class="transfer-label">
                    <div class="transfer-label__header">
                        <div>
                            <div class="transfer-label__university">UNIVERSIDAD DE EL SALVADOR</div>
                            <div class="transfer-label__faculty">Facultad de Ingenieria y Arquitectura - UGDA</div>
                        </div>
                        <div class="transfer-label__date"><span>Fecha</span><strong>${escapeHtml(label.transferDate)}</strong></div>
                    </div>
                    <div class="transfer-label__hero">
                        <div class="transfer-label__qr"><img src="${label.qrDataUrl}" alt="Código QR ${escapeHtml(label.labelCode)}" /><span>Código: ${escapeHtml(label.labelCode)}</span></div>
                        <div>
                            <div class="transfer-label__location"><span>CÓDIGO DE UBICACIÓN</span><strong>${escapeHtml(label.locationCode)}</strong></div>
                            <div class="transfer-label__box"><span>N de caja</span><strong>${escapeHtml(label.boxLabel)}</strong></div>
                        </div>
                    </div>
                    <div class="transfer-label__details">
                        <div><span>N de transferencia</span><strong>${escapeHtml(label.transferNumber)}</strong></div>
                        <div><span>Unidad productora</span><strong>${escapeHtml(label.unit)}</strong></div>
                        <div><span>Contenido de la caja</span><strong>${escapeHtml(label.content)}</strong></div>
                    </div>
                    <div class="transfer-label__assigned">Ubicación reasignada por ${escapeHtml(label.assignedBy)} el ${escapeHtml(label.assignedAt)}</div>
                    <div class="transfer-label__footer">Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicación.</div>
                </article>
                <script>window.onload = () => { window.focus(); window.print(); };<\/script>
            </body>
        </html>
    `);
    popup.document.close();
};

const fetchLocations = async () => {
    isLoading.value = true;

    try {
        const { data } = await axios.get('/api/physical-locations/options');
        locations.value = {
            offices: Array.isArray(data.offices) ? data.offices : [],
            aisles: Array.isArray(data.aisles) ? data.aisles : [],
            shelves: Array.isArray(data.shelves) ? data.shelves : [],
        };
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudo cargar el catalogo de ubicaciones.');
    } finally {
        isLoading.value = false;
    }
};

const resetBoxPagination = () => {
    boxPagination.value = {
        ...boxPagination.value,
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
    };
};

const fetchBoxSearch = async (page = 1, perPage = boxPagination.value.per_page) => {
    const term = boxSearch.value.trim();

    if (!term) {
        boxes.value = [];
        selectedBoxId.value = null;
        selectedBoxRecord.value = null;
        resetBoxPagination();
        return;
    }

    isSearching.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: { q: term, page, per_page: perPage },
        });

        boxes.value = Array.isArray(data.boxes) ? data.boxes : [];
        boxPagination.value = data.meta ?? boxPagination.value;
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudieron cargar las cajas registradas.');
    } finally {
        isSearching.value = false;
    }
};

const searchBoxes = () => {
    fetchBoxSearch(1, boxPagination.value.per_page);
};

const onBoxSearchInput = () => {
    selectedBoxId.value = null;
    selectedBoxRecord.value = null;
    boxes.value = [];
    resetBoxPagination();

    window.clearTimeout(boxSearchTimeout);
    boxSearchTimeout = window.setTimeout(searchBoxes, 250);
};

const goToBoxPage = (page) => {
    if (page >= 1 && page <= boxPagination.value.last_page && page !== boxPagination.value.current_page) {
        fetchBoxSearch(page, boxPagination.value.per_page);
    }
};

const previousBoxPage = () => {
    goToBoxPage(boxPagination.value.current_page - 1);
};

const nextBoxPage = () => {
    goToBoxPage(boxPagination.value.current_page + 1);
};

const changeBoxPageSize = () => {
    fetchBoxSearch(1, boxPagination.value.per_page);
};

const selectBox = (box) => {
    selectedBoxId.value = box.id;
    selectedBoxRecord.value = box;
    boxSearch.value = `${displayBoxCode(box)} - ${box.series}`;
    boxes.value = [];
};

const resetDocumentPagination = () => {
    documentPagination.value = {
        ...documentPagination.value,
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
    };
};

const resetBoxReassignment = () => {
    selectedBoxId.value = null;
    selectedBoxRecord.value = null;
    boxSearch.value = '';
    boxes.value = [];
    resetBoxPagination();
    locationForm.value = { office_id: null, aisle_id: null, shelf_id: null };
    confirmBoxDialogVisible.value = false;
};

const clearBoxSearch = () => {
    window.clearTimeout(boxSearchTimeout);
    resetBoxReassignment();
};

const fetchDocumentSearch = async (page = 1, perPage = documentPagination.value.per_page) => {
    const term = documentSearch.value.trim();

    if (!term) {
        documents.value = [];
        selectedDocumentId.value = null;
        selectedDocumentRecord.value = null;
        resetDocumentPagination();
        return;
    }

    isSearchingDocuments.value = true;

    try {
        const { data } = await axios.get('/api/archive/documents', {
            params: { q: term, page, per_page: perPage },
        });

        documents.value = Array.isArray(data.results) ? data.results : [];
        documentPagination.value = data.meta ?? documentPagination.value;
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudieron cargar los documentos.');
    } finally {
        isSearchingDocuments.value = false;
    }
};

const searchDocuments = () => {
    fetchDocumentSearch(1, documentPagination.value.per_page);
};

const onDocumentSearchInput = () => {
    selectedDocumentId.value = null;
    selectedDocumentRecord.value = null;
    selectedDestinationBoxId.value = null;
    selectedDestinationBoxRecord.value = null;
    destinationBoxSearch.value = '';
    destinationBoxes.value = [];
    documents.value = [];
    resetDocumentPagination();

    window.clearTimeout(documentSearchTimeout);
    documentSearchTimeout = window.setTimeout(searchDocuments, 250);
};

const goToDocumentPage = (page) => {
    if (page >= 1 && page <= documentPagination.value.last_page && page !== documentPagination.value.current_page) {
        fetchDocumentSearch(page, documentPagination.value.per_page);
    }
};

const previousDocumentPage = () => {
    goToDocumentPage(documentPagination.value.current_page - 1);
};

const nextDocumentPage = () => {
    goToDocumentPage(documentPagination.value.current_page + 1);
};

const changeDocumentPageSize = () => {
    fetchDocumentSearch(1, documentPagination.value.per_page);
};

const selectDocument = (document) => {
    selectedDocumentId.value = document.id;
    selectedDocumentRecord.value = document;
    documentSearch.value = `${document.title} - ${document.code}`;
    documents.value = [];
};

const resetDocumentReassignment = () => {
    selectedDocumentId.value = null;
    selectedDocumentRecord.value = null;
    selectedDestinationBoxId.value = null;
    selectedDestinationBoxRecord.value = null;
    documentSearch.value = '';
    destinationBoxSearch.value = '';
    documents.value = [];
    destinationBoxes.value = [];
    resetDocumentPagination();
    confirmDocumentDialogVisible.value = false;
};

const clearDocumentSearch = () => {
    window.clearTimeout(documentSearchTimeout);
    resetDocumentReassignment();
};

const searchDestinationBoxes = async () => {
    const term = destinationBoxSearch.value.trim();

    if (!term) {
        destinationBoxes.value = [];
        selectedDestinationBoxId.value = null;
        selectedDestinationBoxRecord.value = null;
        return;
    }

    isSearchingDestinationBoxes.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: { q: term },
        });

        destinationBoxes.value = Array.isArray(data.boxes) ? data.boxes : [];

        if (!availableDestinationBoxes.value.some((box) => box.id === selectedDestinationBoxId.value) && !selectedDestinationBoxRecord.value) {
            selectedDestinationBoxId.value = null;
        }
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudieron cargar las cajas de destino.');
    } finally {
        isSearchingDestinationBoxes.value = false;
    }
};

const onDestinationBoxSearchInput = () => {
    selectedDestinationBoxId.value = null;
    selectedDestinationBoxRecord.value = null;

    window.clearTimeout(destinationBoxSearchTimeout);
    destinationBoxSearchTimeout = window.setTimeout(searchDestinationBoxes, 250);
};

const clearDestinationBoxSearch = () => {
    window.clearTimeout(destinationBoxSearchTimeout);
    selectedDestinationBoxId.value = null;
    selectedDestinationBoxRecord.value = null;
    destinationBoxSearch.value = '';
    destinationBoxes.value = [];
};

const selectDestinationBox = (box) => {
    selectedDestinationBoxId.value = box.id;
    selectedDestinationBoxRecord.value = box;
    destinationBoxSearch.value = `${displayBoxCode(box)} - ${box.series}`;
    destinationBoxes.value = [];
};

const requestBoxLocationConfirmation = () => {
    if (!selectedBox.value) {
        showToast('error', 'Error', 'Seleccione la caja que desea reasignar.');
        return;
    }

    if (!locationForm.value.office_id || !locationForm.value.aisle_id || !locationForm.value.shelf_id) {
        showToast('error', 'Error', 'Complete la nueva ubicación física de la caja.');
        return;
    }

    // --- VALIDACIÓN NUEVA: Evitar reasignar a la misma ubicación ---
    const currentLocation = selectedBox.value.location;
    const newLocation = generatedLocationCode.value;

    if (currentLocation && currentLocation.trim() === newLocation.trim()) {
        showToast('error', 'Error', 'La nueva ubicación no puede ser la misma que la actual. Seleccione una ubicación diferente.');
        return;
    }
    // -------------------------------------------------------------

    confirmBoxDialogVisible.value = true;
};

const submitBoxLocation = async () => {
    if (!selectedBox.value) {
        showToast('error', 'Error', 'Seleccione la caja que desea reasignar.');
        return;
    }

    if (!locationForm.value.office_id || !locationForm.value.aisle_id || !locationForm.value.shelf_id) {
        showToast('error', 'Error', 'Complete la nueva ubicación física de la caja.');
        return;
    }

    isSaving.value = true;

    try {
        const { data } = await axios.put(`/api/archive/boxes/${selectedBox.value.id}/location`, {
            office_id: locationForm.value.office_id,
            aisle_id: locationForm.value.aisle_id,
            shelf_id: locationForm.value.shelf_id,
        });

        const movedBox = getBoxCode(data.box) || getBoxCode(selectedBox.value) || displayBoxCode(selectedBox.value);
        reassignedLabel.value = await buildReassignedLabel(data.box ?? selectedBox.value);
        labelDialogVisible.value = true;
        showToast('success', 'Caja movida', `Se movio la caja ${movedBox} correctamente.`);
        resetBoxReassignment();
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudo reasignar la ubicación.');
    } finally {
        isSaving.value = false;
    }
};

const requestDocumentReassignmentConfirmation = () => {
    if (!selectedDocument.value) {
        showToast('error', 'Error', 'Seleccione el documento que desea reasignar.');
        return;
    }

    if (!selectedDestinationBox.value) {
        showToast('error', 'Error', 'Seleccione la caja de destino.');
        return;
    }

    confirmDocumentDialogVisible.value = true;
};

const submitDocumentReassignment = async () => {
    if (!selectedDocument.value) {
        showToast('error', 'Error', 'Seleccione el documento que desea reasignar.');
        return;
    }

    if (!selectedDestinationBox.value) {
        showToast('error', 'Error', 'Seleccione la caja de destino.');
        return;
    }

    isSaving.value = true;

    try {
        const movedDocument = selectedDocument.value.title;
        const destinationBoxCode = displayBoxCode(selectedDestinationBox.value);
        const { data } = await axios.put(`/api/archive/documents/${selectedDocument.value.id}/box`, {
            box_id: selectedDestinationBox.value.id,
        });

        showToast('success', 'Documento movido', `Se movio el documento "${movedDocument}" a la caja ${destinationBoxCode}.`);
        resetDocumentReassignment();
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudo reasignar el documento.');
    } finally {
        isSaving.value = false;
    }
};

onMounted(async () => {
    await fetchLocations();
});
</script>

<template>
    <div class="reassign-page fadein animation-duration-500">
        <header class="reassign-header">
            <button type="button" class="reassign-back" @click="router.push('/archivo-general/gestion')">
                <i class="pi pi-arrow-left"></i>
                Volver
            </button>
            <span class="reassign-separator"></span>
            <h1>Reasignación de Cajas y Documentos</h1>
        </header>

        <main class="reassign-main">
            <section class="reassign-section">
                <h2>Tipo de Reasignación</h2>
                <div class="reassign-type-grid">
                    <button
                        type="button"
                        class="reassign-type"
                        :class="{ 'reassign-type--active': reassignmentType === 'box' }"
                        @click="reassignmentType = 'box'"
                    >
                        <span class="reassign-type__icon"><i class="pi pi-box"></i></span>
                        <span>
                            <strong>Reasignar Caja</strong>
                            <small>Cambiar ubicación física de una caja</small>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="reassign-type"
                        :class="{ 'reassign-type--active': reassignmentType === 'document' }"
                        @click="reassignmentType = 'document'"
                    >
                        <span class="reassign-type__icon"><i class="pi pi-file"></i></span>
                        <span>
                            <strong>Reasignar documento</strong>
                            <small>Mover documento a otra caja.</small>
                        </span>
                    </button>
                </div>
            </section>

            <template v-if="reassignmentType === 'box'">
                <section class="reassign-section">
                    <h2>Buscar caja</h2>
                    <form class="reassign-search" @submit.prevent="searchBoxes">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="boxSearch"
                            placeholder="Buscar por código de caja, número, serie o unidad productora..."
                            @input="onBoxSearchInput"
                        />
                        <button type="button" class="reassign-clear" @click="clearBoxSearch">
                            <i class="pi pi-filter-slash"></i>
                            Limpiar
                        </button>
                    </form>

                    <div v-if="isSearching" class="reassign-results-state">Buscando cajas...</div>
                    <div v-else-if="boxSearch.trim() && boxes.length" class="reassign-results-list">
                        <button
                            v-for="box in boxes"
                            :key="box.id"
                            type="button"
                            class="reassign-result-item"
                            @click="selectBox(box)"
                        >
                            <strong>{{ displayBoxCode(box) }}</strong>
                            <span>Serie: {{ box.series }}</span>
                            <span>Ubicación Actual: {{ box.location ?? 'Pendiente' }}</span>
                            <span>Unidad: {{ box.units_label ?? box.unit?.name ?? 'Sin unidad' }}</span>
                        </button>
                    </div>
                    <div v-else-if="boxSearch.trim() && !selectedBox" class="reassign-results-state">
                        No se encontraron cajas con ese criterio.
                    </div>

                    <div v-if="boxes.length && boxPagination.total > 0" class="reassign-pagination">
                        <div class="reassign-pagination__controls">
                            <div class="reassign-pagination__pages">
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="boxPagination.current_page <= 1 || isSearching"
                                    aria-label="Primera página"
                                    @click="goToBoxPage(1)"
                                >
                                    <i class="pi pi-angle-double-left"></i>
                                </button>
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="boxPagination.current_page <= 1 || isSearching"
                                    aria-label="Página anterior"
                                    @click="previousBoxPage"
                                >
                                    <i class="pi pi-angle-left"></i>
                                </button>

                                <button
                                    v-for="page in visibleBoxPages"
                                    :key="page"
                                    type="button"
                                    class="reassign-pagination__number"
                                    :class="{ 'reassign-pagination__number--active': page === boxPagination.current_page }"
                                    :disabled="isSearching"
                                    @click="goToBoxPage(page)"
                                >
                                    {{ page }}
                                </button>

                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="boxPagination.current_page >= boxPagination.last_page || isSearching"
                                    aria-label="Página siguiente"
                                    @click="nextBoxPage"
                                >
                                    <i class="pi pi-angle-right"></i>
                                </button>
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="boxPagination.current_page >= boxPagination.last_page || isSearching"
                                    aria-label="Última página"
                                    @click="goToBoxPage(boxPagination.last_page)"
                                >
                                    <i class="pi pi-angle-double-right"></i>
                                </button>
                            </div>

                            <Dropdown
                                v-model="boxPagination.per_page"
                                :options="rowsPerPageOptions"
                                class="reassign-pagination__size"
                                :disabled="isSearching"
                                aria-label="Resultados por página"
                                @change="changeBoxPageSize"
                            />
                        </div>
                    </div>
                </section>

                <section v-if="selectedBox" class="reassign-section">
                    <h2>Nueva Ubicación</h2>

                    <div class="reassign-current">
                        <strong>Información actual</strong>
                        <span>Código de caja: {{ displayBoxCode(selectedBox) }}</span>
                        <span>Serie: {{ selectedBox?.series ?? 'Seleccione una caja' }}</span>
                        <span>Unidad productora: {{ selectedBox?.units_label ?? selectedBox?.unit?.name ?? '---' }}</span>
                        <span>Ubicación actual: {{ selectedBox?.location ?? 'Pendiente' }}</span>
                    </div>

                    <div class="reassign-grid">
                        <label>
                            <span>Nueva Oficina <b>*</b></span>
                            <Dropdown
                                v-model="locationForm.office_id"
                                :options="locations.offices"
                                optionLabel="name"
                                optionValue="id"
                                placeholder="Seleccionar"
                                class="w-full"
                                :disabled="isLoading"
                            />
                        </label>
                        <label>
                            <span>Nuevo Pasillo <b>*</b></span>
                            <Dropdown
                                v-model="locationForm.aisle_id"
                                :options="filteredAisles"
                                optionLabel="name"
                                optionValue="id"
                                placeholder="Seleccionar"
                                class="w-full"
                                :disabled="isLoading || !locationForm.office_id"
                            />
                        </label>
                        <label>
                            <span>Nuevo Estante <b>*</b></span>
                            <Dropdown
                                v-model="locationForm.shelf_id"
                                :options="filteredShelves"
                                optionLabel="name"
                                optionValue="id"
                                placeholder="Seleccionar"
                                class="w-full"
                                :disabled="isLoading || !locationForm.aisle_id"
                            />
                        </label>
                    </div>

                    <div v-if="generatedLocationCode" class="reassign-location-preview">
                        <span>Nuevo código de ubicación</span>
                        <strong>{{ generatedLocationCode }}</strong>
                    </div>

                    <footer class="reassign-footer">
                        <button type="button" class="reassign-cancel" :disabled="isSaving" @click="router.push('/archivo-general/gestion')">
                            Cancelar
                        </button>
                        <button type="button" class="reassign-primary" :disabled="isSaving" @click="requestBoxLocationConfirmation">
                            Reasignar ubicación
                        </button>
                    </footer>
                </section>
            </template>

            <template v-else>
                <section class="reassign-section">
                    <h2>Buscar documento</h2>
                    <form class="reassign-search" @submit.prevent="searchDocuments">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="documentSearch"
                            placeholder="Buscar por nombre, código o número de caja..."
                             @input="onDocumentSearchInput"
                        />
                        <button type="button" class="reassign-clear" @click="clearDocumentSearch">
                            <i class="pi pi-filter-slash"></i>
                            Limpiar
                        </button>
                    </form>

                    <div v-if="isSearchingDocuments" class="reassign-results-state">Buscando documentos...</div>
                    <div v-else-if="documentSearch.trim() && documents.length" class="reassign-results-list">
                        <button
                            v-for="document in documents"
                            :key="document.id"
                            type="button"
                            class="reassign-result-item"
                            @click="selectDocument(document)"
                        >
                            <strong>{{ document.title }}</strong>
                            <span>Código: {{ document.code }}</span>
                            <span>Caja actual: {{ displayBoxCode(document.box) }} - {{ document.box?.series ?? 'Sin serie' }}</span>
                            <span>Ubicación: {{ document.box?.location ?? 'Pendiente' }}</span>
                        </button>
                    </div>
                    <div v-else-if="documentSearch.trim() && !selectedDocument" class="reassign-results-state">
                        No se encontraron documentos con ese criterio.
                    </div>

                    <div v-if="documents.length && documentPagination.total > 0" class="reassign-pagination">
                        <div class="reassign-pagination__controls">
                            <div class="reassign-pagination__pages">
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="documentPagination.current_page <= 1 || isSearchingDocuments"
                                    aria-label="Primera página"
                                    @click="goToDocumentPage(1)"
                                >
                                    <i class="pi pi-angle-double-left"></i>
                                </button>
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="documentPagination.current_page <= 1 || isSearchingDocuments"
                                    aria-label="Página anterior"
                                    @click="previousDocumentPage"
                                >
                                    <i class="pi pi-angle-left"></i>
                                </button>

                                <button
                                    v-for="page in visibleDocumentPages"
                                    :key="page"
                                    type="button"
                                    class="reassign-pagination__number"
                                    :class="{ 'reassign-pagination__number--active': page === documentPagination.current_page }"
                                    :disabled="isSearchingDocuments"
                                    @click="goToDocumentPage(page)"
                                >
                                    {{ page }}
                                </button>

                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="documentPagination.current_page >= documentPagination.last_page || isSearchingDocuments"
                                    aria-label="Página siguiente"
                                    @click="nextDocumentPage"
                                >
                                    <i class="pi pi-angle-right"></i>
                                </button>
                                <button
                                    type="button"
                                    class="reassign-pagination__icon"
                                    :disabled="documentPagination.current_page >= documentPagination.last_page || isSearchingDocuments"
                                    aria-label="Última página"
                                    @click="goToDocumentPage(documentPagination.last_page)"
                                >
                                    <i class="pi pi-angle-double-right"></i>
                                </button>
                            </div>

                            <Dropdown
                                v-model="documentPagination.per_page"
                                :options="rowsPerPageOptions"
                                class="reassign-pagination__size"
                                :disabled="isSearchingDocuments"
                                aria-label="Resultados por página"
                                @change="changeDocumentPageSize"
                            />
                        </div>
                    </div>
                </section>

                <section v-if="selectedDocument" class="reassign-section">
                    <h2>Nueva Caja de Destino</h2>

                    <div class="reassign-current">
                        <strong>Información del documento</strong>
                        <span>Documento: {{ selectedDocument?.title ?? 'Seleccione un documento' }}</span>
                        <span>Código: {{ selectedDocument?.code ?? '---' }}</span>
                        <span>Caja actual: {{ displayBoxCode(selectedDocument?.box) }} - {{ selectedDocument?.box?.series ?? '---' }}</span>
                    </div>

                    <form class="reassign-search" @submit.prevent="searchDestinationBoxes">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="destinationBoxSearch"
                            placeholder="Buscar código de caja de destino..."
                            @input="onDestinationBoxSearchInput"
                        />
                        <button type="button" class="reassign-clear" @click="clearDestinationBoxSearch">
                            <i class="pi pi-filter-slash"></i>
                            Limpiar
                        </button>
                    </form>

                    <div v-if="isSearchingDestinationBoxes" class="reassign-results-state">Buscando cajas de destino...</div>
                    <div v-else-if="destinationBoxSearch.trim() && availableDestinationBoxes.length" class="reassign-results-list">
                        <button
                            v-for="box in availableDestinationBoxes"
                            :key="box.id"
                            type="button"
                            class="reassign-result-item"
                            @click="selectDestinationBox(box)"
                        >
                            <strong>{{ displayBoxCode(box) }}</strong>
                            <span>Serie: {{ box.series }}</span>
                            <span>Ubicación: {{ box.location ?? 'Pendiente' }}</span>
                        </button>
                    </div>
                    <div v-else-if="destinationBoxSearch.trim() && !selectedDestinationBox" class="reassign-results-state">
                        No se encontraron cajas de destino con ese criterio.
                    </div>

                    <div v-if="selectedDestinationBox" class="reassign-destination">
                        <strong>Caja de Destino Seleccionada</strong>
                        <span>Caja: {{ displayBoxCode(selectedDestinationBox) }}</span>
                        <span>Serie: {{ selectedDestinationBox.series }}</span>
                        <span>Ubicación: {{ selectedDestinationBox.location ?? 'Sin ubicación' }}</span>
                    </div>

                    <footer class="reassign-footer">
                        <button type="button" class="reassign-cancel" :disabled="isSaving" @click="router.push('/archivo-general/gestion')">
                            Cancelar
                        </button>
                        <button type="button" class="reassign-primary" :disabled="isSaving" @click="requestDocumentReassignmentConfirmation">
                            Reasignar documento
                        </button>
                    </footer>
                </section>
            </template>
        </main>

        <Dialog
            v-model:visible="confirmBoxDialogVisible"
            modal
            :draggable="false"
            header="Confirmar reasignación"
            :style="{ width: 'min(480px, 94vw)' }"
        >
            <p class="reassign-confirm-text">
                Se moverá la caja {{ displayBoxCode(selectedBox) }} de
                <strong>{{ selectedBox?.location ?? 'Pendiente' }}</strong>
                a
                <strong>{{ generatedLocationCode }}</strong>.
            </p>
            <template #footer>
                <div class="reassign-dialog-footer">
                    <button type="button" class="reassign-cancel" :disabled="isSaving" @click="confirmBoxDialogVisible = false">
                        Cancelar
                    </button>
                    <button type="button" class="reassign-primary" :disabled="isSaving" @click="submitBoxLocation">
                        {{ isSaving ? 'Moviendo...' : 'Confirmar' }}
                    </button>
                </div>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="confirmDocumentDialogVisible"
            modal
            :draggable="false"
            header="Confirmar reasignación"
            :style="{ width: 'min(520px, 94vw)' }"
        >
            <p class="reassign-confirm-text">
                Se moverá el documento
                <strong>"{{ selectedDocument?.title }}"</strong>
                de la caja {{ displayBoxCode(selectedDocument?.box) }}
                a la caja {{ displayBoxCode(selectedDestinationBox) }}.
            </p>
            <template #footer>
                <div class="reassign-dialog-footer">
                    <button type="button" class="reassign-cancel" :disabled="isSaving" @click="confirmDocumentDialogVisible = false">
                        Cancelar
                    </button>
                    <button type="button" class="reassign-primary" :disabled="isSaving" @click="submitDocumentReassignment">
                        {{ isSaving ? 'Moviendo...' : 'Confirmar' }}
                    </button>
                </div>
            </template>
        </Dialog>

        <Dialog
            v-model:visible="labelDialogVisible"
            modal
            :draggable="false"
            class="reassign-label-dialog"
            :style="{ width: 'min(720px, 94vw)' }"
        >
            <template #header>
                <div>
                    <h2 class="reassign-label-title">Etiqueta de nueva ubicación</h2>
                    <p class="reassign-label-subtitle">Imprima esta etiqueta y adhierala a la caja reasignada.</p>
                </div>
            </template>

            <article v-if="reassignedLabel" class="reassign-label-card">
                <div class="reassign-label-card__header">
                    <div>
                        <strong>UNIVERSIDAD DE EL SALVADOR</strong>
                        <span>Facultad de Ingenieria y Arquitectura - UGDA</span>
                    </div>
                    <div>
                        <span>Fecha</span>
                        <strong>{{ reassignedLabel.transferDate }}</strong>
                    </div>
                </div>
                <div class="reassign-label-card__hero">
                    <div class="reassign-label-card__qr">
                        <img :src="reassignedLabel.qrDataUrl" :alt="`Código QR ${reassignedLabel.labelCode}`" />
                        <span>Código: {{ reassignedLabel.labelCode }}</span>
                    </div>
                    <div class="reassign-label-card__hero-main">
                        <div class="reassign-label-card__location">
                            <span>CÓDIGO DE UBICACIÓN</span>
                            <strong>{{ reassignedLabel.locationCode }}</strong>
                        </div>
                        <div class="reassign-label-card__box">
                            <span>N de caja</span>
                            <strong>{{ reassignedLabel.boxLabel }}</strong>
                        </div>
                    </div>
                </div>
                <div class="reassign-label-card__details">
                    <div><span>N de transferencia</span><strong>{{ reassignedLabel.transferNumber }}</strong></div>
                    <div><span>Unidad productora</span><strong>{{ reassignedLabel.unit }}</strong></div>
                    <div><span>Contenido de la caja</span><strong>{{ reassignedLabel.content }}</strong></div>
                </div>
                <div class="reassign-label-card__assigned">Ubicación reasignada por {{ reassignedLabel.assignedBy }} el {{ reassignedLabel.assignedAt }}</div>
                <div class="reassign-label-card__footer">Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicación.</div>
            </article>

            <template #footer>
                <div class="reassign-dialog-footer">
                    <button type="button" class="reassign-cancel" @click="labelDialogVisible = false">
                        Cerrar
                    </button>
                    <button type="button" class="reassign-primary" @click="printReassignedLabel">
                        <i class="pi pi-print"></i>
                        Imprimir etiqueta
                    </button>
                </div>
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.reassign-page {
    background: #f8fafc;
    color: #0f172a;
    margin: -1.5rem;
    min-height: 100vh;
}

.reassign-header {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid #dbe3ed;
    display: flex;
    gap: 1rem;
    min-height: 5.25rem;
    padding: 0 2rem;
}

.reassign-back {
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

.reassign-separator {
    background: #dbe3ed;
    height: 1.6rem;
    width: 1px;
}

.reassign-header h1 {
    font-size: 1.55rem;
    font-weight: 600;
    margin: 0;
}

.reassign-main {
    margin: 0 auto;
    max-width: 1180px;
    padding: 2rem;
}

.reassign-section {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.65rem;
    margin-bottom: 1.5rem;
    padding: 2rem;
}

.reassign-section h2 {
    font-size: 1.28rem;
    margin: 0 0 1.8rem;
}

.reassign-type-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.reassign-type {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    color: #0f172a;
    cursor: pointer;
    display: flex;
    gap: 1rem;
    min-height: 6.25rem;
    padding: 1.5rem;
    text-align: left;
}

.reassign-type--active {
    background: #eff6ff;
    border-color: #0f4c8f;
    box-shadow: 0 0 0 1px #0f4c8f;
}

.reassign-type__icon {
    align-items: center;
    background: #f1f5f9;
    border-radius: 999px;
    color: #64748b;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.15rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.reassign-type--active .reassign-type__icon {
    background: #0f4c8f;
    color: #ffffff;
}

.reassign-type strong,
.reassign-type small {
    display: block;
}

.reassign-type strong {
    font-size: 1rem;
}

.reassign-type small {
    color: #526477;
    font-size: 0.88rem;
    margin-top: 0.35rem;
}

.reassign-search {
    align-items: center;
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    display: grid;
    gap: 0.65rem;
    grid-template-columns: auto minmax(0, 1fr) auto;
    padding: 0.35rem 0.35rem 0.35rem 0.9rem;
}

.reassign-search i {
    color: #64748b;
}

.reassign-search :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    min-height: 2.4rem;
    padding-left: 0;
    width: 100%;
}

.reassign-results-list {
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    margin-top: 1rem;
}

.reassign-result-item {
    align-items: flex-start;
    background: #ffffff;
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    color: #0f172a;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 1rem;
    text-align: left;
    width: 100%;
}

.reassign-result-item:last-child {
    border-bottom: 0;
}

.reassign-result-item:hover {
    background: #eff6ff;
}

.reassign-result-item span,
.reassign-results-state {
    color: #526477;
    font-size: 0.88rem;
}

.reassign-results-state {
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    margin-top: 1rem;
    padding: 1rem;
}

.reassign-pagination {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1rem;
    padding: 1rem 1.25rem;
}

.reassign-pagination__controls,
.reassign-pagination__pages {
    align-items: center;
    display: flex;
}

.reassign-pagination__controls {
    gap: 0.75rem;
}

.reassign-pagination__pages {
    gap: 0.5rem;
}

.reassign-pagination button {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 999px;
    color: #334155;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    justify-content: center;
    min-height: 2.4rem;
    min-width: 2.4rem;
    padding: 0 0.55rem;
}

.reassign-pagination button:hover:not(:disabled) {
    background: #eaf2f9;
}

.reassign-pagination__icon {
    color: #64748b !important;
}

.reassign-pagination__number--active {
    background: #eaf2f9 !important;
    color: #2f6f9f !important;
}

.reassign-pagination button:disabled {
    cursor: not-allowed;
    color: #cbd5e1;
}

:deep(.reassign-pagination__size) {
    min-width: 5.2rem;
}

:deep(.reassign-pagination__size .p-dropdown-label) {
    font-size: 0.86rem;
    font-weight: 700;
    padding-bottom: 0.55rem;
    padding-top: 0.55rem;
}

.reassign-search button,
.reassign-primary,
.reassign-cancel {
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

.reassign-search button,
.reassign-primary {
    background: var(--ugda-brand);
    border: 1px solid var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.reassign-search .reassign-clear {
    background: #ffffff;
    border-color: #d8e1ec;
    color: #0f172a;
}

.reassign-search .reassign-clear:hover {
    background: #f8fafc;
    border-color: #b8c6d8;
}

.reassign-cancel {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    color: #0f172a;
}

.reassign-box-option {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.reassign-box-option span {
    color: #526477;
    font-size: 0.82rem;
}

.reassign-current {
    background: #f8fafc;
    border-radius: 0.45rem;
    display: grid;
    gap: 0.45rem;
    margin-bottom: 1.75rem;
    padding: 1.2rem 1rem;
}

.reassign-current strong {
    font-size: 0.92rem;
}

.reassign-current span {
    color: #526477;
    font-size: 0.88rem;
}

.reassign-grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.reassign-grid label {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.reassign-grid span {
    color: #0f172a;
    font-size: 0.9rem;
}

.reassign-grid b {
    color: #dc2626;
}

.reassign-location-preview {
    align-items: center;
    background: #eff6ff;
    border: 1px solid #b7d3f4;
    border-radius: 0.45rem;
    display: inline-flex;
    gap: 1rem;
    justify-content: flex-start;
    margin-top: 1.5rem;
    padding: 1rem;
    width: auto;
}

.reassign-location-preview span {
    color: #526477;
}

.reassign-location-preview strong {
    color: #0f4c8f;
    font-size: 1.1rem;
}

.reassign-destination {
    background: #eff6ff;
    border: 1px solid #0f4c8f;
    border-radius: 0.45rem;
    display: grid;
    gap: 0.45rem;
    margin-top: 1rem;
    padding: 1rem;
}

.reassign-destination strong {
    color: #0f172a;
    font-size: 0.92rem;
}

.reassign-destination span {
    color: #526477;
    font-size: 0.88rem;
}

.reassign-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

.reassign-empty {
    align-items: center;
    border: 2px dashed #d8e1ec;
    border-radius: 0.55rem;
    color: #64748b;
    display: flex;
    justify-content: center;
    min-height: 7rem;
    text-align: center;
}

.reassign-confirm-text {
    color: #334155;
    line-height: 1.6;
    margin: 0;
}

.reassign-dialog-footer {
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    width: 100%;
}

.reassign-label-title {
    color: #0f172a;
    font-size: 1.2rem;
    margin: 0;
}

.reassign-label-subtitle {
    color: #526477;
    font-size: 0.9rem;
    margin: 0.35rem 0 0;
}

.reassign-label-card {
    background: #ffffff;
    border: 2px solid #1f2937;
    border-radius: 0.5rem;
    margin: 0 auto;
    max-width: 360px;
    overflow: hidden;
}

.reassign-label-card__header {
    align-items: flex-start;
    border-bottom: 1px solid #dbe5ee;
    display: grid;
    grid-template-columns: 1fr auto;
    padding: 1rem 0.95rem 0.65rem;
}

.reassign-label-card__header > div:first-child {
    display: grid;
    gap: 0.25rem;
}

.reassign-label-card__header > div:first-child strong {
    font-size: 1.05rem;
    line-height: 1.15;
}

.reassign-label-card__header > div:first-child span,
.reassign-label-card__header > div:last-child span,
.reassign-label-card__hero span,
.reassign-label-card__details span,
.reassign-label-card__assigned {
    color: #64748b;
    font-size: 0.6rem;
    line-height: 1.25;
}

.reassign-label-card__header > div:last-child {
    min-width: 5.25rem;
    text-align: right;
}

.reassign-label-card__header > div:last-child strong {
    display: block;
    font-size: 0.8rem;
    margin-top: 0.28rem;
}

.reassign-label-card__hero {
    align-items: center;
    border-bottom: 1px solid #dbe5ee;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: 1fr;
    padding: 0.85rem 0.95rem;
}

.reassign-label-card__qr {
    text-align: center;
}

.reassign-label-card__qr img {
    display: block;
    height: 112px;
    margin: 0 auto 0.3rem;
    width: 112px;
}

.reassign-label-card__hero-main {
    min-width: 0;
}

.reassign-label-card__location {
    background: #2f6f9f;
    border-radius: 0.45rem;
    color: #ffffff;
    padding: 0.65rem 0.7rem;
    text-align: center;
}

.reassign-label-card__location span,
.reassign-label-card__location strong {
    color: #ffffff;
}

.reassign-label-card__location span {
    display: block;
    font-size: 0.6rem;
    font-weight: 700;
}

.reassign-label-card__location strong {
    display: block;
    font-size: 1.45rem;
    line-height: 1.05;
    margin-top: 0.25rem;
}

.reassign-label-card__box {
    background: #edf3fa;
    border-radius: 0.4rem;
    margin-top: 0.55rem;
    padding: 0.6rem 0.7rem;
}

.reassign-label-card__box span {
    display: block;
}

.reassign-label-card__box strong {
    color: #0f172a;
    display: block;
    font-size: 1.2rem;
    line-height: 1.1;
    margin-top: 0.2rem;
}

.reassign-label-card__details {
    display: grid;
    gap: 0.55rem;
    padding: 0.85rem 0.95rem 0.5rem;
}

.reassign-label-card__details strong {
    color: #0f172a;
    display: block;
    font-size: 0.78rem;
    line-height: 1.25;
    margin-top: 0.15rem;
}

.reassign-label-card__assigned {
    border-top: 1px solid #dbe5ee;
    margin: 0 0.95rem;
    padding: 0.6rem 0;
}

.reassign-label-card__footer {
    background: #fff7db;
    color: #b45309;
    font-size: 0.58rem;
    font-weight: 700;
    line-height: 1.3;
    margin-top: auto;
    padding: 0.55rem 0.95rem;
}

button:disabled {
    cursor: not-allowed;
    opacity: 0.72;
}

@media (max-width: 991px) {
    .reassign-page {
        margin: -1rem;
    }

    .reassign-header {
        display: none;
    }

    .reassign-main {
        padding: 1rem;
    }

    .reassign-section {
        padding: 1.1rem;
    }

    .reassign-type-grid,
    .reassign-grid {
        grid-template-columns: 1fr;
    }

    .reassign-search {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .reassign-search button {
        grid-column: 1 / -1;
        width: 100%;
    }

    .reassign-pagination {
        align-items: stretch;
        flex-direction: column;
    }

    .reassign-pagination__pages {
        justify-content: center;
    }

    .reassign-pagination__controls {
        align-items: stretch;
        flex-direction: column;
        width: 100%;
    }

    :deep(.reassign-pagination__size) {
        width: 100%;
    }

    .reassign-footer,
    .reassign-footer button {
        width: 100%;
    }

    .reassign-footer {
        flex-direction: column;
    }
}
</style>
