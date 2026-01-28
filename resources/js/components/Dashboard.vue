<script setup>
import AdminLayout from './layout/AdminLayout.vue';
</script>

<template>
    <AdminLayout>
        <div class="grid">
            <div class="col-12 mb-4">
                <div class="bg-blue-800 text-white p-5 border-round-xl shadow-2 relative overflow-hidden">
                    <div class="relative z-2">
                        <h2 class="text-3xl font-bold m-0 mb-2">¡Bienvenido de nuevo, Administrador!</h2>
                        <p class="opacity-90">Tienes 5 documentos pendientes de revisión hoy.</p>
                    </div>
                    <div class="absolute top-0 right-0 w-20rem h-20rem bg-blue-700 border-circle opacity-50" style="transform: translate(30%, -30%);"></div>
                </div>
            </div>

            <div class="col-12 md:col-4">
                <div class="bg-white p-4 border-round-xl shadow-1 border-1 border-gray-100 flex align-items-center gap-4">
                    <div class="w-4rem h-4rem bg-blue-50 text-blue-600 border-round-lg flex align-items-center justify-content-center text-2xl">
                        <i class="pi pi-file"></i>
                    </div>
                    <div>
                        <span class="block text-gray-500 font-medium mb-1">Total Documentos</span>
                        <div class="text-2xl font-bold text-gray-900">1,254</div>
                    </div>
                </div>
            </div>

            <div class="col-12 md:col-4">
                <div class="bg-white p-4 border-round-xl shadow-1 border-1 border-gray-100 flex align-items-center gap-4">
                    <div class="w-4rem h-4rem bg-green-50 text-green-600 border-round-lg flex align-items-center justify-content-center text-2xl">
                        <i class="pi pi-check-circle"></i>
                    </div>
                    <div>
                        <span class="block text-gray-500 font-medium mb-1">Procesados</span>
                        <div class="text-2xl font-bold text-gray-900">845</div>
                    </div>
                </div>
            </div>

            <div class="col-12 md:col-4">
                <div class="bg-white p-4 border-round-xl shadow-1 border-1 border-gray-100 flex align-items-center gap-4">
                    <div class="w-4rem h-4rem bg-orange-50 text-orange-600 border-round-lg flex align-items-center justify-content-center text-2xl">
                        <i class="pi pi-clock"></i>
                    </div>
                    <div>
                        <span class="block text-gray-500 font-medium mb-1">Pendientes</span>
                        <div class="text-2xl font-bold text-gray-900">42</div>
                    </div>
                </div>
            </div>
        </div>

    </AdminLayout>
</template>