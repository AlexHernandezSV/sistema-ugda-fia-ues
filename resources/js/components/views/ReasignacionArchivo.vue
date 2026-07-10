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
    const boxLabel = `C-AG-${String(Number(box.box_number ?? box.number) || box.number || 0).padStart(3, '0')}`;
    const locationCode = box.location ?? generatedLocationCode.value;

    return {
        boxLabel,
        labelCode: boxLabel,
        transferNumber: 'Archivo General',
        transferDate: currentDateLabel(),
        unit: box.unit?.name ?? selectedBox.value?.unit?.name ?? 'Unidad no registrada',
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
                        <div class="transfer-label__qr"><img src="${label.qrDataUrl}" alt="Codigo QR ${escapeHtml(label.labelCode)}" /><span>Codigo: ${escapeHtml(label.labelCode)}</span></div>
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

const searchBoxes = async () => {
    const term = boxSearch.value.trim();

    if (!term) {
        boxes.value = [];
        selectedBoxId.value = null;
        selectedBoxRecord.value = null;
        return;
    }

    isSearching.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: { q: term },
        });

        boxes.value = Array.isArray(data.boxes) ? data.boxes : [];
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudieron cargar las cajas registradas.');
    } finally {
        isSearching.value = false;
    }
};

const onBoxSearchInput = () => {
    selectedBoxId.value = null;
    selectedBoxRecord.value = null;

    window.clearTimeout(boxSearchTimeout);
    boxSearchTimeout = window.setTimeout(searchBoxes, 250);
};

const selectBox = (box) => {
    selectedBoxId.value = box.id;
    selectedBoxRecord.value = box;
    boxSearch.value = `Caja #${box.number} - ${box.series}`;
    boxes.value = [];
};

const resetBoxReassignment = () => {
    selectedBoxId.value = null;
    selectedBoxRecord.value = null;
    boxSearch.value = '';
    boxes.value = [];
    locationForm.value = { office_id: null, aisle_id: null, shelf_id: null };
    confirmBoxDialogVisible.value = false;
};

const searchDocuments = async () => {
    const term = documentSearch.value.trim();

    if (!term) {
        documents.value = [];
        selectedDocumentId.value = null;
        selectedDocumentRecord.value = null;
        return;
    }

    isSearchingDocuments.value = true;

    try {
        const { data } = await axios.get('/api/archive/documents', {
            params: { q: term },
        });

        documents.value = Array.isArray(data.results) ? data.results : [];
    } catch (error) {
        showToast('error', 'Error', error.response?.data?.message ?? 'No se pudieron cargar los documentos.');
    } finally {
        isSearchingDocuments.value = false;
    }
};

const onDocumentSearchInput = () => {
    selectedDocumentId.value = null;
    selectedDocumentRecord.value = null;
    selectedDestinationBoxId.value = null;
    selectedDestinationBoxRecord.value = null;
    destinationBoxSearch.value = '';
    destinationBoxes.value = [];

    window.clearTimeout(documentSearchTimeout);
    documentSearchTimeout = window.setTimeout(searchDocuments, 250);
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
    confirmDocumentDialogVisible.value = false;
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

const selectDestinationBox = (box) => {
    selectedDestinationBoxId.value = box.id;
    selectedDestinationBoxRecord.value = box;
    destinationBoxSearch.value = `Caja #${box.number} - ${box.series}`;
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

        const movedBox = data.box?.number ?? selectedBox.value.number;
        reassignedLabel.value = await buildReassignedLabel(data.box ?? selectedBox.value);
        labelDialogVisible.value = true;
        showToast('success', 'Caja movida', `Se movio la caja #${movedBox} correctamente.`);
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
        const destinationBoxNumber = selectedDestinationBox.value.number;
        const { data } = await axios.put(`/api/archive/documents/${selectedDocument.value.id}/box`, {
            box_id: selectedDestinationBox.value.id,
        });

        showToast('success', 'Documento movido', `Se movio el documento "${movedDocument}" a la caja #${destinationBoxNumber}.`);
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
                            <strong>Reasignar Documento</strong>
                            <small>Mover documento a otra caja</small>
                        </span>
                    </button>
                </div>
            </section>

            <template v-if="reassignmentType === 'box'">
                <section class="reassign-section">
                    <h2>Buscar Caja</h2>
                    <form class="reassign-search" @submit.prevent="searchBoxes">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="boxSearch"
                            placeholder="Buscar por numero de caja, serie o unidad productora..."
                            :disabled="isSearching"
                            @input="onBoxSearchInput"
                        />
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
                            <strong>Caja #{{ box.number }}</strong>
                            <span>Serie: {{ box.series }}</span>
                            <span>Ubicación Actual: {{ box.location ?? 'Pendiente' }}</span>
                            <span>Unidad: {{ box.unit?.name ?? 'Sin unidad' }}</span>
                        </button>
                    </div>
                    <div v-else-if="boxSearch.trim() && !selectedBox" class="reassign-results-state">
                        No se encontraron cajas con ese criterio.
                    </div>
                </section>

                <section v-if="selectedBox" class="reassign-section">
                    <h2>Nueva Ubicación</h2>

                    <div class="reassign-current">
                        <strong>Informacion Actual</strong>
                        <span>Caja: #{{ selectedBox?.number ?? '---' }}</span>
                        <span>Serie: {{ selectedBox?.series ?? 'Seleccione una caja' }}</span>
                        <span>Unidad productora: {{ selectedBox?.unit?.name ?? '---' }}</span>
                        <span>Ubicación Actual: {{ selectedBox?.location ?? 'Pendiente' }}</span>
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
                            Reasignar Ubicación
                        </button>
                    </footer>
                </section>
            </template>

            <template v-else>
                <section class="reassign-section">
                    <h2>Buscar Documento</h2>
                    <form class="reassign-search" @submit.prevent="searchDocuments">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="documentSearch"
                            placeholder="Buscar por nombre, codigo o numero de caja..."
                            :disabled="isSearchingDocuments"
                            @input="onDocumentSearchInput"
                        />
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
                            <span>Codigo: {{ document.code }}</span>
                            <span>Caja Actual: #{{ document.box?.number ?? '---' }} - {{ document.box?.series ?? 'Sin serie' }}</span>
                            <span>Ubicación: {{ document.box?.location ?? 'Pendiente' }}</span>
                        </button>
                    </div>
                    <div v-else-if="documentSearch.trim() && !selectedDocument" class="reassign-results-state">
                        No se encontraron documentos con ese criterio.
                    </div>
                </section>

                <section v-if="selectedDocument" class="reassign-section">
                    <h2>Nueva Caja de Destino</h2>

                    <div class="reassign-current">
                        <strong>Informacion del Documento</strong>
                        <span>Documento: {{ selectedDocument?.title ?? 'Seleccione un documento' }}</span>
                        <span>Codigo: {{ selectedDocument?.code ?? '---' }}</span>
                        <span>Caja Actual: #{{ selectedDocument?.box?.number ?? '---' }} - {{ selectedDocument?.box?.series ?? '---' }}</span>
                    </div>

                    <form class="reassign-search" @submit.prevent="searchDestinationBoxes">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="destinationBoxSearch"
                            placeholder="Buscar caja de destino..."
                            :disabled="isSearchingDestinationBoxes"
                            @input="onDestinationBoxSearchInput"
                        />
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
                            <strong>Caja #{{ box.number }}</strong>
                            <span>Serie: {{ box.series }}</span>
                            <span>Ubicación: {{ box.location ?? 'Pendiente' }}</span>
                        </button>
                    </div>
                    <div v-else-if="destinationBoxSearch.trim() && !selectedDestinationBox" class="reassign-results-state">
                        No se encontraron cajas de destino con ese criterio.
                    </div>

                    <div v-if="selectedDestinationBox" class="reassign-destination">
                        <strong>Caja de Destino Seleccionada</strong>
                        <span>Caja: #{{ selectedDestinationBox.number }}</span>
                        <span>Serie: {{ selectedDestinationBox.series }}</span>
                        <span>Ubicación: {{ selectedDestinationBox.location ?? 'Sin ubicación' }}</span>
                    </div>

                    <footer class="reassign-footer">
                        <button type="button" class="reassign-cancel" :disabled="isSaving" @click="router.push('/archivo-general/gestion')">
                            Cancelar
                        </button>
                        <button type="button" class="reassign-primary" :disabled="isSaving" @click="requestDocumentReassignmentConfirmation">
                            Reasignar Documento
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
                Se movera la caja #{{ selectedBox?.number }} de
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
                Se movera el documento
                <strong>"{{ selectedDocument?.title }}"</strong>
                de la caja #{{ selectedDocument?.box?.number ?? '---' }}
                a la caja #{{ selectedDestinationBox?.number ?? '---' }}.
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
                <div class="reassign-label-card__top">
                    <div>
                        <strong>UNIVERSIDAD DE EL SALVADOR</strong>
                        <span>Facultad de Ingenieria y Arquitectura - UGDA</span>
                    </div>
                    <div>
                        <span>Fecha</span>
                        <strong>{{ reassignedLabel.transferDate }}</strong>
                    </div>
                </div>
                <div class="reassign-label-card__body">
                    <img :src="reassignedLabel.qrDataUrl" :alt="`Codigo QR ${reassignedLabel.labelCode}`" />
                    <div>
                        <span>CÓDIGO DE UBICACIÓN</span>
                        <strong>{{ reassignedLabel.locationCode }}</strong>
                    </div>
                    <div>
                        <span>N de caja</span>
                        <strong>{{ reassignedLabel.boxLabel }}</strong>
                    </div>
                </div>
                <div class="reassign-label-card__details">
                    <span>Unidad productora: <strong>{{ reassignedLabel.unit }}</strong></span>
                    <span>Contenido: <strong>{{ reassignedLabel.content }}</strong></span>
                    <span>Periodo: <strong>{{ reassignedLabel.period }}</strong></span>
                </div>
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
    grid-template-columns: auto minmax(0, 1fr);
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
    max-height: 18.75rem;
    overflow: auto;
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

.reassign-label-card__top {
    align-items: flex-start;
    border-bottom: 1px solid #dbe5ee;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem;
}

.reassign-label-card__top div {
    display: grid;
    gap: 0.25rem;
}

.reassign-label-card__top span,
.reassign-label-card__body span,
.reassign-label-card__details span {
    color: #64748b;
    font-size: 0.78rem;
}

.reassign-label-card__body {
    display: grid;
    gap: 0.85rem;
    padding: 1rem;
}

.reassign-label-card__body img {
    height: 112px;
    justify-self: center;
    width: 112px;
}

.reassign-label-card__body div {
    background: #edf3fa;
    border-radius: 0.45rem;
    display: grid;
    gap: 0.25rem;
    padding: 0.8rem;
}

.reassign-label-card__body div:first-of-type {
    background: #2f6f9f;
    color: #ffffff;
    text-align: center;
}

.reassign-label-card__body div:first-of-type span,
.reassign-label-card__body div:first-of-type strong {
    color: #ffffff;
}

.reassign-label-card__body strong {
    color: #0f172a;
    font-size: 1.2rem;
}

.reassign-label-card__details {
    border-top: 1px solid #dbe5ee;
    display: grid;
    gap: 0.4rem;
    padding: 1rem;
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

    .reassign-footer,
    .reassign-footer button {
        width: 100%;
    }

    .reassign-footer {
        flex-direction: column;
    }
}
</style>
