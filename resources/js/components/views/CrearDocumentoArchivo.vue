<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';

const router = useRouter();
const toast = useToast();

const isLoading = ref(false);
const isSaving = ref(false);
const isSearchingBoxes = ref(false);
const boxes = ref([]);
const seriesOptions = ref([]);
const supportOptions = ref([
    { label: 'Físico', value: 'Fisico' },
    { label: 'Digital', value: 'Digital' },
    { label: 'Mixto', value: 'Mixto' },
]);
const boxSearch = ref('');
const boxDropdownRef = ref(null);
const documentFileInputRef = ref(null);
const documentDigitalFile = ref(null);
const documentDigitalFileName = ref('');
const documentPreviewVisible = ref(false);
const documentPreviewTitle = ref('Documento PDF');
const documentPreviewSource = ref(null);
const currentYear = new Date().getFullYear();
const currentDate = new Date();
const minimumYearDate = new Date(1900, 0, 1);

const form = ref({
    box_id: null,
    series_id: null,
    code: '',
    name: '',
    year: '',
    support: null,
    pages: '',
});

const selectedBox = computed(() => boxes.value.find((box) => box.id === form.value.box_id) ?? null);
const requiresDigitalFile = computed(() => ['Digital', 'Mixto'].includes(form.value.support));
const normalizeSupportOptions = (options) => (Array.isArray(options) ? options : []).map((option) => {
    const value = typeof option === 'string' ? option : option.value;
    return { label: value === 'Fisico' ? 'Físico' : value, value };
});
const getBoxCode = (box) => box?.box_code || box?.boxCode || box?.code || 'Sin código';
const selectedYear = computed({
    get: () => form.value.year ? new Date(Number(form.value.year), 0, 1) : null,
    set: (value) => {
        form.value.year = value instanceof Date && !Number.isNaN(value.getTime())
            ? String(value.getFullYear())
            : '';
    },
});

watch(() => form.value.support, (support) => {
    if (support === 'Fisico') {
        documentDigitalFile.value = null;
        documentDigitalFileName.value = '';

        if (documentFileInputRef.value) {
            documentFileInputRef.value.value = '';
        }
    }
});

watch(() => form.value.series_id, () => {
    boxes.value = [];
    form.value.box_id = null;
});

const showError = (detail) => {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail,
        life: 4200,
    });
};

const onlyDigits = (value) => String(value ?? '').replace(/\D/g, '');

const sanitizePositiveInteger = (value) => {
    const digits = onlyDigits(value);

    if (!digits) {
        return '';
    }

    const number = Number(digits);

    return number > 0 ? String(number) : '';
};

const onPagesInput = (event) => {
    form.value.pages = sanitizePositiveInteger(event.target.value);
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

const fetchCatalog = async () => {
    isLoading.value = true;

    try {
        const { data } = await axios.get('/api/archive/catalog');
        seriesOptions.value = Array.isArray(data.series) ? data.series : [];
        const normalizedSupportOptions = normalizeSupportOptions(data.support_types);
        supportOptions.value = normalizedSupportOptions.length ? normalizedSupportOptions : supportOptions.value;
    } catch (error) {
        showError(error.response?.data?.message ?? 'No se pudo cargar el catálogo de archivo.');
    } finally {
        isLoading.value = false;
    }
};

const searchBoxes = async () => {
    const term = boxSearch.value.trim();

    if (!form.value.series_id) {
        boxes.value = [];
        form.value.box_id = null;
        showError('Seleccione primero la subserie documental del documento.');
        return;
    }

    if (!term) {
        boxes.value = [];
        form.value.box_id = null;
        showError('Ingrese el código o número de la caja que desea buscar.');
        return;
    }

    isSearchingBoxes.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: {
                q: term,
                classification_id: form.value.series_id,
                page: 1,
                per_page: 50,
            },
        });

        boxes.value = Array.isArray(data.boxes) ? data.boxes : [];
        form.value.box_id = null;

        if (boxes.value.length > 0) {
            await nextTick();
            boxDropdownRef.value?.show();
        }
    } catch (error) {
        showError(error.response?.data?.message ?? 'No se pudieron cargar las cajas registradas.');
    } finally {
        isSearchingBoxes.value = false;
    }
};

const onDocumentDigitalFileSelect = (event) => {
    const file = event.target.files?.[0] ?? null;

    if (!file) {
        documentDigitalFile.value = null;
        documentDigitalFileName.value = '';
        return;
    }

    if (!file.name.toLowerCase().endsWith('.pdf')) {
        showError('Seleccione un archivo PDF para el documento digital.');
        event.target.value = '';
        documentDigitalFile.value = null;
        documentDigitalFileName.value = '';
        return;
    }

    documentDigitalFile.value = file;
    documentDigitalFileName.value = file.name;
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

const validateForm = () => {
    if (!form.value.box_id) {
        showError('Seleccione la caja donde se asignará el documento.');
        return false;
    }

    if (!form.value.series_id || !form.value.code.trim() || !form.value.name.trim() || !form.value.year || !form.value.support || !form.value.pages) {
        showError('Complete todos los campos obligatorios del documento.');
        return false;
    }

    const documentYear = Number(form.value.year);

    if (form.value.year.length !== 4 || !Number.isInteger(documentYear) || documentYear < 1900 || documentYear > currentYear) {
        showError(`El año del documento debe ser un entero de 4 dígitos entre 1900 y ${currentYear}.`);
        return false;
    }

    if (selectedBox.value?.start_year && selectedBox.value?.end_year) {
        const startYear = Number(selectedBox.value.start_year);
        const endYear = Number(selectedBox.value.end_year);

        if (documentYear < startYear || documentYear > endYear) {
        showError(`El año del documento debe estar dentro del período de la caja (${startYear} - ${endYear}).`);
            return false;
        }
    }

    const pages = Number(form.value.pages);

    if (!Number.isInteger(pages) || pages <= 0) {
        showError('El número de páginas debe ser un entero positivo mayor que 0.');
        return false;
    }

    if (requiresDigitalFile.value && !documentDigitalFile.value) {
        showError('Adjunte un archivo PDF para documentos digitales o mixtos.');
        return false;
    }

    return true;
};

const submitDocument = async () => {
    if (!validateForm()) {
        return;
    }

    isSaving.value = true;

    try {
        let digitalFileBase64 = null;

        if (requiresDigitalFile.value && documentDigitalFile.value) {
            digitalFileBase64 = await readFileAsBase64(documentDigitalFile.value);
        }

        await axios.post('/api/archive/documents', {
            box_id: form.value.box_id,
            series_id: form.value.series_id,
            code: form.value.code.trim(),
            name: form.value.name.trim(),
            year: Number(form.value.year),
            support: form.value.support,
            pages: Number(form.value.pages),
            digital_file_name: requiresDigitalFile.value ? documentDigitalFileName.value : null,
            digital_file_base64: requiresDigitalFile.value ? digitalFileBase64 : null,
        });

        toast.add({
            severity: 'success',
            summary: 'Documento creado',
            detail: 'El documento fue asignado correctamente a la caja.',
            life: 3200,
        });
        router.push('/archivo-general/gestion');
    } catch (error) {
        showError(error.response?.data?.message ?? 'No se pudo crear el documento.');
    } finally {
        isSaving.value = false;
    }
};

onMounted(async () => {
    await fetchCatalog();
});
</script>

<template>
    <div class="create-document-page fadein animation-duration-500">
        <header class="create-document-header">
            <button type="button" class="create-document-back" @click="router.push('/archivo-general/gestion')">
                <i class="pi pi-arrow-left"></i>
                Volver
            </button>
            <span class="create-document-separator"></span>
            <h1>Crear Nuevo Documento</h1>
        </header>

        <main class="create-document-main">
            <section class="create-document-section">
                <h2>Datos del Documento</h2>

                <div class="create-document-grid create-document-grid--two">
                    <label>
                        <span>Serie / Subseries <b>*</b></span>
                        <Dropdown
                            v-model="form.series_id"
                            :options="seriesOptions"
                            optionLabel="label"
                            optionValue="value"
                            filter
                            placeholder="Seleccionar subserie"
                            class="w-full"
                            :disabled="isLoading"
                        />
                    </label>

                    <label>
                        <span>Código <b>*</b></span>
                        <InputText v-model="form.code" placeholder="Ej. DOC-001" maxlength="120" />
                    </label>
                </div>

                <label class="create-document-field">
                    <span>Nombre del Documento <b>*</b></span>
                    <InputText v-model="form.name" placeholder="Ingrese el nombre del documento" maxlength="255" />
                </label>

                <div class="create-document-grid create-document-grid--three">
                    <label>
                        <span>Año <b>*</b></span>
                        <DatePicker
                            v-model="selectedYear"
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
                        <span>Tipo de Soporte <b>*</b></span>
                        <Dropdown v-model="form.support" :options="supportOptions" optionLabel="label" optionValue="value" placeholder="Seleccionar" class="w-full" />
                    </label>

                    <label>
                        <span>N de Paginas <b>*</b></span>
                        <InputText
                            v-model="form.pages"
                            inputmode="numeric"
                            placeholder="Ej. 25"
                            @input="onPagesInput"
                        />
                    </label>
                </div>

                <div v-if="requiresDigitalFile" class="create-document-file">
                    <input
                        ref="documentFileInputRef"
                        type="file"
                        accept=".pdf"
                        class="create-document-file__input"
                        @change="onDocumentDigitalFileSelect"
                    />
                    <button type="button" class="create-document-file__button" @click="documentFileInputRef?.click()">
                        <i class="pi pi-upload"></i>
                        <span>{{ documentDigitalFileName || 'Seleccionar PDF' }}</span>
                    </button>
                    <button
                        v-if="documentDigitalFile"
                        type="button"
                        class="create-document-file__button"
                        @click="previewDocumentFile"
                    >
                        <i class="pi pi-eye"></i>
                        <span>Ver PDF</span>
                    </button>
                </div>
            </section>

            <section class="create-document-section">
                <h2>Asignacion de Caja</h2>

                <form class="create-document-search" @submit.prevent="searchBoxes">
                    <label>
                        <span>Buscar caja existente <b>*</b></span>
                        <div class="create-document-search__bar">
                            <i class="pi pi-search"></i>
                            <InputText
                                v-model="boxSearch"
                                placeholder="Buscar por número de caja o ubicación..."
                                :disabled="!form.series_id || isSearchingBoxes"
                            />
                            <button type="submit" :disabled="!form.series_id || isSearchingBoxes">
                                Buscar
                            </button>
                        </div>
                    </label>
                </form>

                <Dropdown
                    ref="boxDropdownRef"
                    v-model="form.box_id"
                    :options="boxes"
                    optionLabel="title"
                    optionValue="id"
                    placeholder="Seleccionar caja"
                    class="w-full"
                    :loading="isSearchingBoxes"
                    :disabled="!form.series_id"
                    appendTo="self"
                    overlayClass="create-document-box-dropdown-overlay"
                >
                    <template #option="{ option }">
                        <div class="create-document-box-option">
                            <strong>{{ getBoxCode(option) }}</strong>
                            <span>{{ option.series }} - {{ option.unit?.name ?? 'Sin unidad' }} - {{ option.location ?? 'Sin ubicación' }}</span>
                        </div>
                    </template>
                    <template #value="{ value, placeholder }">
                        <span v-if="selectedBox">
                            {{ getBoxCode(selectedBox) }} - {{ selectedBox.series }} - {{ selectedBox.location ?? 'Sin ubicación' }}
                        </span>
                        <span v-else>{{ placeholder }}</span>
                    </template>
                </Dropdown>
            </section>

            <footer class="create-document-footer">
                <button type="button" class="create-document-cancel" :disabled="isSaving" @click="router.push('/archivo-general/gestion')">
                    Cancelar
                </button>
                <button type="button" class="create-document-primary" :disabled="isSaving" @click="submitDocument">
                    {{ isSaving ? 'Creando...' : 'Crear Documento' }}
                </button>
            </footer>
        </main>

        <DocumentPreviewDialog
            v-model:visible="documentPreviewVisible"
            :title="documentPreviewTitle"
            :source="documentPreviewSource"
        />
    </div>
</template>

<style scoped>
.create-document-page {
    background: #f8fafc;
    color: #0f172a;
    margin: -1.5rem;
    min-height: 100vh;
}

.create-document-header {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid #dbe3ed;
    display: flex;
    gap: 1rem;
    min-height: 5.25rem;
    padding: 0 2rem;
}

.create-document-back {
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

.create-document-separator {
    background: #dbe3ed;
    height: 1.6rem;
    width: 1px;
}

.create-document-header h1 {
    font-size: 1.55rem;
    font-weight: 600;
    margin: 0;
}

.create-document-main {
    margin: 0 auto;
    max-width: 1140px;
    padding: 2rem;
}

.create-document-section {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.65rem;
    margin-bottom: 1.5rem;
    padding: 2rem;
}

.create-document-section h2 {
    font-size: 1.28rem;
    margin: 0 0 1.8rem;
}

.create-document-grid {
    display: grid;
    gap: 1.5rem;
}

.create-document-grid--two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.create-document-grid--three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.create-document-field,
.create-document-grid label,
.create-document-search label {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.create-document-field {
    margin: 1.5rem 0;
}

.create-document-field span,
.create-document-grid span,
.create-document-search span {
    color: #0f172a;
    font-size: 0.9rem;
}

.create-document-field b,
.create-document-grid b,
.create-document-search b {
    color: #dc2626;
}

.create-document-file {
    align-items: center;
    display: flex;
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.create-document-file__input {
    display: none;
}

.create-document-file__button,
.create-document-primary,
.create-document-cancel,
.create-document-search__bar button {
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

.create-document-file__button,
.create-document-cancel {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    color: #0f172a;
}

.create-document-primary,
.create-document-search__bar button {
    background: var(--ugda-brand);
    border: 1px solid var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.create-document-search {
    margin-bottom: 1rem;
}

.create-document-search__bar {
    align-items: center;
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    display: grid;
    gap: 0.65rem;
    grid-template-columns: auto minmax(0, 1fr) auto;
    padding: 0.35rem 0.35rem 0.35rem 0.9rem;
}

.create-document-search__bar i {
    color: #64748b;
}

.create-document-search__bar :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    min-height: 2.4rem;
    padding-left: 0;
    width: 100%;
}

.create-document-box-option {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.create-document-box-option span {
    color: #526477;
    font-size: 0.82rem;
}

:global(.create-document-box-dropdown-overlay) {
    max-width: 100% !important;
    overflow-x: hidden;
    width: 100% !important;
}

.create-document-footer {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    padding: 0 0 2rem;
}

.create-document-primary:disabled,
.create-document-cancel:disabled,
.create-document-search__bar button:disabled {
    cursor: not-allowed;
    opacity: 0.72;
}

@media (max-width: 991px) {
    .create-document-page {
        margin: -1rem;
    }

    .create-document-header {
        display: none;
    }

    .create-document-main {
        padding: 1rem;
    }

    .create-document-section {
        padding: 1.1rem;
    }

    .create-document-grid--two,
    .create-document-grid--three {
        grid-template-columns: 1fr;
    }

    .create-document-search__bar {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .create-document-search__bar button {
        grid-column: 1 / -1;
        width: 100%;
    }

    .create-document-footer,
    .create-document-footer button,
    .create-document-file__button {
        width: 100%;
    }

    .create-document-footer {
        flex-direction: column;
    }
}
</style>
