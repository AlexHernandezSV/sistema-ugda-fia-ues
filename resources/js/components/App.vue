<script setup>
// Aquí importamos el Toast globalmente para que funcione en TODAS las páginas
import Toast from 'primevue/toast';
</script>

<template>
    <Toast position="top-right" />
    
    <router-view></router-view>
</template>