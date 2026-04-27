<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import { getCurrentUser } from '../../services/authSession';

const router = useRouter();
const toast = useToast();

// --- DATOS PRINCIPALES ---
const headerForm = ref({
    code: '',
    request_date: new Date(),
    unit_id: null,
    user_name: '',
});

const batchForm = ref({ series: null, year_start: '', year_end: '', quantity: 1 });
const boxesList = ref([]); 

// --- CATALOGOS ---
const seriesOptions = ref([]);
const unitsList = ref([]);
const supportOptions = ref(['Físico', 'Digital', 'Mixto']);

// --- ESTADO DEL MODAL ---
const isModalOpen = ref(false);
const currentBoxIndex = ref(null);
const currentBoxData = ref({});
const tempDocuments = ref([]);

// Formulario Modal
const docForm = ref({
    name: '',
    date: '',
    support: null,
    file: null,
    file_url: null // Para previsualizar
});

// --- CARGA INICIAL ---
onMounted(async () => {
    try {
        const [userRes, seriesRes, codeRes] = await Promise.all([
            getCurrentUser(),
            axios.get('/api/documentary-series'),
            axios.get('/api/transfers/next-code'),
        ]);
        headerForm.value.user_name = `${userRes.first_name ?? ''} ${userRes.first_last_name ?? ''}`.trim();
        headerForm.value.unit_id = userRes.unit_id ?? userRes.unit?.id ?? null; 
        headerForm.value.code = codeRes.data.code ?? '';
        unitsList.value = Array.isArray(userRes.units)
            ? userRes.units.map(u => ({ label: u.name, value: u.id }))
            : [];
        seriesOptions.value = Array.isArray(seriesRes.data) && seriesRes.data.length > 0
            ? seriesRes.data.flatMap(series => {
                if (Array.isArray(series.subseries) && series.subseries.length > 0) {
                    return series.subseries.map(subseries => ({
                        id: `subseries-${subseries.id}`,
                        series_id: series.id,
                        subseries_id: subseries.id,
                        label: `${series.code} - ${series.name} / ${subseries.code} - ${subseries.name}`,
                        code: `${series.code}-${subseries.code}`,
                        series_name: series.name,
                        subseries_name: subseries.name,
                    }));
                }

                return [{
                    id: `series-${series.id}`,
                    series_id: series.id,
                    subseries_id: null,
                    label: `${series.code} - ${series.name}`,
                    code: series.code,
                    series_name: series.name,
                    subseries_name: null,
                }];
            })
            : [];
    } catch (e) {
        console.error(e);
        seriesOptions.value = [];
        toast.add({ severity: 'error', summary: 'Error', detail: 'No se pudo cargar la informacion base de la transferencia.', life: 4000 });
    }
});

const totalBoxes = computed(() => boxesList.value.length);

// --- LÓGICA PRINCIPAL: CAJAS ---
const addBatch = () => {
    if (!batchForm.value.series || !batchForm.value.year_start || !batchForm.value.year_end || !batchForm.value.quantity) {
        toast.add({ severity: 'warn', summary: 'Faltan datos', detail: 'Complete todos los campos', life: 3000 });
        return;
    }
    const yearRegex = /^\d{4}$/;
    if (!yearRegex.test(batchForm.value.year_start) || !yearRegex.test(batchForm.value.year_end)) {
        toast.add({ severity: 'error', summary: 'Formato inválido', detail: 'Años deben ser 4 dígitos', life: 3000 });
        return;
    }
    if (parseInt(batchForm.value.year_end) < parseInt(batchForm.value.year_start)) {
        toast.add({ severity: 'error', summary: 'Error', detail: 'Año final menor al inicial', life: 3000 });
        return;
    }

    const qty = parseInt(batchForm.value.quantity);
    const selectedSeriesLabel = batchForm.value.series.label;
    const existingBoxesOfSeries = boxesList.value.filter(box => box.series === selectedSeriesLabel);
    
    let startNumber = 1;
    if (existingBoxesOfSeries.length > 0) {
        const maxNum = Math.max(...existingBoxesOfSeries.map(b => b.box_number));
        startNumber = maxNum + 1;
    }

    for (let i = 0; i < qty; i++) {
        boxesList.value.push({
            id_temp: Date.now() + i, 
            series: selectedSeriesLabel,
            year_start: batchForm.value.year_start,
            year_end: batchForm.value.year_end,
            box_number: startNumber + i,
            documents_count: 0,
            documents_list: []
        });
    }

    toast.add({ severity: 'success', summary: 'Agregado', detail: `${qty} cajas`, life: 3000 });
    batchForm.value.quantity = 1; 
};

const removeBox = (index) => boxesList.value.splice(index, 1);

// --- LÓGICA MODAL: DOCUMENTOS ---

const openDocsModal = (index) => {
    currentBoxIndex.value = index;
    currentBoxData.value = boxesList.value[index];
    tempDocuments.value = JSON.parse(JSON.stringify(boxesList.value[index].documents_list || []));
    docForm.value = { name: '', date: '', support: null, file: null, file_url: null };
    isModalOpen.value = true;
};

// Subida de Archivo (Simulada para visualización)
const onFileSelect = (event) => {
    const file = event.target.files[0];
    if (file) {
        docForm.value.file = file;
        // Creamos una URL temporal para poder "ver" el archivo inmediatamente
        docForm.value.file_url = URL.createObjectURL(file);
    }
};

const addDocumentToTemp = () => {
    if (!docForm.value.name || !docForm.value.date || !docForm.value.support) {
        toast.add({ severity: 'warn', summary: 'Incompleto', detail: 'Complete los campos obligatorios', life: 3000 });
        return;
    }

    if ((docForm.value.support === 'Digital' || docForm.value.support === 'Mixto') && !docForm.value.file) {
        toast.add({ severity: 'error', summary: 'Archivo Requerido', detail: 'Debe cargar un archivo', life: 3000 });
        return;
    }

    tempDocuments.value.push({
        id_doc: Date.now(),
        name: docForm.value.name,
        date: docForm.value.date,
        support: docForm.value.support,
        file_name: docForm.value.file ? docForm.value.file.name : null,
        file_url: docForm.value.file_url // Guardamos la URL temporal
    });

    // Limpiar formulario (incluyendo el input file)
    docForm.value = { name: '', date: '', support: null, file: null, file_url: null };
    // Reset manual del input file
    const fileInput = document.getElementById('fileUploadInput');
    if (fileInput) fileInput.value = '';
};

const removeTempDoc = (idx) => tempDocuments.value.splice(idx, 1);

// Ver archivo (abre en nueva pestaña)
const viewFile = (url) => {
    if (url) window.open(url, '_blank');
};

const saveBoxChanges = () => {
    boxesList.value[currentBoxIndex.value].documents_list = [...tempDocuments.value];
    boxesList.value[currentBoxIndex.value].documents_count = tempDocuments.value.length;
    isModalOpen.value = false;
    toast.add({ severity: 'success', summary: 'Guardado', detail: 'Caja actualizada', life: 3000 });
};

// --- ENVÍO FINAL ---
const submitTransfer = () => {
    if (boxesList.value.length === 0) return;
    const emptyBoxes = boxesList.value.filter(box => box.documents_count === 0);
    if (emptyBoxes.length > 0) {
        toast.add({ severity: 'error', summary: 'Error', detail: `Hay ${emptyBoxes.length} cajas vacías.`, life: 5000 });
        return;
    }

    axios.post('/api/transfers', {
        code: headerForm.value.code,
        request_date: headerForm.value.request_date,
        unit_id: headerForm.value.unit_id,
        boxes: boxesList.value,
    }).then((response) => {
        toast.add({ severity: 'success', summary: 'Exito', detail: response.data.message, life: 3000 });
        setTimeout(() => {
            router.push({
                path: `/solicitudes/transferencias/${response.data.number}`,
                query: { tab: 'transferencias' },
            });
        }, 1200);
    }).catch((error) => {
        const message = error.response?.data?.message || 'No se pudo registrar la transferencia.';
        toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
    });
};

const goBack = () => router.back();
</script>

<template>
    <div class="mb-4">
        <div class="flex align-items-center gap-2 mb-3">
            <Button icon="pi pi-arrow-left" text rounded class="text-gray-700" @click="goBack" />
            <h1 class="text-2xl font-bold m-0 mobile-page-title" style="color: #1560bd;">Nueva Transferencia Documental</h1>
        </div>

        <div class="surface-card shadow-1 border-round-xl overflow-hidden mb-4">
            <div class="p-3 font-bold text-white text-lg" style="background-color: #1560bd;">Datos Generales</div>
            <div class="p-4 grid p-fluid">
                <div class="col-12 md:col-6 mb-3">
                    <div class="flex flex-column gap-2">
                        <label class="font-bold text-sm text-gray-700">N° de transferencia</label>
                        <InputText v-model="headerForm.code" disabled class="bg-gray-100" />
                    </div>
                </div>
                <div class="col-12 md:col-6 mb-3">
                    <div class="flex flex-column gap-2">
                        <label class="font-bold text-sm text-gray-700">Fecha de solicitud *</label>
                        <DatePicker v-model="headerForm.request_date" showIcon dateFormat="dd/mm/yy" class="w-full" />
                    </div>
                </div>
                <div class="col-12 md:col-6 mb-3">
                    <div class="flex flex-column gap-2">
                        <label class="font-bold text-sm text-gray-700">Unidad productora *</label>
                        <Dropdown v-model="headerForm.unit_id" :options="unitsList" optionLabel="label" optionValue="value" placeholder="Seleccione" />
                    </div>
                </div>
                <div class="col-12 md:col-6 mb-3">
                    <div class="flex flex-column gap-2">
                        <label class="font-bold text-sm text-gray-700">Responsable</label>
                        <InputText v-model="headerForm.user_name" disabled class="bg-gray-100" />
                    </div>
                </div>
            </div>
        </div>

        <div class="surface-card shadow-1 border-round-xl overflow-hidden">
            <div class="p-3 font-bold text-white text-lg" style="background-color: #1560bd;">Detalle de Documentos</div>
            <div class="p-4">
                <div class="grid p-fluid align-items-end mb-4">
                    <div class="col-12 md:col-3 mb-2">
                        <div class="flex flex-column gap-2">
                            <label class="font-bold text-sm text-gray-700">Serie / Subserie *</label>
                            <Dropdown v-model="batchForm.series" :options="seriesOptions" optionLabel="label" placeholder="Seleccione" />
                        </div>
                    </div>
                    <div class="col-6 md:col-2 mb-2">
                        <div class="flex flex-column gap-2">
                            <label class="font-bold text-sm text-gray-700">Fecha Inicial *</label>
                            <InputText v-model="batchForm.year_start" placeholder="2020" maxlength="4" />
                        </div>
                    </div>
                    <div class="col-6 md:col-2 mb-2">
                        <div class="flex flex-column gap-2">
                            <label class="font-bold text-sm text-gray-700">Fecha Final *</label>
                            <InputText v-model="batchForm.year_end" placeholder="2021" maxlength="4" />
                        </div>
                    </div>
                    <div class="col-6 md:col-3 mb-2">
                        <div class="flex flex-column gap-2">
                            <label class="font-bold text-sm text-gray-700">Cantidad *</label>
                            <InputText v-model="batchForm.quantity" type="number" min="1" />
                        </div>
                    </div>
                    <div class="col-6 md:col-2 mb-2">
                        <Button label="Agregar" icon="pi pi-plus" class="w-full border-none" style="background-color: #1560bd;" @click="addBatch" />
                    </div>
                </div>

                <div v-if="boxesList.length === 0" class="text-center p-6 border-1 border-dashed border-300 border-round bg-gray-50">
                    <i class="pi pi-box text-6xl text-gray-300 mb-3"></i>
                    <span class="text-gray-500 font-medium block">No hay cajas agregadas.</span>
                </div>

                <DataTable v-else :value="boxesList" stripedRows size="small" class="mt-3 clean-table rounded-table">
                    <Column header="N°" style="width: 3rem; text-align: center" headerClass="bg-primary-custom text-white">
                        <template #body="slotProps">{{ slotProps.index + 1 }}</template>
                    </Column>
                    <Column field="series" header="Serie / Subserie" headerClass="bg-primary-custom text-white"></Column>
                    <Column field="year_start" header="Inicio" style="width: 80px; text-align: center;" headerClass="bg-primary-custom text-white"></Column>
                    <Column field="year_end" header="Fin" style="width: 80px; text-align: center;" headerClass="bg-primary-custom text-white"></Column>
                    <Column field="box_number" header="N° Caja" style="width: 80px; text-align: center;" headerClass="bg-primary-custom text-white"></Column>
                    
                    <Column header="Documentos" style="width: 140px; text-align: center;" headerClass="bg-primary-custom text-white">
                        <template #body="slotProps">
                            <Button 
                                :label="`${slotProps.data.documents_count} (Lista)`" 
                                :icon="slotProps.data.documents_count > 0 ? 'pi pi-check-circle' : 'pi pi-list'" 
                                :severity="slotProps.data.documents_count > 0 ? 'success' : 'info'" 
                                outlined size="small" 
                                class="p-1 text-xs w-full"
                                @click="openDocsModal(slotProps.index)" 
                            />
                        </template>
                    </Column>
                    
                    <Column header="Acciones" style="width: 100px; text-align: center;" headerClass="bg-primary-custom text-white">
                        <template #body="slotProps">
                            <div class="flex justify-content-center gap-2">
                                <Button icon="pi pi-trash" text rounded severity="danger" size="small" @click="removeBox(slotProps.index)" />
                            </div>
                        </template>
                    </Column>
                    <template #footer>
                        <div class="flex justify-content-end align-items-center px-2 py-1 gap-3">
                            <span class="text-gray-600 font-medium">Total Cajas:</span>
                            <span class="text-xl font-bold" style="color: #1560bd;">{{ totalBoxes }}</span>
                        </div>
                    </template>
                </DataTable>

                <div class="flex justify-content-start gap-3 mt-5 pt-3 border-top-1 border-gray-200">
                    <Button label="Enviar transferencia" class="px-4 border-none" style="background-color: #1560bd;" :disabled="boxesList.length === 0" @click="submitTransfer" />
                    <Button label="Cancelar" severity="secondary" outlined class="px-4" @click="goBack" />
                </div>
            </div>
        </div>
    </div>

    <Dialog v-model:visible="isModalOpen" modal :style="{ width: '60vw' }" :draggable="false">
        
        <template #header>
            <div class="w-full">
                <h3 class="m-0 text-white">Documentos de la Caja #{{ currentBoxData.box_number }}</h3>
                <span class="text-sm text-blue-100">{{ currentBoxData.series }} ({{ currentBoxData.year_start }} - {{ currentBoxData.year_end }})</span>
            </div>
        </template>

        <div class="pt-2">
            <div class="surface-ground p-3 border-round mb-4">
                <h4 class="m-0 mb-3 text-gray-800">Agregar Documento</h4>
                
                <div class="grid p-fluid">
                    <div class="col-12 md:col-4 mb-3">
                        <div class="flex flex-column gap-2">
                            <label class="text-sm font-bold text-gray-700">Nombre del Documento *</label>
                            <InputText v-model="docForm.name" placeholder="Ej: Acta de reunión" />
                        </div>
                    </div>

                    <div class="col-12 md:col-4 mb-3">
                        <div class="flex flex-column gap-2">
                            <label class="text-sm font-bold text-gray-700">Fecha (Año) *</label>
                            <InputText v-model="docForm.date" placeholder="Ej: 2020" />
                        </div>
                    </div>

                    <div class="col-12 md:col-4 mb-3">
                        <div class="flex flex-column gap-2">
                            <label class="text-sm font-bold text-gray-700">Soporte *</label>
                            <Dropdown v-model="docForm.support" :options="supportOptions" placeholder="Seleccione" />
                        </div>
                    </div>

                    <div class="col-12 mb-3" v-if="docForm.support === 'Digital' || docForm.support === 'Mixto'">
                        <div class="flex flex-column gap-2">
                            <label class="text-sm font-bold text-gray-700">Archivo Digital (Requerido)</label>
                            <div class="p-inputgroup">
                                <span class="p-inputgroup-addon bg-gray-100"><i class="pi pi-upload"></i></span>
                                <input type="file" id="fileUploadInput" class="w-full border-1 border-gray-300 p-2 border-round-right" @change="onFileSelect" accept=".pdf,.doc,.docx,.jpg,.png" />
                            </div>
                            <small v-if="docForm.file" class="text-green-600 font-bold">
                                <i class="pi pi-check"></i> Archivo seleccionado: {{ docForm.file.name }}
                            </small>
                        </div>
                    </div>

                    <div class="col-12">
                        <Button label="Agregar documento" icon="pi pi-plus" class="w-auto px-4 border-none" style="background-color: #205081;" @click="addDocumentToTemp" />
                    </div>
                </div>
            </div>

            <h4 class="m-0 mb-3 text-gray-800">Documentos agregados ({{ tempDocuments.length }})</h4>
            
            <div v-if="tempDocuments.length === 0" class="text-center p-4 border-1 border-dashed border-200 text-gray-500 mb-4">
                No hay documentos agregados.
            </div>

            <div v-else class="border-1 border-200 border-round mb-4 overflow-hidden">
                <div class="grid bg-gray-100 p-2 font-bold text-sm text-gray-700 m-0 border-bottom-1 border-200">
                    <div class="col-4">Nombre Documento</div>
                    <div class="col-2 text-center">Fecha</div>
                    <div class="col-2 text-center">Soporte</div>
                    <div class="col-3 text-center">Archivo Digital</div>
                    <div class="col-1 text-center">Acción</div>
                </div>

                <div v-for="(doc, idx) in tempDocuments" :key="doc.id_doc" class="grid align-items-center p-2 m-0 border-bottom-1 border-200 text-sm hover:bg-gray-50">
                    
                    <div class="col-4 font-medium text-gray-800">{{ doc.name }}</div>
                    
                    <div class="col-2 text-center text-gray-600">{{ doc.date }}</div>
                    
                    <div class="col-2 text-center">
                        <span class="px-2 py-1 border-round text-xs font-bold" 
                            :class="doc.support === 'Físico' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'">
                            {{ doc.support }}
                        </span>
                    </div>
                    
                    <div class="col-3 text-center">
                        <Button v-if="doc.file_url" label="Ver Archivo" icon="pi pi-eye" link size="small" class="p-0 text-blue-600" @click="viewFile(doc.file_url)" />
                        <span v-else class="text-gray-400">-</span>
                    </div>
                    
                    <div class="col-1 text-center">
                        <Button icon="pi pi-trash" text rounded severity="danger" class="w-2rem h-2rem" @click="removeTempDoc(idx)" />
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button label="Cancelar" severity="secondary" outlined @click="isModalOpen = false" />
            <Button label="Guardar documentos" class="border-none" style="background-color: #205081;" @click="saveBoxChanges" />
        </template>
    </Dialog>
</template>

<style scoped>
:deep(.bg-primary-custom) { background-color: #1560bd !important; }
:deep(.clean-table .p-datatable-thead > tr > th),
:deep(.clean-table .p-datatable-tbody > tr > td) { border: none !important; }
:deep(.clean-table .p-datatable-footer) { background: transparent; border: none; border-top: 1px solid #e5e7eb; }
:deep(.rounded-table) { border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb; }
:deep(.p-datepicker), :deep(.p-datepicker-input) { width: 100% !important; }

/* Modal Styles */
:deep(.p-dialog-header) {
    background-color: #1560bd;
    color: white;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}
:deep(.p-dialog-header-icons .p-dialog-header-icon) { color: white; }
:deep(.p-dialog-content) { padding: 1.5rem 2rem; }

/* Input File Custom */
input[type="file"]::file-selector-button { display: none; }
</style>
