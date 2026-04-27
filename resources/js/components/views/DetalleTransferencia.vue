<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import QRCode from 'qrcode';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const transfer = ref(null);
const loading = ref(true);
const expandedBoxes = ref({});
const allBoxesExpanded = ref(false);
const commentDraft = ref('');
const labelsDialogVisible = ref(false);
const labelsPreviewRef = ref(null);
const labelPreviewCards = ref([]);
const labelPreviewItems = ref([]);
const labelsBusy = ref(false);
const decisionModalVisible = ref(false);
const decisionSubmitting = ref(false);
const currentDecisionAction = ref(null);

const decisionForm = ref({
    date: '',
    time: '',
    comments: '',
});

const buildExpandedState = (boxes, displayState) => {
    if (displayState === 'all') {
        return boxes.reduce((acc, box) => ({
            ...acc,
            [box.number]: true,
        }), {});
    }

    if (displayState === 'first') {
        return boxes.reduce((acc, box, index) => ({
            ...acc,
            [box.number]: index === 0,
        }), {});
    }

    return boxes.reduce((acc, box) => ({
        ...acc,
        [box.number]: false,
    }), {});
};

const goBack = () => {
    router.push('/solicitudes');
};

const toggleBox = (boxNumber) => {
    expandedBoxes.value[boxNumber] = !expandedBoxes.value[boxNumber];
    allBoxesExpanded.value = (transfer.value?.boxes ?? []).length > 0
        && (transfer.value?.boxes ?? []).every((box) => expandedBoxes.value[box.number]);
};

const toggleAllBoxes = () => {
    const nextState = !allBoxesExpanded.value;

    expandedBoxes.value = (transfer.value?.boxes ?? []).reduce((acc, box) => ({
        ...acc,
        [box.number]: nextState,
    }), {});
    allBoxesExpanded.value = nextState;
};

const authChipClass = (status) => {
    switch (status) {
        case 'Autorizado':
            return 'detalle-chip detalle-chip--success';
        case 'Denegado':
            return 'detalle-chip detalle-chip--danger';
        case 'Pendiente':
        default:
            return 'detalle-chip detalle-chip--neutral';
    }
};

const statusChipClass = (status) => {
    switch (status) {
        case 'Transferido':
        case 'Finalizada':
            return 'detalle-chip detalle-chip--success';
        case 'Agendado':
        case 'En proceso':
            return 'detalle-chip detalle-chip--info';
        case 'Observado':
        case 'Pendiente':
        case 'Cancelada':
            return 'detalle-chip detalle-chip--warning';
        case 'Denegado':
            return 'detalle-chip detalle-chip--danger';
        default:
            return 'detalle-chip detalle-chip--neutral';
    }
};

const supportChipClass = (support) => {
    switch (support) {
        case 'Digital':
            return 'detalle-support-chip detalle-support-chip--info';
        case 'Mixto':
            return 'detalle-support-chip detalle-support-chip--accent';
        default:
            return 'detalle-support-chip detalle-support-chip--neutral';
    }
};

const compactDate = (value) => {
    if (!value) {
        return '';
    }

    return String(value).split(' ')[0] ?? value;
};

const compactTime = (value) => {
    if (!value || !String(value).includes(' ')) {
        return '';
    }

    return String(value).split(' ')[1] ?? '';
};

const escapeHtml = (value = '') => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

const buildPeriodYearLabel = (period) => {
    if (!period) {
        return '';
    }

    const years = String(period).match(/\d{4}/g) ?? [];

    if (years.length === 0) {
        return period;
    }

    if (years[0] === years[years.length - 1]) {
        return years[0];
    }

    return `${years[0]}-${years[years.length - 1]}`;
};

const extractBoxContent = (box) => {
    if (box.title && box.title.includes(' - ')) {
        return box.title.split(' - ').slice(1).join(' - ');
    }

    const firstDocument = box.documents?.[0];
    const contentLabel = firstDocument?.series || firstDocument?.name || `Documentacion de caja ${box.number}`;
    const periodLabel = buildPeriodYearLabel(box.period);

    return periodLabel ? `${contentLabel} - Periodo ${periodLabel}` : contentLabel;
};

const buildLocationCode = (box, index) => {
    const suffix = String(box.number ?? index + 1).padStart(2, '0');
    return `A-03-2-${suffix}`;
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

const buildLabelPreviewItems = async (data) => {
    const transferDate = compactDate(data.completion?.date || data.receivedAt);
    const assignedDate = data.completion
        ? `${data.completion.date} ${data.completion.time}`.trim()
        : data.authorizedAt || data.receivedAt || '';
    const assignedBy = data.completion?.completedBy
        || data.authorizedBy
        || 'UGDA';

    return Promise.all((data.boxes ?? []).map(async (box, index) => ({
        boxNumber: box.number,
        boxLabel: `#${box.number}`,
        transferNumber: data.number,
        transferDate,
        unit: data.unit,
        content: extractBoxContent(box),
        period: box.period,
        locationCode: buildLocationCode(box, index),
        labelCode: `${data.number}-${box.number}`,
        assignedBy,
        assignedAt: assignedDate,
        qrDataUrl: await buildQrDataUrl(`${data.number}-${box.number}-${data.unit}`),
    })));
};

const openLabelsPreview = () => {
    labelsDialogVisible.value = true;
    requestAnimationFrame(() => {
        labelsPreviewRef.value?.scrollTo({ top: 0, behavior: 'auto' });
    });
};

const setLabelPreviewCard = (element, index) => {
    if (element) {
        labelPreviewCards.value[index] = element;
    }
};

const buildLabelsDocument = () => {
    const items = labelPreviewItems.value;
    const title = transfer.value ? `Etiquetas de transferencia ${transfer.value.number}` : 'Etiquetas de identificacion';
    const cards = items.map((label) => `
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
                    <img src="${label.qrDataUrl}" alt="Codigo QR ${escapeHtml(label.labelCode)}" />
                    <span>Codigo: ${escapeHtml(label.labelCode)}</span>
                </div>

                <div class="print-label-card__hero-main">
                    <div class="print-label-card__location">
                        <span>CODIGO DE UBICACION</span>
                        <strong>${escapeHtml(label.locationCode)}</strong>
                    </div>
                    <div class="print-label-card__box">
                        <span>Numero de caja</span>
                        <strong>${escapeHtml(label.boxLabel)}</strong>
                    </div>
                </div>
            </div>

            <div class="print-label-card__details">
                <div>
                    <span>N. de transferencia</span>
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
                Ubicacion asignada por ${escapeHtml(label.assignedBy)} el ${escapeHtml(label.assignedAt)}
            </div>

            <div class="print-label-card__footer">
                Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicacion.
            </div>
        </article>
    `).join('');

    return `
        <!DOCTYPE html>
        <html lang="es">
            <head>
                <meta charset="utf-8" />
                <title>${escapeHtml(title)}</title>
                <style>
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        font-family: Arial, Helvetica, sans-serif;
                        background: #f3f6fb;
                        color: #0f172a;
                        padding: 20px;
                    }
                    .print-sheet {
                        display: flex;
                        flex-direction: column;
                        gap: 24px;
                    }
                    .print-label-card {
                        background: #ffffff;
                        border: 2px solid #1f2937;
                        border-radius: 24px;
                        padding: 24px;
                        page-break-inside: avoid;
                    }
                    .print-label-card__header,
                    .print-label-card__hero,
                    .print-label-card__details {
                        display: flex;
                        justify-content: space-between;
                        gap: 24px;
                    }
                    .print-label-card__header {
                        align-items: flex-start;
                        padding-bottom: 18px;
                        border-bottom: 1px solid #d9e2ec;
                    }
                    .print-label-card__university {
                        font-size: 28px;
                        font-weight: 800;
                        letter-spacing: 0.01em;
                    }
                    .print-label-card__faculty {
                        margin-top: 8px;
                        color: #64748b;
                        font-size: 16px;
                    }
                    .print-label-card__date {
                        min-width: 220px;
                        text-align: right;
                    }
                    .print-label-card__date span,
                    .print-label-card__box span,
                    .print-label-card__details span,
                    .print-label-card__assigned,
                    .print-label-card__qr span {
                        color: #64748b;
                        font-size: 14px;
                    }
                    .print-label-card__date strong {
                        display: block;
                        margin-top: 8px;
                        color: #0f172a;
                        font-size: 24px;
                    }
                    .print-label-card__hero {
                        align-items: center;
                        padding: 20px 0;
                        border-bottom: 1px solid #d9e2ec;
                    }
                    .print-label-card__qr {
                        width: 160px;
                        text-align: center;
                        flex-shrink: 0;
                    }
                    .print-label-card__qr img {
                        width: 120px;
                        height: 120px;
                        display: block;
                        margin: 0 auto 10px;
                    }
                    .print-label-card__hero-main {
                        flex: 1;
                    }
                    .print-label-card__location {
                        background: #2f6f9f;
                        border-radius: 18px;
                        padding: 18px 24px;
                        text-align: center;
                        color: #ffffff;
                    }
                    .print-label-card__location span {
                        display: block;
                        font-size: 14px;
                        font-weight: 700;
                        letter-spacing: 0.04em;
                    }
                    .print-label-card__location strong {
                        display: block;
                        margin-top: 8px;
                        font-size: 34px;
                        font-weight: 800;
                        letter-spacing: 0.08em;
                    }
                    .print-label-card__box {
                        margin-top: 16px;
                        border-radius: 16px;
                        background: #edf3fa;
                        padding: 14px 18px;
                    }
                    .print-label-card__box strong {
                        display: block;
                        margin-top: 4px;
                        color: #0f172a;
                        font-size: 28px;
                    }
                    .print-label-card__details {
                        flex-wrap: wrap;
                        padding: 20px 0 10px;
                    }
                    .print-label-card__details > div {
                        flex: 1 1 240px;
                    }
                    .print-label-card__details strong {
                        display: block;
                        margin-top: 8px;
                        font-size: 20px;
                        color: #0f172a;
                    }
                    .print-label-card__details--full {
                        flex-basis: 100%;
                    }
                    .print-label-card__assigned {
                        padding-top: 14px;
                        border-top: 1px solid #d9e2ec;
                    }
                    .print-label-card__footer {
                        margin-top: 18px;
                        background: #fff7db;
                        border-radius: 0 0 18px 18px;
                        padding: 12px 16px;
                        color: #b45309;
                        font-size: 13px;
                        font-weight: 600;
                    }
                    @media print {
                        body {
                            background: #ffffff;
                            padding: 0;
                        }
                        .print-sheet {
                            gap: 18px;
                        }
                        .print-label-card {
                            margin: 0;
                        }
                    }
                </style>
            </head>
            <body>
                <main class="print-sheet">${cards}</main>
            </body>
        </html>
    `;
};

const printLabels = () => {
    const popup = window.open('', '_blank', 'noopener,noreferrer,width=1200,height=900');

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
    if (!transfer.value || labelPreviewCards.value.length === 0 || labelsBusy.value) {
        return;
    }

    labelsBusy.value = true;
    let exportHost = null;

    try {
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'pt',
            format: 'letter',
        });

        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const margin = 22;
        const maxWidth = pageWidth - (margin * 2);
        const maxHeight = pageHeight - (margin * 2);

        exportHost = document.createElement('div');
        exportHost.style.position = 'fixed';
        exportHost.style.left = '-10000px';
        exportHost.style.top = '0';
        exportHost.style.width = '1100px';
        exportHost.style.pointerEvents = 'none';
        exportHost.style.zIndex = '-1';
        document.body.appendChild(exportHost);

        for (let index = 0; index < labelPreviewCards.value.length; index += 1) {
            const card = labelPreviewCards.value[index];
            const exportCard = card.cloneNode(true);
            exportCard.classList.add('transfer-label--export');
            exportCard.style.width = `${card.clientWidth}px`;
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

            const imageData = canvas.toDataURL('image/png');
            const ratio = Math.min(maxWidth / canvas.width, maxHeight / canvas.height);
            const renderWidth = canvas.width * ratio;
            const renderHeight = canvas.height * ratio;
            const offsetX = (pageWidth - renderWidth) / 2;
            const offsetY = (pageHeight - renderHeight) / 2;

            if (index > 0) {
                pdf.addPage();
            }

            pdf.addImage(imageData, 'PNG', offsetX, offsetY, renderWidth, renderHeight, undefined, 'FAST');
            exportCard.remove();
        }

        pdf.save(`etiquetas-transferencia-${transfer.value.number}.pdf`);
        exportHost.remove();
    } catch (error) {
        console.error('Error generando PDF de etiquetas:', error);
        toast.add({
            severity: 'error',
            summary: 'No se pudo descargar',
            detail: 'Ocurrio un problema al generar el PDF de etiquetas.',
            life: 3500,
        });
    } finally {
        exportHost?.remove();
        document.querySelectorAll('.transfer-label--export').forEach((node) => node.remove());
        labelsBusy.value = false;
    }
};

const showError = (message) => {
    toast.add({
        severity: 'error',
        summary: 'Error',
        detail: message,
        life: 3200,
    });
};

const resetDecisionForm = () => {
    decisionForm.value = {
        date: '',
        time: '',
        comments: '',
    };
    currentDecisionAction.value = null;
};

const normalizeScheduledFor = (date, time) => {
    const trimmedDate = String(date ?? '').trim();
    const trimmedTime = String(time ?? '').trim();

    const match = trimmedDate.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);

    if (!match) {
        return null;
    }

    const [, day, month, year] = match;
    return `${year}-${month}-${day} ${trimmedTime}:00`;
};

const boxTitle = (box) => box.title || `Caja #${box.number}`;

const actionClass = (variant) => {
    switch (variant) {
        case 'warning':
            return 'detalle-action detalle-action--warning';
        case 'danger':
            return 'detalle-action detalle-action--danger';
        case 'secondary':
            return 'detalle-action detalle-action--secondary';
        default:
            return 'detalle-action detalle-action--primary';
    }
};

const postTransferAction = async (url, payload, fallbackMessage) => {
    try {
        const { data } = await axios.post(url, payload);
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: data.message || fallbackMessage,
            life: 2800,
        });
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || fallbackMessage || 'No se pudo actualizar la transferencia.');
    }
};

const openDecisionModal = (actionKey) => {
    currentDecisionAction.value = actionKey;
    decisionForm.value = {
        date: '',
        time: '',
        comments: '',
    };
    decisionModalVisible.value = true;
};

const decisionModalConfig = computed(() => {
    switch (currentDecisionAction.value) {
        case 'schedule':
            return {
                icon: 'pi pi-calendar',
                title: 'Programar Entrega',
                subtitle: 'Proporcione la fecha y hora para la entrega de la transferencia',
                confirmLabel: 'Programar',
                cancelLabel: 'Cancelar',
                tone: 'primary',
                required: true,
            };
        case 'observe':
            return {
                icon: 'pi pi-eye',
                title: 'Observar Transferencia',
                subtitle: 'Debe proporcionar una observacion para esta transferencia',
                label: 'Observacion',
                placeholder: 'Describa la observacion para esta transferencia...',
                confirmLabel: 'Observar',
                cancelLabel: 'Cancelar',
                tone: 'warning',
                required: true,
            };
        case 'deny':
            return {
                icon: 'pi pi-times-circle',
                title: 'Denegar Transferencia',
                subtitle: 'Debe proporcionar un motivo para denegar esta transferencia',
                label: 'Motivo de denegacion',
                placeholder: 'Describa el motivo por el cual se deniega esta transferencia...',
                confirmLabel: 'Denegar',
                cancelLabel: 'Cancelar',
                tone: 'danger',
                required: true,
            };
        default:
            return null;
    }
});

const isDecisionSubmitDisabled = computed(() => {
    if (decisionSubmitting.value || !decisionModalConfig.value) {
        return true;
    }

    if (currentDecisionAction.value === 'schedule') {
        return String(decisionForm.value.date ?? '').trim() === '' || String(decisionForm.value.time ?? '').trim() === '';
    }

    if (decisionModalConfig.value.required) {
        return String(decisionForm.value.comments ?? '').trim() === '';
    }

    return false;
});

const handleAction = async (action) => {
    if (action.key === 'back') {
        goBack();
        return;
    }

    if (action.key === 'schedule') {
        openDecisionModal('schedule');
        return;
    }

    if (action.key === 'observe') {
        openDecisionModal('observe');
        return;
    }

    if (action.key === 'deny') {
        openDecisionModal('deny');
    }
};

const submitDecisionAction = async () => {
    const config = decisionModalConfig.value;

    if (!config || !transfer.value) {
        return;
    }

    const comments = decisionForm.value.comments.trim();

    if (currentDecisionAction.value === 'schedule') {
        if (!decisionForm.value.date || !decisionForm.value.time) {
            showError('Debe ingresar la fecha y hora de entrega.');
            return;
        }
    }

    if (config.required && currentDecisionAction.value !== 'schedule' && comments === '') {
        showError(currentDecisionAction.value === 'observe'
            ? 'Debe ingresar la observacion de la transferencia.'
            : 'Debe ingresar el motivo de rechazo.');
        return;
    }

    let endpoint = '';
    let payload = {};
    let successMessage = '';

    switch (currentDecisionAction.value) {
        case 'schedule':
            {
                const scheduledFor = normalizeScheduledFor(decisionForm.value.date, decisionForm.value.time);

                if (!scheduledFor) {
                    showError('La fecha de entrega debe tener el formato dd/mm/aaaa.');
                    return;
                }

            endpoint = `/api/transfers/${transfer.value.number}/schedule`;
            payload = {
                scheduled_for: scheduledFor,
                comments,
            };
            successMessage = 'Entrega programada.';
            break;
            }
        case 'observe':
            endpoint = `/api/transfers/${transfer.value.number}/observe`;
            payload = { message: comments };
            successMessage = 'Transferencia observada.';
            break;
        case 'deny':
            endpoint = `/api/transfers/${transfer.value.number}/deny`;
            payload = { reason: comments };
            successMessage = 'Transferencia denegada.';
            break;
        default:
            return;
    }

    decisionSubmitting.value = true;

    try {
        const { data } = await axios.post(endpoint, payload);
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: data.message || successMessage,
            life: 2800,
        });
        decisionModalVisible.value = false;
        resetDecisionForm();
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo completar la accion.');
    } finally {
        decisionSubmitting.value = false;
    }
};

const fetchDetail = async () => {
    try {
        const { data } = await axios.get(`/api/transfers/${route.params.number}`);
        transfer.value = data;
        labelPreviewCards.value = [];

        const boxes = data.boxes ?? [];
        expandedBoxes.value = buildExpandedState(boxes, data.boxDisplayState);
        allBoxesExpanded.value = boxes.length > 0 && boxes.every((box) => expandedBoxes.value[box.number]);
        labelPreviewItems.value = await buildLabelPreviewItems(data);
    } catch (error) {
        console.error('Error cargando detalle de transferencia:', error);
        router.push('/solicitudes');
    } finally {
        loading.value = false;
    }
};

const isReviewMode = computed(() => transfer.value?.mode === 'review');
const pageTitle = computed(() => transfer.value?.pageTitle ?? (transfer.value ? `Transferencia #${transfer.value.number}` : 'Transferencia'));
const pageSubtitle = computed(() => transfer.value?.pageSubtitle ?? 'Detalle de la solicitud');
const showHistory = computed(() => !isReviewMode.value && (transfer.value?.history?.length ?? 0) > 0);
const showDescription = computed(() => !isReviewMode.value && Boolean(transfer.value?.description));
const showPrintCard = computed(() => Boolean(transfer.value?.showPrintCard) && !transfer.value?.scheduledSummary);
const hasReviewActions = computed(() => (transfer.value?.actions?.length ?? 0) > 0);

onMounted(fetchDetail);
</script>

<template>
    <Toast />

    <div v-if="loading" class="surface-card border-round-xl shadow-1 p-4 text-center text-gray-500">
        Cargando detalle de transferencia...
    </div>

    <div v-else-if="transfer" class="flex flex-column gap-4">
        <div class="mobile-page-title mb-1">
            <div class="flex align-items-center gap-3 mb-2">
                <button type="button" class="detalle-back-button" @click="goBack">
                    <i class="pi pi-arrow-left"></i>
                </button>
                <div>
                    <h1 class="detalle-title m-0">{{ pageTitle }}</h1>
                    <span class="detalle-subtitle">{{ pageSubtitle }}</span>
                </div>
            </div>
        </div>

        <div v-if="transfer.completion" class="detalle-banner detalle-banner--success">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--success">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">Transferencia Completada</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.completion.message }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <span class="detalle-label">Fecha de completacion</span>
                        <div class="detalle-value">{{ transfer.completion.date }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <span class="detalle-label">Hora de completacion</span>
                        <div class="detalle-value">{{ transfer.completion.time }}</div>
                    </div>
                </div>
                <div class="detalle-banner__footer">
                    <i class="pi pi-user mr-2"></i>
                    Completado por {{ transfer.completion.completedBy }}
                </div>
            </div>
        </div>

        <div v-else-if="transfer.scheduledSummary" class="detalle-banner detalle-banner--info">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--info">
                    <i class="pi pi-clock"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.scheduledSummary.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.scheduledSummary.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="grid">
                    <div class="col-12 md:col-6">
                        <span class="detalle-label">Fecha de entrega</span>
                        <div class="detalle-value">{{ transfer.scheduledSummary.deliveryDate }}</div>
                    </div>
                    <div class="col-12 md:col-6">
                        <span class="detalle-label">Hora de entrega</span>
                        <div class="detalle-value">{{ transfer.scheduledSummary.deliveryTime }}</div>
                    </div>
                </div>
                <div class="detalle-banner__footer">
                    <div class="font-semibold mb-2 text-900">Comentarios adicionales</div>
                    <p class="m-0">{{ transfer.scheduledSummary.comments }}</p>
                </div>
                <div class="detalle-banner__footer">
                    <i class="pi pi-calendar mr-2"></i>
                    Programado por {{ transfer.scheduledSummary.scheduledBy }} el {{ transfer.scheduledSummary.scheduledAt }}
                </div>
            </div>
        </div>

        <div v-if="transfer.processCard" class="surface-card shadow-1 border-round-xl p-4 detalle-process-card">
            <div class="flex flex-column md:flex-row md:align-items-center md:justify-content-between gap-3">
                <div class="flex align-items-start gap-3">
                    <div class="detalle-process-card__icon">
                        <i class="pi pi-box text-xl"></i>
                    </div>
                    <div>
                        <h3 class="m-0 text-900 text-xl font-bold">{{ transfer.processCard.title }}</h3>
                        <p class="m-0 text-600 mt-2">{{ transfer.processCard.description }}</p>
                    </div>
                </div>
                <Button :label="transfer.processCard.buttonLabel" icon="pi pi-box" class="detalle-process-card__button" @click="openLabelsPreview" />
            </div>
        </div>

        <div v-if="showPrintCard" class="surface-card shadow-1 border-round-xl p-4 detalle-print-card">
            <div class="flex flex-column md:flex-row md:align-items-center md:justify-content-between gap-3">
                <div class="flex align-items-start gap-3">
                    <div class="detalle-print-card__icon">
                        <i class="pi pi-print text-xl"></i>
                    </div>
                    <div>
                        <h3 class="m-0 text-900 text-xl font-bold">Etiquetas de Identificacion</h3>
                        <p class="m-0 text-600 mt-2">Imprima las etiquetas con codigos QR para identificar y ubicar facilmente las cajas</p>
                    </div>
                </div>
                <Button label="Imprimir etiquetas" icon="pi pi-print" class="detalle-print-card__button" @click="openLabelsPreview" />
            </div>
        </div>

        <div v-else-if="transfer.observationsRegistered" class="detalle-banner detalle-banner--warning">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--warning">
                    <i class="pi pi-eye"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.observationsRegistered.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.observationsRegistered.description }}</p>
                </div>
            </div>

            <div class="flex flex-column gap-3">
                <div v-for="item in transfer.observationsRegistered.items" :key="`${item.actor}-${item.dateTime}`" class="detalle-observation-card">
                    <div class="detalle-observation-card__meta">
                        <i class="pi pi-user"></i>
                        <span class="font-semibold">{{ item.actor }}</span>
                        <span>{{ item.dateTime }}</span>
                    </div>
                    <p class="detalle-observation-card__message m-0">{{ item.message }}</p>
                </div>
            </div>
        </div>

        <div v-else-if="transfer.workflowNotice" class="detalle-notice">
            <div class="detalle-notice__icon">
                <i class="pi pi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="detalle-notice__title">{{ transfer.workflowNotice.title }}</div>
                <p class="detalle-notice__text m-0">{{ transfer.workflowNotice.description }}</p>
            </div>
        </div>

        <div v-else-if="transfer.decisionCard" :class="['detalle-banner', 'detalle-banner--danger']">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--danger">
                    <i :class="transfer.decisionCard.icon"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.decisionCard.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.decisionCard.subtitle }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel detalle-banner__panel--danger">
                <div class="detalle-observation-card__meta detalle-observation-card__meta--danger">
                    <i class="pi pi-user"></i>
                    <span class="font-semibold">{{ transfer.decisionCard.actor }}</span>
                    <span>{{ transfer.decisionCard.dateTime }}</span>
                </div>
                <div class="detalle-banner__reason">
                    <div class="font-semibold mb-2">{{ transfer.decisionCard.reasonLabel }}</div>
                    <p class="m-0">{{ transfer.decisionCard.reason }}</p>
                </div>
            </div>
        </div>

        <template v-if="isReviewMode">
            <div class="detalle-panel">
                <div class="detalle-panel__header">Datos Generales</div>
                <div class="detalle-panel__body">
                    <div class="flex flex-column md:flex-row md:justify-content-end gap-2 mb-4">
                        <span :class="authChipClass(transfer.auth)">{{ transfer.auth }}</span>
                        <span :class="statusChipClass(transfer.status)">{{ transfer.status }}</span>
                    </div>

                    <div class="grid">
                        <div class="col-12 md:col-6">
                            <div class="flex flex-column gap-4">
                                <div>
                                    <span class="detalle-label">N° de transferencia documental</span>
                                    <div class="detalle-value">{{ transfer.number }}</div>
                                </div>
                                <div>
                                    <span class="detalle-label">Unidad productora</span>
                                    <div class="detalle-value">{{ transfer.unit }}</div>
                                </div>
                                <div v-if="transfer.authorizedBy">
                                    <span class="detalle-label">Autorizado por</span>
                                    <div class="detalle-value">{{ transfer.authorizedBy }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 md:col-6">
                            <div class="flex flex-column gap-4">
                                <div>
                                    <span class="detalle-label">Fecha de solicitud</span>
                                    <div class="detalle-value">{{ transfer.receivedAt }}</div>
                                </div>
                                <div>
                                    <span class="detalle-label">Responsable del envio</span>
                                    <div class="detalle-value">{{ transfer.responsible }}</div>
                                </div>
                                <div v-if="transfer.authorizedAt">
                                    <span class="detalle-label">Fecha de autorizacion</span>
                                    <div class="detalle-value">{{ transfer.authorizedAt }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="detalle-panel">
                <div class="detalle-panel__header">Detalle de Documentos</div>
                <div class="detalle-panel__body">
                    <div class="detalle-table-wrapper mb-4">
                        <table class="detalle-summary-table">
                            <thead>
                                <tr>
                                    <th>N°</th>
                                    <th>Serie / Subserie</th>
                                    <th>Fecha inicial</th>
                                    <th>Fecha final</th>
                                    <th>N° de caja</th>
                                    <th>Documentos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in transfer.documentSummary?.rows ?? []" :key="row.index">
                                    <td>{{ row.index }}</td>
                                    <td>{{ row.series }}</td>
                                    <td>{{ row.startYear }}</td>
                                    <td>{{ row.endYear }}</td>
                                    <td>{{ row.boxNumber }}</td>
                                    <td>
                                        <span class="detalle-summary-badge">{{ row.documentsLabel }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="detalle-summary-card">
                        <div class="font-semibold text-900">Total de cajas: {{ transfer.documentSummary?.totalBoxes }}</div>
                        <div class="text-600 mt-2">Total de documentos: {{ transfer.documentSummary?.summaryText }}</div>
                    </div>

                    <h3 class="detalle-section-caption">{{ transfer.documentSummary?.boxesTitle }}</h3>

                    <div class="flex flex-column gap-3">
                        <div v-for="box in transfer.boxes" :key="box.number" class="detalle-box-card">
                            <button type="button" class="detalle-box-card__header" @click="toggleBox(box.number)">
                                <div class="flex align-items-center gap-3 min-w-0">
                                    <div class="detalle-box-card__icon">
                                        <i class="pi pi-box"></i>
                                    </div>
                                    <div class="text-left min-w-0">
                                        <div class="detalle-box-card__title">{{ boxTitle(box) }}</div>
                                        <div class="detalle-box-card__subtitle">Periodo: {{ box.period }} • {{ box.documentsCount }} documentos</div>
                                    </div>
                                </div>
                                <i :class="expandedBoxes[box.number] ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                            </button>

                            <div v-if="expandedBoxes[box.number]" class="detalle-box-card__content">
                                <div class="detalle-table-wrapper">
                                    <table class="detalle-documents-table">
                                        <thead>
                                            <tr>
                                                <th>Nombre del documento</th>
                                                <th>Codigo</th>
                                                <th>Serie del documento</th>
                                                <th>Soporte</th>
                                                <th>Año</th>
                                                <th>Paginas</th>
                                                <th>Archivo digital</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="document in box.documents" :key="`${box.number}-${document.code}`">
                                                <td>
                                                    <div class="detalle-document-name">
                                                        <i class="pi pi-file text-blue-600"></i>
                                                        <span>{{ document.name }}</span>
                                                    </div>
                                                </td>
                                                <td>{{ document.code }}</td>
                                                <td>{{ document.series }}</td>
                                                <td>
                                                    <span :class="supportChipClass(document.support)">{{ document.support }}</span>
                                                </td>
                                                <td>{{ document.year }}</td>
                                                <td>{{ document.pages }}</td>
                                                <td>
                                                    <a
                                                        v-if="document.digitalAvailable"
                                                        class="detalle-document-file detalle-document-link"
                                                        :href="document.digitalUrl"
                                                        :download="document.digitalFile"
                                                    >
                                                        <i class="pi pi-download"></i>
                                                        <span>{{ document.digitalFile }}</span>
                                                    </a>
                                                    <span v-else class="detalle-document-file detalle-document-file--empty">N/A</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="hasReviewActions" class="detalle-panel">
                <div class="detalle-panel__header">Observaciones adicionales</div>
                <div class="detalle-panel__body">
                    <label class="detalle-textarea-label" for="comentario-transferencia">Comentarios (opcional)</label>
                    <textarea
                        id="comentario-transferencia"
                        v-model="commentDraft"
                        rows="4"
                        class="detalle-textarea"
                        placeholder="Agregue cualquier observacion o comentario sobre esta solicitud..."
                    ></textarea>
                </div>
            </div>

            <div v-if="hasReviewActions" class="detalle-actions">
                <button
                    v-for="action in transfer.actions ?? []"
                    :key="action.key"
                    type="button"
                    :class="actionClass(action.variant)"
                    @click="handleAction(action)"
                >
                    <i v-if="action.icon" :class="action.icon"></i>
                    <span>{{ action.label }}</span>
                </button>
            </div>
        </template>

        <template v-else>
            <div class="surface-card shadow-1 border-round-xl p-4">
                <div class="flex flex-column md:flex-row md:justify-content-between md:align-items-start gap-3 mb-4">
                    <h3 class="m-0 text-900 text-2xl font-bold">Informacion General</h3>
                    <div class="flex gap-2 flex-wrap">
                        <span :class="authChipClass(transfer.auth)">{{ transfer.auth }}</span>
                        <span :class="statusChipClass(transfer.status)">{{ transfer.status }}</span>
                    </div>
                </div>

                <div class="grid">
                    <div class="col-12 md:col-6">
                        <div class="flex flex-column gap-4">
                            <div>
                                <span class="detalle-label">N° de transferencia documental</span>
                                <div class="detalle-value">{{ transfer.number }}</div>
                            </div>
                            <div>
                                <span class="detalle-label">Unidad productora</span>
                                <div class="detalle-value">{{ transfer.unit }}</div>
                            </div>
                            <div v-if="transfer.authorizedBy">
                                <span class="detalle-label">Autorizado por</span>
                                <div class="detalle-value">{{ transfer.authorizedBy }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 md:col-6">
                        <div class="flex flex-column gap-4">
                            <div>
                                <span class="detalle-label">Fecha de solicitud</span>
                                <div class="detalle-value">{{ transfer.receivedAt }}</div>
                            </div>
                            <div>
                                <span class="detalle-label">Responsable del envio</span>
                                <div class="detalle-value">{{ transfer.responsible }}</div>
                            </div>
                            <div v-if="transfer.authorizedAt">
                                <span class="detalle-label">Fecha de autorizacion</span>
                                <div class="detalle-value">{{ transfer.authorizedAt }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="showDescription" class="surface-card shadow-1 border-round-xl p-4">
                <h3 class="m-0 text-900 text-2xl font-bold mb-4">Descripcion</h3>
                <p class="m-0 text-700 text-lg line-height-3">{{ transfer.description }}</p>
            </div>

            <div class="surface-card shadow-1 border-round-xl p-4">
                <div class="flex flex-column md:flex-row md:justify-content-between md:align-items-center gap-3 mb-4">
                    <h3 class="m-0 text-900 text-2xl font-bold">Cajas y Documentos</h3>
                    <button type="button" class="detalle-link-button" @click="toggleAllBoxes">
                        {{ allBoxesExpanded ? 'Ocultar documentos' : 'Mostrar todos los documentos' }}
                        <i :class="allBoxesExpanded ? 'pi pi-chevron-up ml-2' : 'pi pi-chevron-down ml-2'"></i>
                    </button>
                </div>

                <div class="flex flex-column gap-3">
                    <div v-for="box in transfer.boxes" :key="box.number" class="detalle-box-card">
                        <button type="button" class="detalle-box-card__header" @click="toggleBox(box.number)">
                            <div class="flex align-items-center gap-3 min-w-0">
                                <div class="detalle-box-card__icon">
                                    <i class="pi pi-box"></i>
                                </div>
                                <div class="text-left min-w-0">
                                    <div class="detalle-box-card__title">{{ boxTitle(box) }}</div>
                                    <div class="detalle-box-card__subtitle">Periodo: {{ box.period }} • {{ box.documentsCount }} documentos</div>
                                </div>
                            </div>
                            <i :class="expandedBoxes[box.number] ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                        </button>

                        <div v-if="expandedBoxes[box.number]" class="detalle-box-card__content">
                            <div class="detalle-table-wrapper">
                                <table class="detalle-documents-table">
                                    <thead>
                                        <tr>
                                            <th>Nombre del documento</th>
                                            <th>Codigo</th>
                                            <th>Serie del documento</th>
                                            <th>Soporte</th>
                                            <th>Año</th>
                                            <th>Paginas</th>
                                            <th>Archivo digital</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="document in box.documents" :key="`${box.number}-${document.code}`">
                                            <td>
                                                <div class="detalle-document-name">
                                                    <i class="pi pi-file text-blue-600"></i>
                                                    <span>{{ document.name }}</span>
                                                </div>
                                            </td>
                                            <td>{{ document.code }}</td>
                                            <td>{{ document.series }}</td>
                                            <td>
                                                <span :class="supportChipClass(document.support)">{{ document.support }}</span>
                                            </td>
                                            <td>{{ document.year }}</td>
                                            <td>{{ document.pages }}</td>
                                            <td>
                                                <a
                                                    v-if="document.digitalAvailable"
                                                    class="detalle-document-file detalle-document-link"
                                                    :href="document.digitalUrl"
                                                    :download="document.digitalFile"
                                                >
                                                    <i class="pi pi-download"></i>
                                                    <span>{{ document.digitalFile }}</span>
                                                </a>
                                                <span v-else class="detalle-document-file detalle-document-file--empty">N/A</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="showHistory" class="surface-card shadow-1 border-round-xl p-4">
                <h3 class="m-0 text-900 text-2xl font-bold mb-4">Historial de Estados</h3>

                <div class="detalle-history">
                    <div v-for="(item, index) in transfer.history" :key="`${item.status}-${item.dateTime}`" class="detalle-history__item">
                        <div class="detalle-history__icon">
                            <i :class="item.icon"></i>
                        </div>
                        <div class="detalle-history__content">
                            <div class="flex flex-wrap align-items-center gap-3 mb-2">
                                <span class="detalle-history__status">{{ item.status }}</span>
                                <span class="detalle-history__date">{{ item.dateTime }}</span>
                            </div>
                            <p class="m-0 detalle-history__description">{{ item.description }}</p>
                        </div>
                        <div v-if="index !== transfer.history.length - 1" class="detalle-history__line"></div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <Dialog
        v-model:visible="decisionModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :style="{ width: 'min(560px, 94vw)' }"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
    >
        <div v-if="decisionModalConfig" class="detalle-decision-modal">
            <div class="detalle-decision-modal__body">
                <div
                    class="detalle-decision-modal__icon"
                    :class="`detalle-decision-modal__icon--${decisionModalConfig.tone}`"
                >
                    <i :class="decisionModalConfig.icon"></i>
                </div>

                <h2
                    class="detalle-decision-modal__title m-0"
                    :class="`detalle-decision-modal__title--${decisionModalConfig.tone}`"
                >
                    {{ decisionModalConfig.title }}
                </h2>

                <p class="detalle-decision-modal__subtitle m-0">
                    {{ decisionModalConfig.subtitle }}
                </p>

                <template v-if="currentDecisionAction === 'schedule'">
                    <div class="detalle-decision-modal__field">
                        <label class="detalle-decision-modal__label">
                            Fecha de entrega <span>*</span>
                        </label>
                        <input
                            v-model="decisionForm.date"
                            type="text"
                            class="detalle-decision-modal__input"
                            placeholder="dd/mm/aaaa"
                        />
                    </div>

                    <div class="detalle-decision-modal__field">
                        <label class="detalle-decision-modal__label">
                            Hora de entrega <span>*</span>
                        </label>
                        <input
                            v-model="decisionForm.time"
                            type="text"
                            class="detalle-decision-modal__input"
                            placeholder="--:--"
                        />
                    </div>

                    <div class="detalle-decision-modal__field">
                        <label class="detalle-decision-modal__label">
                            Comentarios (opcional)
                        </label>
                        <textarea
                            v-model="decisionForm.comments"
                            rows="4"
                            class="detalle-decision-modal__textarea"
                            placeholder="Agregue cualquier comentario adicional para la entrega..."
                        ></textarea>
                    </div>
                </template>

                <div v-else class="detalle-decision-modal__field">
                    <label class="detalle-decision-modal__label">
                        {{ decisionModalConfig.label }}
                        <span v-if="decisionModalConfig.required">*</span>
                    </label>
                    <textarea
                        v-model="decisionForm.comments"
                        rows="5"
                        class="detalle-decision-modal__textarea"
                        :placeholder="decisionModalConfig.placeholder"
                    ></textarea>
                </div>

                <div class="detalle-decision-modal__actions">
                    <button
                        type="button"
                        class="detalle-decision-modal__button detalle-decision-modal__button--cancel"
                        :class="`detalle-decision-modal__button--cancel-${decisionModalConfig.tone}`"
                        :disabled="decisionSubmitting"
                        @click="decisionModalVisible = false; resetDecisionForm()"
                    >
                        {{ decisionModalConfig.cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="detalle-decision-modal__button"
                        :class="`detalle-decision-modal__button--${decisionModalConfig.tone}`"
                        :disabled="isDecisionSubmitDisabled"
                        @click="submitDecisionAction"
                    >
                        {{ decisionSubmitting ? 'Procesando...' : decisionModalConfig.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="labelsDialogVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
        :style="{ width: 'min(1200px, 96vw)' }"
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
                            Vista previa de {{ labelPreviewItems.length }} etiquetas para la transferencia #{{ transfer?.number }}
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
                    <button type="button" class="detalle-labels-modal__close" @click="labelsDialogVisible = false">
                        <i class="pi pi-times"></i>
                    </button>
                </div>
            </div>

            <div class="detalle-labels-modal__body">
                <div ref="labelsPreviewRef" class="detalle-labels-preview">
                    <article
                        v-for="(label, index) in labelPreviewItems"
                        :key="label.labelCode"
                        :ref="(element) => setLabelPreviewCard(element, index)"
                        class="transfer-label"
                    >
                        <div class="transfer-label__header">
                            <div>
                                <div class="transfer-label__university">UNIVERSIDAD DE EL SALVADOR</div>
                                <div class="transfer-label__faculty">Facultad de Ingenieria y Arquitectura - UGDA</div>
                            </div>
                            <div class="transfer-label__date">
                                <span>Fecha de transferencia</span>
                                <strong>{{ label.transferDate }}</strong>
                            </div>
                        </div>

                        <div class="transfer-label__hero">
                            <div class="transfer-label__qr">
                                <img :src="label.qrDataUrl" :alt="`Codigo QR ${label.labelCode}`" />
                                <span>Codigo: {{ label.labelCode }}</span>
                            </div>

                            <div class="transfer-label__hero-main">
                                <div class="transfer-label__location">
                                    <span>CODIGO DE UBICACION</span>
                                    <strong>{{ label.locationCode }}</strong>
                                </div>

                                <div class="transfer-label__box">
                                    <span>Numero de caja</span>
                                    <strong>{{ label.boxLabel }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="transfer-label__details">
                            <div>
                                <span>N. de transferencia</span>
                                <strong>{{ label.transferNumber }}</strong>
                            </div>
                            <div>
                                <span>Unidad productora</span>
                                <strong>{{ label.unit }}</strong>
                            </div>
                            <div class="transfer-label__details--full">
                                <span>Contenido de la caja</span>
                                <strong>{{ label.content }}</strong>
                            </div>
                        </div>

                        <div class="transfer-label__assigned">
                            Ubicacion asignada por {{ label.assignedBy }} el {{ label.assignedAt }}
                        </div>

                        <div class="transfer-label__footer">
                            Instrucciones: Adherir esta etiqueta en la parte frontal de la caja de forma visible. Mantener limpia y legible para facilitar su ubicacion.
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </Dialog>
</template>

<style scoped>
.detalle-back-button {
    width: 2.75rem;
    height: 2.75rem;
    border: none;
    background: transparent;
    color: #2f6f9f;
    border-radius: 0.85rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}

.detalle-title {
    color: #2f6f9f;
    font-size: 2.2rem;
    line-height: 1.1;
    font-weight: 700;
}

.detalle-subtitle {
    color: #64748b;
    font-size: 1rem;
}

.detalle-decision-modal {
    background: #ffffff;
}

.detalle-decision-modal__body {
    padding: 2.25rem 2rem 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
}

.detalle-decision-modal__icon {
    width: 3.9rem;
    height: 3.9rem;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.65rem;
    margin-bottom: 1.5rem;
}

.detalle-decision-modal__icon--success {
    background: #ebf8ee;
    color: #56b657;
}

.detalle-decision-modal__icon--primary {
    background: #ecf4fc;
    color: #2f6f9f;
}

.detalle-decision-modal__icon--warning {
    background: #fff3e3;
    color: #f0a53a;
}

.detalle-decision-modal__icon--danger {
    background: #fde8e8;
    color: #e6554f;
}

.detalle-decision-modal__title {
    font-size: 1.15rem;
    font-weight: 700;
}

.detalle-decision-modal__title--success {
    color: #56b657;
}

.detalle-decision-modal__title--primary {
    color: #2f6f9f;
}

.detalle-decision-modal__title--warning {
    color: #f0a53a;
}

.detalle-decision-modal__title--danger {
    color: #e6554f;
}

.detalle-decision-modal__subtitle {
    margin-top: 1rem;
    color: #64748b;
    font-size: 0.95rem;
    line-height: 1.45;
    max-width: 24rem;
}

.detalle-decision-modal__field {
    width: 100%;
    margin-top: 1.5rem;
    text-align: left;
}

.detalle-decision-modal__label {
    display: block;
    color: #1f2937;
    font-size: 0.95rem;
    margin-bottom: 0.7rem;
}

.detalle-decision-modal__label span {
    color: #ef4444;
    margin-left: 0.2rem;
}

.detalle-decision-modal__textarea {
    width: 100%;
    min-height: 7rem;
    border: 1px solid #d7dee8;
    border-radius: 0.65rem;
    padding: 1rem;
    resize: none;
    font: inherit;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__input {
    width: 100%;
    height: 2.95rem;
    border: 1px solid #d7dee8;
    border-radius: 0.65rem;
    padding: 0 1rem;
    font: inherit;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__input:focus,
.detalle-decision-modal__textarea:focus {
    border-color: #94a3b8;
    box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.18);
}

.detalle-decision-modal__actions {
    width: 100%;
    display: flex;
    justify-content: center;
    gap: 0.85rem;
    margin-top: 1.55rem;
}

.detalle-decision-modal__button {
    min-width: 10rem;
    border-radius: 0.75rem;
    padding: 0.95rem 1.35rem;
    font-weight: 700;
    font-size: 0.98rem;
    cursor: pointer;
    border: 1px solid transparent;
    transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__button:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.detalle-decision-modal__button:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.detalle-decision-modal__button--success {
    background: #56b657;
    color: #ffffff;
}

.detalle-decision-modal__button--primary {
    background: #98b6d2;
    color: #ffffff;
}

.detalle-decision-modal__button--warning {
    background: #f7d18a;
    color: #ffffff;
}

.detalle-decision-modal__button--danger {
    background: #eda3a0;
    color: #ffffff;
}

.detalle-decision-modal__button--cancel {
    background: #ffffff;
}

.detalle-decision-modal__button--cancel-success {
    border-color: #7a8ea5;
    color: #64748b;
}

.detalle-decision-modal__button--cancel-primary {
    border-color: #2f6f9f;
    color: #2f6f9f;
}

.detalle-decision-modal__button--cancel-warning {
    border-color: #f0a53a;
    color: #f0a53a;
}

.detalle-decision-modal__button--cancel-danger {
    border-color: #fb5a55;
    color: #fb5a55;
}

.detalle-banner {
    border-radius: 1rem;
    padding: 1.5rem;
}

.detalle-banner--success {
    background: #d7f8e8;
    border-left: 4px solid #10b981;
}

.detalle-banner--info {
    background: #d6eef6;
    border-left: 4px solid #16a3c3;
}

.detalle-banner--warning {
    background: #fff3c4;
    border-left: 4px solid #f59e0b;
}

.detalle-banner--danger {
    background: #fee2e2;
    border-left: 4px solid #ef4444;
}

.detalle-banner__head {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.detalle-banner__icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 0.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    flex-shrink: 0;
    font-size: 1.15rem;
}

.detalle-banner__icon--success,
.detalle-print-card__icon {
    background: #10b981;
}

.detalle-banner__icon--warning {
    background: #f6ad3c;
}

.detalle-banner__icon--info {
    background: #16a3c3;
}

.detalle-banner__icon--danger {
    background: #ef4444;
}

.detalle-banner__title {
    color: #0f172a;
    font-size: 1.2rem;
    font-weight: 700;
}

.detalle-banner__text {
    color: #5b6576;
    margin-top: 0.35rem;
}

.detalle-banner__panel {
    background: #ffffff;
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}

.detalle-banner__panel--danger {
    border: 1px solid #f7b5b5;
}

.detalle-banner__footer {
    padding-top: 1rem;
    margin-top: 0.75rem;
    border-top: 1px solid #dbe7df;
    color: #475569;
}

.detalle-banner__reason {
    border-top: 1px solid #f7b5b5;
    padding-top: 1rem;
    margin-top: 1rem;
    color: #991b1b;
}

.detalle-print-card {
    border: 2px solid #10b981;
}

.detalle-process-card {
    border: 2px solid #2f6f9f;
}

.detalle-print-card__icon {
    width: 3rem;
    height: 3rem;
    border-radius: 0.95rem;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.detalle-print-card__button {
    background: #10b981;
    border: none;
}

.detalle-process-card__icon {
    width: 3rem;
    height: 3rem;
    border-radius: 0.95rem;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.detalle-process-card__button {
    background: #2f6f9f;
    border: none;
}

.detalle-observation-card {
    background: #ffffff;
    border-radius: 0.95rem;
    padding: 1rem 1.1rem;
}

.detalle-observation-card__meta {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    color: #9a6700;
    font-size: 0.92rem;
    flex-wrap: wrap;
}

.detalle-observation-card__meta--danger {
    color: #b91c1c;
}

.detalle-observation-card__message {
    color: #6b4b0f;
    margin-top: 0.85rem;
    line-height: 1.6;
}

.detalle-notice {
    display: flex;
    align-items: flex-start;
    gap: 0.9rem;
    background: #fff3c4;
    border-left: 4px solid #f6ad3c;
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}

.detalle-notice__icon {
    color: #f59e0b;
    font-size: 1.1rem;
    line-height: 1;
    margin-top: 0.1rem;
}

.detalle-notice__title {
    color: #9a6700;
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.detalle-notice__text {
    color: #7c5d15;
}

.detalle-panel {
    overflow: hidden;
    border-radius: 1rem;
    background: #ffffff;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
}

.detalle-panel__header {
    background: #2f6f9f;
    color: #ffffff;
    font-size: 1.15rem;
    font-weight: 700;
    padding: 1rem 1.3rem;
}

.detalle-panel__body {
    padding: 1.4rem 1.5rem 1.5rem;
}

.detalle-label {
    display: block;
    color: #64748b;
    font-size: 0.96rem;
    margin-bottom: 0.6rem;
}

.detalle-value {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.5;
}

.detalle-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.9rem;
    min-width: 6rem;
    padding: 0.28rem 0.9rem;
    border-radius: 9999px;
    font-size: 0.82rem;
    font-weight: 600;
    line-height: 1;
    white-space: nowrap;
}

.detalle-chip--success {
    background: #c9f2e8;
    color: #0c8a66;
}

.detalle-chip--danger {
    background: #fee2e2;
    color: #dc2626;
}

.detalle-chip--warning {
    background: #fde9a9;
    color: #b45309;
}

.detalle-chip--neutral {
    background: #d9dee7;
    color: #64748b;
}

.detalle-chip--info {
    background: #dbeafe;
    color: #2563eb;
}

.detalle-summary-table,
.detalle-documents-table {
    width: 100%;
    border-collapse: collapse;
}

.detalle-summary-table {
    min-width: 720px;
}

.detalle-documents-table {
    min-width: 860px;
}

.detalle-summary-table th,
.detalle-documents-table th {
    text-align: left;
    padding: 0.95rem 1rem;
    color: #486581;
    font-size: 0.9rem;
    font-weight: 700;
    text-transform: uppercase;
    border-bottom: 1px solid #d7dde6;
}

.detalle-summary-table td,
.detalle-documents-table td {
    padding: 1rem;
    color: #0f172a;
    font-size: 0.98rem;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}

.detalle-summary-table tbody tr:last-child td,
.detalle-documents-table tbody tr:last-child td {
    border-bottom: none;
}

.detalle-summary-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    padding: 0.3rem 0.8rem;
    background: #e8f0fa;
    color: #2f6f9f;
    font-size: 0.84rem;
    font-weight: 700;
}

.detalle-summary-card {
    background: #f8fafc;
    border-radius: 0.95rem;
    padding: 1rem 1.1rem;
    border: 1px solid #e2e8f0;
}

.detalle-section-caption {
    color: #0f172a;
    font-size: 1.15rem;
    font-weight: 700;
    margin: 1.5rem 0 1rem;
}

.detalle-link-button {
    border: none;
    background: transparent;
    color: #2f6f9f;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
}

.detalle-box-card {
    border: 1px solid #d7dde6;
    border-radius: 1rem;
    background: #ffffff;
    overflow: hidden;
}

.detalle-box-card__header {
    width: 100%;
    border: none;
    background: #f8fafc;
    padding: 1.15rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    cursor: pointer;
}

.detalle-box-card__icon {
    width: 2.45rem;
    height: 2.45rem;
    border-radius: 0.85rem;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.detalle-box-card__title {
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 700;
}

.detalle-box-card__subtitle {
    color: #64748b;
    font-size: 0.94rem;
    margin-top: 0.2rem;
}

.detalle-box-card__content {
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid #d7dde6;
}

.detalle-table-wrapper {
    overflow-x: auto;
}

.detalle-document-name,
.detalle-document-file {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}

.detalle-document-name {
    font-weight: 600;
}

.detalle-document-file {
    color: #2f6f9f;
}

.detalle-document-link {
    text-decoration: none;
}

.detalle-document-link:hover {
    text-decoration: underline;
}

.detalle-document-file--empty {
    color: #94a3b8;
}

.detalle-support-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.8rem;
    padding: 0.2rem 0.8rem;
    border-radius: 9999px;
    font-size: 0.84rem;
    font-weight: 600;
}

.detalle-support-chip--neutral {
    background: #eef2f7;
    color: #4b5563;
}

.detalle-support-chip--info {
    background: #dbeafe;
    color: #2563eb;
}

.detalle-support-chip--accent {
    background: #f3e8ff;
    color: #9333ea;
}

.detalle-textarea-label {
    display: inline-block;
    color: #0f172a;
    font-size: 0.95rem;
    margin-bottom: 0.75rem;
}

.detalle-textarea {
    width: 100%;
    border: 1px solid #d7dde6;
    border-radius: 0.9rem;
    padding: 0.95rem 1rem;
    color: #334155;
    resize: vertical;
    min-height: 6.5rem;
    font: inherit;
}

.detalle-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

.detalle-action {
    border: none;
    border-radius: 0.9rem;
    padding: 0.95rem 1.35rem;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}

.detalle-action--primary {
    background: #2f6f9f;
    color: #ffffff;
}

.detalle-action--warning {
    background: #f6ad3c;
    color: #ffffff;
}

.detalle-action--danger {
    background: #ef4444;
    color: #ffffff;
}

.detalle-action--secondary {
    background: #ffffff;
    color: #2f6f9f;
    border: 1px solid #2f6f9f;
}

.detalle-history {
    position: relative;
}

.detalle-history__item {
    position: relative;
    display: flex;
    gap: 1rem;
    padding: 0.35rem 0 1.4rem;
}

.detalle-history__icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    z-index: 1;
}

.detalle-history__content {
    flex: 1;
}

.detalle-history__status {
    color: #0f172a;
    font-size: 1.3rem;
    font-weight: 700;
}

.detalle-history__date {
    color: #64748b;
    font-size: 0.92rem;
}

.detalle-history__description {
    color: #475569;
    font-size: 1rem;
    line-height: 1.6;
}

.detalle-history__line {
    position: absolute;
    top: 2.7rem;
    left: 1.15rem;
    width: 2px;
    height: calc(100% - 1rem);
    background: #d7dde6;
}

.detalle-labels-modal {
    background: #ffffff;
}

.detalle-labels-modal__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.5rem 1.5rem 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.detalle-labels-modal__title-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.9rem;
    background: #edf3fa;
    color: #2f6f9f;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.detalle-labels-modal__title {
    color: #2f6f9f;
    font-size: 2rem;
    font-weight: 700;
}

.detalle-labels-modal__subtitle {
    color: #64748b;
    margin-top: 0.7rem;
    font-size: 1rem;
}

.detalle-labels-modal__actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.detalle-labels-modal__button,
.detalle-labels-modal__close {
    border-radius: 0.9rem;
    font: inherit;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.detalle-labels-modal__button:hover,
.detalle-labels-modal__close:hover {
    transform: translateY(-1px);
}

.detalle-labels-modal__button {
    min-height: 3rem;
    padding: 0.8rem 1.15rem;
    font-size: 0.98rem;
    font-weight: 700;
}

.detalle-labels-modal__button:disabled {
    opacity: 0.72;
    cursor: wait;
}

.detalle-labels-modal__button--ghost {
    background: #ffffff;
    border: 1px solid #2f6f9f;
    color: #2f6f9f;
}

.detalle-labels-modal__button--primary {
    background: #2f6f9f;
    border: 1px solid #2f6f9f;
    color: #ffffff;
}

.detalle-labels-modal__close {
    width: 2.6rem;
    height: 2.6rem;
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 1rem;
}

.detalle-labels-modal__body {
    padding: 0 1.5rem 1.5rem;
}

.detalle-labels-preview {
    max-height: calc(100vh - 12.5rem);
    overflow-x: hidden;
    overflow-y: auto;
    padding: 0.25rem 0.5rem 1rem 0;
}

.transfer-label {
    background: #ffffff;
    border: 2px solid #1f2937;
    border-radius: 1.6rem;
    overflow: visible;
    min-height: auto;
    height: auto;
    display: block;
    margin-bottom: 1.25rem;
}

.transfer-label--export {
    min-height: auto !important;
    height: auto !important;
    margin-bottom: 0 !important;
}

.transfer-label--export .transfer-label__footer {
    margin-top: 0;
}

.transfer-label:last-child {
    margin-bottom: 0;
}

.transfer-label__header,
.transfer-label__hero,
.transfer-label__details {
    display: flex;
    justify-content: space-between;
    gap: 1.5rem;
}

.transfer-label__header {
    padding: 1.45rem 1.5rem 1rem;
    border-bottom: 1px solid #dbe5ee;
    align-items: flex-start;
}

.transfer-label__university {
    color: #0f172a;
    font-size: 1.35rem;
    font-weight: 800;
}

.transfer-label__faculty {
    color: #64748b;
    margin-top: 0.35rem;
    font-size: 0.95rem;
}

.transfer-label__date {
    min-width: 12rem;
    text-align: right;
}

.transfer-label__date span,
.transfer-label__box span,
.transfer-label__details span,
.transfer-label__assigned,
.transfer-label__qr span {
    color: #64748b;
    font-size: 0.85rem;
}

.transfer-label__date strong {
    display: block;
    margin-top: 0.45rem;
    color: #0f172a;
    font-size: 1.2rem;
}

.transfer-label__hero {
    padding: 1.15rem 1.5rem;
    align-items: center;
    border-bottom: 1px solid #dbe5ee;
}

.transfer-label__qr {
    width: 8.5rem;
    text-align: center;
    flex-shrink: 0;
}

.transfer-label__qr img {
    width: 6rem;
    height: 6rem;
    display: block;
    margin: 0 auto 0.55rem;
}

.transfer-label__hero-main {
    flex: 1;
}

.transfer-label__location {
    background: #2f6f9f;
    border-radius: 1rem;
    padding: 1rem 1.2rem;
    text-align: center;
    color: #ffffff;
}

.transfer-label__location span {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.05em;
}

.transfer-label__location strong {
    display: block;
    margin-top: 0.45rem;
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: 0.08em;
}

.transfer-label__box {
    margin-top: 0.9rem;
    border-radius: 0.95rem;
    background: #edf3fa;
    padding: 0.8rem 1rem;
}

.transfer-label__box strong {
    display: block;
    margin-top: 0.25rem;
    font-size: 1.8rem;
    color: #0f172a;
}

.transfer-label__details {
    padding: 1.15rem 1.5rem 0.8rem;
    flex-wrap: wrap;
}

.transfer-label__details > div {
    flex: 1 1 14rem;
}

.transfer-label__details strong {
    display: block;
    margin-top: 0.45rem;
    color: #0f172a;
    font-size: 1.05rem;
}

.transfer-label__details--full {
    flex-basis: 100%;
}

.transfer-label__assigned {
    margin: 0 1.5rem;
    padding: 0.85rem 0 1rem;
    border-top: 1px solid #dbe5ee;
}

.transfer-label__footer {
    background: #fff7db;
    color: #b45309;
    padding: 0.8rem 1.5rem;
    font-size: 0.78rem;
    font-weight: 700;
}

@media (max-width: 991px) {
    .detalle-title {
        font-size: 1.8rem;
    }

    .detalle-panel__body,
    .detalle-box-card__header,
    .detalle-box-card__content,
    .detalle-banner,
    .detalle-print-card {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-actions {
        gap: 0.75rem;
    }

    .detalle-action {
        width: 100%;
        justify-content: center;
    }

    .detalle-labels-preview {
        max-height: calc(100vh - 13rem);
        padding-right: 0;
    }

    .transfer-label {
        min-height: auto;
    }

    .detalle-labels-modal__header,
    .transfer-label__header,
    .transfer-label__hero,
    .transfer-label__details,
    .detalle-labels-modal__body {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-labels-modal__header,
    .transfer-label__header,
    .transfer-label__hero {
        flex-direction: column;
    }

    .detalle-labels-modal__actions {
        width: 100%;
    }

    .detalle-labels-modal__button {
        flex: 1 1 13rem;
    }

    .transfer-label__date,
    .transfer-label__qr {
        width: 100%;
        text-align: left;
    }

    .transfer-label__qr img {
        margin-left: 0;
    }
}

@media (max-width: 640px) {
    .detalle-back-button {
        width: 2.35rem;
        height: 2.35rem;
    }

    .detalle-title {
        font-size: 1.45rem;
    }

    .detalle-subtitle {
        display: inline-block;
        margin-top: 0.2rem;
    }

    .detalle-chip {
        min-width: auto;
    }

    .detalle-summary-table {
        min-width: 640px;
    }

    .detalle-documents-table {
        min-width: 760px;
    }

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
