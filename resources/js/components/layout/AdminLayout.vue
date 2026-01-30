<script setup>
import AppSidebar from './AppSidebar.vue';
</script>

<template>
    <div class="min-h-screen bg-gray-50 font-custom flex relative">
        
        <AppSidebar />

        <main class="flex-grow-1 flex flex-column min-w-0 transition-all transition-duration-300">
            
            

            <div class="p-5">
                <slot></slot>
            </div>
        </main>
    </div>
</template>