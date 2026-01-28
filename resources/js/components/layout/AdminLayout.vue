<script setup>
import { ref } from 'vue';
import AppSidebar from './AppSidebar.vue';

// No necesitamos lógica compleja aquí si usamos CSS flex
</script>

<template>
    <div class="min-h-screen bg-gray-50 font-custom flex">
        
        <AppSidebar />

        <main class="flex-grow-1 flex flex-column transition-all transition-duration-300" 
              style="margin-left: 80px;"> <header class="h-5rem bg-white border-bottom-1 border-gray-200 flex align-items-center px-5 justify-content-between sticky top-0 z-4">
                <h1 class="text-xl font-bold text-gray-800 m-0">Panel de Control</h1>
                <div class="text-sm text-gray-500">Universidad de El Salvador</div>
            </header>

            <div class="p-5">
                <slot></slot>
            </div>
        </main>
    </div>
</template>