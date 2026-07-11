<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";

const router = useRouter();

const loadingSummary = ref(true);
const loadingRegistry = ref(false);
const errorMessage = ref("");
const registryErrorMessage = ref("");
const selectedCategory = ref(null);
const selectedReport = ref(null);
const hoveredPoint = ref(null);

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

const registryReport = ref({
    filters: {
        years: [],
        months: [],
    },
    summary: {
        filtered_documents: 0,
        total_documents: 0,
        units: 0,
        series: 0,
    },
    charts: {
        period: [],
        units: [],
        series: [],
        support: [],
    },
    documents: [],
});

const filters = reactive({
    unit: null,
    series: null,
    support: null,
    search: "",
});

const reportCategories = computed(() => [
    {
        key: "entry_and_registry",
        title: "Reportes de Ingreso y Registro",
        description: "Ingresos, registros, series, unidades y soportes documentales.",
        availableReports: entryReports.value.length,
        reports: entryReports.value,
    },
    {
        key: "access_and_consultation",
        title: "Reportes de Consulta y Acceso",
        description: "Indicadores sobre préstamos, consultas y atención de usuarios.",
        availableReports: reportSummary.value.categories.access_and_consultation,
        reports: [],
    },
    {
        key: "classification_and_organization",
        title: "Reportes de Clasificación y Organización",
        description: "Resúmenes para series, cajas y organización del archivo general.",
        availableReports: reportSummary.value.categories.classification_and_organization,
        reports: [],
    },
]);

const entryReports = computed(() => [
    {
        key: "period",
        title: "Documentos por Periodo",
        description: "Análisis temporal de ingresos",
        icon: "pi pi-chart-bar",
    },
    {
        key: "units",
        title: "Documentos por Unidad",
        description: "Distribución por departamento y usuario",
        icon: "pi pi-chart-bar",
    },
    {
        key: "series",
        title: "Documentos por Serie",
        description: "Clasificación documental",
        icon: "pi pi-chart-bar",
    },
    {
        key: "support",
        title: "Documentos por Soporte",
        description: "Físico, Digital, Mixto",
        icon: "pi pi-chart-bar",
    },
]);

const currentCategory = computed(() =>
    reportCategories.value.find((category) => category.key === selectedCategory.value)
);

const currentReport = computed(() =>
    entryReports.value.find((report) => report.key === selectedReport.value) ??
    entryReports.value[0]
);

const isUnitsReport = computed(() => currentReport.value.key === "units");
const hasActiveFilters = computed(() =>
    Boolean(filters.unit || filters.series || filters.support || filters.search.trim())
);

const documents = computed(() => registryReport.value.documents ?? []);

const unitOptions = computed(() => {
    const options = new Map();

    documents.value.forEach((document) => {
        if (document.unit_id && document.unit) {
            options.set(document.unit_id, {
                label: document.unit,
                value: document.unit_id,
            });
        }
    });

    return Array.from(options.values()).sort((a, b) => a.label.localeCompare(b.label));
});

const seriesOptions = computed(() => {
    const options = new Map();

    documents.value.forEach((document) => {
        if (document.series) {
            options.set(document.series, {
                label: document.series,
                value: document.series,
            });
        }
    });

    return Array.from(options.values()).sort((a, b) => a.label.localeCompare(b.label));
});

const supportOptions = computed(() => {
    const options = new Map();

    documents.value.forEach((document) => {
        if (document.support) {
            options.set(document.support, {
                label: document.support,
                value: document.support,
            });
        }
    });

    return Array.from(options.values()).sort((a, b) => a.label.localeCompare(b.label));
});

const visibleDocuments = computed(() => {
    const searchTerm = filters.search.trim().toLowerCase();

    return documents.value.filter((document) => {
        const matchesUnit = !filters.unit || document.unit_id === filters.unit;
        const matchesSeries = !filters.series || document.series === filters.series;
        const matchesSupport = !filters.support || document.support === filters.support;
        const matchesSearch = !searchTerm || [
            document.code,
            document.name,
            document.series,
            document.unit,
            document.support,
            document.year,
            document.pages,
            document.transfer_code,
            document.box_number,
            document.entered_by,
        ]
            .some((value) => String(value ?? "").toLowerCase().includes(searchTerm));

        return matchesUnit && matchesSeries && matchesSupport && matchesSearch;
    });
});

const filteredDocumentsCount = computed(() => visibleDocuments.value.length);
const reportTotalDocumentsCount = computed(() =>
    isUnitsReport.value ? documents.value.length : registryReport.value.summary.total_documents
);
const visibleUnitsCount = computed(() =>
    new Set(visibleDocuments.value.map((document) => document.unit).filter(Boolean)).size
);
const visibleSeriesCount = computed(() =>
    new Set(visibleDocuments.value.map((document) => document.series).filter(Boolean)).size
);

const chartTitle = computed(() => currentReport.value?.title ?? "Documentos por Periodo");

const periodSeries = computed(() => {
    const grouped = new Map();

    visibleDocuments.value.forEach((document) => {
        const date = document.date || "Sin fecha";
        const label = document.date_label?.slice(0, 10) ?? "Sin fecha";
        const current = grouped.get(date) ?? { date, label, total: 0 };
        current.total += 1;
        grouped.set(date, current);
    });

    return Array.from(grouped.values()).sort((a, b) => a.date.localeCompare(b.date));
});

const periodChartPoints = computed(() => {
    const series = periodSeries.value;
    const maxTotal = Math.max(...series.map((item) => item.total), 1);
    const width = 1000;
    const height = 300;
    const top = 18;
    const bottom = 38;
    const left = 44;
    const right = 18;
    const graphWidth = width - left - right;
    const graphHeight = height - top - bottom;
    const denominator = Math.max(series.length - 1, 1);

    return series.map((item, index) => {
        const x = left + (index / denominator) * graphWidth;
        const y = top + graphHeight - (item.total / maxTotal) * graphHeight;

        return { ...item, x, y };
    });
});

const periodLinePath = computed(() => {
    if (periodChartPoints.value.length === 0) {
        return "";
    }

    return periodChartPoints.value
        .map((point, index) => `${index === 0 ? "M" : "L"} ${point.x} ${point.y}`)
        .join(" ");
});

const periodAreaPath = computed(() => {
    const points = periodChartPoints.value;

    if (points.length === 0) {
        return "";
    }

    const baseline = 262;
    const first = points[0];
    const last = points[points.length - 1];

    return `${periodLinePath.value} L ${last.x} ${baseline} L ${first.x} ${baseline} Z`;
});

const yAxisTicks = computed(() => {
    const totals = periodSeries.value.map((item) => item.total);
    const maxTotal = Math.max(...totals, 1);
    const roundedMax = Math.max(5, Math.ceil(maxTotal / 5) * 5);

    return [0, 1, 2, 3, 4].map((index) => {
        const value = Math.round((roundedMax / 4) * index);
        const y = 262 - (value / roundedMax) * 244;

        return { value, y };
    });
});

const groupedChartRows = computed(() => {
    const key = currentReport.value.key === "support" ? "support" : "series";
    const grouped = new Map();

    visibleDocuments.value.forEach((document) => {
        const label = document[key] || (key === "support" ? "Sin soporte" : "Sin serie");
        grouped.set(label, (grouped.get(label) ?? 0) + 1);
    });

    const rows = Array.from(grouped.entries())
        .map(([label, total]) => ({ label, total }))
        .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label));
    const maxTotal = Math.max(...rows.map((row) => row.total), 1);

    return rows.map((row) => ({
        ...row,
        width: `${Math.max((row.total / maxTotal) * 100, 6)}%`,
    }));
});

const unitChartRows = computed(() => {
    const grouped = new Map();

    visibleDocuments.value.forEach((document) => {
        const unit = document.unit || "Sin unidad";
        grouped.set(unit, (grouped.get(unit) ?? 0) + 1);
    });

    return Array.from(grouped.entries())
        .map(([label, total]) => ({ label, total }))
        .sort((a, b) => b.total - a.total);
});

const unitChartMax = computed(() => Math.max(...unitChartRows.value.map((row) => row.total), 1));

const unitChartTicks = computed(() => {
    const roundedMax = Math.max(4, Math.ceil(unitChartMax.value / 4) * 4);

    return [0, 1, 2, 3, 4].map((index) => Math.round((roundedMax / 4) * index));
});

const unitDistributionRows = computed(() => {
    const units = new Map();

    visibleDocuments.value.forEach((document) => {
        const unit = document.unit || "Sin unidad";
        const series = document.series || "Sin serie";

        if (!units.has(unit)) {
            units.set(unit, {
                label: unit,
                total: 0,
                series: new Map(),
            });
        }

        const group = units.get(unit);
        group.total += 1;
        group.series.set(series, (group.series.get(series) ?? 0) + 1);
    });

    return Array.from(units.values())
        .map((unit) => ({
            label: unit.label,
            total: unit.total,
            series: Array.from(unit.series.entries())
                .map(([label, total]) => ({ label, total }))
                .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label)),
        }))
        .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label));
});

const supportSeverity = (support) => {
    const normalized = (support ?? "").toLowerCase();

    if (normalized.includes("digital")) {
        return "report-tag--digital";
    }

    if (normalized.includes("mixto")) {
        return "report-tag--mixed";
    }

    return "report-tag--physical";
};

const fetchReportSummary = async () => {
    loadingSummary.value = true;
    errorMessage.value = "";

    try {
        const { data } = await axios.get("/api/reports/summary");
        reportSummary.value = {
            categories: {
                entry_and_registry: data.categories?.entry_and_registry ?? 0,
                access_and_consultation: data.categories?.access_and_consultation ?? 0,
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
            error.response?.data?.message ?? "No se pudo cargar el resumen de reportes.";
    } finally {
        loadingSummary.value = false;
    }
};

const fetchEntryRegistryReport = async () => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";
    hoveredPoint.value = null;

    try {
        const { data } = await axios.get("/api/reports/entry-registry");

        registryReport.value = {
            filters: {
                years: data.filters?.years ?? [],
                months: data.filters?.months ?? [],
            },
            summary: {
                filtered_documents: data.summary?.filtered_documents ?? 0,
                total_documents: data.summary?.total_documents ?? 0,
                units: data.summary?.units ?? 0,
                series: data.summary?.series ?? 0,
            },
            charts: {
                period: data.charts?.period ?? [],
                units: data.charts?.units ?? [],
                series: data.charts?.series ?? [],
                support: data.charts?.support ?? [],
            },
            documents: data.documents ?? [],
        };
    } catch (error) {
        console.error("Error al cargar reporte de ingreso y registro:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el reporte de ingreso y registro.";
    } finally {
        loadingRegistry.value = false;
    }
};

const openCategory = async (categoryKey) => {
    selectedCategory.value = categoryKey;
    selectedReport.value = null;

    if (categoryKey === "entry_and_registry" && documents.value.length === 0) {
        await fetchEntryRegistryReport();
    }
};

const openReport = async (reportKey) => {
    selectedReport.value = reportKey;

    if (selectedCategory.value === "entry_and_registry") {
        await fetchEntryRegistryReport();
    }
};

const goBack = () => {
    if (selectedReport.value) {
        selectedReport.value = null;
        hoveredPoint.value = null;
        return;
    }

    if (selectedCategory.value) {
        selectedCategory.value = null;
        hoveredPoint.value = null;
        return;
    }

    router.push("/dashboard");
};

const goBackToCategories = () => {
    selectedCategory.value = null;
    selectedReport.value = null;
    hoveredPoint.value = null;
};

const goBackToReports = () => {
    selectedReport.value = null;
    hoveredPoint.value = null;
};

const clearFilters = () => {
    filters.unit = null;
    filters.series = null;
    filters.support = null;
    filters.search = "";
};

const escapeHtml = (value) =>
    String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

const exportCurrentTable = () => {
    const columns = [
        ["Codigo", "code"],
        ["Nombre", "name"],
        ["Serie", "series"],
        ["Unidad", "unit"],
        ["Usuario que ingreso", "entered_by"],
        ["Fecha", "date_label"],
        ["Soporte", "support"],
        ["Anio documental", "year"],
        ["Paginas", "pages"],
        ["Transferencia", "transfer_code"],
        ["Caja", "box_number"],
    ];

    const rows = visibleDocuments.value
        .map(
            (document) => `
                <tr>
                    ${columns
                        .map(([, key]) => `<td>${escapeHtml(document[key])}</td>`)
                        .join("")}
                </tr>`
        )
        .join("");

    const summaryRows = [
        ["Reporte", chartTitle.value],
        ["Documentos filtrados", filteredDocumentsCount.value],
        ["Total general de documentos", reportTotalDocumentsCount.value],
        ["Unidades en resultado", visibleUnitsCount.value],
        ["Series en resultado", visibleSeriesCount.value],
        ["Generado", new Date().toLocaleString("es-SV")],
    ]
        .map(
            ([label, value]) => `
                <tr>
                    <th>${escapeHtml(label)}</th>
                    <td>${escapeHtml(value)}</td>
                </tr>`
        )
        .join("");

    const workbook = `
        <html>
            <head>
                <meta charset="UTF-8" />
                <style>
                    table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                    th { background: #0b4f9c; color: #ffffff; font-weight: 700; }
                    th, td { border: 1px solid #d9e2ef; padding: 7px 9px; text-align: left; }
                    .summary th { background: #eef5ff; color: #10213d; }
                    h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                </style>
            </head>
            <body>
                <h1>${escapeHtml(chartTitle.value)}</h1>
                <table class="summary">${summaryRows}</table>
                <br />
                <table>
                    <thead>
                        <tr>${columns.map(([label]) => `<th>${escapeHtml(label)}</th>`).join("")}</tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </body>
        </html>
    `;

    const blob = new Blob([workbook], {
        type: "application/vnd.ms-excel;charset=utf-8;",
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    const safeTitle = chartTitle.value.toLowerCase().replaceAll(" ", "-");

    link.href = url;
    link.download = `${safeTitle}.xls`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
};

watch(
    () => filters.unit,
    () => {
        if (filters.series && !seriesOptions.value.some((option) => option.value === filters.series)) {
            filters.series = null;
        }
    }
);

onMounted(fetchReportSummary);
</script>

<template>
    <div class="reports-page fadein animation-duration-500">
        <section class="reports-hero">
            <div class="reports-hero__nav">
                <button type="button" class="reports-link-button" @click="goBack">
                    <i class="pi pi-arrow-left"></i>
                    <span>Volver</span>
                </button>
            </div>

            <div class="reports-hero__copy">
                <h1 class="reports-hero__title m-0">Reportes Dinámicos</h1>
                <p class="reports-hero__subtitle m-0">
                    Dashboards interactivos con filtros en tiempo real
                </p>
            </div>
        </section>
        
        <section v-if="!selectedCategory" class="reports-categories">
            <div class="reports-section-heading">
                <h2 class="m-0">Categorías de Reportes</h2>
                <p class="m-0">Selecciona una categoría para acceder a dashboards interactivos</p>
            </div>

            <div v-if="errorMessage" class="reports-message reports-message--error">
                <i class="pi pi-exclamation-triangle"></i>
                <span>{{ errorMessage }}</span>
            </div>

            <div v-else-if="loadingSummary" class="reports-message">
                <i class="pi pi-spin pi-spinner"></i>
                <span>Cargando reportes disponibles...</span>
            </div>

            <div class="reports-category-grid">
                <button
                    v-for="category in reportCategories"
                    :key="category.key"
                    type="button"
                    class="reports-category-card"
                    @click="openCategory(category.key)"
                >
                    <span class="reports-category-card__icon">
                        <i class="pi pi-chart-bar"></i>
                    </span>
                    <span class="reports-category-card__copy">
                        <strong>{{ category.title }}</strong>
                        <small>{{ category.description }}</small>
                    </span>
                    <span class="reports-category-card__footer">
                        {{ category.availableReports }} reportes disponibles
                        <i class="pi pi-arrow-up-right"></i>
                    </span>
                </button>
            </div>
        </section>

        <section v-else-if="!selectedReport" class="reports-categories">
            <button type="button" class="reports-inline-back" @click="goBackToCategories">
                <i class="pi pi-arrow-left"></i>
                <span>Volver a categorías</span>
            </button>

            <article class="reports-category-panel">
                <h2 class="reports-category-panel__title m-0">
                    {{ currentCategory?.title }}
                </h2>

                <div v-if="currentCategory?.reports?.length" class="reports-tile-grid">
                    <button
                        v-for="report in currentCategory?.reports"
                        :key="report.key"
                        type="button"
                        class="reports-tile"
                        @click="openReport(report.key)"
                    >
                        <span>
                            <strong>{{ report.title }}</strong>
                            <small>{{ report.description }}</small>
                        </span>
                        <i :class="report.icon"></i>
                    </button>
                </div>

                <div v-else class="reports-empty-text">
                    No hay dashboards configurados para esta categoría.
                </div>
            </article>
        </section>

        <section v-else class="reports-detail">
            <div class="reports-detail__topbar">
                <button type="button" class="reports-inline-back" @click="goBackToReports">
                    <i class="pi pi-arrow-left"></i>
                    <span>Volver a reportes</span>
                </button>

                <Button
                    label="Exportar Datos"
                    icon="pi pi-download"
                    class="reports-export-button"
                    :disabled="loadingRegistry || visibleDocuments.length === 0"
                    @click="exportCurrentTable"
                />
            </div>

            <div v-if="registryErrorMessage" class="reports-message reports-message--error">
                <i class="pi pi-exclamation-triangle"></i>
                <span>{{ registryErrorMessage }}</span>
            </div>

            <article class="reports-filter-panel">
                <div class="reports-filter-panel__heading">
                    <div>
                        <i class="pi pi-filter"></i>
                        <strong>Filtros Dinamicos</strong>
                        <span v-if="hasActiveFilters" class="reports-status-chip">Activos</span>
                    </div>

                    <button
                        type="button"
                        class="reports-clear-button"
                        :disabled="!hasActiveFilters"
                        @click="clearFilters"
                    >
                        <i class="pi pi-times"></i>
                        <span>Limpiar filtros</span>
                    </button>
                </div>

                <div class="reports-filter-grid reports-filter-grid--dynamic">
                    <label>
                        <span>Unidad Productora</span>
                        <Dropdown
                            v-model="filters.unit"
                            :options="unitOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todas"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Serie Documental</span>
                        <Dropdown
                            v-model="filters.series"
                            :options="seriesOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todas"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Soporte</span>
                        <Dropdown
                            v-model="filters.support"
                            :options="supportOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todos"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Buscar</span>
                        <span class="reports-search-control">
                            <i class="pi pi-search"></i>
                            <InputText
                                v-model="filters.search"
                                placeholder="Buscar..."
                                class="w-full"
                            />
                        </span>
                    </label>
                </div>

                <div class="reports-filter-panel__footer">
                    Mostrando
                    <strong>{{ filteredDocumentsCount }}</strong>
                    de
                    <strong>{{ reportTotalDocumentsCount }}</strong>
                    documentos
                </div>
            </article>

            <article class="reports-chart-panel">
                <div class="reports-panel-title">
                    <h2 class="m-0">{{ chartTitle }}</h2>
                    <span v-if="loadingRegistry">
                        <i class="pi pi-spin pi-spinner"></i>
                        Actualizando
                    </span>
                </div>

                <div v-if="currentReport.key === 'period'" class="reports-chart">
                    <svg viewBox="0 0 1000 300" role="img" aria-label="Documentos por periodo">
                        <line
                            v-for="tick in yAxisTicks"
                            :key="tick.value"
                            x1="44"
                            x2="982"
                            :y1="tick.y"
                            :y2="tick.y"
                            class="reports-chart__grid"
                        />
                        <text
                            v-for="tick in yAxisTicks"
                            :key="`label-${tick.value}`"
                            x="32"
                            :y="tick.y + 4"
                            text-anchor="end"
                            class="reports-chart__label"
                        >
                            {{ tick.value }}
                        </text>
                        <path :d="periodAreaPath" class="reports-chart__area" />
                        <path :d="periodLinePath" class="reports-chart__line" />
                        <g
                            v-for="(point, index) in periodChartPoints"
                            :key="`${point.date}-${index}`"
                            @mouseenter="hoveredPoint = point"
                            @mouseleave="hoveredPoint = null"
                        >
                            <circle :cx="point.x" :cy="point.y" r="4" class="reports-chart__point" />
                            <text
                                v-if="index % Math.ceil(Math.max(periodChartPoints.length, 1) / 8) === 0 || index === periodChartPoints.length - 1"
                                :x="point.x"
                                y="286"
                                text-anchor="middle"
                                class="reports-chart__label"
                            >
                                {{ point.label.slice(0, 5) }}
                            </text>
                        </g>
                    </svg>

                    <div
                        v-if="hoveredPoint"
                        class="reports-chart-tooltip"
                        :style="{ left: `${hoveredPoint.x / 10}%`, top: `${Math.max(hoveredPoint.y - 12, 16)}px` }"
                    >
                        <strong>{{ hoveredPoint.label }}</strong>
                        <span>documentos: {{ hoveredPoint.total }}</span>
                    </div>
                </div>

                <div v-else-if="isUnitsReport" class="reports-unit-chart">
                    <div class="reports-unit-chart__plot">
                        <div class="reports-unit-chart__axis">
                            <span
                                v-for="tick in unitChartTicks.slice().reverse()"
                                :key="tick"
                            >
                                {{ tick }}
                            </span>
                        </div>

                        <div class="reports-unit-chart__grid">
                            <span v-for="tick in unitChartTicks" :key="`grid-${tick}`"></span>
                        </div>

                        <div class="reports-unit-chart__bars">
                            <div
                                v-for="row in unitChartRows"
                                :key="row.label"
                                class="reports-unit-chart__bar-wrap"
                            >
                                <div class="reports-unit-chart__bar-shell">
                                    <span
                                        class="reports-unit-chart__bar"
                                        :style="{ height: `${Math.max((row.total / unitChartMax) * 100, 8)}%` }"
                                    >
                                        <strong>{{ row.total }}</strong>
                                    </span>
                                </div>
                                <small>{{ row.label }}</small>
                            </div>
                        </div>
                    </div>

                    <p v-if="unitChartRows.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <div v-else class="reports-bars">
                    <div
                        v-for="row in groupedChartRows"
                        :key="row.label"
                        class="reports-bar-row"
                    >
                        <div class="reports-bar-row__meta">
                            <strong>{{ row.label }}</strong>
                            <span>{{ row.total }} documentos</span>
                        </div>
                        <div class="reports-bar-row__track">
                            <span :style="{ width: row.width }"></span>
                        </div>
                    </div>

                    <p v-if="groupedChartRows.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>
            </article>

            <article v-if="isUnitsReport" class="reports-unit-distribution-panel">
                <h2 class="reports-unit-distribution-panel__title m-0">
                    Distribución de Documentos por Unidad y Serie
                </h2>

                <div class="reports-unit-cards">
                    <section
                        v-for="unit in unitDistributionRows"
                        :key="unit.label"
                        class="reports-unit-card"
                    >
                        <header class="reports-unit-card__header">
                            <div>
                                <h3 class="m-0">{{ unit.label }}</h3>
                                <span>{{ unit.series.length }} series documentales</span>
                            </div>
                            <strong>
                                {{ unit.total }}
                                <small>documentos</small>
                            </strong>
                        </header>

                        <div class="reports-unit-card__series-grid">
                            <div
                                v-for="serie in unit.series"
                                :key="`${unit.label}-${serie.label}`"
                                class="reports-unit-card__series"
                            >
                                <span>{{ serie.label }}</span>
                                <strong>{{ serie.total }}</strong>
                            </div>
                        </div>
                    </section>

                    <p v-if="unitDistributionRows.length === 0" class="reports-empty-text m-0">
                        No hay documentos con los filtros actuales.
                    </p>
                </div>
            </article>

            <article v-else class="reports-table-panel">
                <div class="reports-panel-title">
                    <h2 class="m-0">Listado de Documentos Filtrados</h2>
                    <span>{{ visibleDocuments.length }} registros</span>
                </div>

                <DataTable
                    :value="visibleDocuments"
                    :loading="loadingRegistry"
                    :paginator="visibleDocuments.length > 50"
                    :rows="50"
                    responsiveLayout="scroll"
                    stripedRows
                    class="reports-table"
                >
                    <Column field="code" header="Codigo" style="min-width: 10rem" />
                    <Column field="name" header="Nombre" style="min-width: 18rem" />
                    <Column field="series" header="Serie" style="min-width: 16rem" />
                    <Column field="unit" header="Unidad" style="min-width: 15rem" />
                    <Column field="date_label" header="Fecha" style="min-width: 12rem" />
                    <Column header="Soporte" style="min-width: 9rem">
                        <template #body="{ data }">
                            <span class="report-tag" :class="supportSeverity(data.support)">
                                {{ data.support }}
                            </span>
                        </template>
                    </Column>
                    <template #empty>
                        <div class="reports-empty-text">No hay documentos con los filtros actuales.</div>
                    </template>
                </DataTable>

                <p v-if="visibleDocuments.length > 50" class="reports-table-note m-0">
                    Mostrando los primeros 50 resultados por pagina. Usa los filtros para refinar tu busqueda.
                </p>
            </article>
        </section>
    </div>
</template>

<style scoped>
.reports-page {
    background: #f4f6f9;
    display: flex;
    flex-direction: column;
    gap: 2.15rem;
    margin: 0;
    min-height: 100vh;
    padding-bottom: 2.5rem;
}

.reports-hero {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid #dce4ee;
    display: flex;
    gap: 1rem;
    min-height: 6.75rem;
    padding: 1.9rem 2.35rem;
}

.reports-hero__nav {
    border-right: 1px solid #dce4ee;
    padding-right: 1.35rem;
}

.reports-link-button,
.reports-inline-back,
.reports-clear-button {
    align-items: center;
    background: transparent;
    border: 0;
    color: #0b4f9c;
    cursor: pointer;
    display: inline-flex;
    font-weight: 600;
    gap: 0.55rem;
    line-height: 1;
    padding: 0;
}

.reports-clear-button {
    color: #7b8798;
    font-size: 0.92rem;
}

.reports-clear-button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}

.reports-hero__copy {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
    padding-left: 0.15rem;
}

.reports-hero__title {
    color: #061b3a;
    font-size: 1.5rem;
    font-weight: 500;
    letter-spacing: 0;
}

.reports-hero__subtitle {
    color: #667085;
    font-size: 0.95rem;
}

.reports-categories,
.reports-detail {
    display: flex;
    flex-direction: column;
    gap: 1.55rem;
    padding: 0 2rem;
}

.reports-section-heading {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.reports-section-heading h2 {
    color: #061b3a;
    font-size: 1.35rem;
    font-weight: 700;
}

.reports-section-heading p {
    color: #667085;
    font-size: 0.95rem;
}

.reports-category-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.reports-category-card {
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    color: #061b3a;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    min-height: 15rem;
    padding: 1.35rem;
    text-align: left;
    transition:
        border-color 0.18s ease,
        box-shadow 0.18s ease,
        transform 0.18s ease;
}

.reports-category-card:hover {
    border-color: #9ebce2;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    transform: translateY(-2px);
}

.reports-category-card__icon {
    align-items: center;
    background: #eef5ff;
    border-radius: 8px;
    color: #0b4f9c;
    display: inline-flex;
    height: 2.8rem;
    justify-content: center;
    width: 2.8rem;
}

.reports-category-card__copy {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.6rem;
}

.reports-category-card__copy strong {
    font-size: 1.12rem;
}

.reports-category-card__copy small {
    color: #667085;
    font-size: 0.9rem;
    line-height: 1.45;
}

.reports-category-card__footer {
    align-items: center;
    color: #536173;
    display: flex;
    font-size: 0.9rem;
    font-weight: 700;
    justify-content: space-between;
}

.reports-category-panel,
.reports-filter-panel,
.reports-chart-panel,
.reports-table-panel,
.reports-unit-distribution-panel {
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
}

.reports-category-panel {
    padding: 1.55rem 1.5rem 1.45rem;
}

.reports-category-panel__title {
    color: #061b3a;
    font-size: 1.22rem;
    font-weight: 700;
    margin-bottom: 1.85rem;
}

.reports-tile-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin: 14px 0px;
}

.reports-tile {
    align-items: center;
    background: #fbfcfe;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    color: #061b3a;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    min-height: 5.05rem;
    padding: 1rem 1rem;
    text-align: left;
    transition:
        background-color 0.18s ease,
        border-color 0.18s ease,
        box-shadow 0.18s ease;
}

.reports-tile:hover {
    background: #ffffff;
    border-color: #9ebce2;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.07);
}

.reports-tile span {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.reports-tile strong {
    font-size: 0.98rem;
}

.reports-tile small {
    color: #667085;
    font-size: 0.86rem;
}

.reports-tile i {
    color: #7b8798;
    font-size: 1.05rem;
}

.reports-detail__topbar {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.reports-export-button {
    background: #0b4f9c;
    border-color: #0b4f9c;
    border-radius: 8px;
}

.reports-filter-panel,
.reports-chart-panel,
.reports-table-panel,
.reports-unit-distribution-panel {
    padding: 1.35rem 1.55rem;
}

.reports-filter-panel__heading,
.reports-filter-panel__heading > div,
.reports-panel-title {
    align-items: center;
    display: flex;
    gap: 0.65rem;
}

.reports-filter-panel__heading,
.reports-panel-title {
    justify-content: space-between;
}

.reports-filter-panel__heading {
    color: #1f2d3d;
    margin-bottom: 1rem;
}

.reports-filter-panel__heading i {
    color: #0b67c2;
}

.reports-status-chip {
    background: #dfeaff;
    border-radius: 999px;
    color: #4176d7;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.2rem 0.48rem;
}

.reports-filter-grid {
    border-bottom: 1px solid #d9e2ef;
    display: grid;
    gap: 1rem;
    grid-template-columns: 1fr 1fr 1fr;
    padding-bottom: 1rem;
}

.reports-filter-grid--dynamic {
    grid-template-columns: repeat(4, minmax(12rem, 1fr));
}

.reports-filter-grid label {
    color: #667085;
    display: flex;
    flex-direction: column;
    font-size: 0.82rem;
    gap: 0.45rem;
}

.reports-search-control {
    align-items: center;
    display: flex;
    position: relative;
}

.reports-search-control .pi {
    color: #7b8798;
    left: 0.85rem;
    pointer-events: none;
    position: absolute;
    z-index: 1;
}

.reports-search-control :deep(.p-inputtext) {
    padding-left: 2.35rem;
}

.reports-filter-panel__footer {
    color: #667085;
    font-size: 0.92rem;
    padding-top: 1rem;
}

.reports-filter-panel__footer strong {
    color: #27364a;
}

.reports-panel-title {
    color: #27364a;
    margin-bottom: 1rem;
}

.reports-panel-title h2 {
    color: #27364a;
    font-size: 1rem;
    font-weight: 700;
}

.reports-panel-title span {
    color: #667085;
    font-size: 0.88rem;
}

.reports-chart {
    min-height: 310px;
    position: relative;
}

.reports-chart svg {
    display: block;
    height: 310px;
    width: 100%;
}

.reports-chart__grid {
    stroke: #dfe7f1;
    stroke-dasharray: 3 4;
    stroke-width: 1;
}

.reports-chart__area {
    fill: rgba(45, 118, 196, 0.66);
}

.reports-chart__line {
    fill: none;
    stroke: #155aa2;
    stroke-width: 2;
}

.reports-chart__point {
    cursor: pointer;
    fill: #155aa2;
    stroke: #ffffff;
    stroke-width: 3;
}

.reports-chart__label {
    fill: #667085;
    font-size: 12px;
}

.reports-chart-tooltip {
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
    color: #061b3a;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    min-width: 8rem;
    padding: 0.85rem;
    pointer-events: none;
    position: absolute;
    transform: translateX(-50%);
}

.reports-chart-tooltip span {
    color: #0b4f9c;
}

.reports-unit-chart {
    min-height: 360px;
    padding: 0.5rem 0 0;
}

.reports-unit-chart__plot {
    display: grid;
    grid-template-columns: 3rem 1fr;
    min-height: 340px;
    position: relative;
}

.reports-unit-chart__plot::after {
    border-bottom: 1px solid #94a3b8;
    bottom: 4.9rem;
    content: "";
    left: 3rem;
    position: absolute;
    right: 0;
    z-index: 2;
}

.reports-unit-chart__axis {
    color: #667085;
    display: flex;
    flex-direction: column;
    font-size: 0.8rem;
    justify-content: space-between;
    padding: 0.1rem 0.55rem 4.9rem 0;
    text-align: right;
}

.reports-unit-chart__grid {
    bottom: 4.9rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    left: 3rem;
    pointer-events: none;
    position: absolute;
    right: 0;
    top: 0;
}

.reports-unit-chart__grid span {
    border-top: 1px dashed #dfe7f1;
    display: block;
}

.reports-unit-chart__bars {
    align-items: end;
    display: grid;
    gap: 1.15rem;
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
    min-height: 310px;
    padding: 0 1rem 0;
    position: relative;
    z-index: 1;
}

.reports-unit-chart__bar-wrap {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    height: 100%;
    justify-content: flex-end;
    min-width: 0;
}

.reports-unit-chart__bar-shell {
    align-items: end;
    display: flex;
    height: 235px;
    justify-content: center;
    width: 100%;
}

.reports-unit-chart__bar {
    align-items: flex-start;
    background: #2167b8;
    border-radius: 7px 7px 0 0;
    display: flex;
    justify-content: flex-end;
    max-width: 10.5rem;
    min-height: 1.5rem;
    padding: 0.55rem;
    transition: height 0.2s ease;
    width: 100%;
}

.reports-unit-chart__bar strong {
    color: #ffffff;
    font-size: 0.9rem;
}

.reports-unit-chart__bar-wrap small {
    color: #667085;
    font-size: 0.78rem;
    line-height: 1.25;
    min-height: 4.2rem;
    overflow-wrap: anywhere;
    text-align: center;
    transform: none;
    white-space: normal;
    width: min(100%, 13rem);
}

.reports-unit-distribution-panel__title {
    color: #27364a;
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 1.4rem;
}

.reports-unit-cards {
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

.reports-unit-card {
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    overflow: hidden;
}

.reports-unit-card__header {
    align-items: center;
    background: #074a8f;
    color: #ffffff;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.15rem 1.35rem;
}

.reports-unit-card__header h3 {
    color: #ffffff;
    font-size: 1rem;
    font-weight: 700;
}

.reports-unit-card__header span,
.reports-unit-card__header small {
    color: #ffffff;
    display: block;
    font-size: 0.78rem;
    margin-top: 0.32rem;
}

.reports-unit-card__header > strong {
    align-items: flex-end;
    color: #ffffff;
    display: flex;
    flex-direction: column;
    font-size: 2rem;
    line-height: 1;
}

.reports-unit-card__series-grid {
    background: #fbfcfe;
    display: grid;
    gap: 0.8rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 1rem;
}

.reports-unit-card__series {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    min-height: 2.7rem;
    padding: 0.7rem 0.9rem;
}

.reports-unit-card__series span {
    color: #27364a;
    font-size: 0.88rem;
    font-weight: 700;
}

.reports-unit-card__series strong {
    align-items: center;
    background: #dbeafe;
    border-radius: 999px;
    color: #2563eb;
    display: inline-flex;
    font-size: 0.86rem;
    justify-content: center;
    min-width: 3.2rem;
    padding: 0.2rem 0.75rem;
}

.reports-bars {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    min-height: 18rem;
    padding: 0.5rem 0;
}

.reports-bar-row {
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(12rem, 22rem) 1fr;
}

.reports-bar-row__meta {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.reports-bar-row__meta strong {
    color: #27364a;
    font-size: 0.92rem;
}

.reports-bar-row__meta span {
    color: #667085;
    font-size: 0.82rem;
}

.reports-bar-row__track {
    align-items: center;
    background: #eef3f8;
    border-radius: 999px;
    display: flex;
    height: 0.75rem;
    margin-top: 0.42rem;
    overflow: hidden;
}

.reports-bar-row__track span {
    background: #2d76c4;
    border-radius: inherit;
    display: block;
    height: 100%;
}

.reports-table :deep(.p-datatable-thead > tr > th) {
    background: #ffffff;
    border-color: #d9e2ef;
    color: #27364a;
    font-size: 0.84rem;
    font-weight: 700;
}

.reports-table :deep(.p-datatable-tbody > tr > td) {
    border-color: #d9e2ef;
    color: #536173;
    font-size: 0.84rem;
}

.reports-table :deep(.p-datatable-tbody > tr > td:first-child) {
    color: #27364a;
    font-family: Consolas, "Courier New", monospace;
    font-weight: 700;
}

.report-tag {
    border-radius: 999px;
    display: inline-flex;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.22rem 0.52rem;
}

.report-tag--physical {
    background: #eef2f6;
    color: #536173;
}

.report-tag--digital {
    background: #dfeaff;
    color: #2563eb;
}

.report-tag--mixed {
    background: #f3e8ff;
    color: #a855f7;
}

.reports-table-note,
.reports-empty-text {
    color: #667085;
    font-size: 0.88rem;
    padding-top: 1rem;
    text-align: center;
}

.reports-message {
    align-items: center;
    background: #edf3fa;
    border-radius: 8px;
    color: #2f6f9f;
    display: flex;
    gap: 0.75rem;
    padding: 0.95rem 1rem;
}

.reports-message--error {
    background: #fef2f2;
    color: #b91c1c;
}

@media (max-width: 900px) {
    .reports-page {
        margin: 0;
    }

    .reports-hero {
        align-items: flex-start;
        flex-direction: column;
        padding: 1.25rem;
    }

    .reports-hero__nav {
        border-right: 0;
        border-bottom: 1px solid #dce4ee;
        padding: 0 0 0.8rem;
        width: 100%;
    }

    .reports-categories,
    .reports-detail {
        padding: 0 1rem;
    }

    .reports-category-grid {
        grid-template-columns: 1fr;
    }

    .reports-tile-grid,
    .reports-filter-grid,
    .reports-filter-grid--dynamic,
    .reports-unit-card__series-grid {
        grid-template-columns: 1fr;
    }

    .reports-detail__topbar,
    .reports-filter-panel__heading,
    .reports-panel-title {
        align-items: flex-start;
        flex-direction: column;
    }

    .reports-bar-row {
        grid-template-columns: 1fr;
    }

    .reports-unit-chart__plot {
        grid-template-columns: 2.5rem 1fr;
    }

    .reports-unit-chart__grid {
        left: 2.5rem;
    }

    .reports-unit-chart__plot::after {
        left: 2.5rem;
    }

    .reports-unit-chart__bars {
        gap: 0.75rem;
        overflow-x: auto;
    }

    .reports-unit-chart__bar-wrap {
        min-width: 11rem;
    }
}
</style>
