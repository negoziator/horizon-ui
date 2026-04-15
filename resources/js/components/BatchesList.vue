<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Activity,
    CheckCircle2,
    RotateCw,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface Batch {
    id: string;
    name: string;
    totalJobs: number;
    pendingJobs: number;
    processedJobs: number;
    failedJobs: number;
    progress: number;
    finishedAt?: number | string;
    cancelledAt?: number | string;
    createdAt: number | string;
}

interface Props {
    initialBatches?: Batch[];
    routes: Record<string, string>;
}

const props = withDefaults(defineProps<Props>(), {
    initialBatches: () => [],
});

const batches = ref<Batch[]>(props.initialBatches);
const retrying = ref<string | null>(null);
const flushingBatches = ref(false);

watch(
    () => props.initialBatches,
    (newBatches) => {
        batches.value = newBatches;
    },
);

const loadBatches = async () => {
    try {
        const response = await fetch(props.routes.batches);
        const data = await response.json();
        batches.value = data.batches || [];
    } catch (error) {
        console.error('Failed to load batches:', error);
    }
};

const formatTimestamp = (timestamp?: number | string) => {
    if (!timestamp) {
        return '—';
    }
    const date =
        typeof timestamp === 'string'
            ? new Date(timestamp)
            : new Date(timestamp * 1000);
    if (isNaN(date.getTime())) {
        return '—';
    }
    return date.toLocaleString();
};

const getStatusColor = (batch: Batch) => {
    if (batch.cancelledAt) {
        return 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200';
    }
    if (batch.failedJobs > 0) {
        return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
    }
    if (batch.finishedAt) {
        return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    }
    return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
};

const getStatusText = (batch: Batch) => {
    if (batch.cancelledAt) {
        return 'Cancelled';
    }
    if (batch.finishedAt) {
        return batch.failedJobs > 0 ? 'Completed with failures' : 'Completed';
    }
    return 'Processing';
};

const getStatusIcon = (batch: Batch) => {
    if (batch.cancelledAt) {
        return XCircle;
    }
    if (batch.finishedAt) {
        return batch.failedJobs > 0 ? XCircle : CheckCircle2;
    }
    return Activity;
};

const handleRetry = (batchId: string) => {
    if (retrying.value) {
        return;
    }

    retrying.value = batchId;
    router.post(
        `${props.routes.batchRetry}/${batchId}/retry`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                console.error('Retry batch failed:', errors);
            },
            onSuccess: () => {
                loadBatches();
            },
            onFinish: () => {
                retrying.value = null;
            },
        },
    );
};

const handleFlushBatches = () => {
    if (flushingBatches.value) {
        return;
    }

    if (!confirm('Are you sure you want to cancel and delete all batches?')) {
        return;
    }

    flushingBatches.value = true;
    router.delete(props.routes.flushBatches, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            console.error('Flush batches failed:', errors);
        },
        onSuccess: () => {
            loadBatches();
        },
        onFinish: () => {
            flushingBatches.value = false;
        },
    });
};
</script>

<template>
    <div>
        <!-- Flush Batches Action -->
        <div v-if="batches.length > 0" class="mb-4 flex justify-end">
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-md bg-orange-100 px-3 py-1.5 text-xs font-medium text-orange-700 transition hover:bg-orange-200 disabled:opacity-50 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50"
                :disabled="flushingBatches"
                title="Cancel and delete all batches"
                @click="handleFlushBatches"
            >
                <Trash2
                    class="h-3.5 w-3.5"
                    :class="{ 'animate-pulse': flushingBatches }"
                />
                {{ flushingBatches ? 'Flushing...' : 'Flush Batches' }}
            </button>
        </div>

        <!-- Batches List -->
        <div v-if="batches.length === 0" class="py-12 text-center">
            <p class="text-neutral-500 dark:text-neutral-400">
                No batches found.
            </p>
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="batch in batches"
                :key="batch.id"
                class="rounded-lg border border-neutral-200 bg-white p-4 transition hover:border-neutral-300 dark:border-neutral-700 dark:bg-neutral-900/50 dark:hover:border-neutral-600"
            >
                <div class="flex items-start justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-3">
                            <h3
                                class="font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                {{ batch.name }}
                            </h3>
                            <span
                                class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                                :class="getStatusColor(batch)"
                            >
                                <component
                                    :is="getStatusIcon(batch)"
                                    class="h-3 w-3"
                                />
                                {{ getStatusText(batch) }}
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mt-3">
                            <div
                                class="mb-1 flex items-center justify-between text-xs text-neutral-600 dark:text-neutral-400"
                            >
                                <span>Progress</span>
                                <span>{{ batch.progress }}%</span>
                            </div>
                            <div
                                class="h-2 w-full rounded-full bg-neutral-200 dark:bg-neutral-700"
                            >
                                <div
                                    class="h-2 rounded-full transition-all"
                                    :class="
                                        batch.failedJobs > 0
                                            ? 'bg-red-600'
                                            : 'bg-green-600'
                                    "
                                    :style="{ width: `${batch.progress}%` }"
                                />
                            </div>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-4"
                        >
                            <div
                                class="flex items-center gap-2 text-neutral-600 dark:text-neutral-300"
                            >
                                <span class="font-medium">Total:</span>
                                <span>{{ batch.totalJobs }}</span>
                            </div>
                            <div
                                class="flex items-center gap-2 text-neutral-600 dark:text-neutral-300"
                            >
                                <span class="font-medium">Pending:</span>
                                <span>{{ batch.pendingJobs }}</span>
                            </div>
                            <div
                                class="flex items-center gap-2 text-neutral-600 dark:text-neutral-300"
                            >
                                <span class="font-medium">Processed:</span>
                                <span>{{ batch.processedJobs }}</span>
                            </div>
                            <div
                                class="flex items-center gap-2"
                                :class="
                                    batch.failedJobs > 0
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-neutral-600 dark:text-neutral-300'
                                "
                            >
                                <span class="font-medium">Failed:</span>
                                <span>{{ batch.failedJobs }}</span>
                            </div>
                        </div>

                        <div
                            class="mt-2 flex flex-wrap items-center gap-4 text-sm text-neutral-600 dark:text-neutral-300"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-medium">Created:</span>
                                <span>{{
                                    formatTimestamp(batch.createdAt)
                                }}</span>
                            </div>
                            <div
                                v-if="batch.finishedAt"
                                class="flex items-center gap-2"
                            >
                                <span class="font-medium">Finished:</span>
                                <span>{{
                                    formatTimestamp(batch.finishedAt)
                                }}</span>
                            </div>
                        </div>
                    </div>
                    <div
                        v-if="batch.failedJobs > 0 && !batch.cancelledAt"
                        class="ml-4"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded border border-blue-300 bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50"
                            :disabled="retrying === batch.id"
                            @click="handleRetry(batch.id)"
                        >
                            <RotateCw
                                class="h-4 w-4"
                                :class="{
                                    'animate-spin': retrying === batch.id,
                                }"
                            />
                            {{
                                retrying === batch.id
                                    ? 'Retrying...'
                                    : 'Retry Failed'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
