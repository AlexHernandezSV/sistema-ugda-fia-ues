<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";

const router = useRouter();
const loading = ref(true);
const errorMessage = ref("");
const reportSummary = ref({
    categories: {
        entry_and_registry: 0,
        access_and_consultation: 0,
        classification_and_organization: 0,
    },
    summary: {
        total_documents: 0,
        total_transfers: 0,
        total_boxes: 0,
        reserved_documents: 0,
    },
});

const reportCategories = computed(() => [
    {
        title: "Reportes de Ingreso y Registro",
        description:
            "Dashboards para consultar ingresos, recepcion y trazabilidad documental.",
        availableReports: reportSummary.value.categories.entry_and_registry,
        icon: "pi pi-file-edit",
        accentClass: "reports-card--blue",
        iconShellClass: "reports-icon-shell--blue",
    },
    {
        title: "Reportes de Consulta y Acceso",
        description:
            "Indicadores sobre prestamos, consultas y atencion de usuarios.",
        availableReports:
            reportSummary.value.categories.access_and_consultation,
        icon: "pi pi-eye",
        accentClass: "reports-card--green",
        iconShellClass: "reports-icon-shell--green",
    },
    {
        title: "Reportes de Clasificacion y Organizacion",
        description:
            "Resumenes para series, cajas y organizacion del archivo general.",
        availableReports:
            reportSummary.value.categories.classification_and_organization,
        icon: "pi pi-folder-open",
        accentClass: "reports-card--violet",
        iconShellClass: "reports-icon-shell--violet",
    },
]);

const summaryCards = computed(() => [
    {
        label: "Total Documentos",
        value: reportSummary.value.summary.total_documents,
        icon: "pi pi-file",
        accentClass: "reports-mini-card--blue",
    },
    {
        label: "Total Transferencias",
        value: reportSummary.value.summary.total_transfers,
        icon: "pi pi-arrow-up-right",
        accentClass: "reports-mini-card--green",
    },
    {
        label: "Total Cajas",
        value: reportSummary.value.summary.total_boxes,
        icon: "pi pi-folder-open",
        accentClass: "reports-mini-card--violet",
    },
    {
        label: "Docs. Reservados",
        value: reportSummary.value.summary.reserved_documents,
        icon: "pi pi-chart-bar",
        accentClass: "reports-mini-card--amber",
    },
]);

const goBack = () => {
    router.push("/dashboard");
};

const fetchReportSummary = async () => {
    loading.value = true;
    errorMessage.value = "";

    try {
        const { data } = await axios.get("/api/reports/summary");
        reportSummary.value = {
            categories: {
                entry_and_registry: data.categories?.entry_and_registry ?? 0,
                access_and_consultation:
                    data.categories?.access_and_consultation ?? 0,
                classification_and_organization:
                    data.categories?.classification_and_organization ?? 0,
            },
            summary: {
                total_documents: data.summary?.total_documents ?? 0,
                total_transfers: data.summary?.total_transfers ?? 0,
                total_boxes: data.summary?.total_boxes ?? 0,
                reserved_documents: data.summary?.reserved_documents ?? 0,
            },
        };
    } catch (error) {
        console.error("Error al cargar resumen de reportes:", error);
        errorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el resumen de reportes.";
    } finally {
        loading.value = false;
    }
};

onMounted(fetchReportSummary);
</script>

<template>
    <div class="reports-page fadein animation-duration-500">
        <section class="reports-hero shadow-1">
            <div class="reports-hero__nav">
                <button
                    type="button"
                    class="reports-back-button"
                    @click="goBack"
                >
                    <i class="pi pi-arrow-left"></i>
                    <span>Volver</span>
                </button>
            </div>

            <div class="reports-hero__copy">
                <h1 class="reports-hero__title m-0">Reportes Dinamicos</h1>
                <p class="reports-hero__subtitle m-0">
                    Dashboards interactivos con filtros en tiempo real
                </p>
            </div>
        </section>

        <section class="reports-section">
            <div class="reports-section__header">
                <h2 class="reports-section__title m-0">
                    Categorias de Reportes
                </h2>
                <p class="reports-section__subtitle m-0">
                    Selecciona una categoria para acceder a dashboards
                    interactivos
                </p>
            </div>

            <div
                v-if="errorMessage"
                class="reports-message reports-message--error"
            >
                <i class="pi pi-exclamation-triangle"></i>
                <span>{{ errorMessage }}</span>
            </div>

            <div v-else-if="loading" class="reports-message">
                <i class="pi pi-spin pi-spinner"></i>
                <span>Cargando valores desde la base de datos...</span>
            </div>

            <div class="grid">
                <div
                    v-for="category in reportCategories"
                    :key="category.title"
                    class="col-12 lg:col-4"
                >
                    <article
                        class="reports-card shadow-1"
                        :class="category.accentClass"
                    >
                        <div
                            class="reports-card__icon"
                            :class="category.iconShellClass"
                        >
                            <i :class="category.icon"></i>
                        </div>

                        <div class="reports-card__content">
                            <h3 class="reports-card__title m-0">
                                {{ category.title }}
                            </h3>
                            <p class="reports-card__description m-0">
                                {{ category.description }}
                            </p>
                        </div>

                        <div class="reports-card__footer">
                            <span class="reports-card__count"
                                >{{ category.availableReports }} reportes
                                disponibles</span
                            >
                            <i
                                class="pi pi-arrow-up-right reports-card__arrow"
                            ></i>
                        </div>
                    </article>
                </div>
            </div>

            <div class="grid mt-2">
                <div
                    v-for="card in summaryCards"
                    :key="card.label"
                    class="col-12 md:col-6 xl:col-3"
                >
                    <article
                        class="reports-mini-card shadow-1"
                        :class="card.accentClass"
                    >
                        <div class="reports-mini-card__copy">
                            <span class="reports-mini-card__label">{{
                                card.label
                            }}</span>
                            <strong class="reports-mini-card__value">{{
                                card.value
                            }}</strong>
                        </div>

                        <div class="reports-mini-card__icon">
                            <i :class="card.icon"></i>
                        </div>
                    </article>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.reports-page {
    display: flex;
    flex-direction: column;
    gap: 1.75rem;
}

.reports-hero,
.reports-section {
    background: #ffffff;
    border-radius: 1.6rem;
    border: 1px solid #e5edf6;
}

.reports-hero {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 2rem 2.25rem;
}

.reports-hero__nav {
    padding-right: 1.5rem;
    border-right: 1px solid #e5edf6;
}

.reports-back-button {
    border: 0;
    background: transparent;
    color: #1e5fbf;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.05rem;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
}

.reports-back-button .pi {
    font-size: 1.05rem;
}

.reports-hero__copy {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

.reports-hero__title {
    color: #10213d;
    font-size: 2.15rem;
    font-weight: 700;
    letter-spacing: -0.03em;
}

.reports-hero__subtitle {
    color: #6b7a90;
    font-size: 1rem;
}

.reports-section {
    padding: 2rem;
}

.reports-section__header {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-bottom: 1.6rem;
}

.reports-section__title {
    color: #10213d;
    font-size: 1.85rem;
    font-weight: 700;
}

.reports-section__subtitle {
    color: #6b7a90;
    font-size: 1rem;
}

.reports-message {
    align-items: center;
    background: #edf3fa;
    border-radius: 0.9rem;
    color: #2f6f9f;
    display: flex;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    padding: 0.95rem 1rem;
}

.reports-message--error {
    background: #fef2f2;
    color: #b91c1c;
}

.reports-card {
    border-radius: 1.4rem;
    border: 1px solid #dfe8f4;
    padding: 1.6rem;
    min-height: 18rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        border-color 0.2s ease;
}

.reports-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
}

.reports-card__icon {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 1.1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

.reports-card__content {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.reports-card__title {
    color: #10213d;
    font-size: 1.4rem;
    font-weight: 700;
    line-height: 1.25;
}

.reports-card__description {
    color: #6b7a90;
    font-size: 0.98rem;
    line-height: 1.55;
}

.reports-card__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.reports-card__count {
    color: #46556b;
    font-size: 0.95rem;
    font-weight: 600;
}

.reports-card__arrow {
    color: #9ca9bb;
    font-size: 1rem;
}

.reports-mini-card {
    border-radius: 1.25rem;
    border: 1px solid #dfe8f4;
    padding: 1.35rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    min-height: 8rem;
}

.reports-mini-card__copy {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.reports-mini-card__label {
    color: #6b7a90;
    font-size: 0.92rem;
    font-weight: 500;
}

.reports-mini-card__value {
    color: #10213d;
    font-size: 2rem;
    line-height: 1;
}

.reports-mini-card__icon {
    font-size: 2rem;
}

.reports-card--blue:hover,
.reports-mini-card--blue {
    border-color: rgba(29, 78, 216, 0.22);
}

.reports-card--green:hover,
.reports-mini-card--green {
    border-color: rgba(5, 150, 105, 0.22);
}

.reports-card--violet:hover,
.reports-mini-card--violet {
    border-color: rgba(147, 51, 234, 0.22);
}

.reports-mini-card--amber {
    border-color: rgba(245, 158, 11, 0.22);
}

.reports-icon-shell--blue {
    background: #ecf3ff;
    color: #2563eb;
}

.reports-icon-shell--green {
    background: #ebfbf4;
    color: #059669;
}

.reports-icon-shell--violet {
    background: #f5ecff;
    color: #9333ea;
}

.reports-mini-card--blue .reports-mini-card__icon {
    color: #bfdbfe;
}

.reports-mini-card--green .reports-mini-card__icon {
    color: #bbf7d0;
}

.reports-mini-card--violet .reports-mini-card__icon {
    color: #e9d5ff;
}

.reports-mini-card--amber .reports-mini-card__icon {
    color: #fcd34d;
}

@media (max-width: 991px) {
    .reports-hero {
        flex-direction: column;
        align-items: flex-start;
        padding: 1.4rem;
    }

    .reports-hero__nav {
        padding-right: 0;
        padding-bottom: 1rem;
        border-right: 0;
        border-bottom: 1px solid #e5edf6;
        width: 100%;
    }

    .reports-hero__title {
        font-size: 1.75rem;
    }

    .reports-section {
        padding: 1.35rem;
    }

    .reports-section__title {
        font-size: 1.5rem;
    }

    .reports-card {
        min-height: auto;
    }

    .reports-mini-card__value {
        font-size: 1.75rem;
    }
}
</style>
