<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import QRCode from 'qrcode';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';

const router = useRouter();
const toast = useToast();

const boxes = ref([]);
const query = ref('');
const loading = ref(false);
const selectedBox = ref(null);
const documentsDialogVisible = ref(false);
const documentPreviewVisible = ref(false);
const documentPreviewTitle = ref('Documento PDF');
const documentPreviewSource = ref(null);
const reopenDocumentsDialogAfterPreview = ref(false);
const deleteDialogVisible = ref(false);
const deletingBox = ref(false);
const boxPendingDelete = ref(null);
const rowsPerPageOptions = [5, 10, 25, 50];
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
});

const boxesCount = computed(() => pagination.value.total);
const tableFirst = computed(() => (
    (pagination.value.current_page - 1) * pagination.value.per_page
));

const cards = [
    {
        title: 'Crear nueva caja',
        description: 'Crea nuevas cajas y asigna documentos con ubicación física',
        icon: 'pi pi-box',
        action: () => router.push('/archivo-general/cajas/crear'),
    },
    {
        title: 'Crear nuevo documento',
        description: 'Crea documentos y asigna a cajas existentes',
        icon: 'pi pi-file-plus',
        action: () => router.push('/archivo-general/documentos/crear'),
    },
    {
        title: 'Reasignación',
        description: 'Cambia ubicaciones de cajas o documentos',
        icon: 'pi pi-refresh',
        action: () => router.push('/archivo-general/reasignacion'),
    },
    {
        title: 'Documentos reservados',
        description: 'Gestiona documentos reservados para uso especifico',
        icon: 'pi pi-shield',
        action: () => router.push('/archivo-general/documentos-reservados'),
    },
];

const fetchBoxes = async (page = 1, perPage = pagination.value.per_page) => {
    loading.value = true;

    try {
        const { data } = await axios.get('/api/archive/boxes', {
            params: {
                page,
                per_page: perPage,
                ...(query.value.trim() ? { q: query.value.trim() } : {}),
            },
        });

        boxes.value = Array.isArray(data.boxes ?? data.data) ? (data.boxes ?? data.data) : [];
        pagination.value = data.meta ?? pagination.value;
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

const onBoxesPage = (event) => {
    fetchBoxes(event.page + 1, event.rows);
};

const clearBoxSearch = () => {
    query.value = '';
    fetchBoxes(1);
};

const openDocuments = (box) => {
    selectedBox.value = box;
    documentsDialogVisible.value = true;
};

const openDocumentPreview = (document) => {
    if (!document?.digital_url) {
        toast.add({
            severity: 'warn',
            summary: 'Archivo no disponible',
            detail: 'Este documento no tiene un archivo PDF asociado.',
            life: 3500,
        });
        return;
    }

    documentPreviewTitle.value = document.digital_file || document.name || 'Documento PDF';
    documentPreviewSource.value = { url: document.digital_url };
    reopenDocumentsDialogAfterPreview.value = documentsDialogVisible.value;
    documentsDialogVisible.value = false;
    documentPreviewVisible.value = true;
};

const clearDocumentPreview = () => {
    documentPreviewSource.value = null;

    if (reopenDocumentsDialogAfterPreview.value) {
        reopenDocumentsDialogAfterPreview.value = false;
        nextTick(() => {
            documentsDialogVisible.value = true;
        });
    }
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

const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const printLabel = async (box) => {
    const labelCode = box.box_code || box.boxCode || box.code || `C-AG-${box.number}`;
    const label = {
        boxLabel: labelCode,
        labelCode,
        transferNumber: 'Archivo General',
        transferDate: currentDateLabel(),
        unit: box.units_label ?? box.unit?.name ?? 'Unidad no registrada',
        content: box.series ?? box.title ?? 'Serie documental no registrada',
        period: box.period ?? 'Periodo no definido',
        locationCode: box.location ?? 'Pendiente',
        assignedBy: box.assigned_by ?? 'UGDA',
        assignedAt: box.created_at_label?.split(' ')[0] ?? currentDateLabel(),
    };

    try {
        label.qrDataUrl = await buildQrDataUrl(JSON.stringify({
            type: 'archivo-general-box',
            box: label.boxLabel,
            location: label.locationCode,
        }));
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudo generar el código QR de la etiqueta.',
            life: 3500,
        });
        return;
    }

    const safeLabel = Object.fromEntries(
        Object.entries(label).map(([key, value]) => [key, escapeHtml(value)]),
    );

    let exportHost = null;

    try {
        exportHost = document.createElement('div');
        exportHost.style.position = 'fixed';
        exportHost.style.left = '-10000px';
        exportHost.style.top = '0';
        exportHost.style.width = '10cm';
        exportHost.style.pointerEvents = 'none';
        exportHost.style.zIndex = '-1';
        exportHost.innerHTML = `
            <style>
                * { box-sizing: border-box; }
                .transfer-label { background: #ffffff; border: 2px solid #1f2937; border-radius: 0.5cm; display: flex; flex-direction: column; height: 18cm; overflow: hidden; width: 10cm; }
                .transfer-label__header { align-items: flex-start; border-bottom: 1px solid #dbe5ee; display: grid; grid-template-columns: 1fr auto; padding: 0.58cm 0.55cm 0.36cm; }
                .transfer-label__university { font: 700 0.44cm/1.15 Arial, Helvetica, sans-serif; }
                .transfer-label__faculty, .transfer-label__date span, .transfer-label__box span, .transfer-label__details span, .transfer-label__assigned, .transfer-label__qr span { color: #64748b; font: 0.25cm/1.25 Arial, Helvetica, sans-serif; }
                .transfer-label__date { min-width: 2.25cm; text-align: right; }
                .transfer-label__date strong { display: block; font: 0.34cm/1.2 Arial, Helvetica, sans-serif; margin-top: 0.12cm; }
                .transfer-label__hero { align-items: center; border-bottom: 1px solid #dbe5ee; display: grid; grid-template-columns: 1fr; padding: 0.32cm 0.55cm; }
                .transfer-label__qr { text-align: center; }
                .transfer-label__qr img { display: block; height: 2.8cm; margin: 0 auto 0.12cm; width: 2.8cm; }
                .transfer-label__location { background: #2f6f9f; border-radius: 0.32cm; color: #ffffff; padding: 0.34cm 0.36cm; text-align: center; }
                .transfer-label__location span { display: block; font: 700 0.25cm/1.25 Arial, Helvetica, sans-serif; }
                .transfer-label__location strong { display: block; font: 700 0.7cm/1.05 Arial, Helvetica, sans-serif; margin-top: 0.14cm; }
                .transfer-label__box { background: #edf3fa; border-radius: 0.28cm; margin-top: 0.28cm; padding: 0.3cm 0.36cm; }
                .transfer-label__box strong { display: block; font: 0.58cm/1.1 Arial, Helvetica, sans-serif; margin-top: 0.1cm; }
                .transfer-label__details { display: grid; gap: 0.22cm; padding: 0.3cm 0.55cm 0.16cm; }
                .transfer-label__details strong { display: block; font: 0.33cm/1.25 Arial, Helvetica, sans-serif; margin-top: 0.08cm; }
                .transfer-label__assigned { border-top: 1px solid #dbe5ee; margin: 0 0.55cm; padding: 0.2cm 0; }
                .transfer-label__footer { background: #fff7db; color: #b45309; font: 700 0.2cm/1.2 Arial, Helvetica, sans-serif; margin-top: auto; padding: 0.18cm 0.55cm; }
            </style>
            <article class="transfer-label">
                <div class="transfer-label__header">
                    <div>
                        <div class="transfer-label__university">UNIVERSIDAD DE EL SALVADOR</div>
                        <div class="transfer-label__faculty">Facultad de Ingenieria y Arquitectura - UGDA</div>
                    </div>
                    <div class="transfer-label__date"><span>Fecha</span><strong>${safeLabel.transferDate}</strong></div>
                </div>
                <div class="transfer-label__hero">
                    <div class="transfer-label__qr"><img src="${label.qrDataUrl}" alt="Código QR ${safeLabel.labelCode}" /><span>Código: ${safeLabel.labelCode}</span></div>
                    <div>
                        <div class="transfer-label__location"><span>CÓDIGO DE UBICACIÓN</span><strong>${safeLabel.locationCode}</strong></div>
                        <div class="transfer-label__box"><span>N.° de caja</span><strong>${safeLabel.boxLabel}</strong></div>
                    </div>
                </div>
                <div class="transfer-label__details">
                    <div><span>N.° de transferencia</span><strong>${safeLabel.transferNumber}</strong></div>
                    <div><span>Unidad productora</span><strong>${safeLabel.unit}</strong></div>
                    <div><span>Contenido de la caja</span><strong>${safeLabel.content}</strong></div>
                    <div><span>Periodo documental</span><strong>${safeLabel.period}</strong></div>
                </div>
                <div class="transfer-label__assigned">Ubicación asignada por ${safeLabel.assignedBy} el ${safeLabel.assignedAt}</div>
                <div class="transfer-label__footer">Instrucciones: Adherir en la parte frontal de la caja. Mantener limpia y legible.</div>
            </article>
        `;
        document.body.appendChild(exportHost);

        const exportCard = exportHost.querySelector('.transfer-label');
        const image = exportCard.querySelector('img');
        if (!image.complete) {
            await new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }

        const canvas = await html2canvas(exportCard, {
            backgroundColor: '#ffffff',
            scale: 2,
            useCORS: true,
        });
        const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [100, 180] });
        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, 100, 180, undefined, 'FAST');

        const file = new File(
            [pdf.output('blob')],
            `etiqueta-caja-${label.labelCode}.pdf`,
            { type: 'application/pdf' },
        );
        documentPreviewTitle.value = `Etiqueta ${label.labelCode}`;
        documentPreviewSource.value = { file, fileName: file.name };
        documentPreviewVisible.value = true;
    } catch (error) {
        console.error('Error generando la etiqueta PDF:', error);
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: 'No se pudo generar la etiqueta PDF de la caja.',
            life: 3500,
        });
    } finally {
        exportHost?.remove();
    }
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
            <p class="archive-management-copy">Selecciona una opción para gestionar el archivo general.</p>

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
                    <form class="archive-box-search" @submit.prevent="fetchBoxes(1)">
                        <span class="archive-box-search__field">
                            <i class="pi pi-search"></i>
                            <InputText
                                v-model="query"
                                type="text"
                                placeholder="Buscar por número, serie, unidad o ubicación..."
                                :disabled="loading"
                            />
                        </span>
                        <div class="archive-box-search__actions">
                            <Button
                                type="submit"
                                icon="pi pi-search"
                                label="Buscar"
                                :loading="loading"
                                :disabled="loading"
                            />
                            <Button
                                type="button"
                                icon="pi pi-times"
                                label="Limpiar"
                                severity="secondary"
                                outlined
                                :disabled="loading"
                                @click="clearBoxSearch"
                            />
                        </div>
                    </form>
                </div>

                <DataTable
                    :value="boxes"
                    :loading="loading"
                    :paginator="true"
                    lazy
                    :first="tableFirst"
                    :rows="pagination.per_page"
                    :totalRecords="pagination.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    dataKey="id"
                    class="archive-boxes-table"
                    @page="onBoxesPage"
                >
                    <Column field="box_code" header="Código de Caja">
                        <template #body="{ data }">
                            <strong>{{ data.box_code || data.boxCode || data.code || 'Sin código' }}</strong>
                        </template>
                    </Column>
                    <Column field="series" header="Serie Documental" />
                    <Column header="Unidad Productora">
                        <template #body="{ data }">
                            {{ data.units_label ?? data.unit?.name ?? 'Sin unidad' }}
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
            :header="selectedBox ? `Documentos de la Caja ${selectedBox.box_code || selectedBox.boxCode || selectedBox.code}` : 'Documentos de la Caja'"
            :style="{ width: 'min(1100px, 94vw)', maxHeight: '90vh' }"
            :contentStyle="{ overflow: 'hidden' }"
        >
            <p v-if="selectedBox" class="archive-documents-subtitle">
                {{ selectedBox.series }} ({{ selectedBox.start_year }} - {{ selectedBox.end_year }})
            </p>

            <DataTable
                :value="selectedBox?.documents ?? []"
                responsiveLayout="scroll"
                scrollable
                scrollHeight="55vh"
                class="archive-documents-table"
            >
                <Column field="series" header="Serie" />
                <Column field="code" header="Código" />
                <Column field="name" header="Nombre" />
                    <Column field="year" header="Año" />
                <Column field="support" header="Soporte" />
                <Column field="pages" header="Paginas" />
                <Column field="created_at_label" header="Registrado" />
                <Column header="Acciones">
                    <template #body="{ data }">
                        <button
                            v-if="data.digital_available && data.digital_url"
                            type="button"
                            class="archive-table-actions__view"
                            title="Ver PDF"
                            @click="openDocumentPreview(data)"
                        >
                            <i class="pi pi-eye"></i>
                        </button>
                        <span
                            v-else-if="['Digital', 'Mixto'].includes(data.support)"
                            class="archive-document-unavailable"
                        >
                            Sin archivo
                        </span>
                        <span v-else class="archive-document-unavailable">N/D</span>
                    </template>
                </Column>
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
                    <strong>{{ boxPendingDelete.box_code || boxPendingDelete.boxCode || boxPendingDelete.code }}</strong>.
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

        <DocumentPreviewDialog
            v-model:visible="documentPreviewVisible"
            :title="documentPreviewTitle"
            :source="documentPreviewSource"
            @hide="clearDocumentPreview"
        />
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
    display: flex;
    gap: 0.65rem;
    max-width: 48rem;
    width: 100%;
}

.archive-box-search__field {
    align-items: center;
    border: 1px solid #d8e1ec;
    border-radius: 0.4rem;
    display: flex;
    gap: 0.65rem;
    min-width: 14rem;
    padding: 0 0.75rem;
    width: 100%;
}

.archive-box-search__field i {
    color: #64748b;
}

.archive-box-search__field :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    min-height: 2.4rem;
    padding-left: 0;
    width: 100%;
}

.archive-box-search__actions {
    display: flex;
    flex-shrink: 0;
    gap: 0.65rem;
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

.archive-table-actions__view {
    align-items: center;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 0.35rem;
    color: #0f4c8f;
    cursor: pointer;
    display: inline-flex;
    height: 2rem;
    justify-content: center;
    width: 2rem;
}

.archive-table-actions__view:hover {
    background: #dbeafe;
}

.archive-document-unavailable {
    color: #94a3b8;
    font-size: 0.8rem;
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
        flex-wrap: wrap;
    }

    .archive-box-search__actions {
        width: 100%;
    }

    .archive-box-search__actions :deep(.p-button) {
        flex: 1;
    }
}
</style>
