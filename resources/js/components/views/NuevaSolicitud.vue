<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import { getCurrentUser } from '../../services/authSession';

const router = useRouter();
const route = useRoute();
const toast = useToast();

const headerForm = ref({
    code: '',
    request_date: new Date(),
    unit_id: null,
    user_name: '',
    description: '',
});

const batchForm = ref({
    series: null,
    year_start: null,
    year_end: null,
    quantity: '',
});

const boxesList = ref([]);
const editObservations = ref([]);
const seriesOptions = ref([]);
const unitsList = ref([]);
const supportOptions = ref(['Físico', 'Digital', 'Mixto']);
const isLoading = ref(false);
const isSubmitting = ref(false);

const isModalOpen = ref(false);
const isImportModalOpen = ref(false);
const currentBoxIndex = ref(null);
const currentBoxData = ref({});
const tempDocuments = ref([]);
const excelFile = ref(null);
const isImporting = ref(false);
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
const submitLabel = computed(() => isEditMode.value ? 'Guardar correcciones' : 'Enviar solicitud');

const requiresDigitalFileInput = computed(() => docForm.value.support === 'Digital' || docForm.value.support === 'Mixto');

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
                : axios.get('/api/transfers/next-code'),
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
        seriesOptions.value = Array.isArray(seriesRes.data)
            ? seriesRes.data.flatMap((series) => {
                if (Array.isArray(series.subseries) && series.subseries.length > 0) {
                    return series.subseries.map((subseries) => ({
                        id: `subseries-${subseries.id}`,
                        label: `${series.code} - ${series.name} / ${subseries.code} - ${subseries.name}`,
                    }));
                }

                return [{
                    id: `series-${series.id}`,
                    label: `${series.code} - ${series.name}`,
                }];
            })
            : [];

        if (isEditMode.value) {
            const editTransfer = transferRes.data.transfer ?? {};

            headerForm.value.code = editTransfer.code ?? '';
            headerForm.value.request_date = parseApiDate(editTransfer.request_date);
            headerForm.value.unit_id = editTransfer.unit_id ?? headerForm.value.unit_id;
            headerForm.value.user_name = editTransfer.responsible || headerForm.value.user_name;
            headerForm.value.description = editTransfer.description ?? '';
            boxesList.value = Array.isArray(transferRes.data.boxes)
                ? transferRes.data.boxes
                : [];
            editObservations.value = Array.isArray(transferRes.data.observations)
                ? transferRes.data.observations
                : [];
        } else {
            headerForm.value.code = transferRes.data.code ?? '';
        }
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

const addBatch = () => {
    const selectedSeries = batchForm.value.series;
    const startYear = getYearString(batchForm.value.year_start);
    const endYear = getYearString(batchForm.value.year_end);
    const quantity = Number(batchForm.value.quantity);

    if (!selectedSeries || !startYear || !endYear || !quantity) {
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

    for (let index = 0; index < quantity; index += 1) {
        boxesList.value.push({
            id_temp: `${Date.now()}-${index}`,
            series: selectedSeries.label,
            year_start: startYear,
            year_end: endYear,
            box_number: nextBoxNumber + index,
            documents_count: 0,
            documents_list: [],
        });
    }

    batchForm.value = {
        series: null,
        year_start: null,
        year_end: null,
        quantity: '',
    };

    toast.add({
        severity: 'success',
        summary: 'Cajas agregadas',
        detail: `${quantity} caja(s) agregada(s) a la solicitud.`,
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
    const defaultSeries = seriesOptions.value.find((option) => option.label === currentBoxData.value.series) ?? null;
    resetDocumentForm(defaultSeries);
    isModalOpen.value = true;
};

const onFileSelect = (event) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    docForm.value.file = file;
    docForm.value.file_url = URL.createObjectURL(file);
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

const needsDigitalFile = (support) => support === 'Digital' || support === 'Mixto';

const downloadExcelTemplate = () => {
    window.location.href = '/api/transfers/document-import-template';
};

const importDocumentsFromExcel = async () => {
    if (!excelFile.value) {
        showError('Seleccione un archivo Excel para importar.');
        return;
    }

    isImporting.value = true;

    try {
        const formData = new FormData();
        formData.append('file', excelFile.value);
        formData.append('current_box_series', currentBoxData.value.series ?? '');

        const { data } = await axios.post('/api/transfers/import-documents', formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });

        const importedDocuments = Array.isArray(data.documents) ? data.documents : [];

        if (importedDocuments.length === 0) {
            showError('El archivo no contiene filas con documentos.');
            return;
        }

        tempDocuments.value.push(...importedDocuments.map((document, index) => ({
            id_doc: `${Date.now()}-${index}`,
            ...document,
            file_name: null,
            file_url: null,
        })));
        excelFile.value = null;
        resetExcelFileInput();
        isImportModalOpen.value = false;

        toast.add({
            severity: 'success',
            summary: 'Documentos importados',
            detail: `${importedDocuments.length} documento(s) agregado(s) al inventario de la caja.`,
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

    if (!documentSeries || !documentName || !documentCode || !docForm.value.support) {
        showError('Complete los datos obligatorios del documento.');
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
        series: documentSeries,
        name: documentName,
        code: documentCode,
        date: documentDate,
        pages: documentPages,
        support: docForm.value.support,
        file_name: docForm.value.file ? docForm.value.file.name : null,
        file_url: docForm.value.file_url,
    });

    const defaultSeries = docForm.value.series;
    resetDocumentForm(defaultSeries);

    const fileInput = document.getElementById('transferDocumentFile');
    if (fileInput) {
        fileInput.value = '';
    }
};

const removeTempDoc = (index) => {
    tempDocuments.value.splice(index, 1);
};

const onTempDocFileSelect = (index, event) => {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    tempDocuments.value[index].file_name = file.name;
    tempDocuments.value[index].file_url = URL.createObjectURL(file);
};

const removeTempDocFile = (index) => {
    if (!isEditMode.value || !tempDocuments.value[index]) {
        return;
    }

    const currentDocument = tempDocuments.value[index];
    const currentUrl = currentDocument.file_url;
    if (currentUrl && String(currentUrl).startsWith('blob:')) {
        URL.revokeObjectURL(currentUrl);
    }

    tempDocuments.value.splice(index, 1, {
        ...currentDocument,
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

    boxesList.value[currentBoxIndex.value].documents_list = [...tempDocuments.value];
    boxesList.value[currentBoxIndex.value].documents_count = tempDocuments.value.length;
    isModalOpen.value = false;
};

const submitTransfer = async () => {
    if (!headerForm.value.code || !formatDateForApi(headerForm.value.request_date) || !headerForm.value.unit_id) {
        showError('Complete los datos generales de la solicitud.');
        return;
    }

    if (boxesList.value.length === 0) {
        showError('Debe agregar al menos una caja a la solicitud.');
        return;
    }

    const emptyBoxes = boxesList.value.filter((box) => box.documents_count === 0);
    if (emptyBoxes.length > 0) {
        showError('Cada caja debe tener al menos un documento en su inventario.');
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
        };

        const { data } = isEditMode.value
            ? await axios.put(`/api/transfers/${editNumber.value}/resubmit-correction`, payload)
            : await axios.post('/api/transfers', payload);

        toast.add({
            severity: 'success',
            summary: isEditMode.value ? 'Solicitud corregida' : 'Solicitud enviada',
            detail: isEditMode.value
                ? 'La solicitud fue reenviada con autorizacion Pendiente y estado Pendiente.'
                : 'La solicitud fue enviada con autorizacion Pendiente y estado Pendiente.',
            life: 3200,
        });

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
    router.push('/solicitudes');
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
        </section>

        <div class="transfer-request__card">
            <div class="transfer-request__section-title">Datos Generales</div>

            <div class="transfer-request__section-body">
                <div class="transfer-request__grid">
                    <div class="transfer-request__field">
                        <label for="transferCode">N° de transferencia documental <span>*</span></label>
                        <InputText id="transferCode" v-model="headerForm.code" :disabled="isLoading" readonly />
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
                        maxlength="1000"
                        rows="4"
                        placeholder="Ingrese una descripción breve de la documentación a transferir"
                    ></textarea>
                </div>

                <div class="transfer-request__section-title transfer-request__section-title--detached">Detalle de Documentos</div>

                <div class="transfer-request__detail-grid">
                    <div class="transfer-request__field">
                        <label for="transferSeries">Serie / Subserie <span>*</span></label>
                        <Dropdown
                            id="transferSeries"
                            v-model="batchForm.series"
                            :options="seriesOptions"
                            :disabled="isLoading"
                            optionLabel="label"
                            placeholder="Seleccionar"
                            filter
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
                    <Column field="box_number" header="Caja" style="width: 6rem">
                        <template #body="{ data }">#{{ String(data.box_number).padStart(3, '0') }}</template>
                    </Column>
                    <Column field="series" header="Serie / Subserie"></Column>
                    <Column header="Periodo" style="width: 12rem">
                        <template #body="{ data }">{{ data.year_start }} - {{ data.year_end }}</template>
                    </Column>
                    <Column header="Inventario" style="width: 12rem">
                        <template #body="{ data, index }">
                            <Button
                                :label="`${data.documents_count} documento(s)`"
                                :icon="data.documents_count > 0 ? 'pi pi-check-circle' : 'pi pi-list'"
                                :severity="data.documents_count > 0 ? 'success' : 'secondary'"
                                outlined
                                size="small"
                                class="w-full"
                                @click="openDocsModal(index)"
                            />
                        </template>
                    </Column>
                    <Column header="" style="width: 4rem">
                        <template #body="{ index }">
                            <Button icon="pi pi-trash" text rounded severity="danger" aria-label="Eliminar caja" @click="removeBox(index)" />
                        </template>
                    </Column>
                </DataTable>

                <div class="transfer-request__actions">
                    <Button
                        :label="submitLabel"
                        class="transfer-request__submit"
                        :loading="isSubmitting"
                        :disabled="isLoading"
                        @click="submitTransfer"
                    />
                    <Button label="Cancelar" outlined class="transfer-request__cancel" :disabled="isSubmitting" @click="goBack" />
                </div>
            </div>
        </div>

        <Dialog v-model:visible="isModalOpen" modal :draggable="false" class="transfer-docs-dialog" :style="{ width: 'min(1024px, 94vw)' }">
            <template #header>
                <div class="transfer-docs__header">
                    <h2>Documentos de la Caja #{{ currentBoxData.box_number }}</h2>
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
                            @click="isImportModalOpen = true"
                        />
                    </div>

                    <div class="transfer-request__field transfer-docs__series">
                        <label for="documentSeries">Serie del documento <span>*</span></label>
                        <Dropdown
                            id="documentSeries"
                            v-model="docForm.series"
                            :options="seriesOptions"
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
                        <label for="documentDate">Año</label>
                        <DatePicker
                            inputId="documentDate"
                            v-model="docForm.date"
                            view="year"
                            dateFormat="yy"
                            showIcon
                            iconDisplay="input"
                            readonlyInput
                            placeholder="Ej: 2025"
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
                            :href="docForm.file_url"
                            class="transfer-docs__file-link"
                            target="_blank"
                            rel="noopener noreferrer"
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
                                    <a :href="document.file_url" target="_blank" rel="noopener noreferrer">{{ document.file_name }}</a>
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
                <Button label="Cancelar" outlined class="transfer-docs__cancel" @click="isModalOpen = false" />
                <Button label="Guardar documentos" class="transfer-docs__save" @click="saveBoxChanges" />
            </template>
        </Dialog>

        <Dialog
            v-model:visible="isImportModalOpen"
            modal
            :draggable="false"
            class="transfer-import-dialog"
            :style="{ width: 'min(896px, 94vw)' }"
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
                <Button label="Cancelar" outlined class="transfer-docs__cancel" @click="isImportModalOpen = false" />
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
.transfer-request__field :deep(.p-datepicker-input) {
    border-color: #d7dde5;
    border-radius: 6px;
    min-height: 3.15rem;
    width: 100%;
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
    background: #2d6f9f;
    border-color: #2d6f9f;
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

.transfer-request__cancel {
    border-color: #2d6f9f;
    color: #1f5f8e;
}

.transfer-docs__cancel {
    border-color: #2d6f9f;
    color: #1f5f8e;
    min-width: 8rem;
}

.transfer-docs__cancel:hover,
.transfer-docs__cancel:focus,
.transfer-docs__cancel:focus-visible {
    background: #eaf4ff;
    border-color: #2d6f9f;
    box-shadow: 0 0 0 0.2rem rgba(45, 111, 159, 0.18);
    color: #1f5f8e;
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

:global(.transfer-docs-dialog.p-dialog),
:global(.transfer-import-dialog.p-dialog) {
    border-radius: 8px;
    overflow: hidden;
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
    overflow: hidden;
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
    .transfer-docs__form,
    .transfer-docs__item-grid {
        grid-template-columns: 1fr;
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
