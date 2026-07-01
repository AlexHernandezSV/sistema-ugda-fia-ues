<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';
import html2canvas from 'html2canvas';
import jsPDF from 'jspdf';
import QRCode from 'qrcode';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const OBSERVATION_MAX_LENGTH = 1000;

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
const labelSize = {
    widthCm: 10,
    heightCm: 18,
    widthMm: 100,
    heightMm: 180,
};
const decisionModalVisible = ref(false);
const unitAuthorizeModalVisible = ref(false);
const transferProcessModalVisible = ref(false);
const transferConfirmModalVisible = ref(false);
const decisionSubmitting = ref(false);
const unitAuthorizeSubmitting = ref(false);
const transferProcessSubmitting = ref(false);
const currentDecisionAction = ref(null);
const activeProcessBox = ref(null);
const boxLocations = ref({});
const locationOptions = ref({
    offices: [],
    aisles: [],
    shelves: [],
});

const decisionForm = ref({
    date: null,
    time: null,
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
        case 'Subsanada':
        case 'Agendado':
        case 'En proceso':
            return 'detalle-chip detalle-chip--info';
        case 'En Revisión':
        case 'Obs. en Revisión':
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

const documentSupportLabel = (document) => (document.supportRestricted ? 'Restringido' : (document.support || 'N/A'));

const documentSupportClass = (document) => (document.supportRestricted
    ? 'detalle-support-chip detalle-support-chip--restricted'
    : supportChipClass(document.support));

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

    return `${years[0]} - ${years[years.length - 1]}`;
};

const extractBoxContent = (box) => {
    if (box.contentDescription) {
        return box.contentDescription;
    }

    if (box.title && box.title.includes(' - ')) {
        return box.title.split(' - ').slice(1).join(' - ');
    }

    const firstDocument = box.documents?.[0];
    const contentLabel = firstDocument?.series || firstDocument?.name || `Documentación de caja ${box.number}`;
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

const buildLabelAccessUrl = (transferNumber, boxNumber) => {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';
    const normalizedBoxNumber = String(Number(boxNumber) || boxNumber).padStart(3, '0');

    return `${origin}/etiquetas/transferencias/${encodeURIComponent(transferNumber)}/cajas/${encodeURIComponent(normalizedBoxNumber)}`;
};

const transferBoxCode = (box, transferNumber = transfer.value?.number) => (
    box?.boxCode || box?.code || `C-${transferNumber || 'PENDIENTE'}-${String(Number(box?.number) || box?.number || 0).padStart(3, '0')}`
);

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
        boxCode: transferBoxCode(box, data.number),
        boxLabel: transferBoxCode(box, data.number),
        transferNumber: data.number,
        transferDate,
        unit: data.unit,
        content: extractBoxContent(box),
        period: box.period,
        locationCode: box.physicalLocation || buildLocationCode(box, index),
        labelCode: transferBoxCode(box, data.number),
        assignedBy,
        assignedAt: assignedDate,
        qrDataUrl: await buildQrDataUrl(buildLabelAccessUrl(data.number, box.number)),
    })));
};

const openLabelsPreview = () => {
    if (!canViewBoxLocationLabels.value) {
        return;
    }

    labelsDialogVisible.value = true;
    requestAnimationFrame(() => {
        labelsPreviewRef.value?.scrollTo({ top: 0, behavior: 'auto' });
    });
};

const parsePhysicalLocation = (location) => {
    return {
        office_id: null,
        aisle_id: null,
        shelf_id: null,
        savedCode: location || '',
    };
};

const locationSegmentCode = (value, fallback = '') => {
    const text = String(value ?? '').trim();
    const number = text.match(/\d+/)?.[0];

    if (number) {
        return String(Number(number)).padStart(2, '0');
    }

    const letter = text.match(/[A-Za-zÁÉÍÓÚÑ]/)?.[0] ?? fallback;
    return letter.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase();
};

const findOfficeOption = (officeId) => locationOptions.value.offices.find((item) => item.id === officeId);
const findAisleOption = (aisleId) => locationOptions.value.aisles.find((item) => item.id === aisleId);
const findShelfOption = (shelfId) => locationOptions.value.shelves.find((item) => item.id === shelfId);

const aisleOptionsForLocation = (location) => (
    locationOptions.value.aisles.filter((item) => item.office_id === location.office_id)
);

const shelfOptionsForLocation = (location) => (
    locationOptions.value.shelves.filter((item) => item.aisle_id === location.aisle_id)
);

const onBoxOfficeChange = (box) => {
    const location = ensureBoxLocation(box);
    location.aisle_id = null;
    location.shelf_id = null;
};

const onBoxAisleChange = (box) => {
    ensureBoxLocation(box).shelf_id = null;
};

const generatedBoxLocationCode = (box) => {
    const location = boxLocations.value[box.number] ?? parsePhysicalLocation(box.physicalLocation);
    const office = findOfficeOption(location.office_id);
    const aisle = findAisleOption(location.aisle_id);
    const shelf = findShelfOption(location.shelf_id);

    if (!office || !aisle || !shelf) {
        return location.savedCode || '';
    }
    return `${office.code}-${aisle.code}-${shelf.code}`;

    const aisleCode = String(location.aisle).match(/\b([A-Za-zÁÉÍÓÚÑ])\b(?!.*\b[A-Za-zÁÉÍÓÚÑ]\b)/)?.[1]
        ?? locationSegmentCode(location.aisle, 'A');
    const shelfCode = locationSegmentCode(location.shelf, '01');
    const levelCode = String(Number(locationSegmentCode(location.level, '1')) || 1);
    const positionCode = locationSegmentCode(location.position, '01');

    return `${aisleCode.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase()}-${shelfCode}-${levelCode}-${positionCode}`;
};

const ensureBoxLocation = (box) => {
    if (!boxLocations.value[box.number]) {
        boxLocations.value[box.number] = parsePhysicalLocation(box.physicalLocation);
    }

    return boxLocations.value[box.number];
};

const isBoxLocated = (boxNumber) => {
    const location = boxLocations.value[boxNumber] ?? {};

    return Boolean(location.office_id && location.aisle_id && location.shelf_id);
};

const prepareTransferProcess = () => {
    const boxes = transfer.value?.boxes ?? [];
    boxLocations.value = boxes.reduce((acc, box) => ({
        ...acc,
        [box.number]: parsePhysicalLocation(box.physicalLocation),
    }), {});
    activeProcessBox.value = boxes[0]?.number ?? null;
};

const openTransferProcessModal = () => {
    prepareTransferProcess();
    transferProcessModalVisible.value = true;
};

const startPhysicalReview = async () => {
    if (!transfer.value?.number) {
        return;
    }

    try {
        const { data } = await axios.post(`/api/transfers/${transfer.value.number}/start-physical-review`);
        toast.add({
            severity: 'success',
            summary: 'Revisión física iniciada',
            detail: data.message || 'Revision fisica iniciada correctamente.',
            life: 2800,
        });
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo iniciar la revision fisica.');
    }
};

const handleProcessCardAction = () => {
    if (transfer.value?.processCard?.action === 'startPhysicalReview') {
        startPhysicalReview();
        return;
    }

    openTransferProcessPage();
};

const closeTransferProcessModal = () => {
    if (transferProcessSubmitting.value) {
        return;
    }

    transferProcessModalVisible.value = false;
};

const openTransferProcessPage = () => {
    if (!transfer.value?.number) {
        return;
    }

    prepareTransferProcess();
    router.push(`/solicitudes/transferencias/${transfer.value.number}/proceso`);
};

const goBackToTransferDetail = () => {
    if (!transfer.value?.number) {
        router.push('/solicitudes');
        return;
    }

    router.push({
        path: `/solicitudes/transferencias/${transfer.value.number}`,
        query: { tab: 'transferencias' },
    });
};

const openTransferConfirmModal = () => {
    if (!canCompleteTransferProcess.value) {
        return;
    }

    transferConfirmModalVisible.value = true;
};

const closeTransferConfirmModal = () => {
    if (transferProcessSubmitting.value) {
        return;
    }

    transferConfirmModalVisible.value = false;
};

const setLabelPreviewCard = (element, index) => {
    if (element) {
        labelPreviewCards.value[index] = element;
    }
};

const buildLabelsDocument = () => {
    const items = labelPreviewItems.value;
    const title = transfer.value ? `Etiquetas de transferencia ${transfer.value.number}` : 'Etiquetas de identificación';
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
                    <img src="${label.qrDataUrl}" alt="Código QR ${escapeHtml(label.labelCode)}" />
                    <span>Código: ${escapeHtml(label.labelCode)}</span>
                </div>

                <div class="print-label-card__hero-main">
                    <div class="print-label-card__location">
                        <span>CÓDIGO DE UBICACIÓN</span>
                        <strong>${escapeHtml(label.locationCode)}</strong>
                    </div>
                    <div class="print-label-card__box">
                        <span>N° de caja</span>
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
                    @page {
                        size: ${labelSize.widthCm}cm ${labelSize.heightCm}cm;
                        margin: 0;
                    }
                    html,
                    body {
                        width: ${labelSize.widthCm}cm;
                        min-height: ${labelSize.heightCm}cm;
                    }
                    body {
                        margin: 0;
                        font-family: 'Instrument Sans', Arial, Helvetica, sans-serif;
                        background: #ffffff;
                        color: #0f172a;
                    }
                    .print-sheet {
                        display: block;
                        width: ${labelSize.widthCm}cm;
                    }
                    .print-label-card {
                        width: ${labelSize.widthCm}cm;
                        height: ${labelSize.heightCm}cm;
                        min-height: ${labelSize.heightCm}cm;
                        background: #ffffff;
                        border: 2px solid #1f2937;
                        border-radius: 0.5cm;
                        display: flex;
                        flex-direction: column;
                        overflow: hidden;
                        page-break-inside: avoid;
                        break-inside: avoid;
                        page-break-after: always;
                        break-after: page;
                    }
                    .print-label-card:last-child {
                        page-break-after: auto;
                        break-after: auto;
                    }
                    .print-label-card__header,
                    .print-label-card__hero,
                    .print-label-card__details {
                        display: grid;
                        gap: 0.35cm;
                    }
                    .print-label-card__header {
                        grid-template-columns: 1fr auto;
                        align-items: flex-start;
                        padding: 0.58cm 0.55cm 0.36cm;
                        border-bottom: 1px solid #d9e2ec;
                    }
                    .print-label-card__university {
                        font-size: 0.44cm;
                        font-weight: 700;
                        line-height: 1.15;
                        letter-spacing: 0;
                    }
                    .print-label-card__faculty {
                        margin-top: 0.12cm;
                        color: #64748b;
                        font-size: 0.28cm;
                        line-height: 1.25;
                    }
                    .print-label-card__date {
                        min-width: 2.25cm;
                        text-align: right;
                    }
                    .print-label-card__date span,
                    .print-label-card__box span,
                    .print-label-card__details span,
                    .print-label-card__assigned,
                    .print-label-card__qr span {
                        color: #64748b;
                        font-size: 0.25cm;
                        line-height: 1.25;
                    }
                    .print-label-card__date strong {
                        display: block;
                        margin-top: 0.12cm;
                        color: #0f172a;
                        font-size: 0.34cm;
                        line-height: 1.2;
                    }
                    .print-label-card__hero {
                        grid-template-columns: 1fr;
                        align-items: center;
                        padding: 0.42cm 0.55cm;
                        border-bottom: 1px solid #d9e2ec;
                    }
                    .print-label-card__qr {
                        text-align: center;
                    }
                    .print-label-card__qr img {
                        width: 2.8cm;
                        height: 2.8cm;
                        display: block;
                        margin: 0 auto 0.12cm;
                    }
                    .print-label-card__location {
                        background: #2f6f9f;
                        border-radius: 0.32cm;
                        padding: 0.34cm 0.36cm;
                        text-align: center;
                        color: #ffffff;
                    }
                    .print-label-card__location span {
                        display: block;
                        font-size: 0.25cm;
                        font-weight: 700;
                        letter-spacing: 0;
                    }
                    .print-label-card__location strong {
                        display: block;
                        margin-top: 0.14cm;
                        font-size: 0.7cm;
                        font-weight: 700;
                        letter-spacing: 0;
                        line-height: 1.05;
                    }
                    .print-label-card__box {
                        margin-top: 0.28cm;
                        border-radius: 0.28cm;
                        background: #edf3fa;
                        padding: 0.3cm 0.36cm;
                    }
                    .print-label-card__box strong {
                        display: block;
                        margin-top: 0.1cm;
                        color: #0f172a;
                        font-size: 0.58cm;
                        line-height: 1.1;
                    }
                    .print-label-card__details {
                        grid-template-columns: 1fr;
                        padding: 0.42cm 0.55cm 0.24cm;
                    }
                    .print-label-card__details strong {
                        display: block;
                        margin-top: 0.08cm;
                        font-size: 0.33cm;
                        color: #0f172a;
                        line-height: 1.25;
                    }
                    .print-label-card__assigned {
                        margin: 0 0.55cm;
                        padding: 0.3cm 0;
                        border-top: 1px solid #d9e2ec;
                    }
                    .print-label-card__footer {
                        margin-top: auto;
                        background: #fff7db;
                        padding: 0.28cm 0.55cm;
                        color: #b45309;
                        font-size: 0.24cm;
                        font-weight: 700;
                        line-height: 1.3;
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
    if (!canViewBoxLocationLabels.value) {
        return;
    }

    const popup = window.open('', '_blank', 'width=1200,height=900');

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
    if (!canViewBoxLocationLabels.value || !transfer.value || labelPreviewCards.value.length === 0 || labelsBusy.value) {
        return;
    }

    labelsBusy.value = true;
    let exportHost = null;

    try {
        const pdf = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: [labelSize.widthMm, labelSize.heightMm],
        });

        exportHost = document.createElement('div');
        exportHost.style.position = 'fixed';
        exportHost.style.left = '-10000px';
        exportHost.style.top = '0';
        exportHost.style.width = `${labelSize.widthCm}cm`;
        exportHost.style.pointerEvents = 'none';
        exportHost.style.zIndex = '-1';
        document.body.appendChild(exportHost);

        for (let index = 0; index < labelPreviewCards.value.length; index += 1) {
            const card = labelPreviewCards.value[index];
            const exportCard = card.cloneNode(true);
            exportCard.classList.add('transfer-label--export');
            exportCard.style.width = `${labelSize.widthCm}cm`;
            exportCard.style.height = `${labelSize.heightCm}cm`;
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

            if (index > 0) {
                pdf.addPage([labelSize.widthMm, labelSize.heightMm], 'portrait');
            }

            pdf.addImage(imageData, 'PNG', 0, 0, labelSize.widthMm, labelSize.heightMm, undefined, 'FAST');
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
        date: null,
        time: null,
        comments: '',
    };
    currentDecisionAction.value = null;
};

const formatTimeForApi = (value) => {
    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        const hours = String(value.getHours()).padStart(2, '0');
        const minutes = String(value.getMinutes()).padStart(2, '0');

        return `${hours}:${minutes}`;
    }

    const time = String(value ?? '').trim();
    return /^\d{2}:\d{2}$/.test(time) ? time : '';
};

const normalizeScheduledFor = (date, time) => {
    const trimmedTime = formatTimeForApi(time);
    if (!(date instanceof Date) || Number.isNaN(date.getTime()) || !trimmedTime) {
        return null;
    }

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${year}-${month}-${day} ${trimmedTime}:00`;
};

const displayBoxNumber = (box) => transferBoxCode(box);

const boxTitle = (box) => `Caja ${displayBoxNumber(box)}`;

const boxSeriesList = (box) => {
    if (Array.isArray(box.seriesList) && box.seriesList.length > 0) {
        return box.seriesList;
    }

    return String(box.series ?? '')
        .split(';')
        .map((item) => item.trim())
        .filter(Boolean);
};

const boxLocationLabel = (box) => labelPreviewItems.value.find(
    (label) => String(label.boxNumber) === String(box.number),
);

const actionClass = (variant) => {
    switch (variant) {
        case 'success':
            return 'detalle-action detalle-action--success';
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

const transferSummary = computed(() => {
    const boxes = transfer.value?.boxes ?? [];
    const documents = boxes.flatMap((box) => box.documents ?? []);
    const series = new Set(boxes.flatMap((box) => boxSeriesList(box)).filter(Boolean));

    return {
        totalBoxes: boxes.length,
        totalDocuments: documents.length,
        totalSeries: series.size,
    };
});

const locatedBoxesCount = computed(() => (transfer.value?.boxes ?? []).filter((box) => isBoxLocated(box.number)).length);
const pendingBoxesCount = computed(() => Math.max((transfer.value?.boxes?.length ?? 0) - locatedBoxesCount.value, 0));
const locationProgress = computed(() => {
    const total = transfer.value?.boxes?.length ?? 0;

    if (total === 0) {
        return 0;
    }

    return Math.round((locatedBoxesCount.value / total) * 100);
});
const canCompleteTransferProcess = computed(() => {
    const total = transfer.value?.boxes?.length ?? 0;

    return total > 0 && locatedBoxesCount.value === total && !transferProcessSubmitting.value;
});

const openUnitAuthorizeModal = () => {
    unitAuthorizeModalVisible.value = true;
};

const closeUnitAuthorizeModal = () => {
    if (unitAuthorizeSubmitting.value) {
        return;
    }

    unitAuthorizeModalVisible.value = false;
};

const confirmUnitAuthorization = async () => {
    if (!transfer.value) {
        return;
    }

    if (commentDraft.value.trim().length > OBSERVATION_MAX_LENGTH) {
        showError(`Las observaciones no pueden superar ${OBSERVATION_MAX_LENGTH} caracteres.`);
        return;
    }

    unitAuthorizeSubmitting.value = true;

    try {
        const { data } = await axios.post(`/api/transfers/${transfer.value.number}/unit-authorize`, {
            comments: commentDraft.value.trim(),
        });
        toast.add({
            severity: 'success',
            summary: 'Actualizado',
            detail: data.message || 'Transferencia autorizada por jefatura de unidad.',
            life: 2800,
        });
        unitAuthorizeModalVisible.value = false;
        await fetchDetail();
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo autorizar la transferencia.');
    } finally {
        unitAuthorizeSubmitting.value = false;
    }
};

const completeTransferProcess = async () => {
    if (!transfer.value || !canCompleteTransferProcess.value) {
        return;
    }

    const locations = (transfer.value.boxes ?? []).map((box) => ({
        box_number: Number(box.number),
        ...boxLocations.value[box.number],
    }));

    transferProcessSubmitting.value = true;

    try {
        const { data } = await axios.post(`/api/transfers/${transfer.value.number}/complete-locations`, {
            locations,
        });

        toast.add({
            severity: 'success',
            summary: 'Transferencia completada',
            detail: data.message || 'Ubicaciones fisicas asignadas correctamente.',
            life: 3200,
        });
        const transferNumber = transfer.value.number;
        transferConfirmModalVisible.value = false;
        transferProcessModalVisible.value = false;
        await fetchDetail();
        router.push({
            path: `/solicitudes/transferencias/${transferNumber}`,
            query: { tab: 'transferencias' },
        });
    } catch (error) {
        showError(error.response?.data?.message || 'No se pudo completar el proceso de transferencia.');
    } finally {
        transferProcessSubmitting.value = false;
    }
};

const openDecisionModal = (actionKey) => {
    currentDecisionAction.value = actionKey;
    decisionForm.value = {
        date: null,
        time: null,
        comments: '',
    };
    decisionModalVisible.value = true;
};

const decisionModalConfig = computed(() => {
    const isPhysicalObservation = ['En Revisión', 'Obs. en Revisión'].includes(transfer.value?.status);

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
                icon: isPhysicalObservation ? 'pi pi-exclamation-triangle' : 'pi pi-eye',
                title: isPhysicalObservation ? 'Observaciones de Revisión Física' : 'Observar Transferencia',
                subtitle: 'Debe proporcionar una observación para esta transferencia',
                label: 'Observación',
                placeholder: 'Describa la observación para esta transferencia...',
                confirmLabel: isPhysicalObservation ? 'Registrar observaciones' : 'Observar',
                cancelLabel: 'Cancelar',
                tone: 'warning',
                required: true,
            };
        case 'deny':
        case 'denyUnit':
            return {
                icon: 'pi pi-times-circle',
                title: currentDecisionAction.value === 'denyUnit' ? 'Denegar Transferencia por Jefatura' : 'Denegar Transferencia',
                subtitle: 'Debe proporcionar un motivo para denegar esta transferencia',
                label: 'Motivo de denegación',
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
        return !(decisionForm.value.date instanceof Date) || Number.isNaN(decisionForm.value.date?.getTime?.()) || !formatTimeForApi(decisionForm.value.time);
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

    if (action.key === 'authorizeUnit') {
        openUnitAuthorizeModal();
        return;
    }

    if (action.key === 'denyUnit') {
        openDecisionModal('denyUnit');
        return;
    }

    if (action.key === 'deny') {
        openDecisionModal('deny');
    }
};

const editObservedTransfer = () => {
    if (!transfer.value?.number) {
        return;
    }

    router.push(`/solicitudes/transferencias/${transfer.value.number}/editar`);
};

const submitDecisionAction = async () => {
    const config = decisionModalConfig.value;

    if (!config || !transfer.value) {
        return;
    }

    const comments = decisionForm.value.comments.trim();

    if (comments.length > OBSERVATION_MAX_LENGTH) {
        showError(`Las observaciones no pueden superar ${OBSERVATION_MAX_LENGTH} caracteres.`);
        return;
    }

    if (currentDecisionAction.value === 'schedule') {
        if (!decisionForm.value.date || !decisionForm.value.time) {
            showError('Debe ingresar la fecha y hora de entrega.');
            return;
        }
    }

    if (config.required && currentDecisionAction.value !== 'schedule' && comments === '') {
        showError(currentDecisionAction.value === 'observe'
            ? 'Debe ingresar la observación de la transferencia.'
            : 'Debe ingresar el motivo de rechazo.');
        return;
    }

    let endpoint = '';
    let payload = {};
    let successMessage = '';
    let successSummary = 'Actualizado';

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
            successSummary = 'Transferencia agendada exitosamente';
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
        case 'denyUnit':
            endpoint = `/api/transfers/${transfer.value.number}/unit-deny`;
            payload = { reason: comments };
            successMessage = 'Transferencia denegada por jefatura de unidad.';
            break;
        default:
            return;
    }

    decisionSubmitting.value = true;

    try {
        const { data } = await axios.post(endpoint, payload);
        toast.add({
            severity: 'success',
            summary: successSummary,
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
        labelPreviewItems.value = data.canViewBoxLocationLabels ? await buildLabelPreviewItems(data) : [];
        if (isProcessPage.value) {
            prepareTransferProcess();
        }
    } catch (error) {
        console.error('Error cargando detalle de transferencia:', error);
        router.push('/solicitudes');
    } finally {
        loading.value = false;
    }
};

const fetchLocationOptions = async () => {
    try {
        const { data } = await axios.get('/api/physical-locations/options');
        locationOptions.value = {
            offices: Array.isArray(data.offices) ? data.offices : [],
            aisles: Array.isArray(data.aisles) ? data.aisles : [],
            shelves: Array.isArray(data.shelves) ? data.shelves : [],
        };
    } catch (error) {
        console.error('Error cargando ubicaciones fisicas:', error);
        locationOptions.value = { offices: [], aisles: [], shelves: [] };
    }
};

const isReviewMode = computed(() => ['review', 'unitAuthorization'].includes(transfer.value?.mode));
const isProcessPage = computed(() => route.name === 'solicitudes-transferencia-proceso');
const canViewBoxLocationLabels = computed(() => Boolean(transfer.value?.canViewBoxLocationLabels));
const showBoxLocationHeaders = computed(() => canViewBoxLocationLabels.value && transfer.value?.auth === 'Autorizado' && transfer.value?.status === 'Transferido');
const pageTitle = computed(() => transfer.value?.pageTitle ?? (transfer.value ? `Transferencia #${transfer.value.number}` : 'Transferencia'));
const pageSubtitle = computed(() => transfer.value?.pageSubtitle ?? 'Detalle de la solicitud');
const showHistory = computed(() => (transfer.value?.history?.length ?? 0) > 0);
const showDescription = computed(() => Boolean(transfer.value?.description));
const showPrintCard = computed(() => canViewBoxLocationLabels.value && Boolean(transfer.value?.showPrintCard) && !transfer.value?.scheduledSummary);
const hasReviewActions = computed(() => (transfer.value?.actions?.length ?? 0) > 0);

onMounted(async () => {
    await Promise.all([fetchLocationOptions(), fetchDetail()]);
});

watch(
    () => route.params.number,
    async (newNumber, oldNumber) => {
        if (!oldNumber || newNumber === oldNumber) {
            return;
        }

        loading.value = true;
        transfer.value = null;
        await fetchDetail();
    }
);
</script>

<template>
    <Toast />

    <div v-if="loading" class="surface-card border-round-xl shadow-1 p-4 text-center text-gray-500">
        Cargando detalle de transferencia...
    </div>

    <div v-else-if="transfer && isProcessPage" class="detalle-process-page">
        <header class="detalle-process-page__header">
            <button type="button" class="detalle-back-button" :disabled="transferProcessSubmitting" @click="goBackToTransferDetail">
                <i class="pi pi-arrow-left"></i>
            </button>
            <div>
                <h1 class="detalle-title m-0">Proceso de Transferencia</h1>
                <span class="detalle-subtitle">Asignación de ubicaciones físicas - Solicitud #{{ transfer.number }}</span>
            </div>
        </header>

        <section class="detalle-process-modal__progress detalle-process-page__progress">
            <div class="detalle-process-modal__progress-head">
                <strong>Progreso de Asignación</strong>
                <span>{{ locatedBoxesCount }} de {{ transfer.boxes?.length ?? 0 }} cajas ubicadas</span>
            </div>
            <div class="detalle-process-modal__progress-track">
                <div class="detalle-process-modal__progress-bar" :style="{ width: `${locationProgress}%` }"></div>
            </div>
        </section>

        <div class="detalle-process-page__grid">
            <aside class="detalle-process-modal__info detalle-process-page__info">
                <h3>Información de la Solicitud</h3>
                <div>
                    <span>N° de transferencia</span>
                    <strong>{{ transfer.number }}</strong>
                </div>
                <div>
                    <span>Unidad productora</span>
                    <strong>{{ transfer.unit }}</strong>
                </div>
                <div>
                    <span>Responsable</span>
                    <strong>{{ transfer.responsible }}</strong>
                </div>
                <div>
                    <span>Estado</span>
                    <em>{{ transfer.status }}</em>
                </div>
                <hr />
                <div>
                    <span>Fecha de entrega programada</span>
                    <strong>{{ transfer.scheduledSummary?.deliveryDate || 'Sin fecha' }}</strong>
                </div>
                <div>
                    <span>Hora de entrega</span>
                    <strong>{{ transfer.scheduledSummary?.deliveryTime || 'Sin hora' }}</strong>
                </div>
                <hr />
                <div>
                    <span>Total de cajas</span>
                    <strong>{{ transfer.boxes?.length ?? 0 }} caja(s)</strong>
                </div>
            </aside>

            <section class="detalle-process-modal__boxes detalle-process-page__boxes">
                <article
                    v-for="box in transfer.boxes ?? []"
                    :key="`process-page-${box.number}`"
                    :class="[
                        'detalle-process-box',
                        'detalle-process-page__box',
                        {
                            'detalle-process-box--active': activeProcessBox === box.number,
                            'detalle-process-page__box--done': isBoxLocated(box.number),
                        },
                    ]"
                >
                    <button type="button" class="detalle-process-box__summary" @click="activeProcessBox = activeProcessBox === box.number ? null : box.number">
                        <div class="detalle-process-box__summary-main">
                            <div class="detalle-process-box__icon" :class="{ 'detalle-process-box__icon--done': isBoxLocated(box.number) }">
                                <i class="pi pi-box"></i>
                            </div>
                            <div>
                                <div class="detalle-process-box__title">
                                    {{ boxTitle(box) }}
                                    <span :class="isBoxLocated(box.number) ? 'detalle-process-box__status detalle-process-box__status--done' : 'detalle-process-box__status'">
                                        <i :class="isBoxLocated(box.number) ? 'pi pi-check-circle' : 'pi pi-exclamation-circle'"></i>
                                        {{ isBoxLocated(box.number) ? 'Ubicada' : 'Pendiente' }}
                                    </span>
                                </div>
                                <p>{{ box.title }} - {{ box.period }}</p>
                                <span v-if="isBoxLocated(box.number)" class="detalle-process-page__code-chip">
                                    <i class="pi pi-map-marker"></i>
                                    {{ generatedBoxLocationCode(box) }}
                                </span>
                            </div>
                        </div>
                        <i :class="activeProcessBox === box.number ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                    </button>

                    <div v-if="activeProcessBox === box.number" class="detalle-process-box__form">
                        <h4><i class="pi pi-map-marker"></i> Asignar Ubicación Física</h4>
                        <div class="detalle-process-box__fields">
                            <label>
                                <span>Oficina *</span>
                                <Dropdown
                                    v-model="ensureBoxLocation(box).office_id"
                                    :options="locationOptions.offices"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="Seleccionar"
                                    fluid
                                    @change="onBoxOfficeChange(box)"
                                />
                            </label>
                            <label>
                                <span>Pasillo *</span>
                                <Dropdown
                                    v-model="ensureBoxLocation(box).aisle_id"
                                    :options="aisleOptionsForLocation(ensureBoxLocation(box))"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="Seleccionar"
                                    fluid
                                    :disabled="!ensureBoxLocation(box).office_id"
                                    @change="onBoxAisleChange(box)"
                                />
                            </label>
                            <label>
                                <span>Estante *</span>
                                <Dropdown
                                    v-model="ensureBoxLocation(box).shelf_id"
                                    :options="shelfOptionsForLocation(ensureBoxLocation(box))"
                                    optionLabel="name"
                                    optionValue="id"
                                    placeholder="Seleccionar"
                                    fluid
                                    :disabled="!ensureBoxLocation(box).aisle_id"
                                />
                            </label>
                        </div>
                        <div v-if="isBoxLocated(box.number)" class="detalle-process-box__location-code">
                            <i class="pi pi-check-circle"></i>
                            <div>
                                <span>Código de ubicación generado</span>
                                <strong>{{ generatedBoxLocationCode(box) }}</strong>
                                <small>Se guardara al completar la transferencia.</small>
                            </div>
                        </div>
                    </div>
                </article>
            </section>
        </div>

        <footer class="detalle-process-modal__footer detalle-process-page__footer">
            <button type="button" class="detalle-process-modal__complete" :disabled="!canCompleteTransferProcess" @click="openTransferConfirmModal">
                <i class="pi pi-save"></i>
                Completar transferencia
            </button>
            <button type="button" class="detalle-process-modal__cancel" :disabled="transferProcessSubmitting" @click="goBackToTransferDetail">
                Cancelar
            </button>
            <span class="detalle-process-modal__pending" :class="{ 'detalle-process-modal__pending--done': pendingBoxesCount === 0 }">
                <i :class="pendingBoxesCount === 0 ? 'pi pi-check-circle' : 'pi pi-info-circle'"></i>
                {{ pendingBoxesCount === 0 ? 'Todas las cajas ubicadas' : `${pendingBoxesCount} caja(s) pendiente(s)` }}
            </span>
        </footer>
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

        <div v-if="transfer.physicalObservations" class="detalle-banner detalle-banner--warning">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--warning">
                    <i class="pi pi-exclamation-triangle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.physicalObservations.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.physicalObservations.description }}</p>
                    <p v-if="transfer.physicalObservations.instruction" class="detalle-banner__text detalle-banner__text--strong m-0">
                        {{ transfer.physicalObservations.instruction }}
                    </p>
                </div>
            </div>

            <div class="flex flex-column gap-3">
                <div v-for="item in transfer.physicalObservations.items" :key="`${item.actor}-${item.dateTime}-${item.message}`" class="detalle-observation-card">
                    <div class="detalle-observation-card__meta">
                        <i class="pi pi-user"></i>
                        <span class="font-semibold">{{ item.actor }}</span>
                        <span>{{ item.dateTime }}</span>
                    </div>
                    <p class="detalle-observation-card__message m-0">{{ item.message }}</p>
                </div>
            </div>

            <div v-if="transfer.physicalObservations.canCorrect" class="detalle-banner__actions">
                <Button
                    :label="transfer.physicalObservations.actionLabel"
                    icon="pi pi-pencil"
                    class="detalle-banner__edit-button"
                    @click="editObservedTransfer"
                />
            </div>
        </div>

        <div v-if="transfer.subsanationSummary" class="detalle-banner detalle-banner--blue">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--blue">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.subsanationSummary.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.subsanationSummary.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <p class="m-0">{{ transfer.subsanationSummary.message }}</p>
                <div class="detalle-banner__divider"></div>
                <div class="font-semibold mb-2 text-900">{{ transfer.subsanationSummary.notesLabel }}</div>
                <div class="detalle-banner__notes">{{ transfer.subsanationSummary.notes }}</div>
            </div>
        </div>

        <div v-if="transfer.processCard" class="surface-card shadow-1 border-round-xl detalle-process-card">
            <div class="detalle-process-card__content">
                <div class="detalle-process-card__main">
                    <div class="detalle-process-card__icon">
                        <i :class="transfer.processCard.icon || 'pi pi-box'"></i>
                    </div>
                    <div>
                        <h3>{{ transfer.processCard.title }}</h3>
                        <p>{{ transfer.processCard.description }}</p>
                    </div>
                </div>
                <Button :label="transfer.processCard.buttonLabel" :icon="transfer.processCard.icon || 'pi pi-box'" class="detalle-process-card__button" @click="handleProcessCardAction" />
            </div>
        </div>

        <div v-if="transfer.physicalReviewCard" class="surface-card shadow-1 border-round-xl detalle-physical-review-card">
            <div class="detalle-process-card__content">
                <div class="detalle-process-card__main">
                    <div class="detalle-process-card__icon detalle-process-card__icon--warning">
                        <i class="pi pi-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h3>{{ transfer.physicalReviewCard.title }}</h3>
                        <p>{{ transfer.physicalReviewCard.description }}</p>
                    </div>
                </div>
                <div class="detalle-physical-review-card__actions">
                    <Button
                        :label="transfer.physicalReviewCard.observeLabel"
                        icon="pi pi-exclamation-triangle"
                        outlined
                        class="detalle-physical-review-card__observe"
                        @click="openDecisionModal('observe')"
                    />
                    <Button
                        :label="transfer.physicalReviewCard.transferLabel"
                        icon="pi pi-box"
                        class="detalle-process-card__button"
                        :disabled="!transfer.physicalReviewCard.canStartTransfer"
                        @click="openTransferProcessPage"
                    />
                </div>
            </div>
        </div>

        <div v-if="showPrintCard" class="surface-card shadow-1 border-round-xl p-4 detalle-print-card">
            <div class="flex flex-column md:flex-row md:align-items-center md:justify-content-between gap-3">
                <div class="flex align-items-start gap-3">
                    <div class="detalle-print-card__icon">
                        <i class="pi pi-print text-xl"></i>
                    </div>
                    <div>
                        <h3 class="m-0 text-900 text-xl font-bold">Etiquetas de Identificación</h3>
                        <p class="m-0 text-600 mt-2">Imprima las etiquetas con códigos QR para identificar y ubicar facilmente las cajas</p>
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

            <div v-if="transfer.observationsRegistered.canCorrect" class="detalle-banner__actions">
                <Button
                    :label="transfer.observationsRegistered.actionLabel"
                    icon="pi pi-pencil"
                    class="detalle-banner__edit-button"
                    @click="editObservedTransfer"
                />
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

        <div v-if="transfer.resolvedObservation" class="detalle-banner detalle-banner--success">
            <div class="detalle-banner__head">
                <div class="detalle-banner__icon detalle-banner__icon--success">
                    <i class="pi pi-check-circle"></i>
                </div>
                <div>
                    <h2 class="detalle-banner__title m-0">{{ transfer.resolvedObservation.title }}</h2>
                    <p class="detalle-banner__text m-0">{{ transfer.resolvedObservation.description }}</p>
                </div>
            </div>

            <div class="detalle-banner__panel">
                <div class="detalle-observation-card__meta">
                    <i class="pi pi-eye"></i>
                    <span class="font-semibold">{{ transfer.resolvedObservation.observedBy }}</span>
                    <span>{{ transfer.resolvedObservation.observedAt }}</span>
                </div>
                <p class="m-0 detalle-observation-card__message">{{ transfer.resolvedObservation.observation }}</p>
                <div class="detalle-banner__footer">
                    <i class="pi pi-check-circle mr-2"></i>
                    Resuelta por {{ transfer.resolvedObservation.resolvedBy }} el {{ transfer.resolvedObservation.resolvedAt }}
                </div>
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
                <div class="detalle-panel__header detalle-panel__header--split">
                    <span>Datos Generales</span>
                    <div class="detalle-panel__chips">
                        <span :class="authChipClass(transfer.auth)">{{ transfer.auth }}</span>
                        <span :class="statusChipClass(transfer.status)">{{ transfer.status }}</span>
                    </div>
                </div>
                <div class="detalle-panel__body">
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
                                    <span class="detalle-label">Fecha de autorización</span>
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
                                    <th>Codigo de caja</th>
                                    <th class="ugda-chip-table-cell">Documentos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in transfer.documentSummary?.rows ?? []" :key="row.index">
                                    <td>{{ row.index }}</td>
                                    <td>
                                        <div class="detalle-summary-series-row">
                                            <span v-if="boxSeriesList(row).length > 1" class="detalle-summary-series-count">
                                                Múltiples ({{ boxSeriesList(row).length }})
                                            </span>
                                            <span
                                                v-for="series in boxSeriesList(row)"
                                                :key="`${row.index}-${series}`"
                                                class="detalle-summary-series-chip"
                                            >
                                                {{ series }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>{{ row.startYear }}</td>
                                    <td>{{ row.endYear }}</td>
                                    <td>{{ row.boxCode || row.boxNumber }}</td>
                                    <td class="ugda-chip-table-cell">
                                        <div class="ugda-chip-cell">
                                            <span class="detalle-summary-badge">{{ row.documentsLabel }}</span>
                                        </div>
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
                                        <div class="detalle-box-card__series-row">
                                            <span v-if="boxSeriesList(box).length > 1" class="detalle-box-card__series-count">
                                                Múltiples series ({{ boxSeriesList(box).length }})
                                            </span>
                                            <span
                                                v-for="series in boxSeriesList(box)"
                                                :key="`${box.number}-${series}`"
                                                class="detalle-box-card__series-chip"
                                            >
                                                {{ series }}
                                            </span>
                                        </div>
                                        <div class="detalle-box-card__subtitle">Periodo: {{ buildPeriodYearLabel(box.period) }} • {{ box.documentsCount }} documentos</div>
                                    </div>
                                </div>
                                <i :class="expandedBoxes[box.number] ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                            </button>

                            <div v-if="expandedBoxes[box.number]" class="detalle-box-card__content">
                                <section v-if="showBoxLocationHeaders && boxLocationLabel(box)" class="detalle-box-location-header">
                                    <div class="detalle-box-location-header__top">
                                        <div>
                                            <h3>UNIVERSIDAD DE EL SALVADOR</h3>
                                            <p>Facultad de Ingeniería y Arquitectura - UGDA</p>
                                        </div>
                                        <div class="detalle-box-location-header__date">
                                            <span>Fecha de transferencia</span>
                                            <strong>{{ boxLocationLabel(box).transferDate }}</strong>
                                        </div>
                                    </div>

                                    <div class="detalle-box-location-header__main">
                                        <div class="detalle-box-location-header__qr">
                                            <img :src="boxLocationLabel(box).qrDataUrl" :alt="`Código QR ${boxLocationLabel(box).labelCode}`" />
                                            <span>Código: {{ boxLocationLabel(box).labelCode }}</span>
                                        </div>
                                        <div class="detalle-box-location-header__hero">
                                            <div class="detalle-box-location-header__code">
                                                <span>CÓDIGO DE UBICACIÓN</span>
                                                <strong>{{ boxLocationLabel(box).locationCode }}</strong>
                                            </div>
                                            <div class="detalle-box-location-header__box-number">
                                                <span>Número de caja</span>
                                                <strong>{{ boxLocationLabel(box).boxLabel }}</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="detalle-box-location-header__details">
                                        <div>
                                            <span>N. de transferencia</span>
                                            <strong>{{ boxLocationLabel(box).transferNumber }}</strong>
                                        </div>
                                        <div>
                                            <span>Unidad productora</span>
                                            <strong>{{ boxLocationLabel(box).unit }}</strong>
                                        </div>
                                        <div>
                                            <span>Contenido de la caja</span>
                                            <strong>{{ boxLocationLabel(box).content }}</strong>
                                        </div>
                                    </div>

                                    <div class="detalle-box-location-header__assigned">
                                        Ubicación asignada por {{ boxLocationLabel(box).assignedBy }} el {{ boxLocationLabel(box).assignedAt }}
                                    </div>
                                </section>

                                <div class="detalle-table-wrapper">
                                    <table class="detalle-documents-table">
                                        <thead>
                                            <tr>
                                                <th>Nombre del documento</th>
                                                <th>Código</th>
                                                <th>Serie del documento</th>
                                                <th class="ugda-chip-table-cell">Soporte</th>
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
                                                <td class="ugda-chip-table-cell">
                                                    <div class="ugda-chip-cell">
                                                        <span :class="documentSupportClass(document)">{{ documentSupportLabel(document) }}</span>
                                                    </div>
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
                                                    <span v-else-if="document.digitalRestricted" class="detalle-document-file detalle-document-file--restricted">
                                                        <i class="pi pi-lock"></i>
                                                        <span>Restringido</span>
                                                    </span>
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
                        :maxlength="OBSERVATION_MAX_LENGTH"
                        placeholder="Agregue cualquier observación o comentario sobre esta solicitud..."
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

            <div v-if="showHistory" class="detalle-panel">
                <div class="detalle-panel__header">Historial de Estados</div>
                <div class="detalle-panel__body">
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
            </div>
        </template>

        <template v-else>
            <div class="detalle-panel">
                <div class="detalle-panel__header detalle-panel__header--split">
                    <span>Información General</span>
                    <div class="detalle-panel__chips">
                        <span :class="authChipClass(transfer.auth)">{{ transfer.auth }}</span>
                        <span :class="statusChipClass(transfer.status)">{{ transfer.status }}</span>
                    </div>
                </div>
                <div class="detalle-panel__body">
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
                                <span class="detalle-label">Fecha de autorización</span>
                                <div class="detalle-value">{{ transfer.authorizedAt }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <div v-if="showDescription" class="detalle-panel">
                <div class="detalle-panel__header">Descripción</div>
                <div class="detalle-panel__body">
                    <p class="m-0 text-700 text-lg line-height-3">{{ transfer.description }}</p>
                </div>
            </div>

            <div class="detalle-panel">
                <div class="detalle-panel__header detalle-panel__header--split">
                    <span>Cajas y Documentos</span>
                    <button type="button" class="detalle-link-button" @click="toggleAllBoxes">
                        {{ allBoxesExpanded ? 'Ocultar documentos' : 'Mostrar todos los documentos' }}
                        <i :class="allBoxesExpanded ? 'pi pi-chevron-up ml-2' : 'pi pi-chevron-down ml-2'"></i>
                    </button>
                </div>

                <div class="detalle-panel__body">
                <div class="flex flex-column gap-3">
                    <div v-for="box in transfer.boxes" :key="box.number" class="detalle-box-card">
                        <button type="button" class="detalle-box-card__header" @click="toggleBox(box.number)">
                            <div class="flex align-items-center gap-3 min-w-0">
                                <div class="detalle-box-card__icon">
                                    <i class="pi pi-box"></i>
                                </div>
                                <div class="text-left min-w-0">
                                    <div class="detalle-box-card__title">{{ boxTitle(box) }}</div>
                                    <div class="detalle-box-card__series-row">
                                        <span v-if="boxSeriesList(box).length > 1" class="detalle-box-card__series-count">
                                            Múltiples series ({{ boxSeriesList(box).length }})
                                        </span>
                                        <span
                                            v-for="series in boxSeriesList(box)"
                                            :key="`${box.number}-${series}`"
                                            class="detalle-box-card__series-chip"
                                        >
                                            {{ series }}
                                        </span>
                                    </div>
                                    <div class="detalle-box-card__subtitle">Periodo: {{ buildPeriodYearLabel(box.period) }} • {{ box.documentsCount }} documentos</div>
                                </div>
                            </div>
                            <i :class="expandedBoxes[box.number] ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                        </button>

                        <div v-if="expandedBoxes[box.number]" class="detalle-box-card__content">
                            <section v-if="showBoxLocationHeaders && boxLocationLabel(box)" class="detalle-box-location-header">
                                <div class="detalle-box-location-header__top">
                                    <div>
                                        <h3>UNIVERSIDAD DE EL SALVADOR</h3>
                                        <p>Facultad de Ingeniería y Arquitectura - UGDA</p>
                                    </div>
                                    <div class="detalle-box-location-header__date">
                                        <span>Fecha de transferencia</span>
                                        <strong>{{ boxLocationLabel(box).transferDate }}</strong>
                                    </div>
                                </div>

                                <div class="detalle-box-location-header__main">
                                    <div class="detalle-box-location-header__qr">
                                        <img :src="boxLocationLabel(box).qrDataUrl" :alt="`Código QR ${boxLocationLabel(box).labelCode}`" />
                                        <span>Código: {{ boxLocationLabel(box).labelCode }}</span>
                                    </div>
                                    <div class="detalle-box-location-header__hero">
                                        <div class="detalle-box-location-header__code">
                                            <span>CÓDIGO DE UBICACIÓN</span>
                                            <strong>{{ boxLocationLabel(box).locationCode }}</strong>
                                        </div>
                                        <div class="detalle-box-location-header__box-number">
                                            <span>Número de caja</span>
                                            <strong>{{ boxLocationLabel(box).boxLabel }}</strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="detalle-box-location-header__details">
                                    <div>
                                        <span>N. de transferencia</span>
                                        <strong>{{ boxLocationLabel(box).transferNumber }}</strong>
                                    </div>
                                    <div>
                                        <span>Unidad productora</span>
                                        <strong>{{ boxLocationLabel(box).unit }}</strong>
                                    </div>
                                    <div>
                                        <span>Contenido de la caja</span>
                                        <strong>{{ boxLocationLabel(box).content }}</strong>
                                    </div>
                                </div>

                                <div class="detalle-box-location-header__assigned">
                                    Ubicación asignada por {{ boxLocationLabel(box).assignedBy }} el {{ boxLocationLabel(box).assignedAt }}
                                </div>
                            </section>

                            <div class="detalle-table-wrapper">
                                <table class="detalle-documents-table">
                                    <thead>
                                        <tr>
                                            <th>Nombre del documento</th>
                                            <th>Código</th>
                                            <th>Serie del documento</th>
                                            <th class="ugda-chip-table-cell">Soporte</th>
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
                                            <td class="ugda-chip-table-cell">
                                                <div class="ugda-chip-cell">
                                                    <span :class="documentSupportClass(document)">{{ documentSupportLabel(document) }}</span>
                                                </div>
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
                                                <span v-else-if="document.digitalRestricted" class="detalle-document-file detalle-document-file--restricted">
                                                    <i class="pi pi-lock"></i>
                                                    <span>Restringido</span>
                                                </span>
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

            <div v-if="showHistory" class="detalle-panel">
                <div class="detalle-panel__header">Historial de Estados</div>
                <div class="detalle-panel__body">
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
                        <DatePicker
                            v-model="decisionForm.date"
                            showIcon
                            iconDisplay="input"
                            dateFormat="dd/mm/yy"
                            :manualInput="false"
                            class="detalle-decision-modal__datepicker"
                            placeholder="dd/mm/aaaa"
                        />
                    </div>

                    <div class="detalle-decision-modal__field">
                        <label class="detalle-decision-modal__label">
                            Hora de entrega <span>*</span>
                        </label>
                        <DatePicker
                            v-model="decisionForm.time"
                            timeOnly
                            hourFormat="12"
                            :manualInput="false"
                            class="detalle-decision-modal__datepicker"
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
                            :maxlength="OBSERVATION_MAX_LENGTH"
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
                        :maxlength="OBSERVATION_MAX_LENGTH"
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
        v-model:visible="unitAuthorizeModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
        :style="{ width: 'min(780px, 96vw)' }"
    >
        <div class="detalle-unit-auth-modal">
            <header class="detalle-unit-auth-modal__header">
                <div>
                    <h2>Autorizar Transferencia Documental</h2>
                    <p>Revise el resumen completo antes de autorizar</p>
                </div>
                <button type="button" class="detalle-unit-auth-modal__close" :disabled="unitAuthorizeSubmitting" @click="closeUnitAuthorizeModal">
                    <i class="pi pi-times"></i>
                </button>
            </header>

            <div class="detalle-unit-auth-modal__body">
                <section class="detalle-unit-auth-modal__notice">
                    <i class="pi pi-check-circle"></i>
                    <div>
                        <strong>Al autorizar, esta solicitud será enviada a la UGDA para su revisión y procesamiento</strong>
                        <span>La UGDA gestionará el agendamiento y la recepción física de la documentación</span>
                    </div>
                </section>

                <section class="detalle-unit-auth-modal__card detalle-unit-auth-modal__general">
                    <h3>Datos Generales</h3>
                    <div class="detalle-unit-auth-modal__divider"></div>
                    <div class="detalle-unit-auth-modal__grid">
                        <div>
                            <span>Unidad Productora</span>
                            <strong>{{ transfer?.unit || 'Sin unidad' }}</strong>
                        </div>
                        <div>
                            <span>Responsable</span>
                            <strong>{{ transfer?.responsible || 'Sin responsable' }}</strong>
                        </div>
                        <div>
                            <span>Fecha de solicitud</span>
                            <strong>{{ transfer?.receivedAt || '(Sin especificar)' }}</strong>
                        </div>
                        <div>
                            <span>N° de transferencia</span>
                            <strong>{{ transfer?.number }}</strong>
                        </div>
                    </div>
                </section>

                <section class="detalle-unit-auth-modal__summary">
                    <h3>Resumen de Transferencia</h3>
                    <div class="detalle-unit-auth-modal__divider"></div>
                    <div class="detalle-unit-auth-modal__stats">
                        <div>
                            <span>Total de Cajas</span>
                            <strong>{{ transferSummary.totalBoxes }}</strong>
                        </div>
                        <div>
                            <span>Total de Documentos</span>
                            <strong>{{ transferSummary.totalDocuments }}</strong>
                        </div>
                        <div>
                            <span>Series Documentales</span>
                            <strong>{{ transferSummary.totalSeries }}</strong>
                        </div>
                    </div>

                    <div class="detalle-unit-auth-modal__boxes">
                        <article v-for="box in transfer?.boxes ?? []" :key="`auth-${box.number}`" class="detalle-unit-auth-modal__box">
                            <div class="detalle-unit-auth-modal__box-content">
                                <div class="detalle-unit-auth-modal__box-series">
                                    <span class="detalle-unit-auth-modal__box-badge">Caja {{ displayBoxNumber(box) }}</span>
                                    <span v-if="boxSeriesList(box).length > 1" class="detalle-unit-auth-modal__series-count">
                                        Múltiples ({{ boxSeriesList(box).length }})
                                    </span>
                                    <span
                                        v-for="series in boxSeriesList(box)"
                                        :key="`auth-${box.number}-${series}`"
                                        class="detalle-unit-auth-modal__series-chip"
                                    >
                                        {{ series }}
                                    </span>
                                </div>
                                <small class="detalle-unit-auth-modal__box-period">Periodo: <strong>{{ buildPeriodYearLabel(box.period) }}</strong></small>
                            </div>
                            <strong class="detalle-unit-auth-modal__box-documents">
                                {{ box.documentsCount }}<br>
                                documento(s)
                            </strong>
                        </article>
                    </div>
                </section>
            </div>

            <footer class="detalle-unit-auth-modal__footer">
                <button type="button" class="detalle-unit-auth-modal__button detalle-unit-auth-modal__button--cancel" :disabled="unitAuthorizeSubmitting" @click="closeUnitAuthorizeModal">
                    Cancelar
                </button>
                <button type="button" class="detalle-unit-auth-modal__button detalle-unit-auth-modal__button--confirm" :disabled="unitAuthorizeSubmitting" @click="confirmUnitAuthorization">
                    {{ unitAuthorizeSubmitting ? 'Autorizando...' : 'Confirmar Autorización' }}
                </button>
            </footer>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="transferProcessModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
        :style="{ width: 'min(1260px, 96vw)' }"
    >
        <div class="detalle-process-modal">
            <header class="detalle-process-modal__header">
                <button type="button" class="detalle-process-modal__back" :disabled="transferProcessSubmitting" @click="closeTransferProcessModal">
                    <i class="pi pi-arrow-left"></i>
                </button>
                <div>
                    <h2>Proceso de Transferencia</h2>
                    <p>Asignación de ubicaciones físicas - Solicitud #{{ transfer?.number }}</p>
                </div>
                <button type="button" class="detalle-process-modal__close" :disabled="transferProcessSubmitting" @click="closeTransferProcessModal">
                    <i class="pi pi-times"></i>
                </button>
            </header>

            <div class="detalle-process-modal__body">
                <section class="detalle-process-modal__progress">
                    <div class="detalle-process-modal__progress-head">
                        <strong>Progreso de Asignación</strong>
                        <span>{{ locatedBoxesCount }} de {{ transfer?.boxes?.length ?? 0 }} cajas ubicadas</span>
                    </div>
                    <div class="detalle-process-modal__progress-track">
                        <div class="detalle-process-modal__progress-bar" :style="{ width: `${locationProgress}%` }"></div>
                    </div>
                </section>

                <div class="detalle-process-modal__grid">
                    <aside class="detalle-process-modal__info">
                        <h3>Información de la Solicitud</h3>
                        <div>
                            <span>N° de transferencia</span>
                            <strong>{{ transfer?.number }}</strong>
                        </div>
                        <div>
                            <span>Unidad productora</span>
                            <strong>{{ transfer?.unit }}</strong>
                        </div>
                        <div>
                            <span>Responsable</span>
                            <strong>{{ transfer?.responsible }}</strong>
                        </div>
                        <div>
                            <span>Estado</span>
                            <em>{{ transfer?.status }}</em>
                        </div>
                        <hr />
                        <div>
                            <span>Fecha de entrega programada</span>
                            <strong>{{ transfer?.scheduledSummary?.deliveryDate || 'Sin fecha' }}</strong>
                        </div>
                        <div>
                            <span>Hora de entrega</span>
                            <strong>{{ transfer?.scheduledSummary?.deliveryTime || 'Sin hora' }}</strong>
                        </div>
                        <hr />
                        <div>
                            <span>Total de cajas</span>
                            <strong>{{ transfer?.boxes?.length ?? 0 }} caja(s)</strong>
                        </div>
                    </aside>

                    <section class="detalle-process-modal__boxes">
                        <article
                            v-for="box in transfer?.boxes ?? []"
                            :key="`process-${box.number}`"
                            :class="['detalle-process-box', { 'detalle-process-box--active': activeProcessBox === box.number }]"
                        >
                            <button type="button" class="detalle-process-box__summary" @click="activeProcessBox = activeProcessBox === box.number ? null : box.number">
                                <div class="detalle-process-box__summary-main">
                                    <div class="detalle-process-box__icon">
                                        <i class="pi pi-box"></i>
                                    </div>
                                    <div>
                                        <div class="detalle-process-box__title">
                                            Caja {{ displayBoxNumber(box) }}
                                            <span :class="isBoxLocated(box.number) ? 'detalle-process-box__status detalle-process-box__status--done' : 'detalle-process-box__status'">
                                                <i :class="isBoxLocated(box.number) ? 'pi pi-check-circle' : 'pi pi-exclamation-circle'"></i>
                                                {{ isBoxLocated(box.number) ? 'Ubicada' : 'Pendiente' }}
                                            </span>
                                        </div>
                                        <p>{{ box.title }} - {{ box.period }}</p>
                                    </div>
                                </div>
                                <i :class="activeProcessBox === box.number ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"></i>
                            </button>

                            <div v-if="activeProcessBox === box.number" class="detalle-process-box__form">
                                <h4><i class="pi pi-map-marker"></i> Asignar Ubicación Física</h4>
                                <div class="detalle-process-box__fields">
                                    <label>
                                        <span>Oficina *</span>
                                        <Dropdown
                                            v-model="ensureBoxLocation(box).office_id"
                                            :options="locationOptions.offices"
                                            optionLabel="name"
                                            optionValue="id"
                                            placeholder="Seleccionar"
                                            fluid
                                            @change="onBoxOfficeChange(box)"
                                        />
                                    </label>
                                    <label>
                                        <span>Pasillo *</span>
                                        <Dropdown
                                            v-model="ensureBoxLocation(box).aisle_id"
                                            :options="aisleOptionsForLocation(ensureBoxLocation(box))"
                                            optionLabel="name"
                                            optionValue="id"
                                            placeholder="Seleccionar"
                                            fluid
                                            :disabled="!ensureBoxLocation(box).office_id"
                                            @change="onBoxAisleChange(box)"
                                        />
                                    </label>
                                    <label>
                                        <span>Estante *</span>
                                        <Dropdown
                                            v-model="ensureBoxLocation(box).shelf_id"
                                            :options="shelfOptionsForLocation(ensureBoxLocation(box))"
                                            optionLabel="name"
                                            optionValue="id"
                                            placeholder="Seleccionar"
                                            fluid
                                            :disabled="!ensureBoxLocation(box).aisle_id"
                                        />
                                    </label>
                                </div>
                                <div v-if="isBoxLocated(box.number)" class="detalle-process-box__location-code">
                                    <i class="pi pi-check-circle"></i>
                                    <div>
                                        <span>Código de ubicación generado</span>
                                        <strong>{{ generatedBoxLocationCode(box) }}</strong>
                                        <small>Se guardará al completar la transferencia.</small>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </section>
                </div>
            </div>

            <footer class="detalle-process-modal__footer">
                <button type="button" class="detalle-process-modal__complete" :disabled="!canCompleteTransferProcess" @click="completeTransferProcess">
                    <i class="pi pi-save"></i>
                    {{ transferProcessSubmitting ? 'Completando...' : 'Completar transferencia' }}
                </button>
                <button type="button" class="detalle-process-modal__cancel" :disabled="transferProcessSubmitting" @click="closeTransferProcessModal">
                    Cancelar
                </button>
                <span class="detalle-process-modal__pending">
                    <i class="pi pi-info-circle"></i>
                    {{ pendingBoxesCount }} caja(s) pendiente(s)
                </span>
            </footer>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="transferConfirmModalVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
        :style="{ width: 'min(680px, 94vw)' }"
    >
        <div class="detalle-transfer-confirm">
            <header class="detalle-transfer-confirm__header">
                <div class="detalle-transfer-confirm__title">
                    <i class="pi pi-check-circle"></i>
                    <h2>Confirmar Transferencia Física</h2>
                </div>
                <button type="button" class="detalle-transfer-confirm__close" :disabled="transferProcessSubmitting" @click="closeTransferConfirmModal">
                    <i class="pi pi-times"></i>
                </button>
            </header>

            <div class="detalle-transfer-confirm__body">
                <p class="detalle-transfer-confirm__intro">
                    Revise el resumen de la transferencia antes de completar el proceso.
                </p>

                <section class="detalle-transfer-confirm__summary">
                    <h3>Resumen de la Transferencia</h3>
                    <div>
                        <span>N° de transferencia:</span>
                        <strong>{{ transfer?.number }}</strong>
                    </div>
                    <div>
                        <span>Total de cajas:</span>
                        <strong>{{ transfer?.boxes?.length ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>Unidad productora:</span>
                        <strong>{{ transfer?.unit }}</strong>
                    </div>
                </section>

                <section>
                    <h3 class="detalle-transfer-confirm__section-title">Ubicaciones Asignadas</h3>
                    <div class="detalle-transfer-confirm__locations">
                        <article v-for="box in transfer?.boxes ?? []" :key="`confirm-location-${box.number}`" class="detalle-transfer-confirm__location">
                            <div class="detalle-transfer-confirm__location-main">
                                <span class="detalle-transfer-confirm__box-icon">
                                    <i class="pi pi-box"></i>
                                </span>
                                <strong>{{ boxTitle(box) }}</strong>
                            </div>
                            <span class="detalle-transfer-confirm__code">
                                <i class="pi pi-map-marker"></i>
                                {{ generatedBoxLocationCode(box) }}
                            </span>
                        </article>
                    </div>
                </section>

                <section class="detalle-transfer-confirm__notice">
                    <i class="pi pi-info-circle"></i>
                    <div>
                        <strong>Información importante</strong>
                        <p>
                            Al confirmar, la transferencia será marcada como completada y las ubicaciones físicas quedarán registradas en el sistema.
                        </p>
                    </div>
                </section>
            </div>

            <footer class="detalle-transfer-confirm__footer">
                <button type="button" class="detalle-process-modal__cancel" :disabled="transferProcessSubmitting" @click="closeTransferConfirmModal">
                    Cancelar
                </button>
                <button type="button" class="detalle-process-modal__complete" :disabled="transferProcessSubmitting" @click="completeTransferProcess">
                    <i class="pi pi-check-circle"></i>
                    {{ transferProcessSubmitting ? 'Completando...' : 'Completar transferencia' }}
                </button>
            </footer>
        </div>
    </Dialog>

    <Dialog
        v-model:visible="labelsDialogVisible"
        modal
        :draggable="false"
        :closable="false"
        :showHeader="false"
        :contentStyle="{ padding: '0', overflow: 'hidden' }"
        :style="{ width: 'min(900px, 96vw)', maxWidth: '900px', maxHeight: '80vh' }"
    >
        <div class="detalle-labels-modal">
            <div class="detalle-labels-modal__header">
                <div class="flex align-items-start gap-3">
                    <div class="detalle-labels-modal__title-icon">
                        <i class="pi pi-box"></i>
                    </div>
                    <div>
                        <h2 class="detalle-labels-modal__title m-0">Etiquetas de Identificación</h2>
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
                <div
                    ref="labelsPreviewRef"
                    class="detalle-labels-preview"
                    :style="{ overflow: 'auto', maxHeight: 'calc(80vh - 140px)', padding: '1rem' }"
                >
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
                                <img :src="label.qrDataUrl" :alt="`Código QR ${label.labelCode}`" />
                                <span>Código: {{ label.labelCode }}</span>
                            </div>

                            <div class="transfer-label__hero-main">
                                <div class="transfer-label__location">
                                    <span>CÓDIGO DE UBICACIÓN</span>
                                    <strong>{{ label.locationCode }}</strong>
                                </div>

                                <div class="transfer-label__box">
                                    <span>N° de caja</span>
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

:deep(.detalle-decision-modal__datepicker),
:deep(.detalle-decision-modal__datepicker .p-datepicker),
:deep(.detalle-decision-modal__datepicker .p-inputtext) {
    width: 100%;
}

.detalle-decision-modal__actions {
    width: 100%;
    display: flex;
    justify-content: center;
    gap: 0.85rem;
    margin-top: 1.55rem;
}

.detalle-decision-modal__button {
    align-items: center;
    display: inline-flex;
    justify-content: center;
    min-width: 10rem;
    border-radius: 0.75rem;
    padding: 0.95rem 1.35rem;
    font-weight: 700;
    font-size: 0.98rem;
    line-height: 1;
    text-align: center;
    cursor: pointer;
    border: 1px solid transparent;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.detalle-decision-modal__button:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.detalle-decision-modal__button:disabled {
    cursor: not-allowed;
}

.detalle-decision-modal__button--success {
    background: #56b657;
    color: #ffffff;
}

.detalle-decision-modal__button--primary {
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.detalle-decision-modal__button--warning {
    background: var(--ugda-warning);
    border-color: var(--ugda-warning);
    color: #ffffff;
}

.detalle-decision-modal__button--warning:hover:not(:disabled) {
    background: #d97706;
    border-color: #d97706;
}

.detalle-decision-modal__button--danger {
    background: #ef4444;
    color: #ffffff;
}

.detalle-decision-modal__button--danger:hover:not(:disabled) {
    background: #dc2626;
}

.detalle-decision-modal__button--cancel {
    background: #ffffff;
}

.detalle-decision-modal__button--cancel-success {
    border-color: #7a8ea5;
    color: #64748b;
}

.detalle-decision-modal__button--cancel-primary {
    border-color: var(--ugda-border-strong);
    color: var(--ugda-text-secondary);
}

.detalle-decision-modal__button--cancel-warning {
    border-color: var(--ugda-warning);
    color: var(--ugda-warning);
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

.detalle-banner--blue {
    background: #dbeafe;
    border-left: 4px solid #1d4ed8;
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

.detalle-banner__icon--blue {
    background: #1d4ed8;
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

.detalle-banner__text--strong {
    color: #8a5b00;
    font-weight: 800;
    margin-top: 1rem;
}

.detalle-banner__panel {
    background: #ffffff;
    border-radius: 1rem;
    padding: 1rem 1.15rem;
}

.detalle-banner__divider {
    border-top: 1px solid #c7d2fe;
    margin: 1rem 0;
}

.detalle-banner__notes {
    background: #edf4ff;
    border-radius: 0.7rem;
    color: #0f3f91;
    padding: 0.9rem 1rem;
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

.detalle-banner__actions {
    border-top: 1px solid #f3d27a;
    margin-top: 1rem;
    padding-top: 1rem;
}

.detalle-banner__edit-button {
    background: #f6ad3c;
    border-color: #f6ad3c;
    color: #ffffff;
    font-weight: 800;
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
    border-radius: 8px;
    box-shadow: none;
    min-height: 104px;
    overflow: hidden;
}

.detalle-physical-review-card {
    border: 2px solid #f07b00;
    border-radius: 8px;
    box-shadow: none;
    min-height: 104px;
    overflow: hidden;
}

.detalle-process-card__content {
    align-items: center;
    display: flex;
    gap: 1.5rem;
    justify-content: space-between;
    min-height: 100px;
    padding: 1.5rem 1.55rem;
}

.detalle-process-card__main {
    align-items: center;
    display: flex;
    gap: 1rem;
    min-width: 0;
}

.detalle-process-card__icon {
    width: 3.1rem;
    height: 3.1rem;
    border-radius: 8px;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 1.35rem;
}

.detalle-process-card__icon--warning {
    background: #e87900;
}

.detalle-process-card h3 {
    color: #0f172a;
    font-size: 1.15rem;
    font-weight: 800;
    margin: 0;
}

.detalle-process-card p {
    color: #43546a;
    font-size: 0.95rem;
    line-height: 1.45;
    margin: 0.45rem 0 0;
}

.detalle-process-card__button {
    background: #2f6f9f;
    border: none;
    border-radius: 8px;
    flex-shrink: 0;
    font-size: 0.95rem;
    font-weight: 800;
    min-height: 3rem;
    min-width: 19rem;
}

.detalle-physical-review-card__actions {
    display: flex;
    flex-shrink: 0;
    flex-wrap: wrap;
    gap: 0.8rem;
    justify-content: flex-end;
}

.detalle-physical-review-card__observe {
    border-color: #e87900;
    color: #c56200;
    font-weight: 800;
    min-height: 3rem;
    min-width: 12rem;
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
    background: var(--ugda-success) !important;
    border: 1px solid var(--ugda-success) !important;
    color: var(--ugda-text-inverse) !important;
}

.detalle-print-card__button:hover:not(:disabled) {
    background: var(--ugda-success-text) !important;
    border-color: var(--ugda-success-text) !important;
    color: var(--ugda-text-inverse) !important;
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
    background: #ffffff;
    color: #0f172a;
    font-size: 1.25rem;
    font-weight: 600;
    padding: 1.75rem 2rem 0;
}

.detalle-panel__header--split {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.detalle-panel__header--split .detalle-link-button {
    color: #1f5f8e;
}

.detalle-panel__body {
    padding: 1.4rem 2rem 2rem;
}

.detalle-panel__chips {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.7rem;
    justify-content: flex-end;
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

.detalle-summary-series-row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    min-width: 0;
}

.detalle-summary-series-count,
.detalle-summary-series-chip {
    border-radius: 6px;
    display: inline-flex;
    font-size: 0.75rem;
    line-height: 1.2;
    max-width: 20rem;
    overflow: hidden;
    padding: 0.32rem 0.5rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.detalle-summary-series-count {
    background: #dcecff;
    color: #1f5f8e;
    font-weight: 700;
}

.detalle-summary-series-chip {
    background: #f4f7fb;
    color: #123354;
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
    padding: 1.35rem 1.45rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.25rem;
    cursor: pointer;
}

.detalle-box-card__header > .flex {
    align-items: center;
    gap: 1.2rem !important;
}

.detalle-box-card__icon {
    width: 2.45rem;
    height: 2.45rem;
    border-radius: 0.85rem;
    background: #2f6f9f;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}

.detalle-box-card__title {
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 700;
}

.detalle-box-card__series-row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem 0.55rem;
    margin-top: 0.55rem;
}

.detalle-box-card__series-count,
.detalle-box-card__series-chip {
    border-radius: 6px;
    display: inline-flex;
    font-size: 0.78rem;
    line-height: 1.2;
    max-width: min(100%, 24rem);
    overflow: hidden;
    padding: 0.35rem 0.55rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.detalle-box-card__series-count {
    background: #dcecff;
    color: #1f5f8e;
    font-weight: 700;
}

.detalle-box-card__series-chip {
    background: #f4f7fb;
    color: #123354;
}

.detalle-box-card__subtitle {
    color: #64748b;
    font-size: 0.94rem;
    margin-top: 0.6rem;
}

.detalle-box-card__content {
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid #d7dde6;
}

.detalle-box-location-header {
    border: 2px solid #1f2937;
    border-radius: 1rem;
    color: #111827;
    margin-bottom: 1.25rem;
    overflow: hidden;
}

.detalle-box-location-header__top {
    align-items: flex-start;
    border-bottom: 1px solid #d7dde6;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: 1.2rem 1.35rem 1rem;
}

.detalle-box-location-header__top h3 {
    color: #111827;
    font-size: 1.55rem;
    font-weight: 900;
    letter-spacing: 0;
    margin: 0;
}

.detalle-box-location-header__top p {
    color: #64748b;
    margin: 0.45rem 0 0;
}

.detalle-box-location-header__date {
    color: #526377;
    min-width: 11rem;
    text-align: right;
}

.detalle-box-location-header__date span,
.detalle-box-location-header__details span,
.detalle-box-location-header__qr span,
.detalle-box-location-header__box-number span {
    color: #64748b;
    display: block;
    font-size: 0.88rem;
}

.detalle-box-location-header__date strong {
    color: #111827;
    display: block;
    font-size: 1.12rem;
    margin-top: 0.3rem;
}

.detalle-box-location-header__main {
    align-items: center;
    display: grid;
    gap: 1.5rem;
    grid-template-columns: 9rem minmax(0, 1fr);
    padding: 1.2rem 1.35rem;
}

.detalle-box-location-header__qr {
    text-align: center;
}

.detalle-box-location-header__qr img {
    display: block;
    height: 6.2rem;
    margin: 0 auto 0.55rem;
    width: 6.2rem;
}

.detalle-box-location-header__hero {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.detalle-box-location-header__code {
    background: #2f6f9f;
    border-radius: 0.85rem;
    color: #ffffff;
    padding: 1rem 1.2rem;
    text-align: center;
}

.detalle-box-location-header__code span {
    display: block;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0;
}

.detalle-box-location-header__code strong {
    display: block;
    font-size: 2rem;
    font-weight: 900;
    letter-spacing: 0;
    margin-top: 0.45rem;
}

.detalle-box-location-header__box-number {
    background: #edf3fa;
    border-radius: 0.85rem;
    padding: 0.8rem 1rem;
}

.detalle-box-location-header__box-number strong {
    color: #111827;
    display: block;
    font-size: 1.65rem;
    font-weight: 900;
    margin-top: 0.25rem;
}

.detalle-box-location-header__details {
    border-top: 1px solid #d7dde6;
    display: grid;
    gap: 1.3rem;
    grid-template-columns: minmax(8rem, 0.7fr) minmax(12rem, 1fr) minmax(12rem, 1fr);
    padding: 1rem 1.35rem;
}

.detalle-box-location-header__details strong {
    color: #111827;
    display: block;
    font-weight: 800;
    margin-top: 0.35rem;
}

.detalle-box-location-header__assigned {
    border-top: 1px solid #d7dde6;
    color: #64748b;
    padding: 0.8rem 1.35rem;
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

.detalle-document-file--restricted {
    color: #64748b;
    font-weight: 700;
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

.detalle-support-chip--restricted {
    background: #f1f5f9;
    color: #64748b;
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
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
}

.detalle-action--success {
    background: #56b657;
    color: #ffffff;
}

.detalle-action--warning {
    background: var(--ugda-warning);
    border-color: var(--ugda-warning);
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

.detalle-unit-auth-modal {
    background: #ffffff;
    max-height: 86vh;
    display: flex;
    flex-direction: column;
}

.detalle-unit-auth-modal__header {
    align-items: center;
    background: #56b657;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.35rem 1.55rem;
}

.detalle-unit-auth-modal__header h2,
.detalle-unit-auth-modal__header p {
    margin: 0;
}

.detalle-unit-auth-modal__header h2 {
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 800;
}

.detalle-unit-auth-modal__header p {
    color: rgba(255, 255, 255, 0.92);
    font-size: 0.92rem;
    margin-top: 0.45rem;
}

.detalle-unit-auth-modal__close {
    align-items: center;
    background: transparent;
    border: 2px solid rgba(255, 255, 255, 0.9);
    border-radius: 999px;
    color: #ffffff;
    cursor: pointer;
    display: inline-flex;
    height: 2.1rem;
    justify-content: center;
    width: 2.1rem;
}

.detalle-unit-auth-modal__body {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
    overflow: auto;
    padding: 1.5rem;
}

.detalle-unit-auth-modal__notice {
    align-items: flex-start;
    background: #d9f0f6;
    border-left: 4px solid #1da6c1;
    border-radius: 8px;
    color: #14566a;
    display: flex;
    gap: 0.9rem;
    padding: 1rem;
}

.detalle-unit-auth-modal__notice i {
    font-size: 1.25rem;
    margin-top: 0.12rem;
}

.detalle-unit-auth-modal__notice strong,
.detalle-unit-auth-modal__notice span {
    display: block;
}

.detalle-unit-auth-modal__notice span {
    font-size: 0.92rem;
    margin-top: 0.55rem;
}

.detalle-unit-auth-modal__card,
.detalle-unit-auth-modal__summary {
    border-radius: 8px;
    padding: 1.35rem 1.5rem;
}

.detalle-unit-auth-modal__card {
    background: #f8fafc;
}

.detalle-unit-auth-modal__summary {
    border: 1px solid #dbe3ed;
}

.detalle-unit-auth-modal__card h3,
.detalle-unit-auth-modal__summary h3 {
    color: #1f2937;
    font-size: 1rem;
    font-weight: 800;
    margin: 0;
}

.detalle-unit-auth-modal__divider {
    background: #dbe3ed;
    height: 1px;
    margin: 0.9rem 0 1.15rem;
}

.detalle-unit-auth-modal__grid {
    display: grid;
    gap: 1.4rem 2rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.detalle-unit-auth-modal__grid span,
.detalle-unit-auth-modal__box-period {
    color: #64748b;
    display: block;
    font-size: 0.86rem;
}

.detalle-unit-auth-modal__grid strong {
    color: #111827;
    display: block;
    margin-top: 0.45rem;
}

.detalle-unit-auth-modal__stats {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.detalle-unit-auth-modal__stats div {
    background: #eef6fb;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}

.detalle-unit-auth-modal__stats span {
    color: #64748b;
    display: block;
    font-size: 0.82rem;
}

.detalle-unit-auth-modal__stats strong {
    color: #56b657;
    display: block;
    font-size: 1.85rem;
    margin-top: 0.55rem;
}

.detalle-unit-auth-modal__boxes {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    margin-top: 1.5rem;
}

.detalle-unit-auth-modal__box {
    align-items: flex-start;
    border: 1px solid #dbe3ed;
    border-radius: 8px;
    display: flex;
    gap: 1.25rem;
    justify-content: space-between;
    padding: 0.9rem 1rem;
}

.detalle-unit-auth-modal__box-content {
    min-width: 0;
}

.detalle-unit-auth-modal__box-series {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.detalle-unit-auth-modal__box-badge {
    background: #56b657;
    border-radius: 999px;
    color: #ffffff;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1;
    padding: 0.45rem 0.85rem;
    white-space: nowrap;
}

.detalle-unit-auth-modal__series-count {
    background: #dbeafe;
    border-radius: 5px;
    color: #1d5fa0;
    display: inline-flex;
    font-size: 0.76rem;
    font-weight: 700;
    line-height: 1.1;
    padding: 0.35rem 0.55rem;
    white-space: nowrap;
}

.detalle-unit-auth-modal__series-chip {
    background: #f4f6f8;
    border-radius: 4px;
    color: #1f2937;
    display: inline-flex;
    font-size: 0.74rem;
    line-height: 1.2;
    max-width: 100%;
    padding: 0.35rem 0.55rem;
}

.detalle-unit-auth-modal__box-period {
    margin-top: 0.55rem;
}

.detalle-unit-auth-modal__box-period strong {
    color: #111827;
    font-weight: 800;
}

.detalle-unit-auth-modal__box-documents {
    align-self: center;
    color: #56b657;
    flex-shrink: 0;
    font-weight: 800;
    line-height: 1.45;
    min-width: 6.5rem;
    text-align: center;
}

.detalle-unit-auth-modal__footer {
    align-items: center;
    background: #f8fafc;
    border-top: 1px solid #dbe3ed;
    display: flex;
    gap: 0.8rem;
    justify-content: flex-end;
    padding: 1rem 1.5rem;
}

.detalle-unit-auth-modal__button {
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 800;
    min-height: 3.2rem;
    min-width: 7.2rem;
    padding: 0 1.4rem;
}

.detalle-unit-auth-modal__button--cancel {
    background: #ffffff;
    border: 1px solid #64748b;
    color: #64748b;
}

.detalle-unit-auth-modal__button--confirm {
    background: #56b657;
    border: 1px solid #56b657;
    color: #ffffff;
    min-width: 14.5rem;
}

.detalle-process-modal {
    background: #f3f5f8;
    display: flex;
    flex-direction: column;
    max-height: 92vh;
}

.detalle-process-modal__header {
    align-items: center;
    background: #f3f5f8;
    display: flex;
    gap: 1.2rem;
    padding: 1.4rem 1.6rem;
}

.detalle-process-modal__header h2,
.detalle-process-modal__header p {
    margin: 0;
}

.detalle-process-modal__header h2 {
    color: #2f6f9f;
    font-size: 2rem;
    font-weight: 800;
}

.detalle-process-modal__header p {
    color: #526377;
    margin-top: 0.45rem;
}

.detalle-process-modal__back,
.detalle-process-modal__close {
    align-items: center;
    background: transparent;
    border: 0;
    color: #2f6f9f;
    cursor: pointer;
    display: inline-flex;
    font-size: 1.1rem;
    height: 2.4rem;
    justify-content: center;
    width: 2.4rem;
}

.detalle-process-modal__close {
    margin-left: auto;
}

.detalle-process-modal__body {
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
    overflow: auto;
    padding: 0 1.5rem 1rem;
}

.detalle-process-modal__progress,
.detalle-process-modal__info,
.detalle-process-box,
.detalle-process-modal__footer {
    background: #ffffff;
    border: 1px solid #d7e0ea;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
}

.detalle-process-modal__progress {
    padding: 1.4rem 1.5rem;
}

.detalle-process-modal__progress-head {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.detalle-process-modal__progress-head span {
    color: #1f5f8e;
    font-weight: 800;
}

.detalle-process-modal__progress-track {
    background: #e5e7eb;
    border-radius: 999px;
    height: 0.8rem;
    overflow: hidden;
}

.detalle-process-modal__progress-bar {
    background: #2f6f9f;
    height: 100%;
    transition: width 0.2s ease;
}

.detalle-process-modal__grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: minmax(18rem, 0.45fr) minmax(0, 1fr);
}

.detalle-process-modal__info {
    align-self: start;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    padding: 1.5rem;
}

.detalle-process-modal__info h3 {
    color: #1f2937;
    font-size: 1.2rem;
    font-weight: 800;
    margin: 0;
}

.detalle-process-modal__info span {
    color: #64748b;
    display: block;
    font-size: 0.88rem;
    margin-bottom: 0.45rem;
}

.detalle-process-modal__info strong {
    color: #111827;
}

.detalle-process-modal__info em {
    background: #dbeafe;
    border-radius: 999px;
    color: #1f5f8e;
    display: inline-flex;
    font-style: normal;
    font-weight: 800;
    padding: 0.3rem 0.75rem;
}

.detalle-process-modal__info hr {
    border: 0;
    border-top: 1px solid #d7e0ea;
    margin: 0;
    width: 100%;
}

.detalle-process-modal__boxes {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.detalle-process-box {
    overflow: hidden;
}

.detalle-process-box--active {
    border-color: #2f6f9f;
    box-shadow: 0 0 0 1px #2f6f9f;
}

.detalle-process-box__summary {
    align-items: center;
    background: transparent;
    border: 0;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    padding: 1.25rem;
    text-align: left;
    width: 100%;
}

.detalle-process-box__summary-main {
    align-items: center;
    display: flex;
    gap: 1rem;
    min-width: 0;
}

.detalle-process-box__icon {
    align-items: center;
    background: #2f6f9f;
    border-radius: 8px;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.25rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.detalle-process-box__title {
    align-items: center;
    color: #111827;
    display: flex;
    flex-wrap: wrap;
    font-size: 1.05rem;
    font-weight: 800;
    gap: 0.65rem;
}

.detalle-process-box__summary p {
    color: #526377;
    margin: 0.45rem 0 0;
}

.detalle-process-box__status {
    align-items: center;
    color: #f59e0b;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 800;
    gap: 0.3rem;
}

.detalle-process-box__status--done {
    color: #10b981;
}

.detalle-process-box__form {
    border-top: 1px solid #d7e0ea;
    padding: 1.35rem;
}

.detalle-process-box__form h4 {
    align-items: center;
    color: #111827;
    display: flex;
    gap: 0.6rem;
    margin: 0 0 1.2rem;
}

.detalle-process-box__form h4 i {
    color: #2f6f9f;
}

.detalle-process-box__fields {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.detalle-process-box__location-code {
    align-items: flex-start;
    background: #eef6fb;
    border-left: 4px solid #2f6f9f;
    border-radius: 8px;
    color: #1f5f8e;
    display: flex;
    gap: 0.9rem;
    margin-top: 1.25rem;
    padding: 1rem 1.1rem;
}

.detalle-process-box__location-code > i {
    color: #10b981;
    flex-shrink: 0;
    font-size: 1.35rem;
    margin-top: 0.15rem;
}

.detalle-process-box__location-code span,
.detalle-process-box__location-code small {
    color: #334155;
    display: block;
}

.detalle-process-box__location-code span {
    font-weight: 800;
    margin-bottom: 0.45rem;
}

.detalle-process-box__location-code strong {
    color: #1f5f8e;
    display: block;
    font-size: 1.25rem;
    letter-spacing: 0;
    margin-bottom: 0.45rem;
}

.detalle-process-box__fields label {
    color: #526377;
    display: flex;
    flex-direction: column;
    font-size: 0.86rem;
    font-weight: 700;
    gap: 0.55rem;
}

.detalle-process-box__fields label span {
    color: #526377;
}

.detalle-process-modal__footer {
    align-items: center;
    display: flex;
    gap: 1rem;
    margin: 0 1.5rem 1.5rem;
    padding: 1rem;
}

.detalle-process-modal__complete,
.detalle-process-modal__cancel {
    align-items: center;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    font-size: 1rem;
    font-weight: 800;
    gap: 0.65rem;
    justify-content: center;
    min-height: 3.2rem;
    padding: 0 1.4rem;
}

.detalle-process-modal__complete {
    background: #2f6f9f;
    border: 1px solid #2f6f9f;
    color: #ffffff;
    min-width: 16.5rem;
}

.detalle-process-modal__complete:disabled {
    background: #98b6d2;
    border-color: #98b6d2;
    cursor: not-allowed;
}

.detalle-process-modal__cancel {
    background: #ffffff;
    border: 1px solid #2f6f9f;
    color: #1f5f8e;
    min-width: 8rem;
}

.detalle-process-modal__pending {
    align-items: center;
    color: #64748b;
    display: inline-flex;
    gap: 0.55rem;
    margin-left: auto;
}

.detalle-process-modal__pending--done {
    color: #10b981;
    font-weight: 800;
}

.detalle-process-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    padding-bottom: 6rem;
}

.detalle-process-page__header {
    align-items: center;
    display: flex;
    gap: 1rem;
}

.detalle-process-page__progress {
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
}

.detalle-process-page__grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: minmax(17rem, 0.36fr) minmax(0, 1fr);
}

.detalle-process-page__info {
    position: sticky;
    top: 1rem;
}

.detalle-process-page__boxes {
    min-width: 0;
}

.detalle-process-page__box {
    border-width: 2px;
}

.detalle-process-page__box--done {
    border-color: #10b981;
}

.detalle-process-box__icon--done {
    background: #10b981;
}

.detalle-process-page__code-chip {
    align-items: center;
    background: #eef6fb;
    border-radius: 8px;
    color: #1f5f8e;
    display: inline-flex;
    font-weight: 800;
    gap: 0.45rem;
    margin-top: 0.6rem;
    padding: 0.45rem 0.7rem;
}

.detalle-process-page__footer {
    bottom: 1rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16);
    left: 0;
    margin: 0;
    position: sticky;
    z-index: 5;
}

.detalle-transfer-confirm {
    background: #ffffff;
}

.detalle-transfer-confirm__header {
    align-items: center;
    display: flex;
    justify-content: space-between;
    padding: 1.55rem 1.65rem 1rem;
}

.detalle-transfer-confirm__title {
    align-items: center;
    color: #2f6f9f;
    display: flex;
    gap: 0.75rem;
}

.detalle-transfer-confirm__title i {
    color: #10b981;
    font-size: 1.55rem;
}

.detalle-transfer-confirm__title h2 {
    font-size: 1.55rem;
    font-weight: 800;
    margin: 0;
}

.detalle-transfer-confirm__close {
    align-items: center;
    background: transparent;
    border: 0;
    color: #526377;
    cursor: pointer;
    display: inline-flex;
    font-size: 1rem;
    height: 2.25rem;
    justify-content: center;
    width: 2.25rem;
}

.detalle-transfer-confirm__body {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-height: min(68vh, 42rem);
    overflow: auto;
    padding: 0 1.65rem 1.45rem;
}

.detalle-transfer-confirm__intro {
    color: #64748b;
    margin: 0;
}

.detalle-transfer-confirm__summary {
    background: #eef6fb;
    border: 1px solid #c7d9e8;
    border-radius: 8px;
    display: grid;
    gap: 0.8rem;
    padding: 1rem;
}

.detalle-transfer-confirm__summary h3,
.detalle-transfer-confirm__section-title {
    color: #111827;
    font-size: 1rem;
    font-weight: 800;
    margin: 0 0 0.6rem;
}

.detalle-transfer-confirm__summary div {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.detalle-transfer-confirm__summary span {
    color: #64748b;
}

.detalle-transfer-confirm__summary strong {
    color: #111827;
}

.detalle-transfer-confirm__locations {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detalle-transfer-confirm__location {
    align-items: center;
    border: 1px solid #10b981;
    border-radius: 8px;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: 0.85rem 1rem;
}

.detalle-transfer-confirm__location-main {
    align-items: center;
    display: flex;
    gap: 0.8rem;
}

.detalle-transfer-confirm__box-icon {
    align-items: center;
    background: #10b981;
    border-radius: 8px;
    color: #ffffff;
    display: inline-flex;
    height: 2.3rem;
    justify-content: center;
    width: 2.3rem;
}

.detalle-transfer-confirm__code {
    align-items: center;
    background: #2f6f9f;
    border-radius: 6px;
    color: #ffffff;
    display: inline-flex;
    font-weight: 800;
    gap: 0.4rem;
    padding: 0.45rem 0.7rem;
}

.detalle-transfer-confirm__notice {
    align-items: flex-start;
    background: #eef6fb;
    border-left: 4px solid #2f6f9f;
    border-radius: 8px;
    display: flex;
    gap: 0.85rem;
    padding: 1rem;
}

.detalle-transfer-confirm__notice i {
    color: #2f6f9f;
    font-size: 1.2rem;
    margin-top: 0.15rem;
}

.detalle-transfer-confirm__notice strong {
    color: #1f2937;
}

.detalle-transfer-confirm__notice p {
    color: #64748b;
    line-height: 1.45;
    margin: 0.45rem 0 0;
}

.detalle-transfer-confirm__footer {
    align-items: center;
    border-top: 1px solid #dbe3ed;
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    padding: 1rem 1.65rem;
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
    cursor: wait;
}

.detalle-labels-modal__button--ghost {
    background: #ffffff;
    border: 1px solid #2f6f9f;
    color: #2f6f9f;
}

.detalle-labels-modal__button--primary {
    background: var(--ugda-success);
    border: 1px solid var(--ugda-success);
    color: var(--ugda-text-inverse);
}

.detalle-labels-modal__button--primary:hover:not(:disabled) {
    background: var(--ugda-success-text);
    border-color: var(--ugda-success-text);
    color: var(--ugda-text-inverse);
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
    margin-bottom: 1.5rem;
}

.detalle-labels-preview {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-height: calc(100vh - 12.5rem);
    overflow-x: auto;
    overflow-y: auto;
    padding: 0.25rem 0 1rem;
}

.transfer-label {
    background: #ffffff;
    border: 2px solid #1f2937;
    border-radius: 0.5cm;
    display: flex;
    flex-direction: column;
    height: 18cm;
    min-height: 18cm;
    margin-bottom: 0;
    max-width: none;
    overflow: hidden;
    width: 10cm;
}

.transfer-label--export {
    height: 18cm !important;
    margin-bottom: 0 !important;
    max-width: none !important;
    min-height: 18cm !important;
    width: 10cm !important;
}

.transfer-label--export .transfer-label__footer {
    margin-top: auto;
}

.transfer-label__header,
.transfer-label__hero,
.transfer-label__details {
    display: grid;
    gap: 0.35cm;
}

.transfer-label__header {
    align-items: flex-start;
    border-bottom: 1px solid #dbe5ee;
    grid-template-columns: 1fr auto;
    padding: 0.58cm 0.55cm 0.36cm;
}

.transfer-label__university {
    color: #0f172a;
    font-size: 0.44cm;
    font-weight: 700;
    line-height: 1.15;
}

.transfer-label__faculty {
    color: #64748b;
    font-size: 0.28cm;
    line-height: 1.25;
    margin-top: 0.12cm;
}

.transfer-label__date {
    min-width: 2.25cm;
    text-align: right;
}

.transfer-label__date span,
.transfer-label__box span,
.transfer-label__details span,
.transfer-label__assigned,
.transfer-label__qr span {
    color: #64748b;
    font-size: 0.25cm;
    line-height: 1.25;
}

.transfer-label__date strong {
    display: block;
    color: #0f172a;
    font-size: 0.34cm;
    line-height: 1.2;
    margin-top: 0.12cm;
}

.transfer-label__hero {
    align-items: center;
    border-bottom: 1px solid #dbe5ee;
    grid-template-columns: 1fr;
    padding: 0.42cm 0.55cm;
}

.transfer-label__qr {
    text-align: center;
}

.transfer-label__qr img {
    height: 2.8cm;
    display: block;
    margin: 0 auto 0.12cm;
    width: 2.8cm;
}

.transfer-label__hero-main {
    min-width: 0;
}

.transfer-label__location {
    background: #2f6f9f;
    color: #ffffff;
    border-radius: 0.32cm;
    padding: 0.34cm 0.36cm;
    text-align: center;
}

.transfer-label__location span {
    display: block;
    font-size: 0.25cm;
    font-weight: 700;
}

.transfer-label__location strong {
    display: block;
    font-size: 0.7cm;
    font-weight: 700;
    letter-spacing: 0;
    line-height: 1.05;
    margin-top: 0.14cm;
}

.transfer-label__box {
    background: #edf3fa;
    border-radius: 0.28cm;
    margin-top: 0.28cm;
    padding: 0.3cm 0.36cm;
}

.transfer-label__box strong {
    display: block;
    color: #0f172a;
    font-size: 0.58cm;
    line-height: 1.1;
    margin-top: 0.1cm;
}

.transfer-label__details {
    grid-template-columns: 1fr;
    padding: 0.42cm 0.55cm 0.24cm;
}

.transfer-label__details strong {
    display: block;
    color: #0f172a;
    font-size: 0.33cm;
    line-height: 1.25;
    margin-top: 0.08cm;
}

.transfer-label__assigned {
    border-top: 1px solid #dbe5ee;
    margin: 0 0.55cm;
    padding: 0.3cm 0;
}

.transfer-label__footer {
    background: #fff7db;
    color: #b45309;
    font-size: 0.24cm;
    font-weight: 700;
    line-height: 1.3;
    margin-top: auto;
    padding: 0.28cm 0.55cm;
}

@media (max-width: 991px) {
    .detalle-title {
        font-size: 1.8rem;
    }

    .detalle-panel__header,
    .detalle-panel__body,
    .detalle-box-card__header,
    .detalle-box-card__content,
    .detalle-banner,
    .detalle-print-card {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-panel__header--split {
        align-items: flex-start;
        flex-direction: column;
    }

    .detalle-panel__chips {
        justify-content: flex-start;
    }

    .detalle-actions {
        gap: 0.75rem;
    }

    .detalle-action {
        width: 100%;
        justify-content: center;
    }

    .detalle-process-card__content {
        align-items: stretch;
        flex-direction: column;
        min-height: auto;
    }

    .detalle-process-card__button {
        min-width: 0;
        width: 100%;
    }

    .detalle-process-modal__grid,
    .detalle-process-page__grid,
    .detalle-process-box__fields {
        grid-template-columns: 1fr;
    }

    .detalle-process-page__info {
        position: static;
    }

    .detalle-process-modal__footer,
    .detalle-transfer-confirm__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .detalle-process-modal__complete,
    .detalle-process-modal__cancel {
        width: 100%;
    }

    .detalle-process-modal__pending {
        margin-left: 0;
    }

    .detalle-process-page__footer {
        bottom: 0.75rem;
    }

    .detalle-transfer-confirm__location,
    .detalle-transfer-confirm__summary div {
        align-items: flex-start;
        flex-direction: column;
    }

    .detalle-box-location-header__main,
    .detalle-box-location-header__details {
        grid-template-columns: 1fr;
    }

    .detalle-box-location-header__qr {
        text-align: left;
    }

    .detalle-box-location-header__qr img {
        margin-left: 0;
    }

    .detalle-labels-preview {
        max-height: calc(100vh - 13rem);
        padding-right: 0;
    }

    .transfer-label {
        height: 18cm;
        min-height: 18cm;
        width: 10cm;
    }

    .detalle-labels-modal__header,
    .detalle-labels-modal__body {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-labels-modal__header {
        flex-direction: column;
    }

    .detalle-labels-modal__actions {
        width: 100%;
    }

    .detalle-labels-modal__button {
        flex: 1 1 13rem;
    }

    .transfer-label__date {
        text-align: right;
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

    .detalle-process-page {
        padding-bottom: 8rem;
    }

    .detalle-process-page__header {
        align-items: flex-start;
    }

    .detalle-process-modal__progress-head,
    .detalle-process-box__summary {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.75rem;
    }

    .detalle-process-box__summary-main {
        align-items: flex-start;
    }

    .detalle-transfer-confirm__header,
    .detalle-transfer-confirm__body,
    .detalle-transfer-confirm__footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .detalle-transfer-confirm__title h2 {
        font-size: 1.25rem;
    }

    .detalle-box-location-header__top {
        flex-direction: column;
    }

    .detalle-box-location-header__date {
        min-width: 0;
        text-align: left;
    }

    .detalle-box-location-header__top h3 {
        font-size: 1.15rem;
    }

    .detalle-box-location-header__code strong {
        font-size: 1.45rem;
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
