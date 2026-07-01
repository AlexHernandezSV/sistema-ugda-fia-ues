<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const isLoading = ref(false);
const isSaving = ref(false);
const isImporting = ref(false);
const isSearchingDocuments = ref(false);

const units = ref([]);
const seriesOptions = ref([]);
const supportOptions = ref(['Fisico', 'Digital', 'Mixto']);
const locationOptions = ref({
    offices: [],
    aisles: [],
    shelves: [],
});

const form = ref({
    box_number: '',
    unit_id: null,
    series_id: null,
    start_year: '',
    end_year: '',
    office_id: null,
    aisle_id: null,
    shelf_id: null,
});

const documents = ref([]);
const addDocumentDialogVisible = ref(false);
const importDialogVisible = ref(false);
const searchDialogVisible = ref(false);
const excelFile = ref(null);
const excelInputRef = ref(null);
const isExcelDragging = ref(false);
const documentSearch = ref('');
const documentSearchResults = ref([]);
const selectedExistingDocuments = ref([]);

const docForm = ref({
    series_id: null,
    code: '',
    name: '',
    year: '',
    support: null,
    pages: '',
});

const selectedSeriesName = computed(() => (
    seriesOptions.value.find((item) => item.value === form.value.series_id)?.label ?? ''
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

const resetDocForm = () => {
    docForm.value = {
        series_id: form.value.series_id,
        code: '',
        name: '',
        year: '',
        support: null,
        pages: '',
    };
};

const fetchCatalog = async () => {
    isLoading.value = true;

    try {
        const { data } = await axios.get('/api/archive/catalog');
        units.value = Array.isArray(data.units) ? data.units : [];
        seriesOptions.value = Array.isArray(data.series) ? data.series : [];
        supportOptions.value = Array.isArray(data.support_types) ? data.support_types : supportOptions.value;
        locationOptions.value = {
            offices: data.locations?.offices ?? [],
            aisles: data.locations?.aisles ?? [],
            shelves: data.locations?.shelves ?? [],
        };
        form.value.box_number = data.next_box_number ? String(data.next_box_number) : '';
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

const addDocument = () => {
    const selectedDocSeries = seriesOptions.value.find((item) => item.value === docForm.value.series_id);

    if (!docForm.value.name || !docForm.value.code || !docForm.value.year || !docForm.value.support) {
        showError('Complete los campos obligatorios del documento.');
        return;
    }

    documents.value.push({
        id: `manual-${Date.now()}`,
        series: selectedDocSeries?.label ?? selectedSeriesName.value,
        code: docForm.value.code,
        name: docForm.value.name,
        year: docForm.value.year,
        support: docForm.value.support,
        pages: docForm.value.pages,
    });

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
    window.location.href = '/api/transfers/document-import-template';
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

        documents.value.push(...imported.map((document, index) => ({
            id: `excel-${Date.now()}-${index}`,
            series: document.series || selectedSeriesName.value,
            code: document.code,
            name: document.name,
            year: document.date,
            support: document.support,
            pages: document.pages,
        })));

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

const previewLabel = () => {
    window.print();
};

const validateForm = () => {
    if (!form.value.box_number || !form.value.unit_id || !form.value.series_id || !form.value.start_year || !form.value.end_year) {
        showError('Complete la información obligatoria de la caja.');
        return false;
    }

    if (Number(form.value.end_year) < Number(form.value.start_year)) {
        showError('El año final no puede ser menor que el año inicial.');
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
            box_number: Number(form.value.box_number),
            unit_id: form.value.unit_id,
            series_id: form.value.series_id,
            start_year: Number(form.value.start_year),
            end_year: Number(form.value.end_year),
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
        showError(error.response?.data?.message ?? 'No se pudo crear la caja.');
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
                        <span>N de Caja <b>*</b></span>
                        <InputText v-model="form.box_number" type="number" class="w-full" :disabled="isLoading" />
                    </label>
                    <label>
                        <span>Unidad Productora <b>*</b></span>
                        <Dropdown
                            v-model="form.unit_id"
                            :options="units"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Seleccionar unidad productora"
                            class="w-full"
                            :disabled="isLoading"
                        />
                    </label>
                </div>

                <label class="create-box-field">
                    <span>Serie Documental <b>*</b></span>
                    <Dropdown
                        v-model="form.series_id"
                        :options="seriesOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Seleccionar serie documental"
                        class="w-full"
                        :disabled="isLoading"
                    />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Ano Inicial <b>*</b></span>
                        <InputText v-model="form.start_year" type="number" placeholder="Ej: 2024" class="w-full" />
                    </label>
                    <label>
                        <span>Ano Final <b>*</b></span>
                        <InputText v-model="form.end_year" type="number" placeholder="Ej: 2024" class="w-full" />
                    </label>
                </div>
            </section>

            <section class="create-box-section">
                <h2>Ubicacion Fisica</h2>

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
                        <span>Código de Ubicacion Generado:</span>
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
                            <button type="button" class="create-box-remove" @click="removeDocument(data)">
                                <i class="pi pi-trash"></i>
                            </button>
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
        >
            <template #header>
                <div>
                    <h2>Documentos de la Caja #{{ form.box_number || '---' }}</h2>
                </div>
            </template>

            <section class="archive-doc-form">
                <h3>Agregar Documento</h3>

                <label class="create-box-field">
                    <span>Serie Documental <b>*</b></span>
                    <Dropdown
                        v-model="docForm.series_id"
                        :options="seriesOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Seleccionar serie documental"
                        class="w-full"
                    />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Código del Documento <b>*</b></span>
                        <InputText v-model="docForm.code" placeholder="Ej: EXP-2024-001" class="w-full" />
                    </label>
                    <label>
                        <span>Ano <b>*</b></span>
                        <InputText v-model="docForm.year" placeholder="Ej: 2024" class="w-full" />
                    </label>
                </div>

                <label class="create-box-field">
                    <span>Nombre del Documento <b>*</b></span>
                    <InputText v-model="docForm.name" placeholder="Ej: Expediente Academico de Juan Perez" class="w-full" />
                </label>

                <div class="create-box-grid create-box-grid--two">
                    <label>
                        <span>Tipo de Soporte <b>*</b></span>
                        <Dropdown v-model="docForm.support" :options="supportOptions" placeholder="Seleccionar" class="w-full" />
                    </label>
                    <label>
                        <span>N de Paginas</span>
                        <InputText v-model="docForm.pages" placeholder="Ej: 150" class="w-full" />
                    </label>
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
                    responsiveLayout="scroll"
                    class="archive-doc-added__table"
                >
                    <Column field="series" header="Serie" />
                    <Column field="code" header="Código" />
                    <Column field="name" header="Nombre" />
                    <Column field="year" header="Año" />
                    <Column field="support" header="Soporte" />
                    <Column header="Acciones">
                        <template #body="{ data }">
                            <button type="button" class="create-box-remove" @click="removeDocument({ ...data, source: 'manual' })">
                                <i class="pi pi-trash"></i>
                            </button>
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

        <Dialog v-model:visible="searchDialogVisible" modal :draggable="false" header="Buscar documentos existentes" :style="{ width: 'min(900px, 94vw)' }">
            <form class="archive-existing-search" @submit.prevent="searchExistingDocuments">
                <InputText v-model="documentSearch" placeholder="Buscar por código, nombre, serie, caja o ubicacion" class="w-full" />
                <button type="submit" class="create-box-primary" :disabled="isSearchingDocuments">
                    {{ isSearchingDocuments ? 'Buscando...' : 'Buscar' }}
                </button>
            </form>

            <DataTable :value="documentSearchResults" responsiveLayout="scroll" class="archive-existing-table">
                <Column field="series" header="Serie" />
                <Column field="code" header="Código" />
                <Column field="title" header="Nombre" />
                <Column header="Caja Actual">
                    <template #body="{ data }">
                        #{{ data.box?.number ?? 'N/D' }} - {{ data.box?.location ?? 'Sin ubicacion' }}
                    </template>
                </Column>
                <Column header="Accion">
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
}
</style>
