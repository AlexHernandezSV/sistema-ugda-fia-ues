<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const boxes = ref([]);
const query = ref('');
const loading = ref(false);
const selectedBox = ref(null);
const documentsDialogVisible = ref(false);
const deleteDialogVisible = ref(false);
const deletingBox = ref(false);
const boxPendingDelete = ref(null);

const boxesCount = computed(() => boxes.value.length);

const cards = [
    {
        title: 'Crear Nueva Caja',
        description: 'Crea nuevas cajas y asigna documentos con ubicación física',
        icon: 'pi pi-box',
        action: () => router.push('/archivo-general/cajas/crear'),
    },
    {
        title: 'Crear Nuevo Documento',
        description: 'Crea documentos y asigna a cajas existentes',
        icon: 'pi pi-file-plus',
        action: () => router.push('/archivo-general/documentos/crear'),
    },
    {
        title: 'Reasignacion',
        description: 'Cambia ubicaciones de cajas o documentos',
        icon: 'pi pi-refresh',
        action: () => router.push('/archivo-general/reasignacion'),
    },
    {
        title: 'Documentos Reservados',
        description: 'Gestiona documentos reservados para uso especifico',
        icon: 'pi pi-shield',
        action: () => router.push('/archivo-general/documentos-reservados'),
    },
];

const fetchBoxes = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: query.value.trim() ? { q: query.value.trim() } : {},
        });

        boxes.value = Array.isArray(data.boxes) ? data.boxes : [];
    } catch (error) {
        console.error('Error cargando cajas:', error);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudieron cargar las cajas registradas.',
            life: 4000,
        });
    } finally {
        loading.value = false;
    }
};

const openDocuments = (box) => {
    selectedBox.value = box;
    documentsDialogVisible.value = true;
};

const printLabel = (box) => {
    const popup = window.open('', '_blank', 'width=800,height=600');

    if (!popup) {
        toast.add({
            severity: 'warn',
            summary: 'Ventana bloqueada',
            detail: 'Permita ventanas emergentes para imprimir la etiqueta.',
            life: 3500,
        });
        return;
    }

    popup.document.write(`
        <html>
            <head>
                <title>Etiqueta Caja ${box.number}</title>
                <style>
                    body { font-family: 'Instrument Sans', Arial, Helvetica, sans-serif; padding: 24px; }
                    .label { border: 2px solid #1560bd; padding: 24px; width: 360px; }
                    h1 { color: #1560bd; margin: 0 0 12px; }
                    p { margin: 8px 0; font-size: 14px; }
                    strong { display: block; font-size: 18px; margin-top: 6px; }
                </style>
            </head>
            <body>
                <section class="label">
                    <h1>UGDA FIA UES</h1>
                    <p>Caja <strong>#${box.number}</strong></p>
                    <p>Serie <strong>${box.series ?? 'Sin serie'}</strong></p>
                    <p>Unidad <strong>${box.unit?.name ?? 'Sin unidad'}</strong></p>
                    <p>Periodo <strong>${box.period ?? 'N/D'}</strong></p>
                    <p>Ubicación <strong>${box.location ?? 'Pendiente'}</strong></p>
                </section>
                <script>window.print();<\/script>
            </body>
        </html>
    `);
    popup.document.close();
};

const deleteBox = (box) => {
    if (!box.can_delete) {
        toast.add({
            severity: 'warn',
            summary: 'Accion no disponible',
            detail: 'Solo puede eliminar cajas creadas directamente desde Archivo General.',
            life: 3500,
        });
        return;
    }

    boxPendingDelete.value = box;
    deleteDialogVisible.value = true;
};

const closeDeleteDialog = () => {
    if (deletingBox.value) {
        return;
    }

    deleteDialogVisible.value = false;
    boxPendingDelete.value = null;
};

const confirmDeleteBox = async () => {
    if (!boxPendingDelete.value) {
        return;
    }

    deletingBox.value = true;

    try {
        const box = boxPendingDelete.value;
        await axios.delete(`/api/archive/boxes/${box.id}`);
        toast.add({
            severity: 'success',
            summary: 'Caja eliminada',
            detail: 'La caja fue eliminada correctamente.',
            life: 3000,
        });
        deleteDialogVisible.value = false;
        boxPendingDelete.value = null;
        await fetchBoxes();
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message ?? 'No se pudo eliminar la caja.',
            life: 4200,
        });
    } finally {
        deletingBox.value = false;
    }
};

onMounted(fetchBoxes);
</script>

<template>
    <div class="archive-management-page fadein animation-duration-500">
        <header class="archive-management-header">
            <button type="button" class="archive-back-button" @click="router.push('/dashboard')">
                <i class="pi pi-arrow-left"></i>
                Volver
            </button>
            <span class="archive-header-separator"></span>
            <h1 class="mobile-page-title">Gestión de Archivo General</h1>
        </header>

        <main class="archive-management-main">
            <p class="archive-management-copy">Selecciona una opcion para gestionar el archivo general</p>

            <section class="archive-action-grid">
                <button
                    v-for="card in cards"
                    :key="card.title"
                    type="button"
                    class="archive-action-card"
                    @click="card.action"
                >
                    <span class="archive-action-card__icon">
                        <i :class="card.icon"></i>
                    </span>
                    <strong>{{ card.title }}</strong>
                    <span>{{ card.description }}</span>
                </button>
            </section>

            <section class="archive-boxes-card">
                <div class="archive-boxes-card__header">
                    <h2>Cajas Registradas ({{ boxesCount }})</h2>
                    <form class="archive-box-search" @submit.prevent="fetchBoxes">
                        <i class="pi pi-search"></i>
                        <InputText
                            v-model="query"
                            type="text"
                            placeholder="Buscar por numero, serie, unidad o ubicación..."
                            :disabled="loading"
                        />
                    </form>
                </div>

                <DataTable
                    :value="boxes"
                    :loading="loading"
                    responsiveLayout="scroll"
                    dataKey="id"
                    class="archive-boxes-table"
                >
                    <Column field="number" header="N Caja">
                        <template #body="{ data }">
                            <strong>#{{ data.number }}</strong>
                        </template>
                    </Column>
                    <Column field="series" header="Serie Documental" />
                    <Column header="Unidad Productora">
                        <template #body="{ data }">
                            {{ data.unit?.name ?? 'Sin unidad' }}
                        </template>
                    </Column>
                    <Column field="period" header="Periodo" />
                    <Column field="location" header="Ubicación" />
                    <Column field="documents_count" header="Documentos" />
                    <Column field="created_at_label" header="Registrada" />
                    <Column header="Acciones">
                        <template #body="{ data }">
                            <div class="archive-table-actions">
                                <button type="button" title="Ver documentos" @click="openDocuments(data)">
                                    <i class="pi pi-eye"></i>
                                </button>
                                <button type="button" title="Imprimir etiqueta" @click="printLabel(data)">
                                    <i class="pi pi-print"></i>
                                </button>
                                <button
                                    type="button"
                                    title="Eliminar"
                                    class="archive-table-actions__danger"
                                    :disabled="!data.can_delete"
                                    @click="deleteBox(data)"
                                >
                                    <i class="pi pi-trash"></i>
                                </button>
                            </div>
                        </template>
                    </Column>
                    <template #empty>
                        <div class="archive-boxes-empty">No hay cajas registradas con esos criterios.</div>
                    </template>
                </DataTable>
            </section>
        </main>

        <Dialog
            v-model:visible="documentsDialogVisible"
            modal
            :draggable="false"
            :header="selectedBox ? `Documentos de la Caja #${selectedBox.number}` : 'Documentos de la Caja'"
            :style="{ width: 'min(900px, 94vw)' }"
        >
            <p v-if="selectedBox" class="archive-documents-subtitle">
                {{ selectedBox.series }} ({{ selectedBox.start_year }} - {{ selectedBox.end_year }})
            </p>

            <DataTable
                :value="selectedBox?.documents ?? []"
                responsiveLayout="scroll"
                class="archive-documents-table"
            >
                <Column field="series" header="Serie" />
                <Column field="code" header="Código" />
                <Column field="name" header="Nombre" />
                <Column field="year" header="Ano" />
                <Column field="support" header="Soporte" />
                <Column field="pages" header="Paginas" />
                <Column field="created_at_label" header="Registrado" />
                <template #empty>
                    <div class="archive-boxes-empty">Esta caja no tiene documentos registrados.</div>
                </template>
            </DataTable>
        </Dialog>

        <Dialog
            v-model:visible="deleteDialogVisible"
            modal
            :closable="!deletingBox"
            :dismissableMask="!deletingBox"
            :style="{ width: '440px', maxWidth: '92vw' }"
            class="archive-delete-dialog"
        >
            <template #header>
                <div class="archive-delete-dialog__header">
                    <span class="archive-delete-dialog__icon">
                        <i class="pi pi-exclamation-triangle"></i>
                    </span>
                    <span>Confirmar eliminacion</span>
                </div>
            </template>

            <div class="archive-delete-dialog__body" v-if="boxPendingDelete">
                <p>
                    Se eliminara la caja
                    <strong>#{{ boxPendingDelete.number }}</strong>.
                </p>
                <p>Esta accion no se puede revertir.</p>
            </div>

            <template #footer>
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    :disabled="deletingBox"
                    @click="closeDeleteDialog"
                />
                <Button
                    label="Aceptar"
                    severity="danger"
                    :loading="deletingBox"
                    @click="confirmDeleteBox"
                />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.archive-management-page {
    background: #f8fafc;
    color: #0f172a;
    margin: -1.5rem;
    min-height: calc(100vh - 0rem);
}

.archive-management-header {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid #dbe3ed;
    display: flex;
    gap: 1rem;
    min-height: 5.25rem;
    padding: 0 2rem;
}

.archive-back-button {
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

.archive-header-separator {
    background: #dbe3ed;
    height: 1.6rem;
    width: 1px;
}

.archive-management-header h1 {
    font-size: 1.55rem;
    font-weight: 600;
    margin: 0;
}

.archive-management-main {
    padding: 2rem;
}

.archive-management-copy {
    color: #526477;
    margin: 0 0 2rem;
}

.archive-action-grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 2rem;
}

.archive-action-card {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.45rem;
    color: inherit;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    min-height: 13.9rem;
    justify-content: center;
    padding: 1.5rem;
    text-align: center;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.archive-action-card:hover {
    border-color: #0f4c8f;
    box-shadow: 0 18px 35px rgba(15, 76, 143, 0.11);
    transform: translateY(-2px);
}

.archive-action-card__icon {
    align-items: center;
    background: #eff6ff;
    border-radius: 999px;
    color: #0f4c8f;
    display: inline-flex;
    font-size: 1.75rem;
    height: 4rem;
    justify-content: center;
    width: 4rem;
}

.archive-action-card strong {
    font-size: 1.05rem;
}

.archive-action-card span:last-child {
    color: #526477;
    font-size: 0.9rem;
    line-height: 1.45;
    max-width: 18rem;
}

.archive-boxes-card {
    background: #ffffff;
    border: 1px solid #d8e1ec;
    border-radius: 0.65rem;
    overflow: hidden;
}

.archive-boxes-card__header {
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.5rem;
}

.archive-boxes-card__header h2 {
    font-size: 1.25rem;
    margin: 0;
}

.archive-box-search {
    align-items: center;
    border: 1px solid #d8e1ec;
    border-radius: 0.4rem;
    display: flex;
    gap: 0.65rem;
    max-width: 26rem;
    padding: 0 0.75rem;
    width: 100%;
}

.archive-box-search i {
    color: #64748b;
}

.archive-box-search :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    min-height: 2.4rem;
    padding-left: 0;
    width: 100%;
}

.archive-boxes-table :deep(.p-datatable-thead > tr > th) {
    background: #f8fafc;
    color: #0f172a;
    font-size: 0.82rem;
}

.archive-table-actions {
    display: flex;
    gap: 0.35rem;
}

.archive-table-actions button {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 0.35rem;
    color: #0f4c8f;
    cursor: pointer;
    display: inline-flex;
    height: 2rem;
    justify-content: center;
    width: 2rem;
}

.archive-table-actions button:hover {
    background: #eff6ff;
}

.archive-table-actions button:disabled {
    cursor: not-allowed;
}

.archive-table-actions__danger {
    color: #dc2626 !important;
}

.archive-boxes-empty {
    color: #64748b;
    padding: 1.25rem;
    text-align: center;
}

.archive-documents-subtitle {
    color: #526477;
    margin: 0.5rem 0 1.25rem; /* o 0.75rem arriba */
}

.archive-delete-dialog__header {
    align-items: center;
    color: #111827;
    display: flex;
    font-weight: 700;
    gap: 0.75rem;
}

.archive-delete-dialog__icon {
    align-items: center;
    background: #fee2e2;
    border-radius: 999px;
    color: #dc2626;
    display: inline-flex;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.archive-delete-dialog__body {
    display: grid;
    gap: 0.65rem;
}

.archive-delete-dialog__body p {
    color: #475569;
    line-height: 1.5;
    margin: 0;
}

.archive-delete-dialog__body strong {
    color: #111827;
    font-weight: 700;
}

@media (max-width: 991px) {
    .archive-management-page {
        margin: -1rem;
    }

    .archive-management-header {
        display: none;
    }

    .archive-management-main {
        padding: 1rem;
    }

    .archive-action-grid {
        grid-template-columns: 1fr;
    }

    .archive-boxes-card__header {
        align-items: stretch;
        flex-direction: column;
    }

    .archive-box-search {
        max-width: none;
    }
}
</style>
