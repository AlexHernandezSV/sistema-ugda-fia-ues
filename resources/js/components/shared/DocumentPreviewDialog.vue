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
});

const emit = defineEmits(['hide']);

const pdfCanvas = ref(null);
const loading = ref(false);
const errorMessage = ref('');
const currentPage = ref(1);
const totalPages = ref(0);

let pdfDocument = null;
let renderTask = null;

const isPdfFile = (file) => (
    file
    && (
        file.type === 'application/pdf'
        || String(file.name ?? '').toLowerCase().endsWith('.pdf')
    )
);

const destroyPdfDocument = async () => {
    if (renderTask) {
        try {
            renderTask.cancel();
        } catch (error) {
            // PDF.js may throw if the render task already finished.
        } finally {
            renderTask = null;
        }
    }

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

const renderPage = async () => {
    if (!pdfDocument || !pdfCanvas.value) {
        return;
    }

    const page = await pdfDocument.getPage(currentPage.value);
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

    renderTask = page.render({ canvasContext: context, viewport });

    try {
        await renderTask.promise;
    } finally {
        renderTask = null;
    }
};

const loadPdf = async () => {
    await destroyPdfDocument();

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

    loading.value = true;
    errorMessage.value = '';
    currentPage.value = 1;
    totalPages.value = 0;

    await nextTick();

    try {
        pdfDocument = await loadPdf();
        totalPages.value = pdfDocument.numPages;
        await renderPage();
    } catch (error) {
        errorMessage.value = error.message || 'No se pudo visualizar el PDF.';
    } finally {
        loading.value = false;
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

const closePreview = () => {
    visible.value = false;
};

const handleHide = async () => {
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
        class="transfer-pdf-preview-dialog"
        :style="{ width: 'min(960px, 94vw)' }"
        @hide="handleHide"
    >
        <template #header>
            <div class="document-preview__header">
                <h2>Vista previa del documento</h2>
                <p>{{ title }}</p>
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
                    <span>Pagina {{ currentPage }} de {{ totalPages || 1 }}</span>
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

:global(.transfer-pdf-preview-dialog .p-dialog-header) {
    background: #2d6f9f;
    border-bottom: 0;
    color: #ffffff;
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

.document-preview__header h2 {
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
