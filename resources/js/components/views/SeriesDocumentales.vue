<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { useToast } from "primevue/usetoast";

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const isSavingSeries = ref(false);
const isSavingSubseries = ref(false);
const displaySeriesModal = ref(false);
const displayDeleteModal = ref(false);
const showCreateSeriesPanel = ref(false);
const activeSubseriesSeriesId = ref(null);
const activeEditSubseriesId = ref(null);
const expandedSeriesIds = ref([]);
const searchValue = ref("");

const seriesList = ref([]);
const unitOptions = ref([]);

const createSeriesForm = ref({
    code: "",
    name: "",
    unit_ids: [],
});

const createSubseriesForm = ref({
    code: "",
    name: "",
    unit_ids: [],
});

const editSeriesForm = ref({
    id: null,
    code: "",
    name: "",
    is_active: true,
    unit_ids: [],
});

const editSubseriesForm = ref({
    id: null,
    documentary_series_id: null,
    parent_series_name: "",
    code: "",
    name: "",
    is_active: true,
    unit_ids: [],
});

const seriesErrors = ref({
    code: "",
    name: "",
});

const subseriesErrors = ref({
    code: "",
    name: "",
});

const deleteDialogState = ref({
    type: "",
    series: null,
    subseries: null,
    message: "",
});

const searchTerm = computed(() => searchValue.value.trim().toLowerCase());
const canSaveSeries = computed(
    () =>
        /^\d+$/.test(createSeriesForm.value.code) &&
        String(createSeriesForm.value.name ?? "").trim().length > 0,
);
const canSaveSubseries = computed(
    () =>
        /^\d+$/.test(createSubseriesForm.value.code) &&
        String(createSubseriesForm.value.name ?? "").trim().length > 0,
);

const filteredSeries = computed(() => {
    if (!searchTerm.value) {
        return seriesList.value;
    }

    return seriesList.value.filter((series) => {
        if (matchesSeries(series, searchTerm.value)) {
            return true;
        }

        return (series.subseries ?? []).some((subseries) =>
            matchesSubseries(subseries, searchTerm.value),
        );
    });
});

const fetchSeries = async () => {
    loading.value = true;

    try {
        const [seriesResponse, unitsResponse] = await Promise.all([
            axios.get("/api/documentary-series"),
            axios.get("/api/units"),
        ]);
        seriesList.value = Array.isArray(seriesResponse.data)
            ? seriesResponse.data
            : [];
        unitOptions.value = Array.isArray(unitsResponse.data)
            ? unitsResponse.data
                  .filter((unit) => unit.is_active !== false)
                  .map((unit) => ({
                      label: unit.name,
                      value: unit.id,
                  }))
            : [];
    } catch (error) {
        if ([401, 419].includes(error.response?.status)) {
            return;
        }

        toast.add({
            severity: "error",
            summary: "Error",
            detail: "No se pudieron cargar las series y subseries documentales",
            life: 3000,
        });
    } finally {
        loading.value = false;
    }
};

onMounted(fetchSeries);

const resetSeriesErrors = () => {
    seriesErrors.value = { code: "", name: "" };
};

const resetSubseriesErrors = () => {
    subseriesErrors.value = { code: "", name: "" };
};

const normalizeSeriesCode = (target = createSeriesForm.value) => {
    target.code = String(target.code ?? "")
        .replace(/\D/g, "")
        .slice(0, 20);
};

const normalizeSubseriesCode = (target = createSubseriesForm.value) => {
    target.code = String(target.code ?? "")
        .replace(/\D/g, "")
        .slice(0, 20);
};

const validateSeriesForm = (target = createSeriesForm.value) => {
    resetSeriesErrors();
    normalizeSeriesCode(target);

    if (!/^\d+$/.test(target.code)) {
        seriesErrors.value.code = "El código solo puede contener digitos.";
    }

    if (!String(target.name ?? "").trim()) {
        seriesErrors.value.name = "El nombre de la serie es obligatorio.";
    }

    return !seriesErrors.value.code && !seriesErrors.value.name;
};

const validateSubseriesForm = (target = createSubseriesForm.value) => {
    resetSubseriesErrors();
    normalizeSubseriesCode(target);

    if (!/^\d+$/.test(target.code)) {
        subseriesErrors.value.code = "El código solo puede contener digitos.";
    }

    if (!String(target.name ?? "").trim()) {
        subseriesErrors.value.name = "El nombre de la subserie es obligatorio.";
    }

    return !subseriesErrors.value.code && !subseriesErrors.value.name;
};

const matchesSeries = (series, term) =>
    `${series.code} ${series.name}`.toLowerCase().includes(term);
const matchesSubseries = (subseries, term) =>
    `${subseries.code} ${subseries.name}`.toLowerCase().includes(term);
const getSeriesUnitsSummary = (series) => {
    const units = Array.isArray(series.units) ? series.units : [];

    if (units.length === 0) {
        return "Sin unidades asignadas - no visible en solicitudes";
    }

    if (units.length <= 3) {
        return units.map((unit) => unit.name).join(", ");
    }

    return `${units.length} unidades asignadas`;
};
const getSubseriesUnitsSummary = (subseries) => {
    const units = Array.isArray(subseries.units) ? subseries.units : [];

    if (units.length === 0) {
        return "Sin unidades asignadas";
    }

    if (units.length <= 3) {
        return units.map((unit) => unit.name).join(", ");
    }

    return `${units.length} unidades asignadas`;
};
const getSeriesUnitOptions = (series) =>
    Array.isArray(series.units)
        ? series.units.map((unit) => ({
              label: unit.name,
              value: unit.id,
          }))
        : [];

const getVisibleSubseries = (series) => {
    if (!searchTerm.value || matchesSeries(series, searchTerm.value)) {
        return series.subseries ?? [];
    }

    return (series.subseries ?? []).filter((subseries) =>
        matchesSubseries(subseries, searchTerm.value),
    );
};

const isSeriesExpanded = (seriesId) => {
    if (searchTerm.value) {
        return true;
    }

    return expandedSeriesIds.value.includes(seriesId);
};

const toggleSeries = (seriesId) => {
    if (searchTerm.value) {
        return;
    }

    if (expandedSeriesIds.value.includes(seriesId)) {
        expandedSeriesIds.value = expandedSeriesIds.value.filter(
            (id) => id !== seriesId,
        );
        return;
    }

    expandedSeriesIds.value = [...expandedSeriesIds.value, seriesId];
};

const ensureExpanded = (seriesId) => {
    if (!expandedSeriesIds.value.includes(seriesId)) {
        expandedSeriesIds.value = [...expandedSeriesIds.value, seriesId];
    }
};

const goBack = () => {
    router.back();
};

const openCreateSeriesPanel = () => {
    showCreateSeriesPanel.value = true;
    resetSeriesErrors();
    createSeriesForm.value = { code: "", name: "", unit_ids: [] };
};

const closeCreateSeriesPanel = () => {
    showCreateSeriesPanel.value = false;
    resetSeriesErrors();
    createSeriesForm.value = { code: "", name: "", unit_ids: [] };
};

const saveSeries = async () => {
    if (isSavingSeries.value || !validateSeriesForm(createSeriesForm.value)) {
        return;
    }

    isSavingSeries.value = true;

    try {
        await axios.post("/api/documentary-series", {
            code: createSeriesForm.value.code,
            name: createSeriesForm.value.name.trim(),
            is_active: true,
            unit_ids: createSeriesForm.value.unit_ids,
        });

        toast.add({
            severity: "success",
            summary: "Creada",
            detail: "Serie documental creada correctamente",
            life: 3000,
        });

        closeCreateSeriesPanel();
        await fetchSeries();
    } catch (error) {
        handleValidationErrors(error, seriesErrors);
    } finally {
        isSavingSeries.value = false;
    }
};

const openEditSeriesModal = (series) => {
    resetSeriesErrors();
    editSeriesForm.value = {
        id: series.id,
        code: series.code,
        name: series.name,
        is_active: Boolean(series.is_active),
        unit_ids: Array.isArray(series.unit_ids) ? [...series.unit_ids] : [],
    };
    displaySeriesModal.value = true;
};

const updateSeries = async () => {
    if (isSavingSeries.value || !validateSeriesForm(editSeriesForm.value)) {
        return;
    }

    isSavingSeries.value = true;

    try {
        await axios.put(`/api/documentary-series/${editSeriesForm.value.id}`, {
            code: editSeriesForm.value.code,
            name: editSeriesForm.value.name.trim(),
            is_active: editSeriesForm.value.is_active,
            unit_ids: editSeriesForm.value.unit_ids,
        });

        toast.add({
            severity: "success",
            summary: "Actualizada",
            detail: "Serie documental actualizada correctamente",
            life: 3000,
        });

        displaySeriesModal.value = false;
        await fetchSeries();
    } catch (error) {
        handleValidationErrors(error, seriesErrors);
    } finally {
        isSavingSeries.value = false;
    }
};

const openDeleteSeriesModal = (series) => {
    deleteDialogState.value = {
        type: "series",
        series,
        subseries: null,
        message: `Desea eliminar la serie ${series.code} - ${series.name} y todas sus subseries?`,
    };
    displayDeleteModal.value = true;
};

const openCreateSubseriesForm = (series) => {
    activeSubseriesSeriesId.value = series.id;
    activeEditSubseriesId.value = null;
    resetSubseriesErrors();
    createSubseriesForm.value = { code: "", name: "", unit_ids: [] };
    ensureExpanded(series.id);
};

const closeCreateSubseriesForm = () => {
    activeSubseriesSeriesId.value = null;
    resetSubseriesErrors();
    createSubseriesForm.value = { code: "", name: "", unit_ids: [] };
};

const saveSubseries = async () => {
    if (
        isSavingSubseries.value ||
        !activeSubseriesSeriesId.value ||
        !validateSubseriesForm(createSubseriesForm.value)
    ) {
        return;
    }

    isSavingSubseries.value = true;

    try {
        await axios.post(
            `/api/documentary-series/${activeSubseriesSeriesId.value}/subseries`,
            {
                code: createSubseriesForm.value.code,
                name: createSubseriesForm.value.name.trim(),
                is_active: true,
                unit_ids: createSubseriesForm.value.unit_ids,
            },
        );

        toast.add({
            severity: "success",
            summary: "Creada",
            detail: "Subserie documental creada correctamente",
            life: 3000,
        });

        const expandedSeriesId = activeSubseriesSeriesId.value;
        closeCreateSubseriesForm();
        await fetchSeries();
        ensureExpanded(expandedSeriesId);
    } catch (error) {
        handleValidationErrors(error, subseriesErrors);
    } finally {
        isSavingSubseries.value = false;
    }
};

const openEditSubseriesModal = (series, subseries) => {
    activeSubseriesSeriesId.value = null;
    activeEditSubseriesId.value = subseries.id;
    resetSubseriesErrors();
    editSubseriesForm.value = {
        id: subseries.id,
        documentary_series_id: series.id,
        parent_series_name: series.name,
        code: subseries.code,
        name: subseries.name,
        is_active: Boolean(subseries.is_active),
        unit_ids: Array.isArray(subseries.unit_ids)
            ? [...subseries.unit_ids]
            : [],
    };
    ensureExpanded(series.id);
};

const closeEditSubseriesForm = () => {
    activeEditSubseriesId.value = null;
    resetSubseriesErrors();
    editSubseriesForm.value = {
        id: null,
        documentary_series_id: null,
        parent_series_name: "",
        code: "",
        name: "",
        is_active: true,
        unit_ids: [],
    };
};

const updateSubseries = async () => {
    if (
        isSavingSubseries.value ||
        !validateSubseriesForm(editSubseriesForm.value)
    ) {
        return;
    }

    isSavingSubseries.value = true;

    try {
        await axios.put(
            `/api/documentary-subseries/${editSubseriesForm.value.id}`,
            {
                code: editSubseriesForm.value.code,
                name: editSubseriesForm.value.name.trim(),
                is_active: editSubseriesForm.value.is_active,
                unit_ids: editSubseriesForm.value.unit_ids,
            },
        );

        toast.add({
            severity: "success",
            summary: "Actualizada",
            detail: "Subserie documental actualizada correctamente",
            life: 3000,
        });

        const expandedSeriesId = editSubseriesForm.value.documentary_series_id;
        closeEditSubseriesForm();
        await fetchSeries();
        ensureExpanded(expandedSeriesId);
    } catch (error) {
        handleValidationErrors(error, subseriesErrors);
    } finally {
        isSavingSubseries.value = false;
    }
};

const openDeleteSubseriesModal = (series, subseries) => {
    deleteDialogState.value = {
        type: "subseries",
        series,
        subseries,
        message: `Desea eliminar la subserie ${subseries.code} - ${subseries.name}?`,
    };
    displayDeleteModal.value = true;
};

const confirmDelete = async () => {
    try {
        if (
            deleteDialogState.value.type === "series" &&
            deleteDialogState.value.series
        ) {
            await axios.delete(
                `/api/documentary-series/${deleteDialogState.value.series.id}`,
            );
            toast.add({
                severity: "success",
                summary: "Eliminada",
                detail: "Serie documental eliminada correctamente",
                life: 3000,
            });
        }

        if (
            deleteDialogState.value.type === "subseries" &&
            deleteDialogState.value.subseries
        ) {
            await axios.delete(
                `/api/documentary-subseries/${deleteDialogState.value.subseries.id}`,
            );
            toast.add({
                severity: "success",
                summary: "Eliminada",
                detail: "Subserie documental eliminada correctamente",
                life: 3000,
            });
        }

        const expandedSeriesId = deleteDialogState.value.series?.id ?? null;
        displayDeleteModal.value = false;
        await fetchSeries();

        if (expandedSeriesId) {
            ensureExpanded(expandedSeriesId);
        }
    } catch (error) {
        toast.add({
            severity: "error",
            summary: "Error",
            detail: "No se pudo completar la eliminacion",
            life: 3000,
        });
    }
};

const closeDeleteModal = () => {
    displayDeleteModal.value = false;
    deleteDialogState.value = {
        type: "",
        series: null,
        subseries: null,
        message: "",
    };
};

const handleValidationErrors = (error, errorsRef) => {
    if (error.response?.status === 422) {
        const errors = error.response.data.errors ?? {};
        errorsRef.value = {
            code: errors.code?.[0] ?? "",
            name: errors.name?.[0] ?? "",
        };
        return;
    }

    toast.add({
        severity: "error",
        summary: "Error",
        detail: "No se pudo guardar la informacion",
        life: 3000,
    });
};

const clearFilter = () => {
    searchValue.value = "";
};
</script>

<template>
    <Toast />

    <div class="mb-4">
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
                <h1 class="reports-hero__title m-0">
                    Gestión de Series y Subseries Documentales
                </h1>
                <p class="reports-hero__subtitle m-0">
                    Administrar el catalogo de series y subseries documentales
                </p>
            </div>
        </section>
    </div>

    <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
        <div class="filters-toolbar">
            <div class="relative w-full filters-toolbar__search">
                <i
                    class="pi pi-search absolute text-gray-500 series-search-card__icon"
                ></i>
                <InputText
                    v-model="searchValue"
                    placeholder="Buscar serie o subserie documental..."
                    class="w-full"
                    style="padding-left: 2.5rem"
                />
            </div>

            <div class="filters-toolbar__actions">
                <Button
                    icon="pi pi-times"
                    label="Limpiar"
                    severity="secondary"
                    outlined
                    class="w-auto px-3 flex-shrink-0 filters-toolbar__clear"
                    @click="clearFilter"
                />
                <Button
                    label="Nueva Serie"
                    icon="pi pi-plus"
                    class="w-auto px-4 border-none filters-toolbar__primary"
                    @click="openCreateSeriesPanel"
                />
            </div>
        </div>
    </div>

    <div
        v-if="showCreateSeriesPanel"
        class="surface-card p-4 border-round-xl shadow-1 mb-4"
    >
        <div class="series-form-card__header">
            <div>
                <h2 class="series-form-card__title">Nueva Serie Documental</h2>
            </div>
            <Button
                icon="pi pi-times"
                text
                rounded
                class="text-gray-500"
                @click="closeCreateSeriesPanel"
            />
        </div>

        <div class="series-create-grid">
            <div
                class="series-create-grid__field series-create-grid__field--code"
            >
                <label class="series-field__label">Código de Serie</label>
                <InputText
                    v-model="createSeriesForm.code"
                    maxlength="20"
                    placeholder="Ej: 5"
                    :class="{ 'p-invalid': seriesErrors.code }"
                    @input="normalizeSeriesCode(createSeriesForm)"
                />
                <small class="p-error block mt-1">{{
                    seriesErrors.code
                }}</small>
            </div>
            <div
                class="series-create-grid__field series-create-grid__field--name"
            >
                <label class="series-field__label">Nombre de Serie</label>
                <InputText
                    v-model="createSeriesForm.name"
                    maxlength="150"
                    placeholder="Ej: ACTAS"
                    :class="{ 'p-invalid': seriesErrors.name }"
                />
                <small class="p-error block mt-1">{{
                    seriesErrors.name
                }}</small>
            </div>
        </div>

        <div class="series-units-field">
            <label class="series-field__label">
                <i class="pi pi-building mr-2"></i>
                Unidades productoras que pueden usar esta serie
            </label>
            <MultiSelect
                v-model="createSeriesForm.unit_ids"
                :options="unitOptions"
                optionLabel="label"
                optionValue="value"
                placeholder="Seleccionar unidades productoras..."
                display="chip"
                filter
                class="w-full"
            />
            <small class="series-field__hint"
                >Solo estas unidades veran esta serie al crear solicitudes de
                transferencia.</small
            >
        </div>

        <div class="series-form-card__footer">
            <Button
                label="Cancelar"
                severity="secondary"
                text
                @click="closeCreateSeriesPanel"
            />
            <Button
                label="Guardar Serie"
                icon="pi pi-save"
                class="border-none"
                :disabled="!canSaveSeries"
                :loading="isSavingSeries"
                @click="saveSeries"
            />
        </div>
    </div>

    <div
        v-if="loading"
        class="surface-card p-5 border-round-xl shadow-1 text-center text-gray-500"
    >
        <i class="pi pi-spin pi-spinner text-2xl mb-3 block"></i>
        Cargando series documentales...
    </div>

    <div
        v-else-if="filteredSeries.length === 0"
        class="surface-card p-5 border-round-xl shadow-1 text-center text-gray-500"
    >
        <i class="pi pi-folder-open text-3xl mb-3 block text-gray-300"></i>
        No se encontraron series o subseries con los filtros actuales.
    </div>

    <div v-else class="series-panels">
        <article
            v-for="series in filteredSeries"
            :key="series.id"
            class="surface-card border-round-xl shadow-1 overflow-hidden series-panel"
        >
            <div class="series-panel__header">
                <button
                    type="button"
                    class="series-panel__toggle"
                    :aria-expanded="isSeriesExpanded(series.id)"
                    @click="toggleSeries(series.id)"
                >
                    <i
                        :class="[
                            'pi',
                            isSeriesExpanded(series.id)
                                ? 'pi-chevron-down'
                                : 'pi-chevron-right',
                        ]"
                    ></i>
                </button>

                <div class="series-panel__code">{{ series.code }}</div>

                <div class="series-panel__details">
                    <h5 class="series-panel__title">{{ series.name }}</h5>
                    <span class="series-panel__meta"
                        >{{ series.subseries_count }} subseries</span
                    >
                    <span class="series-panel__units">{{
                        getSeriesUnitsSummary(series)
                    }}</span>
                </div>

                <div class="series-panel__actions">
                    <span
                        :class="[
                            'series-status-chip',
                            series.is_active
                                ? 'series-status-chip--active'
                                : 'series-status-chip--inactive',
                        ]"
                    >
                        {{ series.is_active ? "Activa" : "Inactiva" }}
                    </span>
                    <Button
                        icon="pi pi-pencil"
                        text
                        rounded
                        severity="secondary"
                        class="text-gray-600 hover:text-blue-600"
                        @click="openEditSeriesModal(series)"
                    />
                    <Button
                        icon="pi pi-trash"
                        text
                        rounded
                        severity="secondary"
                        class="text-red-500 hover:text-red-600"
                        @click="openDeleteSeriesModal(series)"
                    />
                </div>
            </div>

            <div v-if="isSeriesExpanded(series.id)" class="series-panel__body">
                <div class="subseries-toolbar">
                    <div>
                        <h4 class="subseries-toolbar__title">Subseries</h4>
                    </div>

                    <Button
                        label="Agregar Subserie"
                        icon="pi pi-plus"
                        text
                        class="subseries-toolbar__add"
                        @click="openCreateSubseriesForm(series)"
                    />
                </div>

                <div
                    v-if="activeSubseriesSeriesId === series.id"
                    class="surface-card border-round-xl border-1 border-200 p-3 mb-4 subseries-form-card subseries-inline-form"
                >
                    <div class="subseries-inline-form__row">
                        <div>
                            <label class="series-field__label">Código</label>
                            <InputText
                                v-model="createSubseriesForm.code"
                                maxlength="20"
                                placeholder="Ej: 12"
                                :class="{ 'p-invalid': subseriesErrors.code }"
                                @input="
                                    normalizeSubseriesCode(createSubseriesForm)
                                "
                            />
                            <small class="p-error block mt-1">{{
                                subseriesErrors.code
                            }}</small>
                        </div>
                        <div>
                            <label class="series-field__label"
                                >Nombre de Subserie</label
                            >
                            <InputText
                                v-model="createSubseriesForm.name"
                                maxlength="150"
                                placeholder="Ej: Actas Asamblea General Universitaria"
                                :class="{ 'p-invalid': subseriesErrors.name }"
                            />
                            <small class="p-error block mt-1">{{
                                subseriesErrors.name
                            }}</small>
                        </div>
                    </div>

                    <div class="subseries-inline-form__units">
                        <label class="series-field__label">
                            <i class="pi pi-building mr-2"></i>
                            Unidades productoras
                        </label>
                        <MultiSelect
                            v-model="createSubseriesForm.unit_ids"
                            :options="getSeriesUnitOptions(series)"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Seleccionar unidades..."
                            display="chip"
                            filter
                            class="w-full"
                        />
                    </div>

                    <div class="series-form-card__footer">
                        <Button
                            label="Cancelar"
                            severity="secondary"
                            text
                            @click="closeCreateSubseriesForm"
                        />
                        <Button
                            label="Guardar"
                            icon="pi pi-save"
                            class="border-none"
                            :disabled="!canSaveSubseries"
                            :loading="isSavingSubseries"
                            @click="saveSubseries"
                        />
                    </div>
                </div>

                <div
                    v-if="getVisibleSubseries(series).length === 0"
                    class="border-1 border-dashed border-300 border-round-lg p-4 text-center text-gray-500"
                >
                    No hay subseries para mostrar en esta serie documental.
                </div>

                <div v-else class="subseries-list">
                    <div
                        v-for="subseries in getVisibleSubseries(series)"
                        :key="subseries.id"
                        :class="[
                            'subseries-item',
                            {
                                'subseries-item--editing':
                                    activeEditSubseriesId === subseries.id,
                            },
                        ]"
                    >
                        <template v-if="activeEditSubseriesId === subseries.id">
                            <div
                                class="subseries-inline-form subseries-inline-form--edit"
                            >
                                <div class="subseries-inline-form__row">
                                    <div>
                                        <label class="series-field__label"
                                            >Código</label
                                        >
                                        <InputText
                                            v-model="editSubseriesForm.code"
                                            maxlength="20"
                                            placeholder="Ej: 12"
                                            :class="{
                                                'p-invalid':
                                                    subseriesErrors.code,
                                            }"
                                            @input="
                                                normalizeSubseriesCode(
                                                    editSubseriesForm,
                                                )
                                            "
                                        />
                                        <small class="p-error block mt-1">{{
                                            subseriesErrors.code
                                        }}</small>
                                    </div>
                                    <div>
                                        <label class="series-field__label"
                                            >Nombre de Subserie</label
                                        >
                                        <InputText
                                            v-model="editSubseriesForm.name"
                                            maxlength="150"
                                            placeholder="Ej: Actas Asamblea General Universitaria"
                                            :class="{
                                                'p-invalid':
                                                    subseriesErrors.name,
                                            }"
                                        />
                                        <small class="p-error block mt-1">{{
                                            subseriesErrors.name
                                        }}</small>
                                    </div>
                                </div>

                                <div class="subseries-inline-form__units">
                                    <label class="series-field__label">
                                        <i class="pi pi-building mr-2"></i>
                                        Unidades productoras
                                    </label>
                                    <MultiSelect
                                        v-model="editSubseriesForm.unit_ids"
                                        :options="getSeriesUnitOptions(series)"
                                        optionLabel="label"
                                        optionValue="value"
                                        placeholder="Seleccionar unidades..."
                                        display="chip"
                                        filter
                                        class="w-full"
                                    />
                                </div>

                                <div class="subseries-inline-form__status">
                                    <label class="series-field__label"
                                        >Estado</label
                                    >
                                    <div
                                        :class="[
                                            'series-status-toggle',
                                            editSubseriesForm.is_active
                                                ? 'is-active'
                                                : 'is-inactive',
                                        ]"
                                    >
                                        <ToggleSwitch
                                            v-model="
                                                editSubseriesForm.is_active
                                            "
                                        />
                                        <span
                                            class="series-status-toggle__text"
                                        >
                                            {{
                                                editSubseriesForm.is_active
                                                    ? "Activa"
                                                    : "Inactiva"
                                            }}
                                        </span>
                                    </div>
                                </div>

                                <div class="series-form-card__footer">
                                    <Button
                                        label="Cancelar"
                                        severity="secondary"
                                        text
                                        @click="closeEditSubseriesForm"
                                    />
                                    <Button
                                        label="Guardar"
                                        icon="pi pi-save"
                                        class="border-none"
                                        :loading="isSavingSubseries"
                                        @click="updateSubseries"
                                    />
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <div class="subseries-item__code">
                                {{ subseries.code }}
                            </div>

                            <div class="subseries-item__details">
                                <span class="subseries-item__name">{{
                                    subseries.name
                                }}</span>
                                <span class="subseries-item__units">{{
                                    getSubseriesUnitsSummary(subseries)
                                }}</span>
                            </div>

                            <div class="subseries-item__actions">
                                <span
                                    :class="[
                                        'series-status-chip',
                                        subseries.is_active
                                            ? 'series-status-chip--active'
                                            : 'series-status-chip--inactive',
                                    ]"
                                >
                                    {{
                                        subseries.is_active
                                            ? "Activa"
                                            : "Inactiva"
                                    }}
                                </span>
                                <Button
                                    icon="pi pi-pencil"
                                    text
                                    rounded
                                    severity="secondary"
                                    class="text-gray-600 hover:text-blue-600"
                                    @click="
                                        openEditSubseriesModal(
                                            series,
                                            subseries,
                                        )
                                    "
                                />
                                <Button
                                    icon="pi pi-trash"
                                    text
                                    rounded
                                    severity="danger"
                                    class="series-delete-button text-red-500 hover:text-red-600"
                                    @click="
                                        openDeleteSubseriesModal(
                                            series,
                                            subseries,
                                        )
                                    "
                                />
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </article>
    </div>

    <Dialog
        v-model:visible="displaySeriesModal"
        modal
        :draggable="false"
        :style="{ width: '760px', maxWidth: 'calc(100vw - 2rem)' }"
        class="p-fluid blue-header-dialog series-edit-dialog"
        header="Editar Serie Documental"
    >
        <div class="series-edit-dialog__content">
            <div class="series-edit-dialog__row">
                <div
                    class="series-edit-dialog__field series-edit-dialog__field--code"
                >
                    <label class="series-field__label">Código de Serie</label>
                    <InputText
                        v-model="editSeriesForm.code"
                        maxlength="20"
                        placeholder="Ej: 5"
                        :class="{ 'p-invalid': seriesErrors.code }"
                        @input="normalizeSeriesCode(editSeriesForm)"
                    />
                    <small class="p-error block mt-1">{{
                        seriesErrors.code
                    }}</small>
                </div>
                <div
                    class="series-edit-dialog__field series-edit-dialog__field--name"
                >
                    <label class="series-field__label">Nombre de Serie</label>
                    <InputText
                        v-model="editSeriesForm.name"
                        maxlength="150"
                        placeholder="Nombre de la serie"
                        :class="{ 'p-invalid': seriesErrors.name }"
                    />
                    <small class="p-error block mt-1">{{
                        seriesErrors.name
                    }}</small>
                </div>
            </div>
            <div
                class="series-edit-dialog__field series-edit-dialog__field--units"
            >
                <label class="series-field__label">
                    <i class="pi pi-building mr-2"></i>
                    Unidades productoras que pueden usar esta serie
                </label>
                <MultiSelect
                    v-model="editSeriesForm.unit_ids"
                    :options="unitOptions"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Seleccionar unidades productoras..."
                    display="chip"
                    filter
                    class="w-full"
                />
                <small class="series-field__hint"
                    >Solo estas unidades veran esta serie al crear solicitudes
                    de transferencia.</small
                >
            </div>
            <div
                class="series-edit-dialog__field series-edit-dialog__field--status"
            >
                <label class="series-field__label">Estado</label>
                <div
                    :class="[
                        'series-status-toggle',
                        editSeriesForm.is_active ? 'is-active' : 'is-inactive',
                    ]"
                >
                    <ToggleSwitch v-model="editSeriesForm.is_active" />
                    <span class="series-status-toggle__text">
                        {{ editSeriesForm.is_active ? "Activa" : "Inactiva" }}
                    </span>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="series-dialog__footer">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    @click="displaySeriesModal = false"
                />
                <Button
                    label="Guardar Cambios"
                    class="border-none"
                    :loading="isSavingSeries"
                    @click="updateSeries"
                />
            </div>
        </template>
    </Dialog>

    <Dialog
        v-model:visible="displayDeleteModal"
        modal
        :draggable="false"
        :style="{ width: '500px', maxWidth: 'calc(100vw - 2rem)' }"
        class="p-fluid blue-header-dialog series-delete-dialog"
        header="Confirmar eliminacion"
    >
        <div class="series-delete-dialog__content">
            <p class="series-delete-dialog__message">
                {{ deleteDialogState.message }}
            </p>
        </div>

        <template #footer>
            <div class="series-dialog__footer">
                <Button
                    label="Cancelar"
                    severity="secondary"
                    outlined
                    @click="closeDeleteModal"
                />
                <Button
                    label="Eliminar"
                    class="border-none"
                    severity="danger"
                    @click="confirmDelete"
                />
            </div>
        </template>
    </Dialog>
</template>

<style>
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

.blue-header-dialog .p-dialog-header {
    border-bottom: none !important;
    background-color: var(--ugda-surface-card) !important;
    border-top-left-radius: var(--ugda-radius-lg) !important;
    border-top-right-radius: var(--ugda-radius-lg) !important;
}

.blue-header-dialog .p-dialog-title {
    color: var(--ugda-brand) !important;
    font-weight: var(--ugda-weight-semibold) !important;
    font-size: var(--ugda-font-h3);
}

.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon {
    color: var(--ugda-text-secondary) !important;
}

.blue-header-dialog .p-dialog-header-icons .p-dialog-header-icon:hover {
    background-color: var(--ugda-surface-soft) !important;
    color: var(--ugda-brand) !important;
}

.blue-header-dialog .p-dialog-content {
    border-bottom-left-radius: var(--ugda-radius-lg) !important;
    border-bottom-right-radius: var(--ugda-radius-lg) !important;
}
</style>

<style scoped>
.series-module-header {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 1.5rem;
    padding: 1.25rem 1.5rem;
    border-radius: 1rem;
}

.series-module-header__left {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    min-width: 0;
}

.series-module-header__back {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    border: 0;
    background: transparent;
    color: var(--ugda-brand);
    font-size: var(--ugda-font-body);
    cursor: pointer;
    padding: 0;
    flex-shrink: 0;
}

.series-module-header__copy {
    min-width: 0;
    padding-left: var(--ugda-space-lg);
    border-left: 1px solid var(--ugda-border);
}

.series-module-header__title {
    margin: 0;
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-semibold);
}

.series-module-header__subtitle {
    margin: var(--ugda-space-xs) 0 0;
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-secondary);
}

.series-search-card__icon {
    top: 50%;
    left: 1rem;
    transform: translateY(-50%);
    z-index: 1;
}

.series-edit-dialog__content {
    margin-top: 0.25rem;
}

.series-edit-dialog__row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    align-items: start;
}

.series-edit-dialog__field {
    width: 100%;
    max-width: 100%;
    margin-bottom: 1rem;
    min-width: 0;
}

.series-edit-dialog__field--code {
    max-width: 100%;
}

.series-edit-dialog__field--name {
    min-width: 0;
    max-width: 100%;
}

.series-edit-dialog__field--status {
    max-width: 100%;
    margin-bottom: 0;
}

.series-status-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    width: fit-content;
    min-height: 2.5rem;
    padding: 0.35rem 0.65rem 0.35rem 0;
}

.series-status-toggle__text {
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

.series-status-toggle.is-active .series-status-toggle__text {
    color: var(--ugda-success-text);
}

.series-status-toggle.is-inactive .series-status-toggle__text {
    color: var(--ugda-error);
}

:deep(.series-status-toggle.is-active .p-toggleswitch-slider) {
    background: var(--ugda-success) !important;
}

:deep(
    .series-status-toggle.is-active
        .p-toggleswitch.p-focus
        .p-toggleswitch-slider
) {
    box-shadow: 0 0 0 0.2rem rgba(22, 163, 74, 0.2) !important;
}

:deep(.series-status-toggle.is-inactive .p-toggleswitch-slider) {
    background: var(--ugda-error) !important;
}

:deep(
    .series-status-toggle.is-inactive
        .p-toggleswitch.p-focus
        .p-toggleswitch-slider
) {
    box-shadow: 0 0 0 0.2rem rgba(220, 38, 38, 0.18) !important;
}

.filters-toolbar {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.filters-toolbar__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    justify-content: space-between;
}

.filters-toolbar__primary {
    margin-left: auto;
}

.series-form-card__header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.series-form-card__title {
    margin: 0;
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-medium);
}

.series-field__label {
    display: block;
    margin-bottom: var(--ugda-space-sm);
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

.series-field__hint {
    color: var(--ugda-text-muted);
    display: block;
    font-size: var(--ugda-font-helper);
    margin-top: var(--ugda-space-xs);
}

.series-units-field {
    margin-top: 1rem;
}

.series-create-grid {
    display: grid;
    gap: 1.25rem;
    grid-template-columns: minmax(0, 0.45fr) minmax(0, 1fr);
}

.series-create-grid__field {
    width: 100%;
    max-width: 100%;
}

.series-form-card__footer,
.series-dialog__footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
}

:deep(.series-edit-dialog .p-dialog-header) {
    padding: 1.25rem 1.5rem 0.75rem;
}

:deep(.series-edit-dialog .p-dialog-content) {
    padding: 0.25rem 1.5rem 1rem;
}

:deep(.series-edit-dialog .p-dialog-footer) {
    padding: 0.75rem 1.5rem 1.25rem;
}

:deep(.series-edit-dialog .p-dialog-header-icon) {
    width: 2.5rem !important;
    height: 2.5rem !important;
}

:deep(.series-edit-dialog .p-dialog-header-icon .pi) {
    font-size: 1rem !important;
}

.series-delete-dialog__content {
    padding-top: 0.5rem;
}

.series-delete-dialog__message {
    margin: 0;
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-body);
    line-height: 1.6;
}

.series-panels {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.series-panel__header {
    display: grid;
    grid-template-columns: auto auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    padding: 1rem 1.25rem;
}

.series-panel__toggle {
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 9999px;
    background: transparent;
    color: var(--ugda-text-secondary);
    cursor: pointer;
}

.series-panel__toggle:hover {
    background: var(--ugda-brand-soft);
    color: var(--ugda-brand);
}

.series-panel__code {
    min-width: 4.2rem;
    padding: 0.95rem 0.75rem;
    border-radius: var(--ugda-radius-lg);
    background: var(--ugda-brand);
    color: var(--ugda-text-inverse);
    text-align: center;
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-semibold);
    line-height: 1;
}

.series-panel__details {
    min-width: 0;
}

.series-panel__title {
    margin: 0;
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-medium);
}

.series-panel__meta {
    display: inline-block;
    margin-top: 0.35rem;
    color: var(--ugda-text-secondary);
    font-size: var(--ugda-font-secondary);
}

.series-panel__units {
    color: var(--ugda-text-muted);
    display: block;
    font-size: var(--ugda-font-helper);
    margin-top: var(--ugda-space-xs);
}

.series-panel__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.series-panel__body {
    border-top: 1px solid var(--ugda-border);
    padding: 1.25rem;
}

.subseries-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    margin-bottom: 1rem;
}

.subseries-toolbar__title {
    margin: 0;
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-card-title);
    font-weight: var(--ugda-weight-medium);
}

.subseries-form-card {
    background: var(--ugda-surface-card);
}

.subseries-inline-form {
    width: 100%;
}

.subseries-inline-form__row {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(0, 0.32fr) minmax(0, 1fr);
}

.subseries-inline-form__units {
    margin-top: 0.75rem;
}

.subseries-inline-form__status {
    margin-top: 0.75rem;
}

.subseries-inline-form .series-form-card__footer {
    margin-top: 0.75rem;
}

.subseries-list {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.subseries-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    border: 1px solid var(--ugda-border);
    border-radius: var(--ugda-radius-lg);
    padding: 0.9rem 1rem;
}

.subseries-item--editing {
    display: block;
    padding: 0.9rem;
}

.subseries-item__code {
    min-width: 3.25rem;
    padding: 0.8rem 0.6rem;
    border-radius: var(--ugda-radius-md);
    background: var(--ugda-info-soft);
    color: var(--ugda-info-text);
    text-align: center;
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
}

.subseries-item__details {
    min-width: 0;
}

.subseries-item__name {
    color: var(--ugda-text-primary);
    font-size: var(--ugda-font-body);
    font-weight: var(--ugda-weight-medium);
    word-break: break-word;
}

.subseries-item__units {
    color: var(--ugda-text-muted);
    display: block;
    font-size: var(--ugda-font-helper);
    margin-top: var(--ugda-space-xs);
}

.subseries-item__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.p-error {
    color: var(--ugda-error);
    font-size: var(--ugda-font-helper);
}

.series-status-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 1.7rem;
    padding: 0.15rem 0.7rem;
    border-radius: 9999px;
    font-size: var(--ugda-font-helper);
    font-weight: var(--ugda-weight-medium);
    line-height: 1;
    width: fit-content;
}

.series-status-chip--active {
    background: var(--ugda-success-soft);
    color: var(--ugda-success-text);
}

.series-status-chip--inactive {
    background: var(--ugda-error-soft);
    color: var(--ugda-error-text);
}

.subseries-toolbar__add {
    color: var(--ugda-brand) !important;
    font-weight: var(--ugda-weight-medium) !important;
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
    .series-module-header {
        padding: 1rem;
    }

    .series-module-header__left {
        display: none;
    }

    .filters-toolbar__actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        width: 100%;
    }

    .filters-toolbar__clear,
    .filters-toolbar__primary {
        width: 100%;
        margin-left: 0;
        justify-content: center;
    }

    .series-form-card__header {
        flex-direction: column;
        align-items: stretch;
    }

    .series-edit-dialog__row {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .series-edit-dialog__field--code,
    .series-edit-dialog__field--name,
    .series-edit-dialog__field--status {
        max-width: 100%;
    }

    .series-create-grid__field--code,
    .series-create-grid__field--name {
        max-width: 100%;
    }

    .series-create-grid {
        grid-template-columns: 1fr;
    }

    .series-form-card__footer,
    .series-dialog__footer {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        width: 100%;
    }

    .series-form-card__footer :deep(.p-button),
    .series-dialog__footer :deep(.p-button) {
        width: 100%;
        justify-content: center;
    }

    :deep(.series-edit-dialog .p-dialog-header) {
        padding: 1rem 1rem 0.5rem;
    }

    :deep(.series-edit-dialog .p-dialog-content) {
        padding: 0.25rem 1rem 0.75rem;
    }

    :deep(.series-edit-dialog .p-dialog-footer) {
        padding: 0.5rem 1rem 1rem;
    }

    .series-panel__header {
        grid-template-columns: auto auto minmax(0, 1fr);
    }

    .series-panel__actions {
        grid-column: 1 / -1;
        justify-content: flex-start;
        padding-left: 3rem;
    }

    .subseries-toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .subseries-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .subseries-inline-form__row {
        grid-template-columns: 1fr;
    }

    .subseries-item__actions {
        grid-column: 1 / -1;
        justify-content: flex-start;
        padding-left: 4rem;
    }
}

@media (min-width: 992px) {
    .filters-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: center;
        gap: 0.75rem;
    }

    .filters-toolbar__actions {
        display: contents;
    }

    .filters-toolbar__primary {
        margin-left: 0;
    }
}

@media (max-width: 640px) {
    .series-panel__code {
        min-width: 3.5rem;
        font-size: 1rem;
    }

    .series-panel__body {
        padding: 1rem;
    }
}
</style>
