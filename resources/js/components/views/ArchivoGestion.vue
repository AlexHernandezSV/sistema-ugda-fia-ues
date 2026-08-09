<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';
import QRCode from 'qrcode';

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

    const labelCode = box.box_code || box.boxCode || box.code || `C-AG-${box.number}`;
    const label = {
        boxLabel: labelCode,
        labelCode,
        transferNumber: 'Archivo General',
        transferDate: currentDateLabel(),
        unit: box.unit?.name ?? 'Unidad no registrada',
        content: box.series ?? box.title ?? 'Serie documental no registrada',
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
        popup.close();
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

    popup.document.open();
    popup.document.write(`
        <!DOCTYPE html>
        <html lang="es">
            <head>
                <meta charset="utf-8" />
                <title>Etiqueta ${safeLabel.labelCode}</title>
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
                        <div class="transfer-label__date"><span>Fecha</span><strong>${safeLabel.transferDate}</strong></div>
                    </div>
                    <div class="transfer-label__hero">
                        <div class="transfer-label__qr"><img src="${label.qrDataUrl}" alt="Codigo QR ${safeLabel.labelCode}" /><span>Codigo: ${safeLabel.labelCode}</span></div>
                        <div>
                            <div class="transfer-label__location"><span>CÓDIGO DE UBICACIÓN</span><strong>${safeLabel.locationCode}</strong></div>
                            <div class="transfer-label__box"><span>N de caja</span><strong>${safeLabel.boxLabel}</strong></div>
                        </div>
                    </div>
                    <div class="transfer-label__details">
                        <div><span>N de transferencia</span><strong>${safeLabel.transferNumber}</strong></div>
                        <div><span>Unidad productora</span><strong>${safeLabel.unit}</strong></div>
                        <div><span>Contenido de la caja</span><strong>${safeLabel.content}</strong></div>
                    </div>
                    <div class="transfer-label__assigned">Ubicación reasignada por ${safeLabel.assignedBy} el ${safeLabel.assignedAt}</div>
                    <div class="transfer-label__footer">Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicación.</div>
                </article>
                <script>window.onload = () => { window.focus(); window.print(); };<\/script>
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
