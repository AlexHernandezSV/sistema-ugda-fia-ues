<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { normalizeSearchText } from "../../services/textSearch";

const router = useRouter();

const loadingSummary = ref(true);
const loadingRegistry = ref(false);
const errorMessage = ref("");
const registryErrorMessage = ref("");
const selectedCategory = ref(null);
const selectedReport = ref(null);
const hoveredPoint = ref(null);
const classificationSearchTimeout = ref(null);
const rowsPerPageOptions = ref([5, 10, 25, 50]);

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

const accessHistoryReport = ref({
    filters: {
        statuses: [],
        units: [],
        users: [],
    },
    summary: {
        filtered_loans: 0,
        total_loans: 0,
        users: 0,
        units: 0,
    },
    loans: [],
});

const accessDocumentsReport = ref({
    summary: {
        total_documents: 0,
        total_consultations: 0,
    },
    documents: [],
});

const accessResponseTimeReport = ref({
    summary: {
        total_rows: 0,
        average_minutes: 0,
        minimum_minutes: 0,
        maximum_minutes: 0,
    },
    rows: [],
});

const classificationSeriesReport = ref({
    filters: {
        units: [],
        series: [],
        support: [],
    },
    summary: {
        filtered_documents: 0,
        total_documents: 0,
    },
    charts: {
        series: [],
    },
    series_counts: {
        data: [],
        meta: {
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 0,
            from: null,
            to: null,
        },
    },
    documents: {
        data: [],
        meta: {
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: 0,
            from: null,
            to: null,
        },
    },
});

const unclassifiedDocumentsReport = ref({
    filters: {
        units: [],
        series: [],
        support: [],
    },
    summary: {
        filtered_documents: 0,
        total_documents: 0,
        has_filters: false,
    },
    documents: {
        data: [],
        meta: {
            current_page: 1,
            last_page: 1,
            per_page: 50,
            total: 0,
            from: null,
            to: null,
        },
    },
});

const filters = reactive({
    unit: null,
    series: null,
    support: null,
    search: "",
});

const entryFilters = reactive({
    mode: "date",
    date: null,
    startDate: null,
    endDate: null,
});

const accessFilters = reactive({
    status: null,
    unit: null,
    user: null,
    startDate: null,
    endDate: null,
});

const accessDocumentFilters = reactive({
    analysisType: "most",
    startDate: null,
    endDate: null,
});

const accessResponseTimeFilters = reactive({
    startDate: null,
    endDate: null,
});

const accessDocumentAnalysisOptions = [
    { label: "Más Consultados", value: "most" },
    { label: "Menos Consultados", value: "least" },
];

const entryPeriodModeOptions = [
    { label: "Fecha específica", value: "date" },
    { label: "Rango de fechas", value: "range" },
];

const expandedAccessUsers = ref(new Set());

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
        availableReports: accessReports.value.length,
        reports: accessReports.value,
    },
    {
        key: "classification_and_organization",
        title: "Reportes de Clasificación y Organización",
        description: "Resúmenes para series, cajas y organización del archivo general.",
        availableReports: classificationReports.value.length,
        reports: classificationReports.value,
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

const accessReports = computed(() => [
    {
        key: "consultation-history",
        title: "Historial de Consultas por Usuario",
        description: "Solicitudes de préstamo realizadas",
        icon: "pi pi-chart-bar",
    },
    {
        key: "most-consulted-documents",
        title: "Documentos Más y Menos Consultados",
        description: "Análisis de préstamos por documento",
        icon: "pi pi-chart-bar",
    },
    {
        key: "average-response-times",
        title: "Tiempos Promedio de Respuesta",
        description: "Tiempo de localización de documentos",
        icon: "pi pi-chart-bar",
    },
]);

const classificationReports = computed(() => [
    {
        key: "documentary-series-subseries",
        title: "Series y Subseries Documentales",
        description: "Conteo de documentos por serie",
        icon: "pi pi-chart-bar",
        hasDashboard: true,
    },
    {
        key: "unclassified-documents",
        title: "Documentos sin Clasificación",
        description: "Documentos pendientes de clasificar",
        icon: "pi pi-chart-bar",
        hasDashboard: true,
    },
]);

const currentCategory = computed(() =>
    reportCategories.value.find((category) => category.key === selectedCategory.value)
);

const currentReport = computed(() =>
    [...entryReports.value, ...accessReports.value, ...classificationReports.value]
        .find((report) => report.key === selectedReport.value) ??
    entryReports.value[0]
);

const isEntryReport = computed(() => selectedCategory.value === "entry_and_registry");
const isClassificationSeriesReport = computed(() =>
    selectedCategory.value === "classification_and_organization" &&
    selectedReport.value === "documentary-series-subseries"
);
const isUnclassifiedDocumentsReport = computed(() =>
    selectedCategory.value === "classification_and_organization" &&
    selectedReport.value === "unclassified-documents"
);
const isAccessHistoryReport = computed(() =>
    selectedCategory.value === "access_and_consultation" &&
    selectedReport.value === "consultation-history"
);
const isAccessDocumentsReport = computed(() =>
    selectedCategory.value === "access_and_consultation" &&
    selectedReport.value === "most-consulted-documents"
);
const isAccessResponseTimeReport = computed(() =>
    selectedCategory.value === "access_and_consultation" &&
    selectedReport.value === "average-response-times"
);
const isUnitsReport = computed(() => currentReport.value.key === "units");
const hasDocumentFilters = () => Boolean(
    filters.unit ||
    filters.series ||
    filters.support ||
    filters.search.trim()
);
const hasActiveFilters = computed(() =>
    isAccessResponseTimeReport.value
        ? Boolean(accessResponseTimeFilters.startDate || accessResponseTimeFilters.endDate)
        : isAccessDocumentsReport.value
        ? Boolean(
            accessDocumentFilters.analysisType !== "most" ||
            accessDocumentFilters.startDate ||
            accessDocumentFilters.endDate
        )
        : isAccessHistoryReport.value
        ? Boolean(
            accessFilters.status ||
            accessFilters.unit ||
            accessFilters.user ||
            accessFilters.startDate ||
            accessFilters.endDate
        )
        : isEntryReport.value
        ? Boolean(
            entryFilters.mode === "date"
                ? entryFilters.date
                : entryFilters.startDate || entryFilters.endDate
        ) || hasDocumentFilters()
        : hasDocumentFilters()
);

const documents = computed(() => registryReport.value.documents ?? []);
const classificationDocuments = computed(() => classificationSeriesReport.value.documents.data ?? []);
const unclassifiedDocuments = computed(() => unclassifiedDocumentsReport.value.documents.data ?? []);
const classificationSeriesCountRows = computed(() =>
    classificationSeriesReport.value.series_counts.data ?? []
);
const classificationSeriesMeta = computed(() => classificationSeriesReport.value.series_counts.meta ?? {
    current_page: 1,
    last_page: 1,
    per_page: 5,
    total: 0,
    from: null,
    to: null,
});
const classificationDocumentsMeta = computed(() => classificationSeriesReport.value.documents.meta ?? {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
    from: null,
    to: null,
});
const unclassifiedDocumentsMeta = computed(() => unclassifiedDocumentsReport.value.documents.meta ?? {
    current_page: 1,
    last_page: 1,
    per_page: 50,
    total: 0,
    from: null,
    to: null,
});

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

const classificationUnitOptions = computed(() => classificationSeriesReport.value.filters.units ?? []);
const classificationSeriesOptions = computed(() => classificationSeriesReport.value.filters.series ?? []);
const classificationSupportOptions = computed(() => classificationSeriesReport.value.filters.support ?? []);
const unclassifiedUnitOptions = computed(() => unclassifiedDocumentsReport.value.filters.units ?? []);
const unclassifiedSeriesOptions = computed(() => unclassifiedDocumentsReport.value.filters.series ?? []);
const unclassifiedSupportOptions = computed(() => unclassifiedDocumentsReport.value.filters.support ?? []);
const activeUnitOptions = computed(() =>
    isUnclassifiedDocumentsReport.value
        ? unclassifiedUnitOptions.value
        : isClassificationSeriesReport.value
        ? classificationUnitOptions.value
        : unitOptions.value
);
const activeSeriesOptions = computed(() =>
    isUnclassifiedDocumentsReport.value
        ? unclassifiedSeriesOptions.value
        : isClassificationSeriesReport.value
        ? classificationSeriesOptions.value
        : seriesOptions.value
);
const activeSupportOptions = computed(() =>
    isUnclassifiedDocumentsReport.value
        ? unclassifiedSupportOptions.value
        : isClassificationSeriesReport.value
        ? classificationSupportOptions.value
        : supportOptions.value
);

const visibleDocuments = computed(() => {
    const searchTerm = normalizeSearchText(filters.search);

    return documents.value.filter((document) => {
        const matchesUnit = !filters.unit || document.unit_id === filters.unit;
        const matchesSeries = !filters.series || document.series === filters.series;
        const matchesSupport = !filters.support || document.support === filters.support;
        const matchesPeriod = !isEntryReport.value || entryPeriodMatches(document.date);
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
            .some((value) => normalizeSearchText(value).includes(searchTerm));

        return matchesUnit && matchesSeries && matchesSupport && matchesSearch && matchesPeriod;
    });
});

const accessLoans = computed(() => accessHistoryReport.value.loans ?? []);
const accessDocumentConsultations = computed(() => accessDocumentsReport.value.documents ?? []);
const accessResponseTimeRows = computed(() => accessResponseTimeReport.value.rows ?? []);

const accessStatusOptions = computed(() => accessHistoryReport.value.filters.statuses ?? []);
const accessUnitOptions = computed(() => accessHistoryReport.value.filters.units ?? []);
const accessUserOptions = computed(() => accessHistoryReport.value.filters.users ?? []);

const toReportDate = (value) => {
    if (!value) {
        return null;
    }

    if (typeof value === "string") {
        return value.slice(0, 10);
    }

    const year = value.getFullYear();
    const month = String(value.getMonth() + 1).padStart(2, "0");
    const day = String(value.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

const entryPeriodParams = () => {
    if (entryFilters.mode === "date") {
        return {
            date: toReportDate(entryFilters.date),
            start_date: null,
            end_date: null,
        };
    }

    return {
        date: null,
        start_date: toReportDate(entryFilters.startDate),
        end_date: toReportDate(entryFilters.endDate),
    };
};

const entryPeriodMatches = (documentDate) => {
    const params = entryPeriodParams();

    if (!params.date && !params.start_date && !params.end_date) {
        return true;
    }

    if (!documentDate) {
        return false;
    }

    if (params.date) {
        return documentDate === params.date;
    }

    return (!params.start_date || documentDate >= params.start_date) &&
        (!params.end_date || documentDate <= params.end_date);
};

const visibleAccessLoans = computed(() => {
    const startDate = toReportDate(accessFilters.startDate);
    const endDate = toReportDate(accessFilters.endDate);

    return accessLoans.value.filter((loan) => {
        const requestedDate = loan.requested_at;
        const matchesStatus = !accessFilters.status || loan.status_code === accessFilters.status;
        const matchesUnit = !accessFilters.unit || loan.unit_id === accessFilters.unit;
        const matchesUser = !accessFilters.user || loan.user_id === accessFilters.user;
        const matchesStart = !startDate || requestedDate >= startDate;
        const matchesEnd = !endDate || requestedDate <= endDate;

        return matchesStatus && matchesUnit && matchesUser && matchesStart && matchesEnd;
    });
});

const visibleAccessDocumentConsultations = computed(() => {
    const startDate = toReportDate(accessDocumentFilters.startDate);
    const endDate = toReportDate(accessDocumentFilters.endDate);

    return accessDocumentConsultations.value.filter((document) => {
        const consultationDate = document.date;
        const matchesStart = !startDate || consultationDate >= startDate;
        const matchesEnd = !endDate || consultationDate <= endDate;

        return matchesStart && matchesEnd;
    });
});

const visibleAccessResponseTimeRows = computed(() => {
    const startDate = toReportDate(accessResponseTimeFilters.startDate);
    const endDate = toReportDate(accessResponseTimeFilters.endDate);

    return accessResponseTimeRows.value.filter((row) => {
        const startedAt = row.started_at;
        const matchesStart = !startDate || startedAt >= startDate;
        const matchesEnd = !endDate || startedAt <= endDate;

        return matchesStart && matchesEnd;
    });
});

const filteredDocumentsCount = computed(() => visibleDocuments.value.length);
const filteredClassificationDocumentsCount = computed(() =>
    isUnclassifiedDocumentsReport.value
        ? unclassifiedDocumentsReport.value.summary.filtered_documents ?? 0
        : classificationSeriesReport.value.summary.filtered_documents ?? 0
);
const filteredAccessLoansCount = computed(() => visibleAccessLoans.value.length);
const accessDocumentTableRows = computed(() => {
    const grouped = new Map();

    visibleAccessDocumentConsultations.value.forEach((document) => {
        const key = document.document_key || document.title;

        if (!grouped.has(key)) {
            grouped.set(key, {
                key,
                title: document.title || "Sin titulo",
                series: document.series || "Sin serie",
                total: 0,
                consultations: [],
            });
        }

        const row = grouped.get(key);
        row.total += 1;
        row.consultations.push(document);
    });

    const direction = accessDocumentFilters.analysisType === "least" ? 1 : -1;

    return Array.from(grouped.values())
        .sort((a, b) => {
            const totalComparison = (a.total - b.total) * direction;

            return totalComparison || a.title.localeCompare(b.title);
        });
});
const accessDocumentRows = computed(() => accessDocumentTableRows.value.slice(0, 12));
const filteredAccessDocumentsCount = computed(() => accessDocumentTableRows.value.length);
const filteredAccessResponseTimeCount = computed(() => visibleAccessResponseTimeRows.value.length);
const reportTotalDocumentsCount = computed(() =>
    isUnitsReport.value ? documents.value.length : registryReport.value.summary.total_documents
);
const reportTotalClassificationDocumentsCount = computed(() =>
    isUnclassifiedDocumentsReport.value
        ? unclassifiedDocumentsReport.value.summary.total_documents ?? 0
        : classificationSeriesReport.value.summary.total_documents ?? 0
);
const reportTotalAccessLoansCount = computed(() => accessHistoryReport.value.summary.total_loans);
const reportTotalAccessDocumentsCount = computed(() => accessDocumentsReport.value.summary.total_documents);
const reportTotalAccessResponseTimeCount = computed(() => accessResponseTimeReport.value.summary.total_rows);
const visibleUnitsCount = computed(() =>
    new Set(visibleDocuments.value.map((document) => document.unit).filter(Boolean)).size
);
const visibleSeriesCount = computed(() =>
    new Set(visibleDocuments.value.map((document) => document.series).filter(Boolean)).size
);

const chartTitle = computed(() => currentReport.value?.title ?? "Documentos por Periodo");

const classificationSeriesChartRows = computed(() => classificationSeriesReport.value.charts.series ?? []);
const classificationSeriesPieRows = computed(() => {
    const rows = classificationSeriesChartRows.value;

    if (rows.length <= 8) {
        return rows;
    }

    const mainRows = rows.slice(0, 7);
    const otherRows = rows.slice(7);
    const otherDocuments = otherRows.reduce((sum, row) => sum + row.documents_count, 0);
    const otherUnits = otherRows.reduce((sum, row) => sum + row.units_count, 0);

    return [
        ...mainRows,
        {
            label: "Otros",
            documents_count: otherDocuments,
            units_count: otherUnits,
            documents_label: otherDocuments === 1 ? "1 documento" : `${otherDocuments} documentos`,
            units_label: otherUnits === 1 ? "1 unidad productora" : `${otherUnits} unidades productoras`,
        },
    ];
});
const classificationSeriesPieTotal = computed(() =>
    classificationSeriesPieRows.value.reduce((sum, row) => sum + row.documents_count, 0)
);
const classificationDocumentsFirst = computed(() =>
    ((classificationDocumentsMeta.value.current_page ?? 1) - 1) *
    (classificationDocumentsMeta.value.per_page ?? 50)
);
const unclassifiedDocumentsFirst = computed(() =>
    ((unclassifiedDocumentsMeta.value.current_page ?? 1) - 1) *
    (unclassifiedDocumentsMeta.value.per_page ?? 50)
);

const accessChartRows = computed(() => {
    const grouped = new Map();

    visibleAccessLoans.value.forEach((loan) => {
        const unit = loan.unit || "Sin unidad";
        grouped.set(unit, (grouped.get(unit) ?? 0) + 1);
    });

    return Array.from(grouped.entries())
        .map(([label, total]) => ({ label, total }))
        .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label));
});

const accessChartMax = computed(() => Math.max(...accessChartRows.value.map((row) => row.total), 1));

const accessChartTicks = computed(() => {
    const roundedMax = Math.max(4, Math.ceil(accessChartMax.value / 4) * 4);

    return [0, 1, 2, 3, 4].map((index) => Math.round((roundedMax / 4) * index));
});

const accessDocumentChartMax = computed(() =>
    Math.max(...accessDocumentRows.value.map((row) => row.total), 1)
);

const accessDocumentChartTicks = computed(() => {
    const roundedMax = Math.max(3, Math.ceil(accessDocumentChartMax.value / 3) * 3);

    return [0, 1, 2, 3, 4].map((index) => Number(((roundedMax / 4) * index).toFixed(2)));
});

const accessResponseTimeSummary = computed(() => {
    const times = visibleAccessResponseTimeRows.value.map((row) => Number(row.minutes) || 0);

    if (times.length === 0) {
        return {
            average: 0,
            minimum: 0,
            maximum: 0,
        };
    }

    return {
        average: Math.round(times.reduce((sum, value) => sum + value, 0) / times.length),
        minimum: Math.min(...times),
        maximum: Math.max(...times),
    };
});

const accessResponseTimeAxis = computed(() => {
    const maxMinutes = Math.max(
        ...visibleAccessResponseTimeRows.value.map((row) => Number(row.minutes) || 0),
        0
    );
    const maximum = Math.max(4, Math.ceil(maxMinutes / 4) * 4);

    return {
        maximum,
        ticks: [0, 1, 2, 3, 4].map((index) => (maximum / 4) * index),
    };
});

const accessResponseTimeChartPoints = computed(() => {
    const rows = visibleAccessResponseTimeRows.value.slice(0, 12);
    const maxMinutes = accessResponseTimeAxis.value.maximum;
    const width = 1000;
    const height = 300;
    const top = 16;
    const bottom = 40;
    const left = 54;
    const right = 18;
    const graphWidth = width - left - right;
    const graphHeight = height - top - bottom;
    const denominator = Math.max(rows.length - 1, 1);

    return rows.map((row, index) => ({
        ...row,
        label: `#${index + 1}`,
        x: left + (index / denominator) * graphWidth,
        y: top + graphHeight - (row.minutes / maxMinutes) * graphHeight,
    }));
});

const accessResponseTimeLinePath = computed(() => {
    if (accessResponseTimeChartPoints.value.length === 0) {
        return "";
    }

    return accessResponseTimeChartPoints.value
        .map((point, index) => `${index === 0 ? "M" : "L"} ${point.x} ${point.y}`)
        .join(" ");
});

const accessResponseTimeTicks = computed(() => {
    const maxMinutes = accessResponseTimeAxis.value.maximum;

    return accessResponseTimeAxis.value.ticks.map((value) => ({
        value,
        y: 260 - (value / maxMinutes) * 244,
    }));
});

const accessDetailRows = computed(() => {
    const units = new Map();

    visibleAccessLoans.value.forEach((loan) => {
        const unitKey = loan.unit_id ?? loan.unit;

        if (!units.has(unitKey)) {
            units.set(unitKey, {
                key: unitKey,
                label: loan.unit || "Sin unidad",
                users: new Map(),
            });
        }

        const unit = units.get(unitKey);
        const userKey = `${unitKey}-${loan.user_id ?? loan.user}`;

        if (!unit.users.has(userKey)) {
            unit.users.set(userKey, {
                key: userKey,
                name: loan.user || "Sin usuario",
                initials: loan.user_initials || "SU",
                unit: loan.unit || "Sin unidad",
                total: 0,
                documents: [],
            });
        }

        const user = unit.users.get(userKey);
        user.total += 1;
        user.documents.push(...(loan.documents ?? []).map((document) => ({
            ...document,
            loan_number: loan.number,
        })));
    });

    return Array.from(units.values())
        .map((unit) => ({
            ...unit,
            users: Array.from(unit.users.values())
                .sort((a, b) => b.total - a.total || a.name.localeCompare(b.name)),
        }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

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
    const key = "series";
    const grouped = new Map();

    visibleDocuments.value.forEach((document) => {
        const label = document[key] || "Sin serie";
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

const supportColor = (support, index) => {
    const normalized = (support ?? "").toLowerCase();

    if (normalized.includes("fisico") || normalized.includes("físico")) {
        return "#084a8f";
    }

    if (normalized.includes("digital")) {
        return "#2167b8";
    }

    if (normalized.includes("mixto")) {
        return "#4d93df";
    }

    return ["#084a8f", "#2167b8", "#4d93df", "#78aee8"][index % 4];
};

const pointFromAngle = (center, radius, angle) => {
    const radians = ((angle - 90) * Math.PI) / 180;

    return {
        x: center + radius * Math.cos(radians),
        y: center + radius * Math.sin(radians),
    };
};

const describePieSlice = (center, radius, startAngle, endAngle) => {
    const start = pointFromAngle(center, radius, endAngle);
    const end = pointFromAngle(center, radius, startAngle);
    const largeArcFlag = endAngle - startAngle > 180 ? 1 : 0;

    if (endAngle - startAngle >= 359.99) {
        return [
            `M ${center} ${center}`,
            `L ${center} ${center - radius}`,
            `A ${radius} ${radius} 0 1 1 ${center} ${center + radius}`,
            `A ${radius} ${radius} 0 1 1 ${center} ${center - radius}`,
            "Z",
        ].join(" ");
    }

    return [
        `M ${center} ${center}`,
        `L ${start.x} ${start.y}`,
        `A ${radius} ${radius} 0 ${largeArcFlag} 0 ${end.x} ${end.y}`,
        "Z",
    ].join(" ");
};

const classificationPieColor = (index) => [
    "#0b4f9c",
    "#2167b8",
    "#4d93df",
    "#78aee8",
    "#0f766e",
    "#059669",
    "#a855f7",
    "#64748b",
][index % 8];

const classificationSeriesPieSegments = computed(() => {
    const total = classificationSeriesPieTotal.value;
    let currentAngle = 90;
    const center = 190;
    const radius = 128;

    return classificationSeriesPieRows.value.map((row, index) => {
        const sliceAngle = total > 0 ? (row.documents_count / total) * 360 : 0;
        const startAngle = currentAngle;
        const endAngle = currentAngle + sliceAngle;
        currentAngle = endAngle;

        return {
            ...row,
            color: classificationPieColor(index),
            percentage: total > 0 ? Math.round((row.documents_count / total) * 100) : 0,
            path: describePieSlice(center, radius, startAngle, endAngle),
        };
    });
});

const supportChartRows = computed(() => {
    const grouped = new Map();

    visibleDocuments.value.forEach((document) => {
        const label = document.support || "Sin soporte";
        grouped.set(label, (grouped.get(label) ?? 0) + 1);
    });

    const total = Array.from(grouped.values()).reduce((sum, value) => sum + value, 0);

    return Array.from(grouped.entries())
        .map(([label, value], index) => ({
            label,
            value,
            color: supportColor(label, index),
            percentage: total > 0 ? Math.round((value / total) * 100) : 0,
        }))
        .sort((a, b) => b.value - a.value || a.label.localeCompare(b.label));
});

const supportPieSegments = computed(() => {
    const total = supportChartRows.value.reduce((sum, row) => sum + row.value, 0);
    let currentAngle = 90;
    const center = 190;
    const radius = 118;

    return supportChartRows.value.map((row) => {
        const sliceAngle = total > 0 ? (row.value / total) * 360 : 0;
        const startAngle = currentAngle;
        const endAngle = currentAngle + sliceAngle;
        const labelAngle = startAngle + sliceAngle / 2;
        const labelPoint = pointFromAngle(center, radius + 52, labelAngle);
        currentAngle = endAngle;

        return {
            ...row,
            path: describePieSlice(center, radius, startAngle, endAngle),
            labelX: labelPoint.x,
            labelY: labelPoint.y,
            textAnchor: labelPoint.x >= center ? "start" : "end",
        };
    });
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
        const { data } = await axios.get("/api/reports/entry-registry", {
            params: entryPeriodParams(),
        });

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

const fetchAccessConsultationHistory = async () => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";

    try {
        const { data } = await axios.get("/api/reports/access-consultation/history");

        accessHistoryReport.value = {
            filters: {
                statuses: data.filters?.statuses ?? [],
                units: data.filters?.units ?? [],
                users: data.filters?.users ?? [],
            },
            summary: {
                filtered_loans: data.summary?.filtered_loans ?? 0,
                total_loans: data.summary?.total_loans ?? 0,
                users: data.summary?.users ?? 0,
                units: data.summary?.units ?? 0,
            },
            loans: data.loans ?? [],
        };

        const firstUser = accessDetailRows.value[0]?.users?.[0]?.key;
        expandedAccessUsers.value = firstUser ? new Set([firstUser]) : new Set();
    } catch (error) {
        console.error("Error al cargar historial de consultas:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el historial de consultas por usuario.";
    } finally {
        loadingRegistry.value = false;
    }
};

const fetchAccessConsultationDocuments = async () => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";

    try {
        const { data } = await axios.get("/api/reports/access-consultation/documents");

        accessDocumentsReport.value = {
            summary: {
                total_documents: data.summary?.total_documents ?? 0,
                total_consultations: data.summary?.total_consultations ?? 0,
            },
            documents: data.documents ?? [],
        };
    } catch (error) {
        console.error("Error al cargar documentos consultados:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el reporte de documentos consultados.";
    } finally {
        loadingRegistry.value = false;
    }
};

const fetchAccessConsultationResponseTimes = async () => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";

    try {
        const { data } = await axios.get("/api/reports/access-consultation/response-times");

        accessResponseTimeReport.value = {
            summary: {
                total_rows: data.summary?.total_rows ?? 0,
                average_minutes: data.summary?.average_minutes ?? 0,
                minimum_minutes: data.summary?.minimum_minutes ?? 0,
                maximum_minutes: data.summary?.maximum_minutes ?? 0,
            },
            rows: data.rows ?? [],
        };
    } catch (error) {
        console.error("Error al cargar tiempos promedio de respuesta:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el reporte de tiempos promedio de respuesta.";
    } finally {
        loadingRegistry.value = false;
    }
};

const normalizeReportMeta = (meta, fallbackPerPage) => ({
    current_page: meta?.current_page ?? 1,
    last_page: meta?.last_page ?? 1,
    per_page: meta?.per_page ?? fallbackPerPage,
    total: meta?.total ?? 0,
    from: meta?.from ?? null,
    to: meta?.to ?? null,
});

const resetDocumentFilters = () => {
    filters.unit = null;
    filters.series = null;
    filters.support = null;
    filters.search = "";
};

const resetEntryFilters = () => {
    entryFilters.mode = "date";
    entryFilters.date = null;
    entryFilters.startDate = null;
    entryFilters.endDate = null;
};

const fetchClassificationSeriesSubseries = async ({ resetPages = false } = {}) => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";

    const seriesMeta = classificationSeriesMeta.value;
    const documentsMeta = classificationDocumentsMeta.value;
    const subseriesPage = resetPages ? 1 : seriesMeta.current_page;
    const documentsPage = resetPages ? 1 : documentsMeta.current_page;

    try {
        const { data } = await axios.get("/api/reports/classification-organization/series-subseries", {
            params: {
                unit: filters.unit,
                series: filters.series,
                support: filters.support,
                search: filters.search.trim() || null,
                subseries_page: subseriesPage,
                subseries_per_page: seriesMeta.per_page ?? 5,
                documents_page: documentsPage,
                documents_per_page: documentsMeta.per_page ?? 50,
            },
        });

        classificationSeriesReport.value = {
            filters: {
                units: data.filters?.units ?? [],
                series: data.filters?.series ?? [],
                support: data.filters?.support ?? [],
            },
            summary: {
                filtered_documents: data.summary?.filtered_documents ?? 0,
                total_documents: data.summary?.total_documents ?? 0,
            },
            charts: {
                series: data.charts?.series ?? [],
            },
            series_counts: {
                data: data.series_counts?.data ?? [],
                meta: normalizeReportMeta(data.series_counts?.meta, 5),
            },
            documents: {
                data: data.documents?.data ?? [],
                meta: normalizeReportMeta(data.documents?.meta, 50),
            },
        };
    } catch (error) {
        console.error("Error al cargar series y subseries documentales:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el reporte de series y subseries documentales.";
    } finally {
        loadingRegistry.value = false;
    }
};

const fetchUnclassifiedDocuments = async ({ resetPages = false } = {}) => {
    loadingRegistry.value = true;
    registryErrorMessage.value = "";

    const documentsMeta = unclassifiedDocumentsMeta.value;
    const documentsPage = resetPages ? 1 : documentsMeta.current_page;

    try {
        const { data } = await axios.get("/api/reports/classification-organization/unclassified-documents", {
            params: {
                unit: filters.unit,
                series: filters.series,
                support: filters.support,
                search: filters.search.trim() || null,
                documents_page: documentsPage,
                documents_per_page: documentsMeta.per_page ?? 50,
            },
        });

        unclassifiedDocumentsReport.value = {
            filters: {
                units: data.filters?.units ?? [],
                series: data.filters?.series ?? [],
                support: data.filters?.support ?? [],
            },
            summary: {
                filtered_documents: data.summary?.filtered_documents ?? 0,
                total_documents: data.summary?.total_documents ?? 0,
                has_filters: Boolean(data.summary?.has_filters),
            },
            documents: {
                data: data.documents?.data ?? [],
                meta: normalizeReportMeta(data.documents?.meta, 50),
            },
        };
    } catch (error) {
        console.error("Error al cargar documentos sin clasificación:", error);
        registryErrorMessage.value =
            error.response?.data?.message ??
            "No se pudo cargar el reporte de documentos sin clasificación.";
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
        return;
    }

    if (selectedCategory.value === "access_and_consultation") {
        if (reportKey === "consultation-history") {
            await fetchAccessConsultationHistory();
            return;
        }

        if (reportKey === "most-consulted-documents") {
            await fetchAccessConsultationDocuments();
            return;
        }

        if (reportKey === "average-response-times") {
            await fetchAccessConsultationResponseTimes();
        }
    }

    if (
        selectedCategory.value === "classification_and_organization" &&
        reportKey === "documentary-series-subseries"
    ) {
        await fetchClassificationSeriesSubseries({ resetPages: true });
        return;
    }

    if (
        selectedCategory.value === "classification_and_organization" &&
        reportKey === "unclassified-documents"
    ) {
        resetDocumentFilters();
        await fetchUnclassifiedDocuments({ resetPages: true });
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
    accessResponseTimeFilters.startDate = null;
    accessResponseTimeFilters.endDate = null;
    accessDocumentFilters.analysisType = "most";
    accessDocumentFilters.startDate = null;
    accessDocumentFilters.endDate = null;
    accessFilters.status = null;
    accessFilters.unit = null;
    accessFilters.user = null;
    accessFilters.startDate = null;
    accessFilters.endDate = null;
    resetEntryFilters();
    resetDocumentFilters();
};

const queueClassificationReportFetch = () => {
    if (!isClassificationSeriesReport.value && !isUnclassifiedDocumentsReport.value) {
        return;
    }

    if (classificationSearchTimeout.value) {
        window.clearTimeout(classificationSearchTimeout.value);
    }

    classificationSearchTimeout.value = window.setTimeout(() => {
        if (isUnclassifiedDocumentsReport.value) {
            fetchUnclassifiedDocuments({ resetPages: true });
            return;
        }

        fetchClassificationSeriesSubseries({ resetPages: true });
    }, 350);
};

const changeClassificationSeriesPage = (direction) => {
    const meta = classificationSeriesMeta.value;
    const nextPage = Math.min(
        Math.max((meta.current_page ?? 1) + direction, 1),
        meta.last_page ?? 1
    );

    if (nextPage === meta.current_page) {
        return;
    }

    classificationSeriesReport.value.series_counts.meta = {
        ...meta,
        current_page: nextPage,
    };
    fetchClassificationSeriesSubseries();
};

const onClassificationDocumentsPage = (event) => {
    classificationSeriesReport.value.documents.meta = {
        ...classificationDocumentsMeta.value,
        current_page: event.page + 1,
        per_page: event.rows,
    };
    fetchClassificationSeriesSubseries();
};

const onUnclassifiedDocumentsPage = (event) => {
    unclassifiedDocumentsReport.value.documents.meta = {
        ...unclassifiedDocumentsMeta.value,
        current_page: event.page + 1,
        per_page: event.rows,
    };
    fetchUnclassifiedDocuments();
};

const paginationLabel = (meta, singular, plural) => {
    if (!meta?.total) {
        return `Sin ${plural}`;
    }

    const itemLabel = meta.total === 1 ? singular : plural;

    return `Mostrando ${meta.from ?? 0} - ${meta.to ?? 0} de ${meta.total} ${itemLabel}`;
};

const toggleAccessUserPanel = (key) => {
    const nextExpanded = new Set(expandedAccessUsers.value);

    if (nextExpanded.has(key)) {
        nextExpanded.delete(key);
    } else {
        nextExpanded.add(key);
    }

    expandedAccessUsers.value = nextExpanded;
};

const isAccessUserExpanded = (key) => expandedAccessUsers.value.has(key);

const escapeHtml = (value) =>
    String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");

const isCurrentExportDisabled = computed(() =>
    loadingRegistry.value ||
    (isUnclassifiedDocumentsReport.value
        ? unclassifiedDocuments.value.length === 0
        : isClassificationSeriesReport.value
        ? classificationDocuments.value.length === 0
        : isAccessResponseTimeReport.value
        ? visibleAccessResponseTimeRows.value.length === 0
        : isAccessDocumentsReport.value
        ? accessDocumentTableRows.value.length === 0
        : isAccessHistoryReport.value
        ? visibleAccessLoans.value.length === 0
        : visibleDocuments.value.length === 0)
);

const exportCurrentTable = () => {
    if (isUnclassifiedDocumentsReport.value) {
        const rows = unclassifiedDocuments.value
            .map((document) => `
                <tr>
                    <td>${escapeHtml(document.code)}</td>
                    <td>${escapeHtml(document.name)}</td>
                    <td>${escapeHtml(document.unit)}</td>
                    <td>${escapeHtml(document.date_label)}</td>
                    <td>${escapeHtml(document.box)}</td>
                </tr>`)
            .join("");

        const workbook = `
            <html>
                <head>
                    <meta charset="UTF-8" />
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                        th { background: #0b4f9c; color: #ffffff; font-weight: 700; }
                        th, td { border: 1px solid #d9e2ef; padding: 7px 9px; text-align: left; }
                        h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <h1>Documentos sin Clasificación</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Unidad</th>
                                <th>Fecha</th>
                                <th>Caja</th>
                            </tr>
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

        link.href = url;
        link.download = "documentos-sin-clasificacion.xls";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        return;
    }

    if (isClassificationSeriesReport.value) {
        const rows = classificationDocuments.value
            .map((document) => `
                <tr>
                    <td>${escapeHtml(document.code)}</td>
                    <td>${escapeHtml(document.name)}</td>
                    <td>${escapeHtml(document.series)}</td>
                    <td>${escapeHtml(document.unit)}</td>
                    <td>${escapeHtml(document.date_label)}</td>
                    <td>${escapeHtml(document.support)}</td>
                </tr>`)
            .join("");

        const workbook = `
            <html>
                <head>
                    <meta charset="UTF-8" />
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                        th { background: #0b4f9c; color: #ffffff; font-weight: 700; }
                        th, td { border: 1px solid #d9e2ef; padding: 7px 9px; text-align: left; }
                        h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <h1>${escapeHtml(chartTitle.value)}</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Serie</th>
                                <th>Unidad</th>
                                <th>Fecha</th>
                                <th>Soporte</th>
                            </tr>
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

        link.href = url;
        link.download = "series-y-subseries-documentales.xls";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        return;
    }

    if (isAccessResponseTimeReport.value) {
        const rows = visibleAccessResponseTimeRows.value
            .map((row, index) => `
                <tr>
                    <td>#${index + 1}</td>
                    <td>${escapeHtml(row.request)}</td>
                    <td>${escapeHtml(row.document)}</td>
                    <td>${escapeHtml(row.started_at_label)}</td>
                    <td>${escapeHtml(row.completed_at_label)}</td>
                    <td>${escapeHtml(row.minutes)} min</td>
                </tr>`)
            .join("");

        const workbook = `
            <html>
                <head>
                    <meta charset="UTF-8" />
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                        th { background: #0b4f9c; color: #ffffff; font-weight: 700; }
                        th, td { border: 1px solid #d9e2ef; padding: 7px 9px; text-align: left; }
                        h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <h1>${escapeHtml(chartTitle.value)}</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Solicitud</th>
                                <th>Código</th>
                                <th>Documento</th>
                                <th>Inicio Búsqueda</th>
                                <th>Fin Búsqueda</th>
                                <th>Tiempo</th>
                            </tr>
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

        link.href = url;
        link.download = "tiempos-promedio-de-respuesta.xls";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        return;
    }

    if (isAccessDocumentsReport.value) {
        const rows = accessDocumentTableRows.value
            .map((document) => `
                <tr>
                    <td>${escapeHtml(document.title)}</td>
                    <td>${escapeHtml(document.series)}</td>
                    <td>${escapeHtml(document.total)}</td>
                    <td>${escapeHtml(document.consultations.map((item) => item.loan_number).join(", "))}</td>
                    <td>${escapeHtml(document.consultations.map((item) => item.date_label).join(", "))}</td>
                </tr>`)
            .join("");

        const workbook = `
            <html>
                <head>
                    <meta charset="UTF-8" />
                    <style>
                        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                        th { background: #0b4f9c; color: #ffffff; font-weight: 700; }
                        th, td { border: 1px solid #d9e2ef; padding: 7px 9px; text-align: left; }
                        h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <h1>${escapeHtml(chartTitle.value)}</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Serie</th>
                                <th>Consultas</th>
                                <th>Prestamos</th>
                                <th>Fechas</th>
                            </tr>
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

        link.href = url;
        link.download = "documentos-mas-y-menos-consultados.xls";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        return;
    }

    if (isAccessHistoryReport.value) {
        const rows = visibleAccessLoans.value
            .flatMap((loan) =>
                (loan.documents ?? []).map((document) => `
                    <tr>
                        <td>${escapeHtml(loan.number)}</td>
                        <td>${escapeHtml(loan.user)}</td>
                        <td>${escapeHtml(loan.unit)}</td>
                        <td>${escapeHtml(loan.status)}</td>
                        <td>${escapeHtml(loan.requested_at_label)}</td>
                        <td>${escapeHtml(document.title)}</td>
                        <td>${escapeHtml(document.date_label)}</td>
                        <td>${escapeHtml(document.status)}</td>
                    </tr>`)
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
                        h1 { color: #10213d; font-family: Arial, sans-serif; font-size: 18px; }
                    </style>
                </head>
                <body>
                    <h1>${escapeHtml(chartTitle.value)}</h1>
                    <table>
                        <thead>
                            <tr>
                                <th>Préstamo</th>
                                <th>Usuario</th>
                                <th>Unidad</th>
                                <th>Estado</th>
                                <th>Fecha solicitud</th>
                                <th>Documento</th>
                                <th>Fecha</th>
                                <th>Estado documento</th>
                            </tr>
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

        link.href = url;
        link.download = "historial-de-consultas-por-usuario.xls";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        return;
    }

    const columns = [
        ["Código", "code"],
        ["Nombre", "name"],
        ["Serie", "series"],
        ["Unidad", "unit"],
        ["Usuario que ingreso", "entered_by"],
        ["Fecha", "date_label"],
        ["Soporte", "support"],
        ["Año documental", "year"],
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
        if (filters.series && !activeSeriesOptions.value.some((option) => option.value === filters.series)) {
            filters.series = null;
        }
    }
);

watch(
    () => [filters.unit, filters.series, filters.support, filters.search],
    () => queueClassificationReportFetch()
);

watch(
    () => [entryFilters.mode, entryFilters.date, entryFilters.startDate, entryFilters.endDate],
    () => {
        if (isEntryReport.value) {
            fetchEntryRegistryReport();
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
                        :class="{ 'reports-tile--catalog-only': report.hasDashboard === false }"
                        :aria-disabled="report.hasDashboard === false"
                        :disabled="report.hasDashboard === false"
                        @click="report.hasDashboard === false ? null : openReport(report.key)"
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
                    :disabled="isCurrentExportDisabled"
                    @click="exportCurrentTable"
                />
            </div>

            <article class="reports-filter-panel">
                <div class="reports-filter-panel__heading">
                    <div>
                        <i class="pi pi-filter"></i>
                        <strong>Filtros dinámicos</strong>
                        <span v-if="hasActiveFilters" class="reports-status-chip">Activos</span>
                    </div>

                    <button
                        type="button"
                        class="reports-clear-button"
                        :disabled="!hasActiveFilters"
                        @click="clearFilters"
                    >
                        <i class="pi pi-times"></i>
                        <span>Limpiar</span>
                    </button>
                </div>

                <div
                    v-if="isAccessResponseTimeReport"
                    class="reports-filter-grid reports-filter-grid--response-time"
                >
                    <label>
                        <span>Fecha Inicio</span>
                        <DatePicker
                            v-model="accessResponseTimeFilters.startDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Fecha Fin</span>
                        <DatePicker
                            v-model="accessResponseTimeFilters.endDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>
                </div>

                <div
                    v-else-if="isAccessDocumentsReport"
                    class="reports-filter-grid reports-filter-grid--documents"
                >
                    <label>
                        <span>Tipo de Análisis</span>
                        <Dropdown
                            v-model="accessDocumentFilters.analysisType"
                            :options="accessDocumentAnalysisOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Más Consultados"
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Fecha Inicio</span>
                        <DatePicker
                            v-model="accessDocumentFilters.startDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Fecha Fin</span>
                        <DatePicker
                            v-model="accessDocumentFilters.endDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>
                </div>

                <div
                    v-else-if="isAccessHistoryReport"
                    class="reports-filter-grid reports-filter-grid--access"
                >
                    <label>
                        <span>Estado de Solicitud</span>
                        <Dropdown
                            v-model="accessFilters.status"
                            :options="accessStatusOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todos los estados"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Unidad Productora</span>
                        <Dropdown
                            v-model="accessFilters.unit"
                            :options="accessUnitOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todas"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Usuario</span>
                        <Dropdown
                            v-model="accessFilters.user"
                            :options="accessUserOptions"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Todos"
                            showClear
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Fecha Inicio</span>
                        <DatePicker
                            v-model="accessFilters.startDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>

                    <label>
                        <span>Fecha Fin</span>
                        <DatePicker
                            v-model="accessFilters.endDate"
                            dateFormat="dd/mm/yy"
                            placeholder="dd/mm/aaaa"
                            class="w-full"
                        />
                    </label>
                </div>

                <div v-else class="reports-filter-grid reports-filter-grid--dynamic">
                    <template v-if="isEntryReport">
                        <label>
                            <span>Tipo de periodo</span>
                            <Dropdown
                                v-model="entryFilters.mode"
                                :options="entryPeriodModeOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full"
                            />
                        </label>

                        <label v-if="entryFilters.mode === 'date'">
                            <span>Fecha específica</span>
                            <DatePicker
                                v-model="entryFilters.date"
                                dateFormat="dd/mm/yy"
                                placeholder="dd/mm/aaaa"
                                showIcon
                                iconDisplay="input"
                                :manualInput="false"
                                class="w-full reports-datepicker"
                            />
                        </label>

                        <label v-else>
                            <span>Fecha inicio</span>
                            <DatePicker
                                v-model="entryFilters.startDate"
                                dateFormat="dd/mm/yy"
                                placeholder="dd/mm/aaaa"
                                showIcon
                                iconDisplay="input"
                                :manualInput="false"
                                class="w-full reports-datepicker"
                            />
                        </label>

                        <label v-if="entryFilters.mode === 'range'">
                            <span>Fecha fin</span>
                            <DatePicker
                                v-model="entryFilters.endDate"
                                dateFormat="dd/mm/yy"
                                placeholder="dd/mm/aaaa"
                                showIcon
                                iconDisplay="input"
                                :manualInput="false"
                                class="w-full reports-datepicker"
                            />
                        </label>
                    </template>

                    <label>
                        <span>Unidad Productora</span>
                        <Dropdown
                            v-model="filters.unit"
                            :options="activeUnitOptions"
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
                            :options="activeSeriesOptions"
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
                            :options="activeSupportOptions"
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

                <div v-if="registryErrorMessage" class="reports-message reports-message--error">
                    <i class="pi pi-exclamation-triangle"></i>
                    <span>{{ registryErrorMessage }}</span>
                </div>

                <div class="reports-filter-panel__footer">
                    Mostrando
                    <strong>
                        {{
                            isClassificationSeriesReport || isUnclassifiedDocumentsReport
                                ? filteredClassificationDocumentsCount
                                : isAccessResponseTimeReport
                                ? filteredAccessResponseTimeCount
                                : isAccessDocumentsReport
                                ? filteredAccessDocumentsCount
                                : isAccessHistoryReport
                                    ? filteredAccessLoansCount
                                    : filteredDocumentsCount
                        }}
                    </strong>
                    de
                    <strong>
                        {{
                            isClassificationSeriesReport || isUnclassifiedDocumentsReport
                                ? reportTotalClassificationDocumentsCount
                                : isAccessResponseTimeReport
                                ? reportTotalAccessResponseTimeCount
                                : isAccessDocumentsReport
                                ? reportTotalAccessDocumentsCount
                                : isAccessHistoryReport
                                    ? reportTotalAccessLoansCount
                                    : reportTotalDocumentsCount
                        }}
                    </strong>
                    {{ isAccessHistoryReport ? 'préstamos' : 'documentos' }}
                </div>
            </article>

            <article v-if="!isUnclassifiedDocumentsReport" class="reports-chart-panel">
                <div class="reports-panel-title">
                    <h2 class="m-0">{{ chartTitle }}</h2>
                    <span v-if="loadingRegistry">
                        <i class="pi pi-spin pi-spinner"></i>
                        Actualizando
                    </span>
                </div>

                <div v-if="isClassificationSeriesReport" class="reports-classification-pie">
                    <div class="reports-classification-pie__figure">
                        <svg
                            v-if="classificationSeriesPieSegments.length > 0"
                            viewBox="0 0 380 380"
                            role="img"
                            aria-label="Series y subseries documentales"
                        >
                            <path
                                v-for="segment in classificationSeriesPieSegments"
                                :key="segment.label"
                                :d="segment.path"
                                :fill="segment.color"
                                class="reports-classification-pie__slice"
                            />
                            <circle cx="190" cy="190" r="76" class="reports-classification-pie__center" />
                            <text x="190" y="184" text-anchor="middle" class="reports-classification-pie__total">
                                {{ classificationSeriesPieTotal }}
                            </text>
                            <text x="190" y="206" text-anchor="middle" class="reports-classification-pie__caption">
                                documentos
                            </text>
                        </svg>
                    </div>

                    <div class="reports-classification-pie__legend">
                        <div
                            v-for="segment in classificationSeriesPieSegments"
                            :key="`legend-${segment.label}`"
                            class="reports-classification-pie__legend-row"
                        >
                            <span
                                class="reports-classification-pie__swatch"
                                :style="{ backgroundColor: segment.color }"
                            ></span>
                            <span>
                                <strong>{{ segment.label }}</strong>
                                <small>{{ segment.documents_count }} docs | {{ segment.percentage }}%</small>
                            </span>
                        </div>
                    </div>

                    <p v-if="classificationSeriesPieSegments.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <div v-else-if="isAccessResponseTimeReport" class="reports-response-time-chart">
                    <svg viewBox="0 0 1000 300" role="img" aria-label="Tiempos promedio de respuesta">
                        <line
                            v-for="tick in accessResponseTimeTicks"
                            :key="tick.value"
                            x1="54"
                            x2="982"
                            :y1="tick.y"
                            :y2="tick.y"
                            class="reports-chart__grid"
                        />
                        <text
                            v-for="tick in accessResponseTimeTicks"
                            :key="`response-label-${tick.value}`"
                            x="42"
                            :y="tick.y + 4"
                            text-anchor="end"
                            class="reports-chart__label"
                        >
                            {{ tick.value }}
                        </text>
                        <text
                            x="12"
                            y="150"
                            transform="rotate(-90 12 150)"
                            text-anchor="middle"
                            class="reports-chart__label"
                        >
                            Tiempo (min)
                        </text>
                        <path :d="accessResponseTimeLinePath" class="reports-response-time-chart__line" />
                        <g
                            v-for="point in accessResponseTimeChartPoints"
                            :key="point.id"
                        >
                            <circle
                                :cx="point.x"
                                :cy="point.y"
                                r="4"
                                class="reports-response-time-chart__point"
                            />
                            <text
                                :x="point.x"
                                y="286"
                                text-anchor="middle"
                                class="reports-chart__label"
                            >
                                {{ point.label }}
                            </text>
                        </g>
                    </svg>

                    <p v-if="accessResponseTimeChartPoints.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <div v-else-if="isAccessDocumentsReport" class="reports-document-consultation-chart">
                    <div class="reports-document-consultation-chart__plot">
                        <div class="reports-document-consultation-chart__labels">
                            <span
                                v-for="row in accessDocumentRows"
                                :key="`label-${row.key}`"
                            >
                                {{ row.title }}
                            </span>
                        </div>

                        <div class="reports-document-consultation-chart__body">
                            <div class="reports-document-consultation-chart__grid">
                                <span
                                    v-for="tick in accessDocumentChartTicks"
                                    :key="`doc-grid-${tick}`"
                                ></span>
                            </div>

                            <div
                                v-for="row in accessDocumentRows"
                                :key="row.key"
                                class="reports-document-consultation-chart__row"
                            >
                                <span
                                    class="reports-document-consultation-chart__bar"
                                    :style="{ width: `${Math.max((row.total / accessDocumentChartMax) * 100, 6)}%` }"
                                >
                                    <strong>{{ row.total }}</strong>
                                </span>
                            </div>

                            <div class="reports-document-consultation-chart__axis">
                                <span
                                    v-for="tick in accessDocumentChartTicks"
                                    :key="`doc-tick-${tick}`"
                                >
                                    {{ tick }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <p v-if="accessDocumentRows.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <div v-else-if="isAccessHistoryReport" class="reports-access-chart">
                    <div class="reports-access-chart__plot">
                        <div class="reports-access-chart__axis">
                            <span
                                v-for="tick in accessChartTicks.slice().reverse()"
                                :key="tick"
                            >
                                {{ tick }}
                            </span>
                        </div>

                        <div class="reports-access-chart__grid">
                            <span v-for="tick in accessChartTicks" :key="`access-grid-${tick}`"></span>
                        </div>

                        <div class="reports-access-chart__bars">
                            <div
                                v-for="row in accessChartRows"
                                :key="row.label"
                                class="reports-access-chart__bar-wrap"
                            >
                                <div class="reports-access-chart__bar-shell">
                                    <span
                                        class="reports-access-chart__bar"
                                        :style="{ height: `${Math.max((row.total / accessChartMax) * 100, 8)}%` }"
                                    >
                                        <strong>{{ row.total }}</strong>
                                    </span>
                                </div>
                                <small>{{ row.label }}</small>
                            </div>
                        </div>
                    </div>

                    <p v-if="accessChartRows.length === 0" class="reports-empty-text m-0">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <div v-else-if="currentReport.key === 'period'" class="reports-chart">
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

                <div v-else-if="currentReport.key === 'support'" class="reports-support-chart">
                    <svg
                        v-if="supportPieSegments.length > 0"
                        viewBox="0 0 380 380"
                        role="img"
                        aria-label="Documentos por soporte"
                    >
                        <g>
                            <path
                                v-for="segment in supportPieSegments"
                                :key="segment.label"
                                :d="segment.path"
                                :fill="segment.color"
                                class="reports-support-chart__slice"
                            />
                        </g>
                        <text
                            v-for="segment in supportPieSegments"
                            :key="`label-${segment.label}`"
                            :x="segment.labelX"
                            :y="segment.labelY"
                            :text-anchor="segment.textAnchor"
                            class="reports-support-chart__label"
                        >
                            {{ segment.label }}: {{ segment.value }} ({{ segment.percentage }}%)
                        </text>
                    </svg>

                    <p v-if="supportPieSegments.length === 0" class="reports-empty-text m-0">
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

            <template v-if="isClassificationSeriesReport">
                <article class="reports-classification-count-panel">
                    <div class="reports-panel-title">
                        <h2 class="m-0">Series y Subseries Documentales - Conteo de Documentos</h2>
                        <span>{{ paginationLabel(classificationSeriesMeta, 'clasificacion', 'clasificaciones') }}</span>
                    </div>

                    <div class="reports-classification-count-list">
                        <section
                            v-for="row in classificationSeriesCountRows"
                            :key="row.label"
                            class="reports-classification-count-card"
                        >
                            <span>
                                <strong>{{ row.label }}</strong>
                                <small>{{ row.documents_label }} | {{ row.units_label }}</small>
                            </span>
                            <strong class="reports-classification-count-card__badge">
                                {{ row.documents_count }}
                                <small>docs</small>
                            </strong>
                        </section>

                        <p v-if="classificationSeriesCountRows.length === 0" class="reports-empty-text m-0">
                            No hay series o subseries con los filtros actuales.
                        </p>
                    </div>

                    <div
                        v-if="classificationSeriesMeta.total > classificationSeriesMeta.per_page"
                        class="reports-pagination"
                    >
                        <button
                            type="button"
                            class="reports-pagination__button"
                            :disabled="classificationSeriesMeta.current_page <= 1 || loadingRegistry"
                            @click="changeClassificationSeriesPage(-1)"
                        >
                            <i class="pi pi-chevron-left"></i>
                            <span>Anterior</span>
                        </button>
                        <span>
                            Página {{ classificationSeriesMeta.current_page }} de {{ classificationSeriesMeta.last_page }}
                        </span>
                        <button
                            type="button"
                            class="reports-pagination__button"
                            :disabled="classificationSeriesMeta.current_page >= classificationSeriesMeta.last_page || loadingRegistry"
                            @click="changeClassificationSeriesPage(1)"
                        >
                            <span>Siguiente</span>
                            <i class="pi pi-chevron-right"></i>
                        </button>
                    </div>
                </article>

                <article class="reports-table-panel">
                    <div class="reports-panel-title">
                        <h2 class="m-0">Listado de Documentos Filtrados</h2>
                        <span>{{ paginationLabel(classificationDocumentsMeta, 'documento', 'documentos') }}</span>
                    </div>

                    <DataTable
                        :value="classificationDocuments"
                        :loading="loadingRegistry"
                        :paginator="true"
                        lazy
                        :first="classificationDocumentsFirst"
                        :rows="classificationDocumentsMeta.per_page"
                        :totalRecords="classificationDocumentsMeta.total"
                        :rowsPerPageOptions="rowsPerPageOptions"
                        responsiveLayout="scroll"
                        stripedRows
                        class="reports-table"
                        @page="onClassificationDocumentsPage"
                    >
                        <Column field="code" header="Código" style="min-width: 10rem" />
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
                </article>
            </template>

            <article v-else-if="isUnclassifiedDocumentsReport" class="reports-table-panel">
                <div class="reports-panel-title">
                    <h2 class="m-0">
                        Documentos sin Clasificación ({{ unclassifiedDocumentsMeta.total }})
                    </h2>
                    <span>{{ paginationLabel(unclassifiedDocumentsMeta, 'documento', 'documentos') }}</span>
                </div>

                <DataTable
                    :value="unclassifiedDocuments"
                    :loading="loadingRegistry"
                    :paginator="true"
                    lazy
                    :first="unclassifiedDocumentsFirst"
                    :rows="unclassifiedDocumentsMeta.per_page"
                    :totalRecords="unclassifiedDocumentsMeta.total"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    class="reports-table"
                    @page="onUnclassifiedDocumentsPage"
                >
                    <Column field="code" header="Código" style="min-width: 10rem" />
                    <Column field="name" header="Nombre" style="min-width: 18rem" />
                    <Column field="unit" header="Unidad" style="min-width: 15rem" />
                    <Column field="date_label" header="Fecha" style="min-width: 12rem" />
                    <Column field="box" header="Caja" style="min-width: 12rem" />
                    <template #empty>
                        <div class="reports-empty-text">
                            {{
                                unclassifiedDocumentsReport.summary.has_filters
                                    ? 'No hay documentos con los filtros actuales.'
                                    : 'Selecciona al menos un filtro para consultar documentos.'
                            }}
                        </div>
                    </template>
                </DataTable>
            </article>

            <template v-else-if="isAccessResponseTimeReport">
                <article class="reports-response-summary-panel">
                    <h2 class="reports-response-summary-panel__title m-0">
                        Tiempos de Localización de Documentos
                    </h2>

                    <div class="reports-response-summary-grid">
                        <div class="reports-response-summary-card reports-response-summary-card--average">
                            <span>Tiempo Promedio General</span>
                            <strong>{{ accessResponseTimeSummary.average }} min</strong>
                        </div>
                        <div class="reports-response-summary-card reports-response-summary-card--minimum">
                            <span>Tiempo Mínimo</span>
                            <strong>{{ accessResponseTimeSummary.minimum }} min</strong>
                        </div>
                        <div class="reports-response-summary-card reports-response-summary-card--maximum">
                            <span>Tiempo Máximo</span>
                            <strong>{{ accessResponseTimeSummary.maximum }} min</strong>
                        </div>
                    </div>
                </article>

                <article class="reports-table-panel">
                    <div class="reports-panel-title">
                        <h2 class="m-0">Detalle de Tiempos de Búsqueda</h2>
                        <span>{{ visibleAccessResponseTimeRows.length }} registros</span>
                    </div>

                    <DataTable
                        :value="visibleAccessResponseTimeRows"
                        :loading="loadingRegistry"
                        :paginator="true"
                        :rows="5"
                        :rowsPerPageOptions="rowsPerPageOptions"
                        responsiveLayout="scroll"
                        stripedRows
                        class="reports-table"
                    >
                        <Column header="Solicitud" style="min-width: 8rem">
                            <template #body="{ index }">#{{ index + 1 }}</template>
                        </Column>
                        <Column field="document" header="Documento" style="min-width: 18rem" />
                        <Column field="started_at_label" header="Inicio Búsqueda" style="min-width: 11rem" />
                        <Column field="completed_at_label" header="Fin Búsqueda" style="min-width: 11rem" />
                        <Column header="Tiempo" style="min-width: 8rem">
                            <template #body="{ data }">
                                <span
                                    class="reports-response-time-tag"
                                    :class="{
                                        'reports-response-time-tag--slow': data.minutes >= accessResponseTimeSummary.maximum,
                                        'reports-response-time-tag--fast': data.minutes <= accessResponseTimeSummary.minimum,
                                    }"
                                >
                                    {{ data.minutes }} min
                                </span>
                            </template>
                        </Column>
                        <template #empty>
                            <div class="reports-empty-text">No hay tiempos con los filtros actuales.</div>
                        </template>
                    </DataTable>
                </article>
            </template>

            <article v-else-if="isAccessDocumentsReport" class="reports-table-panel">
                <div class="reports-panel-title">
                    <h2 class="m-0">Listado de Documentos Consultados</h2>
                    <span>{{ accessDocumentTableRows.length }} registros</span>
                </div>

                <DataTable
                    :value="accessDocumentTableRows"
                    :loading="loadingRegistry"
                    :paginator="true"
                    :rows="5"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    class="reports-table"
                >
                    <Column field="title" header="Documento" style="min-width: 18rem" />
                    <Column field="series" header="Serie" style="min-width: 16rem" />
                    <Column field="total" header="Consultas" style="min-width: 8rem" />
                    <template #empty>
                        <div class="reports-empty-text">No hay documentos con los filtros actuales.</div>
                    </template>
                </DataTable>
            </article>

            <article v-else-if="isAccessHistoryReport" class="reports-access-detail-panel">
                <h2 class="reports-access-detail-panel__title m-0">
                    Detalle de Préstamos por Usuario
                </h2>

                <div class="reports-access-units">
                    <section
                        v-for="unit in accessDetailRows"
                        :key="unit.key"
                        class="reports-access-unit"
                    >
                        <header class="reports-access-unit__header">
                            {{ unit.label }}
                        </header>

                        <div class="reports-access-user-list">
                            <div
                                v-for="user in unit.users"
                                :key="user.key"
                                class="reports-access-user-panel"
                            >
                                <button
                                    type="button"
                                    class="reports-access-user-panel__summary"
                                    @click="toggleAccessUserPanel(user.key)"
                                >
                                    <span class="reports-access-user-panel__avatar">
                                        {{ user.initials }}
                                    </span>
                                    <span class="reports-access-user-panel__identity">
                                        <strong>{{ user.name }}</strong>
                                        <small>{{ user.unit }}</small>
                                    </span>
                                    <span class="reports-access-user-panel__count">
                                        <strong>{{ user.total }}</strong>
                                        <small>{{ user.total === 1 ? 'préstamo' : 'préstamos' }}</small>
                                    </span>
                                    <i
                                        class="pi"
                                        :class="isAccessUserExpanded(user.key) ? 'pi-chevron-up' : 'pi-chevron-down'"
                                    ></i>
                                </button>

                                <div
                                    v-if="isAccessUserExpanded(user.key)"
                                    class="reports-access-user-panel__content"
                                >
                                    <h3 class="m-0">Documentos Prestados</h3>

                                    <div
                                        v-for="document in user.documents"
                                        :key="`${user.key}-${document.loan_number}-${document.id}`"
                                        class="reports-access-document"
                                    >
                                        <span>
                                            <strong>{{ document.title }}</strong>
                                            <small>Fecha: {{ document.date_label }}</small>
                                        </span>
                                        <span class="reports-access-document__status">
                                            {{ document.status }}
                                        </span>
                                    </div>

                                    <p v-if="user.documents.length === 0" class="reports-empty-text m-0">
                                        No hay documentos prestados registrados para este usuario.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <p v-if="accessDetailRows.length === 0" class="reports-empty-text m-0">
                        No hay préstamos con los filtros actuales.
                    </p>
                </div>
            </article>

            <article v-else-if="isUnitsReport" class="reports-unit-distribution-panel">
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
                    :paginator="true"
                    :rows="5"
                    :rowsPerPageOptions="rowsPerPageOptions"
                    responsiveLayout="scroll"
                    stripedRows
                    class="reports-table"
                >
                        <Column field="code" header="Código" style="min-width: 10rem" />
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
.reports-access-detail-panel,
.reports-classification-count-panel,
.reports-response-summary-panel,
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

.reports-tile--catalog-only {
    cursor: default;
    opacity: 1;
}

.reports-tile--catalog-only:hover {
    background: #fbfcfe;
    border-color: #d9e2ef;
    box-shadow: none;
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
.reports-access-detail-panel,
.reports-classification-count-panel,
.reports-response-summary-panel,
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

.reports-filter-grid--access {
    grid-template-columns: repeat(4, minmax(12rem, 1fr));
}

.reports-filter-grid--documents {
    grid-template-columns: minmax(12rem, 18rem) minmax(12rem, 18rem) minmax(12rem, 18rem);
}

.reports-filter-grid--response-time {
    grid-template-columns: minmax(12rem, 18rem) minmax(12rem, 18rem);
}

.reports-filter-grid label {
    color: #667085;
    display: flex;
    flex-direction: column;
    font-size: 0.82rem;
    gap: 0.45rem;
    min-width: 0;
}

.reports-filter-grid :deep(.p-datepicker),
.reports-filter-grid :deep(.p-datepicker-input),
.reports-filter-grid :deep(.reports-datepicker .p-inputtext) {
    min-width: 0;
    width: 100%;
}

.reports-filter-panel > .reports-message {
    margin-top: 1rem;
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

.reports-response-time-chart {
    min-height: 360px;
    padding: 0.5rem 0 0;
}

.reports-response-time-chart svg {
    display: block;
    height: 330px;
    width: 100%;
}

.reports-response-time-chart__line {
    fill: none;
    stroke: #0b4f9c;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2.2;
}

.reports-response-time-chart__point {
    fill: #2167b8;
    stroke: #0b4f9c;
    stroke-width: 2;
}

.reports-classification-pie {
    align-items: center;
    display: grid;
    gap: 1.5rem;
    grid-template-columns: minmax(18rem, 26rem) 1fr;
    min-height: 360px;
}

.reports-classification-pie__figure {
    align-items: center;
    display: flex;
    justify-content: center;
}

.reports-classification-pie__figure svg {
    display: block;
    height: min(360px, 64vw);
    max-height: 360px;
    width: min(100%, 360px);
}

.reports-classification-pie__slice {
    stroke: #ffffff;
    stroke-width: 1.4;
}

.reports-classification-pie__center {
    fill: #ffffff;
    stroke: #d9e2ef;
    stroke-width: 1;
}

.reports-classification-pie__total {
    fill: #061b3a;
    font-size: 2.1rem;
    font-weight: 700;
}

.reports-classification-pie__caption {
    fill: #667085;
    font-size: 0.82rem;
}

.reports-classification-pie__legend {
    display: grid;
    gap: 0.75rem;
}

.reports-classification-pie__legend-row {
    align-items: center;
    background: #fbfcfe;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: auto 1fr;
    min-height: 3.4rem;
    padding: 0.7rem 0.85rem;
}

.reports-classification-pie__swatch {
    border-radius: 999px;
    display: inline-flex;
    height: 0.8rem;
    width: 0.8rem;
}

.reports-classification-pie__legend-row span:last-child {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    min-width: 0;
}

.reports-classification-pie__legend-row strong {
    color: #061b3a;
    font-size: 0.88rem;
    overflow-wrap: anywhere;
}

.reports-classification-pie__legend-row small {
    color: #667085;
    font-size: 0.78rem;
}

.reports-support-chart {
    align-items: center;
    display: flex;
    justify-content: center;
    min-height: 390px;
    padding: 0.5rem 0 1rem;
}

.reports-support-chart svg {
    display: block;
    height: min(390px, 64vw);
    max-height: 390px;
    max-width: 44rem;
    width: 100%;
}

.reports-support-chart__slice {
    stroke: #ffffff;
    stroke-width: 1.2;
}

.reports-support-chart__label {
    fill: #0b4f9c;
    font-size: 14px;
    font-weight: 500;
}

.reports-access-chart {
    min-height: 360px;
    padding: 0.5rem 0 0;
}

.reports-access-chart__plot {
    display: grid;
    grid-template-columns: 3rem 1fr;
    min-height: 340px;
    position: relative;
}

.reports-access-chart__plot::after {
    border-bottom: 1px solid #94a3b8;
    bottom: 4.9rem;
    content: "";
    left: 3rem;
    position: absolute;
    right: 0;
    z-index: 2;
}

.reports-access-chart__axis {
    color: #667085;
    display: flex;
    flex-direction: column;
    font-size: 0.8rem;
    justify-content: space-between;
    padding: 0.1rem 0.55rem 4.9rem 0;
    text-align: right;
}

.reports-access-chart__grid {
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

.reports-access-chart__grid span {
    border-top: 1px dashed #dfe7f1;
    display: block;
}

.reports-access-chart__bars {
    align-items: end;
    display: grid;
    gap: 1.15rem;
    grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
    min-height: 310px;
    padding: 0 1rem 0;
    position: relative;
    z-index: 1;
}

.reports-access-chart__bar-wrap {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    height: 100%;
    justify-content: flex-end;
    min-width: 0;
}

.reports-access-chart__bar-shell {
    align-items: end;
    display: flex;
    height: 235px;
    justify-content: center;
    width: 100%;
}

.reports-access-chart__bar {
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

.reports-access-chart__bar strong {
    color: #ffffff;
    font-size: 0.9rem;
}

.reports-access-chart__bar-wrap small {
    color: #667085;
    font-size: 0.78rem;
    line-height: 1.25;
    min-height: 4.2rem;
    overflow-wrap: anywhere;
    text-align: center;
    white-space: normal;
    width: min(100%, 13rem);
}

.reports-document-consultation-chart {
    min-height: 350px;
    overflow-x: auto;
    padding: 0.7rem 0 0.4rem;
}

.reports-document-consultation-chart__plot {
    display: grid;
    grid-template-columns: minmax(12rem, 14rem) 1fr;
    min-width: 48rem;
}

.reports-document-consultation-chart__labels {
    display: grid;
    gap: 0.75rem;
    padding: 0.6rem 0.6rem 2.45rem 0;
}

.reports-document-consultation-chart__labels span {
    align-items: center;
    color: #536173;
    display: flex;
    font-size: 0.78rem;
    justify-content: flex-end;
    line-height: 1.2;
    min-height: 3.3rem;
    overflow-wrap: anywhere;
    text-align: right;
}

.reports-document-consultation-chart__body {
    border-bottom: 1px solid #94a3b8;
    display: grid;
    gap: 0.75rem;
    padding: 0.6rem 0 2.45rem;
    position: relative;
}

.reports-document-consultation-chart__grid {
    bottom: 2.45rem;
    display: flex;
    justify-content: space-between;
    left: 0;
    pointer-events: none;
    position: absolute;
    right: 0;
    top: 0;
}

.reports-document-consultation-chart__grid span {
    border-left: 1px dashed #dfe7f1;
    display: block;
}

.reports-document-consultation-chart__row {
    align-items: center;
    display: flex;
    min-height: 3.3rem;
    position: relative;
    z-index: 1;
}

.reports-document-consultation-chart__bar {
    align-items: center;
    background: #2167b8;
    border-radius: 0 7px 7px 0;
    color: #ffffff;
    display: flex;
    height: 3rem;
    justify-content: flex-end;
    min-width: 2rem;
    padding-right: 0.7rem;
}

.reports-document-consultation-chart__bar strong {
    color: #ffffff;
    font-size: 0.86rem;
}

.reports-document-consultation-chart__axis {
    bottom: 0;
    color: #536173;
    display: flex;
    font-size: 0.78rem;
    justify-content: space-between;
    left: 0;
    position: absolute;
    right: 0;
}

.reports-response-summary-panel__title {
    color: #061b3a;
    font-size: 1.08rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
}

.reports-response-summary-grid {
    display: grid;
    gap: 1.5rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.reports-response-summary-card {
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    min-height: 6.1rem;
    padding: 1.05rem 1.2rem;
}

.reports-response-summary-card span {
    font-size: 0.84rem;
    font-weight: 500;
}

.reports-response-summary-card strong {
    font-size: 2rem;
    line-height: 1;
}

.reports-response-summary-card--average {
    background: #eef5ff;
    color: #1d4ed8;
}

.reports-response-summary-card--minimum {
    background: #ecfdf3;
    color: #047857;
}

.reports-response-summary-card--maximum {
    background: #fffbeb;
    color: #92400e;
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

.reports-classification-count-list {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}

.reports-classification-count-card {
    align-items: center;
    background: #fbfcfe;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    min-height: 5.7rem;
    padding: 1rem;
}

.reports-classification-count-card > span {
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
    min-width: 0;
}

.reports-classification-count-card > span strong {
    color: #061b3a;
    font-size: 0.98rem;
}

.reports-classification-count-card > span small {
    color: #536173;
    font-size: 0.82rem;
}

.reports-classification-count-card__badge {
    align-items: center;
    background: #0b4f9c;
    border-radius: 999px;
    color: #ffffff;
    display: inline-flex;
    flex-direction: column;
    flex-shrink: 0;
    font-size: 1.25rem;
    height: 4rem;
    justify-content: center;
    line-height: 1;
    width: 4rem;
}

.reports-classification-count-card__badge small {
    color: #ffffff;
    font-size: 0.58rem;
    font-weight: 700;
    margin-top: 0.25rem;
}

.reports-pagination {
    align-items: center;
    color: #536173;
    display: flex;
    font-size: 0.88rem;
    gap: 0.9rem;
    justify-content: flex-end;
    padding-top: 1rem;
}

.reports-pagination__button {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    color: #0b4f9c;
    cursor: pointer;
    display: inline-flex;
    font-weight: 700;
    gap: 0.45rem;
    min-height: 2.25rem;
    padding: 0.45rem 0.75rem;
}

.reports-pagination__button:disabled {
    color: #94a3b8;
    cursor: not-allowed;
    opacity: 0.65;
}

.reports-access-detail-panel__title {
    color: #27364a;
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 1.4rem;
}

.reports-access-units {
    display: flex;
    flex-direction: column;
    gap: 1.2rem;
}

.reports-access-unit {
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    overflow: hidden;
}

.reports-access-unit__header {
    background: #074a8f;
    color: #ffffff;
    font-size: 0.94rem;
    font-weight: 700;
    padding: 1rem 1.35rem;
}

.reports-access-user-list {
    background: #ffffff;
}

.reports-access-user-panel + .reports-access-user-panel {
    border-top: 1px solid #d9e2ef;
}

.reports-access-user-panel__summary {
    align-items: center;
    background: #ffffff;
    border: 0;
    color: #061b3a;
    cursor: pointer;
    display: grid;
    gap: 1rem;
    grid-template-columns: auto 1fr auto auto;
    min-height: 4.8rem;
    padding: 0.95rem 1.35rem;
    text-align: left;
    width: 100%;
}

.reports-access-user-panel__avatar {
    align-items: center;
    background: #dbeafe;
    border-radius: 50%;
    color: #2563eb;
    display: inline-flex;
    font-size: 0.86rem;
    font-weight: 700;
    height: 2.7rem;
    justify-content: center;
    width: 2.7rem;
}

.reports-access-user-panel__identity,
.reports-access-user-panel__count {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.reports-access-user-panel__identity strong {
    color: #061b3a;
    font-size: 0.94rem;
}

.reports-access-user-panel__identity small,
.reports-access-user-panel__count small {
    color: #667085;
    font-size: 0.78rem;
}

.reports-access-user-panel__count {
    align-items: flex-end;
}

.reports-access-user-panel__count strong {
    color: #074a8f;
    font-size: 1.35rem;
    line-height: 1;
}

.reports-access-user-panel__summary > .pi {
    color: #7b8798;
}

.reports-access-user-panel__content {
    background: #f8fafc;
    border-top: 1px solid #d9e2ef;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    padding: 1.1rem 1.35rem 1.25rem;
}

.reports-access-user-panel__content h3 {
    color: #061b3a;
    font-size: 0.92rem;
    font-weight: 700;
    margin-bottom: 0.35rem;
}

.reports-access-document {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d9e2ef;
    border-radius: 8px;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    min-height: 3.95rem;
    padding: 0.75rem 1rem;
}

.reports-access-document span:first-child {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.reports-access-document strong {
    color: #061b3a;
    font-size: 0.88rem;
}

.reports-access-document small {
    color: #667085;
    font-size: 0.76rem;
}

.reports-access-document__status {
    background: #d1fae5;
    border-radius: 999px;
    color: #059669;
    font-size: 0.76rem;
    font-weight: 700;
    padding: 0.28rem 0.7rem;
    white-space: nowrap;
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

.reports-response-time-tag {
    background: #fef3c7;
    border-radius: 999px;
    color: #92400e;
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 700;
    padding: 0.24rem 0.62rem;
}

.reports-response-time-tag--fast {
    background: #d1fae5;
    color: #059669;
}

.reports-response-time-tag--slow {
    background: #fee2e2;
    color: #dc2626;
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
    .reports-filter-grid--access,
    .reports-filter-grid--documents,
    .reports-filter-grid--response-time,
    .reports-response-summary-grid,
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

    .reports-support-chart {
        min-height: 320px;
    }

    .reports-support-chart__label {
        font-size: 12px;
    }

    .reports-classification-pie {
        grid-template-columns: 1fr;
    }

    .reports-access-chart__plot {
        grid-template-columns: 2.5rem 1fr;
    }

    .reports-access-chart__grid,
    .reports-access-chart__plot::after {
        left: 2.5rem;
    }

    .reports-access-chart__bars {
        gap: 0.75rem;
        overflow-x: auto;
    }

    .reports-access-chart__bar-wrap {
        min-width: 11rem;
    }

    .reports-access-user-panel__summary {
        grid-template-columns: auto 1fr auto;
    }

    .reports-access-user-panel__summary > .pi {
        grid-column: 3;
    }

    .reports-access-user-panel__count {
        align-items: flex-start;
        grid-column: 2;
    }

    .reports-access-document {
        align-items: flex-start;
        flex-direction: column;
    }

    .reports-classification-count-card {
        align-items: flex-start;
    }

    .reports-pagination {
        align-items: flex-start;
        flex-direction: column;
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
