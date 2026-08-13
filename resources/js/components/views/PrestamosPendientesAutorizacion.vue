<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import axios from 'axios';

const router = useRouter();
const toast = useToast();

const loading = ref(true);
const items = ref([]);
const summary = ref({
    pending: 0,
    today: 0,
    units: 0,
});

const hasItems = computed(() => items.value.length > 0);

const fetchPending = async () => {
    loading.value = true;

    try {
        const { data } = await axios.get('/api/loans/pending-authorization');
        items.value = data.items || [];
        summary.value = data.summary || { pending: 0, today: 0, units: 0 };
    } catch (error) {
        toast.add({
            severity: 'error',
            summary: 'Error',
            detail: error.response?.data?.message || 'No se pudieron cargar las solicitudes pendientes.',
            life: 3600,
        });
    } finally {
        loading.value = false;
    }
};

const openLoan = (loan) => {
    router.push(`/solicitudes/prestamos/${loan.number}`);
};

onMounted(fetchPending);
</script>

<template>
    <Toast />
    <div class="pending-page">
        <header class="pending-header">
            <div>
                <h1>Solicitudes Pendientes de Autorización</h1>
                <p>Revise y autorice las solicitudes de prestamo documental de su unidad</p>
            </div>
        </header>

        <section class="pending-metrics">
            <article class="pending-metric">
                <div>
                    <span>Pendientes de autorización</span>
                    <strong>{{ summary.pending }}</strong>
                </div>
                <i class="pi pi-file-check pending-metric__icon pending-metric__icon--warning"></i>
            </article>
            <article class="pending-metric">
                <div>
                    <span>Solicitudes de hoy</span>
                    <strong>{{ summary.today }}</strong>
                </div>
                <i class="pi pi-calendar pending-metric__icon pending-metric__icon--info"></i>
            </article>
            <article class="pending-metric">
                <div>
                    <span>Unidades vinculadas</span>
                    <strong>{{ summary.units }}</strong>
                </div>
                <i class="pi pi-users pending-metric__icon pending-metric__icon--success"></i>
            </article>
        </section>

        <section v-if="hasItems" class="pending-alert">
            <i class="pi pi-exclamation-circle"></i>
            <div>
                <strong>Hay {{ summary.pending }} solicitudes esperando su autorización</strong>
                <p>Revise cada solicitud cuidadosamente antes de autorizar o denegar.</p>
            </div>
        </section>

        <section class="pending-list">
            <div v-if="loading" class="pending-empty">
                Cargando solicitudes pendientes...
            </div>

            <div v-else-if="!hasItems" class="pending-empty">
                <i class="pi pi-check-circle"></i>
                <strong>No hay prestamos pendientes de autorización</strong>
                <span>Cuando una unidad solicitante envie una solicitud, aparecera en esta pantalla.</span>
            </div>

            <article v-for="loan in items" v-else :key="loan.number" class="pending-card">
                <div class="pending-card__icon">
                    <i class="pi pi-file-check"></i>
                </div>

                <div class="pending-card__main">
                    <div class="pending-card__title">
                        <h2>Préstamo #{{ loan.number }}</h2>
                        <span>{{ loan.auth }}</span>
                    </div>
                    <div class="pending-card__grid">
                        <p><small>Unidad solicitante:</small> <strong>{{ loan.unit }}</strong></p>
                        <p><small>Responsable:</small> <strong>{{ loan.responsible }}</strong></p>
                        <p><small>Fecha de solicitud:</small> <strong>{{ loan.requested_at }}</strong></p>
                        <p><small>Estado:</small> <span class="pending-chip">{{ loan.status }}</span></p>
                    </div>
                </div>

                <Button
                    label="Revisar y Autorizar"
                    icon="pi pi-file-check"
                    class="pending-card__action"
                    @click="openLoan(loan)"
                />
            </article>
        </section>
    </div>
</template>

<style scoped>
.pending-page {
    display: flex;
    flex-direction: column;
    gap: 1.35rem;
}

.pending-header h1 {
    margin: 0 0 .45rem;
    color: #2f6fae;
    font-size: 1.9rem;
    line-height: 1.15;
}

.pending-header p {
    margin: 0;
    color: #526b89;
}

.pending-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1.4rem;
}

.pending-metric,
.pending-card,
.pending-empty {
    border: 1px solid #dce3ec;
    background: #ffffff;
    box-shadow: 0 4px 14px rgba(37, 72, 112, .08);
}

.pending-metric {
    min-height: 126px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.4rem 1.55rem;
    border-radius: 10px;
}

.pending-metric span {
    display: block;
    color: #526b89;
    margin-bottom: 1rem;
}

.pending-metric strong {
    color: #06162b;
    font-size: 2rem;
}

.pending-metric__icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    font-size: 1.25rem;
}

.pending-metric__icon--warning {
    background: #fff2cc;
    color: #f59e0b;
}

.pending-metric__icon--info {
    background: #e6f6fb;
    color: #2f6fae;
}

.pending-metric__icon--success {
    background: #eaf8ed;
    color: #32a852;
}

.pending-alert {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: .9rem;
    align-items: flex-start;
    padding: 1rem 1.25rem;
    border-left: 4px solid #f59e0b;
    border-radius: 8px;
    background: #fff4c8;
    color: #7a4d0b;
}

.pending-alert i {
    margin-top: .15rem;
}

.pending-alert p {
    margin: .45rem 0 0;
}

.pending-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.pending-card {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
    padding: 1.45rem 1.5rem;
    border-radius: 10px;
}

.pending-card__icon {
    width: 48px;
    height: 48px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #fff0c7;
    color: #f59e0b;
    font-size: 1.25rem;
}

.pending-card__main {
    min-width: 0;
}

.pending-card__title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .6rem;
    margin-bottom: .7rem;
}

.pending-card h2 {
    margin: 0;
    color: #06162b;
    font-size: 1.05rem;
}

.pending-card__title span,
.pending-chip {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: .2rem .65rem;
    border-radius: 999px;
    background: #d9dee3;
    color: #506579;
    font-size: .75rem;
    font-weight: 800;
}

.pending-card__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .6rem 2rem;
}

.pending-card__grid p {
    margin: 0;
    color: #49617b;
}

.pending-card__grid small {
    font-size: .82rem;
}

.pending-card__grid strong {
    color: #06162b;
}

.pending-card__action {
    min-height: 46px;
    border: none !important;
    border-radius: 7px !important;
    background: #2f6fae !important;
    font-weight: 800 !important;
    white-space: nowrap;
}

.pending-empty {
    min-height: 180px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .55rem;
    padding: 2rem;
    border-radius: 10px;
    color: #526b89;
    text-align: center;
}

.pending-empty i {
    color: #32a852;
    font-size: 1.8rem;
}

.pending-empty strong {
    color: #06162b;
}

@media (max-width: 1100px) {
    .pending-metrics {
        grid-template-columns: 1fr;
        gap: .85rem;
    }

    .pending-metric {
        min-height: 96px;
    }

    .pending-card {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .pending-card__action {
        grid-column: 1 / -1;
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 640px) {
    .pending-header h1 {
        font-size: 1.45rem;
    }

    .pending-metric,
    .pending-card {
        padding: 1rem;
    }

    .pending-card {
        grid-template-columns: 1fr;
    }

    .pending-card__icon {
        width: 44px;
        height: 44px;
    }

    .pending-card__grid {
        grid-template-columns: 1fr;
        gap: .45rem;
    }
}
</style>
