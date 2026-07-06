<script setup>
import { computed, onMounted, ref } from 'vue';
import axios from 'axios';
import DocumentPreviewDialog from '../shared/DocumentPreviewDialog.vue';

const query = ref('');
const searchedQuery = ref('');
const loading = ref(false);
const initialLoading = ref(true);
const errorMessage = ref('');
const totals = ref({
    documents: 0,
    boxes: 0,
    completed_transfers: 0,
});
const results = ref([]);
const resultsCount = ref(0);
const resultsLimit = ref(100);
const hasSearched = ref(false);
const selectedDocument = ref(null);
const documentDialogVisible = ref(false);
const documentPreviewVisible = ref(false);
const documentPreviewTitle = ref('Documento PDF');
const documentPreviewSource = ref(null);

const trimmedQuery = computed(() => query.value.trim());
const hasResults = computed(() => results.value.length > 0);
const resultCountLabel = computed(() => {
    const count = resultsCount.value;

    if (count === 1) {
        return '1 coincidencia';
    }

    return `${count} coincidencias`;
});
const limitNotice = computed(() => (
    resultsCount.value > results.value.length
        ? `Mostrando ${results.value.length} de ${resultsCount.value} coincidencias. Refine la busqueda para ver resultados mas precisos.`
        : ''
));

const summaryCards = computed(() => ([
    {
        label: 'Total de Documentos',
        value: totals.value.documents,
        icon: 'pi pi-file',
        accent: 'archive-stat--blue',
    },
    {
        label: 'Cajas en Resguardo',
        value: totals.value.boxes,
        icon: 'pi pi-box',
        accent: 'archive-stat--green',
    },
    {
        label: 'Transferencias Completadas',
        value: totals.value.completed_transfers,
        icon: 'pi pi-inbox',
        accent: 'archive-stat--amber',
    },
]));

const selectedLocationParts = computed(() => {
    const location = selectedDocument.value?.box?.location ?? '';
    const parts = location
        .split(/\s*[•|,]\s*|\s+-\s+|-/)
        .map((part) => part.trim())
        .filter(Boolean);

    return {
        office: parts[0] || location || 'Pendiente de registrar',
        hallway: parts[1] || 'N/D',
        shelves: parts.length > 2 ? parts.slice(2).join('-') : 'N/D',
        full: location || 'Pendiente de registrar',
    };
});

const formatDocumentSupport = (support) => support || 'Sin soporte';

const formatPeriodStart = (document) => document?.box?.start_year || document?.year || 'N/D';
const formatPeriodEnd = (document) => document?.box?.end_year || document?.year || 'N/D';

const openDocumentDialog = (document) => {
    selectedDocument.value = document;
    documentDialogVisible.value = true;
};

const closeDocumentDialog = () => {
    documentDialogVisible.value = false;
};

const openDocumentPreview = (document = selectedDocument.value) => {
    if (!document?.digital_url) {
        return;
    }

    documentPreviewTitle.value = document.digital_file || document.title || 'Documento PDF';
    documentPreviewSource.value = { url: document.digital_url };
    documentPreviewVisible.value = true;
};

const clearDocumentPreview = () => {
    documentPreviewSource.value = null;
};

const fetchArchive = async (searchTerm = '') => {
    loading.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.get('/api/archive/documents', {
            params: searchTerm ? { q: searchTerm } : {},
        });

        totals.value = data.totals ?? totals.value;
        results.value = data.results ?? [];
        resultsCount.value = data.results_count ?? 0;
        resultsLimit.value = data.results_limit ?? 100;
        searchedQuery.value = data.query ?? searchTerm;
    } catch (error) {
        console.error('Error al consultar archivo general:', error);
        errorMessage.value = error.response?.data?.message ?? 'No se pudo consultar el archivo general.';
        results.value = [];
        resultsCount.value = 0;
    } finally {
        loading.value = false;
        initialLoading.value = false;
    }
};

const searchDocuments = async () => {
    hasSearched.value = trimmedQuery.value.length > 0;

    if (!hasSearched.value) {
        results.value = [];
        resultsCount.value = 0;
        searchedQuery.value = '';
        return;
    }

    await fetchArchive(trimmedQuery.value);
};

const clearSearch = () => {
    query.value = '';
    hasSearched.value = false;
    searchedQuery.value = '';
    results.value = [];
    resultsCount.value = 0;
    errorMessage.value = '';
    selectedDocument.value = null;
    documentDialogVisible.value = false;
};

onMounted(() => fetchArchive());
</script>

<template>
    <div class="archive-page fadein animation-duration-500">
        <header class="archive-header">
            <h1 class="archive-header__title mobile-page-title">Archivo General</h1>
            <p class="archive-header__subtitle">Localice documentos en resguardo de la UGDA</p>
        </header>

        <section class="archive-stats">
            <article v-for="card in summaryCards" :key="card.label" class="archive-stat shadow-1" :class="card.accent">
                <div>
                    <span>{{ card.label }}</span>
                    <strong>{{ card.value }}</strong>
                </div>
                <i :class="card.icon"></i>
            </article>
        </section>

        <section class="archive-search shadow-1">
            <div class="archive-search__head">
                <div class="archive-search__icon">
                    <i class="pi pi-search"></i>
                </div>
                <div>
                    <h2>Buscar Documentos</h2>
                    <p>Busque por nombre, código, serie documental, caja, unidad productora, ubicación, transferencia o fecha</p>
                </div>
            </div>

            <form class="archive-search__form" @submit.prevent="searchDocuments">
                <span class="archive-search__input">
                    <i class="pi pi-search"></i>
                    <InputText
                        v-model="query"
                        type="text"
                        placeholder="Ej: Expediente, Acta, OFC-001, Caja 003, 19/03/2025..."
                        :disabled="loading"
                    />
                </span>

                <button
                    type="button"
                    class="archive-search__clear"
                    :disabled="loading || (!query && !hasSearched)"
                    @click="clearSearch"
                >
                    Limpiar
                </button>

                <button type="submit" class="archive-search__button" :disabled="loading || trimmedQuery.length === 0">
                    <i class="pi pi-search"></i>
                    <span>{{ loading ? 'Buscando...' : 'Buscar' }}</span>
                </button>
            </form>
        </section>

        <section class="archive-results shadow-1">
            <div v-if="errorMessage" class="archive-state archive-state--error">
                <i class="pi pi-exclamation-triangle"></i>
                <h2>No se pudo completar la busqueda</h2>
                <p>{{ errorMessage }}</p>
            </div>

            <div v-else-if="initialLoading" class="archive-state">
                <i class="pi pi-spin pi-spinner"></i>
                <h2>Cargando archivo general</h2>
                <p>Espere mientras se preparan los totales del archivo.</p>
            </div>

            <template v-else-if="hasSearched">
                <div class="archive-results__header">
                    <h2 data-v-b54a6ae6="" style="color: #ffffff;">Resultados de Busqueda</h2>
                    <span>{{"( " + resultCountLabel + " )" }}</span>
                </div>

                <div v-if="loading" class="archive-state archive-state--compact">
                    <i class="pi pi-spin pi-spinner"></i>
                    <h2>Buscando documentos</h2>
                    <p>Revisando coincidencias en el archivo general.</p>
                </div>

                <div v-else-if="hasResults" class="archive-results__list">
                    <p v-if="limitNotice" class="archive-results__notice">{{ limitNotice }}</p>

                    <button
                        v-for="document in results"
                        :key="document.id"
                        type="button"
                        class="archive-document"
                        @click="openDocumentDialog(document)"
                    >
                        <div class="archive-document__icon">
                            <i class="pi pi-file"></i>
                        </div>

                        <div class="archive-document__content">
                            <div class="archive-document__main">
                                <div>
                                    <h3>{{ document.title }}</h3>
                                    <p>Código: {{ document.code || 'N/D' }}</p>
                                </div>
                                <span class="archive-document__support">{{ document.support || 'Sin soporte' }}</span>
                            </div>

                            <div class="archive-document__meta">
                                <div>
                                    <i class="pi pi-box"></i>
                                    <span>Caja</span>
                                    <strong>#{{ document.box?.number || 'N/D' }}</strong>
                                </div>
                                <div>
                                    <i class="pi pi-clone"></i>
                                    <span>Serie</span>
                                    <strong>{{ document.series || document.box?.series || 'Sin serie' }}</strong>
                                </div>
                                <div>
                                    <i class="pi pi-building"></i>
                                    <span>Unidad</span>
                                    <strong>{{ document.unit?.name || 'Sin unidad' }}</strong>
                                </div>
                                <div>
                                    <i class="pi pi-calendar"></i>
                                    <span>Transferencia</span>
                                    <strong>{{ document.transfer?.completed_at_label || 'N/D' }}</strong>
                                </div>
                            </div>

                            <div class="archive-document__details">
                                <span v-if="document.transfer?.code">
                                    <i class="pi pi-hashtag"></i>
                                    Transferencia #{{ document.transfer.code }}
                                </span>
                                <span v-if="document.year">
                                    <i class="pi pi-clock"></i>
                                    Año {{ document.year }}
                                </span>
                                <span v-if="document.pages">
                                    <i class="pi pi-file-edit"></i>
                                    {{ document.pages }}
                                </span>
                                <span v-if="document.digital_available">
                                    <i class="pi pi-paperclip"></i>
                                    Digital disponible
                                </span>
                            </div>

                            <div class="archive-document__location">
                                <i class="pi pi-map-marker"></i>
                                <span>Ubicación fisica</span>
                                <strong>{{ document.box?.location || 'Pendiente de registrar' }}</strong>
                            </div>
                        </div>
                    </button>
                </div>

                <div v-else class="archive-state">
                    <i class="pi pi-search"></i>
                    <h2>Sin coincidencias</h2>
                    <p>No se encontraron documentos que coincidan con "{{ searchedQuery }}".</p>
                </div>
            </template>

            <div v-else class="archive-state">
                <i class="pi pi-search"></i>
                <h2>Comience su busqueda</h2>
                <p>Utilice el buscador para localizar documentos en el archivo general. Puede buscar por:</p>

                <div class="archive-state__hints">
                    <div>
                        <i class="pi pi-folder-open"></i>
                        <strong>Nombre del documento</strong>
                        <span>Busque por titulo o descripcion</span>
                    </div>
                    <div>
                        <i class="pi pi-hashtag"></i>
                        <strong>Código o numero de caja</strong>
                        <span>Identifique por código unico</span>
                    </div>
                    <div>
                        <i class="pi pi-clone"></i>
                        <strong>Serie documental</strong>
                        <span>Encuentre por tipo de documento</span>
                    </div>
                    <div>
                        <i class="pi pi-calendar"></i>
                        <strong>Fecha</strong>
                        <span>Use formato dia/mes/anio</span>
                    </div>
                </div>
            </div>
        </section>

        <Dialog
            v-model:visible="documentDialogVisible"
            modal
            :closable="false"
            :draggable="false"
            :showHeader="false"
            :style="{ width: 'min(900px, calc(100vw - 2rem))' }"
            class="archive-document-dialog"
            contentClass="p-0"
            @hide="selectedDocument = null"
        >
            <div v-if="selectedDocument" class="archive-document-modal">
                <header class="archive-document-modal__header">
                    <div class="archive-document-modal__heading">
                        <span class="archive-document-modal__icon">
                            <i class="pi pi-file"></i>
                        </span>
                        <div>
                            <h2>Información del Documento</h2>
                            <p>Detalles completos y ubicación</p>
                        </div>
                    </div>

                    <button type="button" class="archive-document-modal__close" @click="closeDocumentDialog">
                        <i class="pi pi-times"></i>
                    </button>
                </header>

                <div class="archive-document-modal__body">
                    <section class="archive-document-modal__section archive-document-modal__section--muted">
                        <h3>
                            <i class="pi pi-file"></i>
                            Información del Documento
                        </h3>

                        <div class="archive-document-modal__divider"></div>

                        <div class="archive-document-modal__grid archive-document-modal__grid--two">
                            <div class="archive-document-modal__field">
                                <span>Nombre del documento</span>
                                <strong>{{ selectedDocument.title || 'Sin nombre' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Código</span>
                                <strong>{{ selectedDocument.code || 'N/D' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Tipo de soporte</span>
                                <strong class="archive-document-modal__badge">{{ formatDocumentSupport(selectedDocument.support) }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Archivo digital</span>
                                <button
                                    v-if="selectedDocument.digital_available"
                                    type="button"
                                    class="archive-document-modal__link archive-document-modal__link-button"
                                    @click="openDocumentPreview(selectedDocument)"
                                >
                                    <i class="pi pi-eye"></i>
                                    <span>{{ selectedDocument.digital_file }}</span>
                                </button>
                                <strong v-else class="archive-document-modal__link">No disponible</strong>
                            </div>
                        </div>
                    </section>

                    <section class="archive-document-modal__section">
                        <h3>
                            <i class="pi pi-box"></i>
                            Información de la Caja
                        </h3>

                        <div class="archive-document-modal__divider"></div>

                        <div class="archive-document-modal__grid archive-document-modal__grid--two">
                            <div class="archive-document-modal__field">
                                <span>Número de caja</span>
                                <strong>#{{ selectedDocument.box?.number || 'N/D' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Serie documental</span>
                                <strong>{{ selectedDocument.series || selectedDocument.box?.series || 'Sin serie' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Fecha inicial</span>
                                <strong>{{ formatPeriodStart(selectedDocument) }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Fecha final</span>
                                <strong>{{ formatPeriodEnd(selectedDocument) }}</strong>
                            </div>
                        </div>
                    </section>

                    <section class="archive-document-modal__section archive-document-modal__section--location">
                        <h3>
                            <i class="pi pi-map-marker"></i>
                            Ubicación Física en UGDA
                        </h3>

                        <div class="archive-document-modal__divider"></div>

                        <div class="archive-document-modal__location-grid">
                            <div class="archive-document-modal__location-card">
                                <span>Edificio / Oficina</span>
                                <strong>{{ selectedLocationParts.office }}</strong>
                            </div>
                            <div class="archive-document-modal__location-card">
                                <span>Pasillo</span>
                                <strong>{{ selectedLocationParts.hallway }}</strong>
                            </div>
                            <div class="archive-document-modal__location-card">
                                <span>Estantes</span>
                                <strong>{{ selectedLocationParts.shelves }}</strong>
                            </div>
                            <div class="archive-document-modal__location-card archive-document-modal__location-card--wide">
                                <span>Ubicación completa</span>
                                <strong>{{ selectedLocationParts.full }}</strong>
                            </div>
                        </div>
                    </section>

                    <section class="archive-document-modal__section archive-document-modal__section--muted">
                        <h3>
                            <i class="pi pi-inbox"></i>
                            Información de la Transferencia
                        </h3>

                        <div class="archive-document-modal__divider"></div>

                        <div class="archive-document-modal__grid archive-document-modal__grid--two">
                            <div class="archive-document-modal__field">
                                <span>Número de transferencia</span>
                                <strong>#{{ selectedDocument.transfer?.code || 'N/D' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Fecha de transferencia</span>
                                <strong>{{ selectedDocument.transfer?.completed_at_label || selectedDocument.transfer?.request_date_label || 'N/D' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Unidad productora</span>
                                <strong>{{ selectedDocument.unit?.name || 'Sin unidad' }}</strong>
                            </div>
                            <div class="archive-document-modal__field">
                                <span>Responsable</span>
                                <strong>{{ selectedDocument.transfer?.responsible || 'N/D' }}</strong>
                            </div>
                        </div>
                    </section>
                </div>

                <footer class="archive-document-modal__footer">
                    <button type="button" class="archive-document-modal__button" @click="closeDocumentDialog">
                        Cerrar
                    </button>
                </footer>
            </div>
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
.archive-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.archive-header__title {
    color: #2f6f9f;
    font-size: 2.05rem;
    font-weight: 800;
    line-height: 1.1;
    margin: 0;
}

.archive-header__subtitle {
    color: #526477;
    font-size: 0.95rem;
    margin: 0.45rem 0 0;
}

.archive-stats {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.archive-stat {
    align-items: center;
    background: #ffffff;
    border: 1px solid #e5edf6;
    border-left: 4px solid #2f6f9f;
    border-radius: 0.7rem;
    display: flex;
    justify-content: space-between;
    min-height: 7.2rem;
    padding: 1.5rem;
}

.archive-stat span {
    color: #526477;
    display: block;
    font-size: 0.9rem;
    margin-bottom: 0.8rem;
}

.archive-stat strong {
    color: #2f6f9f;
    display: block;
    font-size: 2rem;
    line-height: 1;
}

.archive-stat > i {
    align-items: center;
    background: #edf3fa;
    border-radius: 0.7rem;
    color: #2f6f9f;
    display: inline-flex;
    font-size: 1.55rem;
    height: 3.25rem;
    justify-content: center;
    width: 3.25rem;
}

.archive-stat--green {
    border-left-color: #4caf50;
}

.archive-stat--green strong,
.archive-stat--green > i {
    color: #4caf50;
}

.archive-stat--green > i {
    background: #edf8ef;
}

.archive-stat--amber {
    border-left-color: #f59e0b;
}

.archive-stat--amber strong,
.archive-stat--amber > i {
    color: #f59e0b;
}

.archive-stat--amber > i {
    background: #fff7ed;
}

.archive-search,
.archive-results {
    background: #ffffff;
    border: 1px solid #e5edf6;
    border-radius: 0.7rem;
}

.archive-search {
    padding: 2rem;
}

.archive-search__head {
    align-items: center;
    display: flex;
    gap: 0.9rem;
    margin-bottom: 1.35rem;
}

.archive-search__icon {
    align-items: center;
    background: #2f6f9f;
    border-radius: 0.55rem;
    color: #ffffff;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.35rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.archive-search__head h2 {
    color: #0f172a;
    font-size: 1.35rem;
    margin: 0 0 0.25rem;
}

.archive-search__head p {
    color: #526477;
    margin: 0;
}

.archive-search__form {
    display: grid;
    gap: 0.8rem;
    grid-template-columns: minmax(0, 1fr) auto auto;
}

.archive-search__input {
    align-items: center;
    border: 1px solid #d7e2ef;
    border-radius: 0.55rem;
    display: flex;
    gap: 0.75rem;
    min-width: 0;
    padding: 0 0.9rem;
}

.archive-search__input > i {
    color: #64748b;
    flex-shrink: 0;
}

.archive-search__input :deep(.p-inputtext) {
    border: 0;
    box-shadow: none;
    flex: 1;
    min-width: 0;
    padding-left: 0;
    padding-right: 0;
}

.archive-search__button,
.archive-search__clear {
    align-items: center;
    border-radius: 0.55rem;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    font-weight: 800;
    gap: 0.65rem;
    justify-content: center;
    min-height: 3.5rem;
    padding: 0 1.7rem;
}

.archive-search__button {
    background: #2f6f9f;
    border: 1px solid #2f6f9f;
    color: #ffffff;
}

.archive-search__clear {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
}

.archive-search__button:disabled,
.archive-search__clear:disabled {
    cursor: not-allowed;
}

.archive-results {
    overflow: hidden;
}

.archive-results__header {
    align-items: center;
    background: #2f6f9f;
    color: #ffffff;
    display: flex;
    gap: 0.6rem;
    padding: 1.1rem 1.5rem;
}

.archive-results__header h2 {
    color: #ffffff;
    font-size: 1.15rem;
    margin: 0;
}

.archive-results__header span {
    font-size: 0.85rem;
    font-weight: 700;
    opacity: 0.92;
}

.archive-results__list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1.5rem;
}

.archive-results__notice {
    background: #edf3fa;
    border-radius: 0.55rem;
    color: #2f6f9f;
    font-size: 0.9rem;
    margin: 0;
    padding: 0.85rem 1rem;
}

.archive-document {
    align-items: flex-start;
    background: #ffffff;
    border: 1px solid #dbe3ed;
    border-radius: 0.7rem;
    color: inherit;
    cursor: pointer;
    display: flex;
    font: inherit;
    gap: 1rem;
    padding: 1.25rem;
    text-align: left;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    width: 100%;
}

.archive-document:hover,
.archive-document:focus-visible {
    border-color: #2f6f9f;
    box-shadow: 0 14px 28px rgba(47, 111, 159, 0.12);
    outline: none;
    transform: translateY(-1px);
}

.archive-document__icon {
    align-items: center;
    background: #edf3fa;
    border-radius: 0.55rem;
    color: #2f6f9f;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.2rem;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.archive-document__content {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 1rem;
    min-width: 0;
}

.archive-document__main {
    align-items: flex-start;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
}

.archive-document__main h3 {
    color: #0f172a;
    font-size: 1rem;
    margin: 0 0 0.35rem;
}

.archive-document__main p {
    color: #475569;
    margin: 0;
}

.archive-document__support {
    background: #f1f5f9;
    border-radius: 999px;
    color: #334155;
    flex-shrink: 0;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 0.35rem 0.75rem;
}

.archive-document__meta {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.archive-document__meta div {
    min-width: 0;
}

.archive-document__meta i {
    color: #64748b;
    margin-right: 0.4rem;
}

.archive-document__meta span,
.archive-document__details span,
.archive-document__location span {
    color: #64748b;
    font-size: 0.82rem;
}

.archive-document__meta strong {
    color: #0f172a;
    display: block;
    font-size: 0.85rem;
    margin-top: 0.2rem;
    overflow-wrap: anywhere;
}

.archive-document__details {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}

.archive-document__details span {
    align-items: center;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    display: inline-flex;
    gap: 0.4rem;
    padding: 0.35rem 0.7rem;
}

.archive-document__location {
    align-items: center;
    background: #edf6fd;
    border-radius: 0.55rem;
    color: #2f6f9f;
    display: flex;
    gap: 0.6rem;
    padding: 0.8rem 0.9rem;
}

.archive-document__location span {
    color: #526477;
}

.archive-document__location strong {
    color: #2f6f9f;
}

.archive-state {
    align-items: center;
    color: #526477;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 24rem;
    padding: 2rem;
    text-align: center;
}

.archive-state--compact {
    min-height: 14rem;
}

.archive-state--error i,
.archive-state--error h2 {
    color: #b91c1c;
}

.archive-state > i {
    align-items: center;
    background: #edf3fa;
    border-radius: 999px;
    color: #2f6f9f;
    display: inline-flex;
    font-size: 2.2rem;
    height: 5rem;
    justify-content: center;
    margin-bottom: 1.25rem;
    width: 5rem;
}

.archive-state h2 {
    color: #0f172a;
    font-size: 1.25rem;
    margin: 0 0 0.8rem;
}

.archive-state p {
    margin: 0;
}

.archive-state__hints {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-top: 1.8rem;
    max-width: 62rem;
    width: 100%;
}

.archive-state__hints div {
    background: #f8fafc;
    border-radius: 0.55rem;
    padding: 1.15rem;
}

.archive-state__hints i {
    color: #2f6f9f;
    display: block;
    font-size: 1.4rem;
    margin-bottom: 0.7rem;
}

.archive-state__hints strong {
    color: #0f172a;
    display: block;
    font-size: 0.9rem;
    margin-bottom: 0.45rem;
}

.archive-state__hints span {
    color: #64748b;
    font-size: 0.78rem;
}

:global(.archive-document-dialog .p-dialog-header) {
    display: none;
}

:global(.archive-document-dialog .p-dialog-content) {
    border-radius: inherit;
}

.archive-document-modal {
    background: #ffffff;
    color: #0f172a;
    overflow: hidden;
}

.archive-document-modal__header {
    align-items: center;
    background: #2f6f9f;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    padding: 1rem 1.5rem;
}

.archive-document-modal__heading {
    align-items: center;
    display: flex;
    gap: 0.85rem;
}

.archive-document-modal__icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 0.65rem;
    display: inline-flex;
    flex-shrink: 0;
    font-size: 1.25rem;
    height: 2.75rem;
    justify-content: center;
    width: 2.75rem;
}

.archive-document-modal__header h2,
.archive-document-modal__header p {
    margin: 0;
}

.archive-document-modal__header h2 {
    color: #ffffff;
    font-size: 1.25rem;
    font-weight: 800;
}

.archive-document-modal__header p {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.82rem;
    margin-top: 0.2rem;
}

.archive-document-modal__close {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 999px;
    color: #ffffff;
    cursor: pointer;
    display: inline-flex;
    font-size: 1.25rem;
    height: 2.5rem;
    justify-content: center;
    width: 2.5rem;
}

.archive-document-modal__close:hover,
.archive-document-modal__close:focus-visible {
    background: rgba(255, 255, 255, 0.14);
    outline: none;
}

.archive-document-modal__body {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    max-height: min(68vh, 42rem);
    overflow-y: auto;
    padding: 1.5rem;
}

.archive-document-modal__section {
    background: #ffffff;
    border: 1px solid #cbddeb;
    border-radius: 0.65rem;
    padding: 1.5rem;
}

.archive-document-modal__section--muted {
    background: #f8fafc;
    border-color: #f1f5f9;
}

.archive-document-modal__section--location {
    background: linear-gradient(135deg, #f8fbff 0%, #eef6fd 100%);
    border: 2px solid #2f6f9f;
}

.archive-document-modal__section h3 {
    align-items: center;
    color: #0f172a;
    display: flex;
    font-size: 1.15rem;
    font-weight: 800;
    gap: 0.5rem;
    margin: 0;
}

.archive-document-modal__section h3 i {
    color: #2f6f9f;
}

.archive-document-modal__divider {
    border-top: 1px solid #dbe3ed;
    margin: 0.95rem 0 1.2rem;
}

.archive-document-modal__grid {
    display: grid;
    gap: 1.6rem 2rem;
}

.archive-document-modal__grid--two {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.archive-document-modal__field {
    min-width: 0;
}

.archive-document-modal__field span,
.archive-document-modal__location-card span {
    color: #64748b;
    display: block;
    font-size: 0.82rem;
    margin-bottom: 0.5rem;
}

.archive-document-modal__field strong,
.archive-document-modal__location-card strong {
    color: #111827;
    display: block;
    font-size: 1rem;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.archive-document-modal__badge {
    background: #eef2f7;
    border-radius: 999px;
    color: #334155 !important;
    display: inline-flex !important;
    font-size: 0.78rem !important;
    padding: 0.35rem 0.8rem;
    width: fit-content;
}

.archive-document-modal__link {
    color: #2f6f9f !important;
}

.archive-document-modal__link-button {
    align-items: center;
    background: transparent;
    border: 0;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    font-weight: 800;
    gap: 0.45rem;
    padding: 0;
    text-align: left;
}

.archive-document-modal__link-button:hover {
    text-decoration: underline;
}

.archive-document-modal__location-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.archive-document-modal__location-card {
    background: #ffffff;
    border: 1px solid #e5edf6;
    border-radius: 0.65rem;
    box-shadow: 0 8px 16px rgba(15, 23, 42, 0.08);
    min-width: 0;
    padding: 1.1rem 1rem;
}

.archive-document-modal__location-card strong {
    color: #2f6f9f;
    font-size: 1.08rem;
}

.archive-document-modal__location-card--wide {
    grid-column: 1 / -1;
}

.archive-document-modal__footer {
    align-items: center;
    background: #ffffff;
    border-top: 1px solid #dbe3ed;
    display: flex;
    justify-content: flex-end;
    padding: 1rem 1.5rem;
}

.archive-document-modal__button {
    background: #2f6f9f;
    border: 1px solid #2f6f9f;
    border-radius: 0.65rem;
    color: #ffffff;
    cursor: pointer;
    font: inherit;
    font-weight: 800;
    min-height: 3rem;
    padding: 0 1.5rem;
}

.archive-document-modal__button:hover,
.archive-document-modal__button:focus-visible {
    background: #285f88;
    border-color: #285f88;
    outline: none;
}

@media (max-width: 991px) {
    .archive-stats,
    .archive-document__meta,
    .archive-state__hints,
    .archive-document-modal__grid--two,
    .archive-document-modal__location-grid {
        grid-template-columns: 1fr;
    }

    .archive-search__form {
        grid-template-columns: 1fr;
    }

    .archive-document,
    .archive-search__head,
    .archive-document__main,
    .archive-document__location {
        align-items: flex-start;
        flex-direction: column;
    }

    .archive-document-modal__header,
    .archive-document-modal__footer {
        padding: 1rem;
    }

    .archive-document-modal__body {
        padding: 1rem;
    }

    .archive-search__button,
    .archive-search__clear {
        width: 100%;
    }
}
</style>
