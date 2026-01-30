<script setup>
import { ref } from 'vue';
// 1. IMPORTAR EL LAYOUT
// Ajusta la ruta '../layout/AdminLayout.vue' si tu carpeta es distinta, 
// pero basándome en lo anterior, esta debería ser la correcta.
import AdminLayout from './layout/AdminLayout.vue';

// --- DATOS DE EJEMPLO ---
const stats = ref([
    { title: 'Total de Solicitudes', value: '12', icon: 'pi pi-file', color: 'text-blue-500', bg: 'bg-blue-50' },
    { title: 'Autorizadas', value: '7', icon: 'pi pi-check-circle', color: 'text-green-500', bg: 'bg-green-50' },
    { title: 'Pendientes', value: '3', icon: 'pi pi-clock', color: 'text-gray-500', bg: 'bg-gray-100' },
    { title: 'Denegadas', value: '2', icon: 'pi pi-times-circle', color: 'text-red-500', bg: 'bg-red-50' },
]);

const activeUnits = ref([
    { name: 'Decanato', count: 5, percent: 90 },
    { name: 'Escuela de Ingeniería Industrial', count: 4, percent: 70 },
    { name: 'Escuela de Ingeniería Civil', count: 2, percent: 40 },
    { name: 'Escuela de Arquitectura', count: 1, percent: 20 },
]);

const transfers = ref([
    { id: '#D00964', user: 'Ing. Luis Salvador Barrera', date: '19/04/2025 08:30', status: 'Enviado' },
    { id: '#D00965', user: 'Msc. Karla Beatriz Baires', date: 'Pendiente', status: 'Pendiente' },
    { id: '#D00966', user: 'Ing. Luis Salvador Barrera', date: 'Pendiente', status: 'Pendiente' },
    { id: '#D00965', user: 'Msc. Karla Beatriz Baires', date: 'Pendiente', status: 'Pendiente' },
]);
</script>

<template>
    <AdminLayout>
        
        <div class="grid p-fluid animation-duration-500 fadein">
            
            <div class="col-12 mb-2">
                <h1 class="text-2xl font-bold text-gray-800 m-0">Dashboard</h1>
                <span class="text-gray-500 text-sm">Sistema de Gestión Documental - Facultad de Ingeniería y Arquitectura</span>
            </div>

            <div class="col-12 md:col-6 lg:col-3" v-for="(stat, index) in stats" :key="index">
                <div class="surface-card shadow-1 p-3 border-round-xl h-full border-1 border-transparent hover:border-gray-200 transition-duration-200">
                    <div class="flex justify-content-between mb-3">
                        <div>
                            <span class="block text-500 font-medium text-xs mb-1 uppercase tracking-wide">{{ stat.title }}</span>
                            <div class="text-900 font-bold text-3xl">{{ stat.value }}</div>
                        </div>
                        <div class="flex align-items-center justify-content-center border-round" 
                             :class="[stat.bg, stat.color]" 
                             style="width: 2.5rem; height: 2.5rem">
                            <i :class="[stat.icon, 'text-xl']"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 lg:col-6">
                <div class="surface-card shadow-1 p-4 border-round-xl h-full">
                    <h5 class="text-lg font-bold text-gray-800 mb-4">Unidades más activas</h5>
                    
                    <div class="flex flex-column gap-4">
                        <div v-for="unit in activeUnits" :key="unit.name">
                            <div class="flex justify-content-between mb-2">
                                <span class="text-sm font-medium text-gray-700">{{ unit.name }}</span>
                                <span class="text-sm font-bold text-blue-600">{{ unit.count }}</span>
                            </div>
                            <div class="w-full bg-gray-100 border-round-2xl h-1rem overflow-hidden">
                                <div class="bg-blue-600 h-full border-round-2xl" :style="{ width: unit.percent + '%' }"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 lg:col-6">
                <div class="surface-card shadow-1 p-4 border-round-xl h-full">
                    <h5 class="text-lg font-bold text-gray-800 mb-4">Transferencias recientes</h5>
                    
                    <ul class="list-none p-0 m-0">
                        <li v-for="item in transfers" :key="item.id" 
                            class="flex align-items-center py-3 border-bottom-1 surface-border last:border-bottom-none">
                            <div class="w-3rem h-3rem bg-blue-50 text-blue-500 flex align-items-center justify-content-center border-round mr-3 flex-shrink-0">
                                <i class="pi pi-file text-lg"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="font-bold text-gray-800 text-sm">{{ item.id }}</div>
                                <div class="text-xs text-gray-600 mt-1">{{ item.user }}</div>
                            </div>
                            <div class="text-right">
                                <span class="text-xs text-gray-400 block">{{ item.date }}</span>
                                <span class="inline-block mt-1 bg-green-100 text-green-700 text-xs font-bold px-2 py-1 border-round">{{ item.status }}</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-12">
                <div class="shadow-2 p-4 border-round-xl flex flex-column md:flex-row align-items-start md:align-items-center justify-content-between gap-4"
                     style="background-color: #1e4f8a; color: white;">
                    
                    <div class="flex flex-column">
                        <div class="flex align-items-center gap-2 mb-1">
                            <i class="pi pi-chart-line text-xl"></i>
                            <span class="font-bold text-lg">Rendimiento mensual</span>
                        </div>
                        <span class="text-sm opacity-70">Enero 2026</span>
                    </div>

                    <div class="flex gap-6 text-center md:text-left">
                        <div>
                            <span class="block text-xs opacity-70 mb-1">Tasa de autorización</span>
                            <span class="text-xl font-bold">58%</span>
                        </div>
                        <div>
                            <span class="block text-xs opacity-70 mb-1">Tiempo promedio</span>
                            <span class="text-xl font-bold">2.5 días</span>
                        </div>
                        <div>
                            <span class="block text-xs opacity-70 mb-1">Documentos procesados</span>
                            <span class="text-xl font-bold">36</span>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </AdminLayout>
</template>

<style scoped>
.tracking-wide {
    letter-spacing: 0.5px;
}
</style>