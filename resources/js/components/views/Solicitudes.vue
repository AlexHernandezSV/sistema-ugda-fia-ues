<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();

// DATOS SIMULADOS
const transfers = ref([
    { 
        code: 'TR-00964', 
        responsible: 'Ing. Luis Salvador Barrera', 
        unit: 'Decanato', 
        date: '19/04/2025', 
        auth: 'Autorizado', 
        status: 'Transferido' 
    },
    { 
        code: 'TR-00965', 
        responsible: 'Msc. Karla Beatriz Baires', 
        unit: 'Escuela de Ing. Industrial', 
        date: 'Pendiente', 
        auth: 'Autorizado', 
        status: 'Aprobado' 
    },
    { 
        code: 'TR-00966', 
        responsible: 'Ing. Luis Salvador Barrera', 
        unit: 'Decanato', 
        date: 'Pendiente', 
        auth: 'Denegado', 
        status: 'Denegado'
    },
    { 
        code: 'TR-00968', 
        responsible: 'Lic. Ana Maria', 
        unit: 'Escuela de Sistemas', 
        date: '20/04/2025', 
        auth: 'Pendiente', 
        status: 'Observado' 
    },
    { 
        code: 'TR-00969', 
        responsible: 'Arq. Sofia Mendez', 
        unit: 'Escuela de Arquitectura', 
        date: 'Pendiente', 
        auth: 'Autorizado', 
        status: 'Agendado'
    },
]);

const unitsList = ref([]);
const filters = ref({
    number: '',
    auth: null,
    status: null,
    unit: null
    // Año eliminado
});

const rowsPerPageOptions = ref([5, 10, 25, 50]);

// COLORES DE ESTADO
const getStatusBadge = (status) => {
    switch(status) {
        case 'Transferido': return 'bg-green-100 text-green-700';
        case 'Aprobado': return 'bg-blue-100 text-blue-700';
        case 'Cancelado': return 'bg-red-100 text-red-700';
        case 'Denegado': return 'bg-red-100 text-red-700';
        case 'Observado': return 'bg-orange-100 text-orange-700';
        case 'Agendado': return 'bg-purple-100 text-purple-700';
        default: return 'bg-gray-100 text-gray-700';
    }
};

onMounted(async () => {
    try {
        const response = await axios.get('/api/units');
        unitsList.value = response.data.map(u => ({ label: u.name, value: u.id }));
    } catch (error) {
        console.error("Error cargando unidades:", error);
    }
});

const goToCreate = () => {
    router.push('/solicitudes/crear');
};

const clearFilters = () => {
    filters.value = { number: '', auth: null, status: null, unit: null };
};
</script>

<template>
    <div class="mb-4">
        <h1 class="text-2xl font-bold mobile-page-title" style="color: #1560bd;">Solicitudes de Transferencia</h1>
    </div>

    <div class="surface-card p-4 border-round-xl shadow-1 mb-4">
        
        <div class="flex flex-wrap align-items-end gap-3">
            
            <div class="flex flex-column gap-2" style="flex: 1; min-width: 150px;">
                <label class="text-sm font-bold text-gray-700">N° Transferencia</label>
                <InputText v-model="filters.number" placeholder="Escriba..." class="w-full" />
            </div>

            <div class="flex flex-column gap-2" style="flex: 1; min-width: 140px;">
                <label class="text-sm font-bold text-gray-700">Autorización</label>
                <Dropdown v-model="filters.auth" :options="['Autorizado', 'Denegado', 'Pendiente']" placeholder="Todos" showClear class="w-full" />
            </div>

            <div class="flex flex-column gap-2" style="flex: 1; min-width: 140px;">
                <label class="text-sm font-bold text-gray-700">Estado</label>
                <Dropdown v-model="filters.status" 
                          :options="['Aprobado', 'Transferido', 'Cancelado', 'Observado', 'Denegado', 'Agendado']" 
                          placeholder="Todos" showClear class="w-full" />
            </div>

            <div class="flex flex-column gap-2" style="flex: 1.5; min-width: 200px;">
                <label class="text-sm font-bold text-gray-700">Unidad Productora</label>
                <Dropdown v-model="filters.unit" :options="unitsList" optionLabel="label" optionValue="value" placeholder="Seleccione Unidad" showClear filter class="w-full" />
            </div>

            <div class="flex">
                 <Button icon="pi pi-filter-slash" label="Limpiar" severity="secondary" outlined @click="clearFilters" />
            </div>

            <div class="ml-auto">
                <Button label="Nueva Transferencia" icon="pi pi-plus" class="border-none px-4" style="background-color: #1560bd;" @click="goToCreate" />
            </div>

        </div>
    </div>

    <div class="surface-card p-0 border-round-xl shadow-1 overflow-hidden">
        <DataTable :value="transfers" 
                   :paginator="true" 
                   :rows="5" 
                   :rowsPerPageOptions="rowsPerPageOptions"
                   stripedRows 
                   responsiveLayout="scroll">
            
            <template #empty>
                <div class="text-center p-4 text-gray-500 font-medium">No se encontraron solicitudes.</div>
            </template>

            <Column field="code" header="N° Transferencia" headerClass="bg-primary-custom text-white font-semibold"></Column>
            <Column field="responsible" header="Responsable" headerClass="bg-primary-custom text-white font-semibold"></Column>
            <Column field="unit" header="Unidad Productora" headerClass="bg-primary-custom text-white font-semibold"></Column>
            <Column field="date" header="Fecha Recepción" headerClass="bg-primary-custom text-white font-semibold"></Column>

            <Column header="Autorización" headerClass="bg-primary-custom text-white font-semibold">
                <template #body="slotProps">
                    <span :class="['px-2 py-1 border-round text-xs font-bold', 
                        slotProps.data.auth === 'Autorizado' ? 'bg-green-100 text-green-700' : 
                        slotProps.data.auth === 'Denegado' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700']">
                        {{ slotProps.data.auth }}
                    </span>
                </template>
            </Column>

            <Column header="Estado" headerClass="bg-primary-custom text-white font-semibold">
                <template #body="slotProps">
                    <span :class="['px-2 py-1 border-round text-xs font-bold', getStatusBadge(slotProps.data.status)]">
                        {{ slotProps.data.status }}
                    </span>
                </template>
            </Column>

            <Column header="Acciones" headerClass="bg-primary-custom text-white font-semibold" bodyClass="text-center">
                <template #body>
                    <Button icon="pi pi-eye" text rounded severity="secondary" class="text-gray-600 hover:text-blue-600" />
                </template>
            </Column>
        </DataTable>
    </div>
</template>

<style scoped>
:deep(.bg-primary-custom) {
    background-color: #1560bd !important;
}
</style>
