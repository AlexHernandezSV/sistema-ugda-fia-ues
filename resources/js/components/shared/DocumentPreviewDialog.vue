<script setup>
import { nextTick, ref, watch } from 'vue';
import * as pdfjsLib from 'pdfjs-dist/legacy/build/pdf';
import pdfWorkerUrl from 'pdfjs-dist/legacy/build/pdf.worker.min.js?url';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const visible = defineModel('visible', { type: Boolean, default: false });

const props = defineProps({
    title: {
        type: String,
        default: 'Documento PDF',
    },
    source: {
        type: Object,
        default: null,
    },
    preserveUnderlyingDialogs: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['hide']);

const pdfCanvas = ref(null);
const loading = ref(false);
const downloading = ref(false);
const errorMessage = ref('');
const currentPage = ref(1);
const totalPages = ref(0);

let pdfDocument = null;
let renderTask = null;
let renderQueue = Promise.resolve();
let previewRequestId = 0;

const isPdfFile = (file) => (
    file
    && (
        file.type === 'application/pdf'
        || String(file.name ?? '').toLowerCase().endsWith('.pdf')
    )
);

const cancelRenderTask = async () => {
    const task = renderTask;

    if (!task) {
        return;
    }

    renderTask = null;

    try {
        task.cancel();
        await task.promise;
    } catch (error) {
        if (error?.name !== 'RenderingCancelledException') {
            console.warn('No se pudo cancelar el renderizado del PDF:', error);
        }
    }
};

const destroyPdfDocument = async () => {
    await renderQueue;
    await cancelRenderTask();

    if (!pdfDocument) {
        return;
    }

    try {
        await pdfDocument.destroy();
    } catch (error) {
        console.warn('No se pudo liberar la vista previa del PDF:', error);
    } finally {
        pdfDocument = null;
    }
};

const renderPage = (requestId = previewRequestId) => {
    const operation = renderQueue.then(async () => {
        if (requestId !== previewRequestId || !visible.value || !pdfDocument || !pdfCanvas.value) {
            return;
        }

        const documentForRender = pdfDocument;
        const page = await documentForRender.getPage(currentPage.value);

        if (requestId !== previewRequestId || documentForRender !== pdfDocument || !pdfCanvas.value) {
            return;
        }

        const canvas = pdfCanvas.value;
        const context = canvas.getContext('2d');
        const viewport = page.getViewport({ scale: 1.25 });
        const pixelRatio = window.devicePixelRatio || 1;

        canvas.width = Math.floor(viewport.width * pixelRatio);
        canvas.height = Math.floor(viewport.height * pixelRatio);
        canvas.style.width = `${viewport.width}px`;
        canvas.style.height = `${viewport.height}px`;

        context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
        context.clearRect(0, 0, viewport.width, viewport.height);

        const task = page.render({ canvasContext: context, viewport });
        renderTask = task;

        try {
            await task.promise;
        } finally {
            if (renderTask === task) {
                renderTask = null;
            }
        }
    });

    renderQueue = operation.catch(() => {});

    return operation;
};

const loadPdf = async () => {
    if (!props.source) {
        throw new Error('No hay archivo digital para visualizar.');
    }

    if (props.source.file) {
        if (!isPdfFile(props.source.file)) {
            throw new Error('Solo se puede visualizar archivos PDF.');
        }

        const arrayBuffer = await props.source.file.arrayBuffer();
        return pdfjsLib.getDocument({ data: new Uint8Array(arrayBuffer) }).promise;
    }

    if (props.source.url) {
        const response = await fetch(props.source.url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/pdf',
            },
        });

        if (!response.ok) {
            throw new Error('No se pudo cargar el archivo digital.');
        }

        const arrayBuffer = await response.arrayBuffer();
        return pdfjsLib.getDocument({ data: new Uint8Array(arrayBuffer) }).promise;
    }

    throw new Error('No hay archivo digital para visualizar.');
};

const openPreview = async () => {
    if (!visible.value) {
        return;
    }

    const requestId = ++previewRequestId;

    loading.value = true;
    errorMessage.value = '';
    currentPage.value = 1;
    totalPages.value = 0;

    await nextTick();

    try {
        await destroyPdfDocument();

        if (requestId !== previewRequestId || !visible.value) {
            return;
        }

        const loadedDocument = await loadPdf();

        if (requestId !== previewRequestId || !visible.value) {
            await loadedDocument.destroy();
            return;
        }

        pdfDocument = loadedDocument;
        totalPages.value = pdfDocument.numPages;
        await renderPage(requestId);
    } catch (error) {
        if (requestId === previewRequestId && error?.name !== 'RenderingCancelledException') {
            errorMessage.value = error.message || 'No se pudo visualizar el PDF.';
        }
    } finally {
        if (requestId === previewRequestId) {
            loading.value = false;
        }
    }
};

const goToPage = async (direction) => {
    if (!pdfDocument || loading.value) {
        return;
    }

    const nextPage = currentPage.value + direction;

    if (nextPage < 1 || nextPage > totalPages.value) {
        return;
    }

    loading.value = true;
    errorMessage.value = '';
    currentPage.value = nextPage;

    try {
        await renderPage();
    } catch (error) {
        if (error?.name !== 'RenderingCancelledException') {
            errorMessage.value = error.message || 'No se pudo cargar la pagina del PDF.';
        }
    } finally {
        loading.value = false;
    }
};

const sanitizeDownloadName = (name) => {
    const baseName = String(name || 'documento.pdf')
        .trim()
        .replace(/[\\/:*?"<>|]+/g, '-')
        .replace(/\s+/g, ' ');

    return baseName.toLowerCase().endsWith('.pdf') ? baseName : `${baseName}.pdf`;
};

const triggerBrowserDownload = (url, fileName) => {
    const link = document.createElement('a');
    link.href = url;
    link.download = sanitizeDownloadName(fileName);
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const downloadPreview = async () => {
    if (!props.source || downloading.value) {
        return;
    }

    downloading.value = true;
    const fileName = props.source.file?.name || props.source.fileName || props.source.name || props.title;
    let objectUrl = '';

    try {
        if (props.source.file) {
            objectUrl = URL.createObjectURL(props.source.file);
            triggerBrowserDownload(objectUrl, fileName);
            return;
        }

        if (props.source.url) {
            const response = await fetch(props.source.url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/pdf',
                },
            });

            if (!response.ok) {
                throw new Error('No se pudo descargar el PDF.');
            }

            const blob = await response.blob();
            objectUrl = URL.createObjectURL(blob);
            triggerBrowserDownload(objectUrl, fileName);
        }
    } catch (error) {
        errorMessage.value = error.message || 'No se pudo descargar el PDF.';
    } finally {
        if (objectUrl) {
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        }

        downloading.value = false;
    }
};

const closePreview = () => {
    visible.value = false;
};

const handleHide = async () => {
    previewRequestId += 1;
    await destroyPdfDocument();
    errorMessage.value = '';
    loading.value = false;
    emit('hide');
};

watch(
    () => [visible.value, props.source?.url, props.source?.file],
    () => {
        if (visible.value) {
            openPreview();
        }
    },
);
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :draggable="false"
        :pt="preserveUnderlyingDialogs ? { mask: { class: 'document-preview-dialog-mask--transparent' } } : undefined"
        class="transfer-pdf-preview-dialog"
        :style="{ width: 'min(960px, 94vw)' }"
        @hide="handleHide"
    >
        <template #header>
            <div class="document-preview__header">
                <div class="document-preview__header-text">
                    <h2>Vista previa del documento</h2>
                    <p>{{ title }}</p>
                </div>
                <Button
                    label="Descargar"
                    icon="pi pi-download"
                    iconPos="right"
                    class="document-preview__download"
                    :loading="downloading"
                    :disabled="downloading || !source"
                    @click="downloadPreview"
                />
            </div>
        </template>

        <div class="transfer-pdf-preview">
            <div v-if="loading" class="transfer-pdf-preview__state">
                Cargando PDF...
            </div>
            <div v-else-if="errorMessage" class="transfer-pdf-preview__state transfer-pdf-preview__state--error">
                {{ errorMessage }}
            </div>
            <div v-show="!loading && !errorMessage" class="transfer-pdf-preview__canvas-wrap">
                <canvas ref="pdfCanvas" class="transfer-pdf-preview__canvas"></canvas>
            </div>
        </div>

        <template #footer>
            <div class="transfer-pdf-preview__footer">
                <div class="transfer-pdf-preview__pagination">
                    <Button
                        icon="pi pi-chevron-left"
                        outlined
                        class="transfer-pdf-preview__page-button"
                        :disabled="loading || currentPage <= 1"
                        @click="goToPage(-1)"
                    />
                    <span>Página {{ currentPage }} de {{ totalPages || 1 }}</span>
                    <Button
                        icon="pi pi-chevron-right"
                        outlined
                        class="transfer-pdf-preview__page-button"
                        :disabled="loading || currentPage >= totalPages"
                        @click="goToPage(1)"
                    />
                </div>
                <Button label="Cerrar" class="document-preview__close" @click="closePreview" />
            </div>
        </template>
    </Dialog>
</template>

<style scoped>
:global(.transfer-pdf-preview-dialog.p-dialog) {
    border-radius: 8px;
    overflow: hidden;
}

:global(.document-preview-dialog-mask--transparent) {
    background: transparent !important;
}

:global(.transfer-pdf-preview-dialog .p-dialog-header) {
    align-items: center;
    background: #2d6f9f;
    border-bottom: 0;
    color: #ffffff;
    gap: 0.75rem;
    padding: 1.35rem 1.5rem;
}

:global(.transfer-pdf-preview-dialog .p-dialog-header-close) {
    color: #ffffff;
}

:global(.transfer-pdf-preview-dialog .p-dialog-content) {
    padding: 1.5rem;
}

:global(.transfer-pdf-preview-dialog .p-dialog-footer) {
    border-top: 1px solid #dbe3ed;
    padding: 1rem 1.5rem;
}

.document-preview__header {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    width: 100%;
}

.document-preview__header-text {
    min-width: 0;
}

.document-preview__header h2 {
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.2;
    margin: 0;
}

.document-preview__header p {
    color: rgba(255, 255, 255, 0.82);
    font-size: 0.92rem;
    margin: 0.35rem 0 0;
    word-break: break-word;
}

.transfer-pdf-preview {
    background: #f8fafc;
    border: 1px solid #dbe3ed;
    border-radius: 8px;
    max-height: min(68vh, 720px);
    overflow: auto;
    padding: 1rem;
}

.transfer-pdf-preview__canvas-wrap {
    display: flex;
    justify-content: center;
    min-height: 18rem;
}

.transfer-pdf-preview__canvas {
    background: #ffffff;
    box-shadow: 0 10px 26px rgba(15, 23, 42, 0.12);
    max-width: 100%;
}

.transfer-pdf-preview__state {
    align-items: center;
    color: #48627c;
    display: flex;
    font-weight: 700;
    justify-content: center;
    min-height: 18rem;
    text-align: center;
}

.transfer-pdf-preview__state--error {
    color: #b42318;
}

.transfer-pdf-preview__footer {
    align-items: center;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    width: 100%;
}

.transfer-pdf-preview__pagination {
    align-items: center;
    color: #48627c;
    display: flex;
    gap: 0.75rem;
    font-weight: 700;
}

.transfer-pdf-preview__page-button {
    min-width: 2.75rem;
}

.document-preview__download {
    background: #ffffff !important;
    border-color: #ffffff !important;
    border-radius: var(--ugda-radius-md) !important;
    color: #245d88 !important;
    flex-shrink: 0;
    font-weight: var(--ugda-weight-medium) !important;
    min-height: var(--ugda-control-height);
    padding: 0 var(--ugda-space-md) !important;
}

.document-preview__download:hover,
.document-preview__download:focus {
    background: var(--ugda-brand-soft) !important;
    border-color: var(--ugda-brand-soft) !important;
    color: #245d88 !important;
}

.document-preview__close {
    background: #2f6f9f;
    border-color: #2f6f9f;
    min-width: 7rem;
}

.document-preview__close:hover,
.document-preview__close:focus {
    background: #245d88;
    border-color: #245d88;
}

@media (max-width: 640px) {
    .document-preview__header {
        align-items: flex-start;
        flex-direction: column;
    }

    .document-preview__download {
        width: 100%;
    }

    .transfer-pdf-preview__footer {
        align-items: stretch;
        flex-direction: column;
    }

    .transfer-pdf-preview__pagination {
        justify-content: space-between;
    }

    .document-preview__close {
        width: 100%;
    }
}
</style>
