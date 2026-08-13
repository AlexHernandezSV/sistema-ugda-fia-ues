<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import { useToast } from 'primevue/usetoast';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const loading = ref(true);
const label = ref(null);

const number = computed(() => String(route.params.number ?? ''));
const boxNumber = computed(() => String(route.params.boxNumber ?? ''));
const documents = computed(() => label.value?.documents ?? []);

const fetchLabel = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get(`/api/transfers/${number.value}/boxes/${boxNumber.value}/label`);
        label.value = data;
    } catch (error) {
        const status = error?.response?.status;
        const message = error?.response?.data?.message || 'No se pudo cargar la informacion de la etiqueta.';

        toast.add({
            severity: status === 403 ? 'warn' : 'error',
            summary: status === 403 ? 'Acceso restringido' : 'Error',
            detail: status === 403
                ? 'Esta informacion solo puede ser consultada por personal UGDA.'
                : message,
            life: 4200,
        });

        router.replace('/dashboard');
    } finally {
        loading.value = false;
    }
};

onMounted(fetchLabel);
</script>

<template>
    <main class="label-access-page">
        <button type="button" class="label-access-back" @click="router.push('/dashboard')">
            <i class="pi pi-arrow-left"></i>
            <span>Volver al dashboard</span>
        </button>

        <section v-if="loading" class="label-access-state">
            <i class="pi pi-spin pi-spinner"></i>
            <span>Cargando informacion de la etiqueta...</span>
        </section>

        <section v-else-if="label" class="label-access-panel">
            <header class="label-access-header">
                <div class="label-access-header__icon">
                    <i class="pi pi-qrcode"></i>
                </div>
                <div>
                    <p class="label-access-kicker">Consulta de etiqueta</p>
                    <h1>Etiqueta {{ label.labelCode }}</h1>
                    <span>Información de ubicación física para UGDA</span>
                </div>
            </header>

            <div class="label-access-location">
                <span>Código de ubicación</span>
                <strong>{{ label.locationCode || 'Sin ubicación asignada' }}</strong>
            </div>

            <div class="label-access-grid">
                <article>
                    <span>Transferencia</span>
                    <strong>{{ label.transferNumber }}</strong>
                </article>
                <article>
                    <span>Caja</span>
                    <strong>{{ label.boxLabel }}</strong>
                </article>
                <article>
                    <span>Unidad productora</span>
                    <strong>{{ label.unit }}</strong>
                </article>
                <article>
                    <span>Fecha de transferencia</span>
                    <strong>{{ label.transferDate || 'Sin fecha' }}</strong>
                </article>
                <article>
                    <span>Periodo</span>
                    <strong>{{ label.period || 'Sin periodo' }}</strong>
                </article>
                <article>
                    <span>Estado</span>
                    <strong>{{ label.workflowStatus }}</strong>
                </article>
            </div>

            <section class="label-access-content">
                <span>Contenido de la caja</span>
                <strong>{{ label.content }}</strong>
                <p>Ubicación asignada por {{ label.assignedBy }} el {{ label.assignedAt || 'sin fecha registrada' }}.</p>
            </section>

            <section class="label-access-documents">
                <h2>Documentos registrados</h2>
                <div v-if="documents.length" class="label-access-documents__list">
                    <article v-for="document in documents" :key="document.code || document.name">
                        <div>
                            <strong>{{ document.name }}</strong>
                            <span>{{ document.series || 'Sin serie documental' }}</span>
                        </div>
                        <span>{{ document.year || 'Sin año' }}</span>
                    </article>
                </div>
                <p v-else>No hay documentos registrados para esta caja.</p>
            </section>
        </section>
    </main>
</template>

<style scoped>
.label-access-page {
    background: #f5f7fb;
    min-height: 100vh;
    padding: 2rem;
}

.label-access-back {
    align-items: center;
    background: transparent;
    border: none;
    color: #1f66b2;
    cursor: pointer;
    display: inline-flex;
    font-size: 0.95rem;
    font-weight: 600;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    padding: 0;
}

.label-access-state,
.label-access-panel {
    background: #ffffff;
    border: 1px solid #d8e2ef;
    border-radius: 0.5rem;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    margin: 0 auto;
    max-width: 980px;
}

.label-access-state {
    align-items: center;
    color: #46617f;
    display: flex;
    gap: 0.75rem;
    justify-content: center;
    min-height: 18rem;
}

.label-access-panel {
    overflow: hidden;
}

.label-access-header {
    align-items: center;
    background: #1f66b2;
    color: #ffffff;
    display: flex;
    gap: 1rem;
    padding: 1.5rem;
}

.label-access-header__icon {
    align-items: center;
    background: rgba(255, 255, 255, 0.16);
    border-radius: 0.5rem;
    display: flex;
    height: 3rem;
    justify-content: center;
    width: 3rem;
}

.label-access-header h1,
.label-access-header p,
.label-access-header span {
    margin: 0;
}

.label-access-header h1 {
    color: #ffffff;
    font-size: 2rem;
    font-weight: 600;
}

.label-access-kicker,
.label-access-header span {
    color: rgba(255, 255, 255, 0.84);
    font-size: 0.85rem;
}

.label-access-location {
    background: #eaf2fb;
    margin: 1.5rem;
    padding: 1.5rem;
    text-align: center;
}

.label-access-location span,
.label-access-grid span,
.label-access-content span {
    color: #5f7691;
    display: block;
    font-size: 0.82rem;
    margin-bottom: 0.35rem;
}

.label-access-location strong {
    color: #1f66b2;
    display: block;
    font-size: 2.4rem;
    font-weight: 600;
}

.label-access-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    padding: 0 1.5rem 1.5rem;
}

.label-access-grid article,
.label-access-content,
.label-access-documents {
    border: 1px solid #d8e2ef;
    border-radius: 0.5rem;
    padding: 1rem;
}

.label-access-grid strong,
.label-access-content strong {
    color: #111827;
    font-size: 1rem;
    font-weight: 600;
}

.label-access-content {
    margin: 0 1.5rem 1.5rem;
}

.label-access-content p {
    color: #5f7691;
    margin: 0.75rem 0 0;
}

.label-access-documents {
    margin: 0 1.5rem 1.5rem;
}

.label-access-documents h2 {
    color: #111827;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 0 1rem;
}

.label-access-documents__list {
    display: grid;
    gap: 0.75rem;
}

.label-access-documents__list article {
    align-items: center;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding-bottom: 0.75rem;
}

.label-access-documents__list article:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.label-access-documents__list strong,
.label-access-documents__list span {
    display: block;
}

.label-access-documents__list span,
.label-access-documents p {
    color: #5f7691;
}

@media (max-width: 720px) {
    .label-access-page {
        padding: 1rem;
    }

    .label-access-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .label-access-header h1 {
        font-size: 1.55rem;
    }

    .label-access-location strong {
        font-size: 1.7rem;
    }

    .label-access-grid {
        grid-template-columns: 1fr;
    }

    .label-access-documents__list article {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
