<script setup lang="ts">
import BatchesList from '../components/BatchesList.vue';
import HorizonControls from '../components/HorizonControls.vue';
import JobSearchBar from '../components/JobSearchBar.vue';
import JobsList from '../components/JobsList.vue';
import QueueMetrics from '../components/QueueMetrics.vue';
import { usePoll } from '@inertiajs/vue3';
import { Activity } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface HorizonStats {
    status: string;
    jobsPerMinute: number;
    failedJobs: number;
    recentJobs: number;
    processes: number;
    pausedMasters: number;
    queueWithMaxRuntime?: {
        runtime: number;
        name: string;
    };
    queueWithMaxThroughput?: {
        throughput: number;
        name: string;
    };
}

interface QueueMetrics {
    queues: any[];
    workload: any[];
}

interface Supervisor {
    name: string;
    status: string;
    processes: number[];
}

interface Props {
    horizonStats: HorizonStats;
    queueMetrics: QueueMetrics;
    recentJobs: any[];
    supervisors: Supervisor[];
    recentBatches: any[];
    pollingInterval: number;
    routes: Record<string, string>;
}

const props = defineProps<Props>();

const activePane = ref<'metrics' | 'jobs' | 'batches' | 'search'>('jobs');

const pendingJobsCount = computed(() =>
    props.queueMetrics.workload.reduce(
        (sum: number, w: any) => sum + (w.length || 0),
        0,
    ),
);

const failedBatchesCount = computed(
    () => props.recentBatches.filter((b: any) => b.failedJobs > 0).length,
);

usePoll(props.pollingInterval, {
    only: [
        'horizonStats',
        'queueMetrics',
        'recentJobs',
        'supervisors',
        'recentBatches',
    ],
});
</script>

<template>
    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <!-- Header Section -->
        <section
            class="rounded-lg border border-neutral-200/70 bg-gradient-to-br from-neutral-950 via-neutral-900 to-neutral-800 p-6 text-white shadow-2xl dark:border-neutral-800/70 dark:from-neutral-900 dark:via-black dark:to-neutral-950"
        >
            <p class="text-sm tracking-[0.35em] text-neutral-400 uppercase">
                Queue Management
            </p>
            <div class="mt-3">
                <h1 class="text-3xl font-semibold tracking-tight">
                    Horizon Dashboard
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-neutral-300">
                    Monitor and control your queue workers, view job statistics,
                    and manage Horizon in real-time.
                </p>
            </div>
        </section>

        <!-- Horizon panel -->
        <div
            class="rounded-lg border border-neutral-200/70 bg-white/80 backdrop-blur dark:border-neutral-800/70 dark:bg-black/40"
        >
            <!-- Tabs -->
            <div
                class="flex items-center gap-1 border-b border-neutral-200 p-2 dark:border-neutral-700"
            >
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-medium transition"
                    :class="
                        activePane === 'batches'
                            ? 'bg-blue-600 text-white dark:bg-blue-500'
                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                    "
                    @click="activePane = 'batches'"
                >
                    Batches
                    <span
                        v-if="failedBatchesCount > 0"
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none font-semibold"
                        :class="
                            activePane === 'batches'
                                ? 'bg-red-500/40 text-red-100'
                                : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'
                        "
                    >
                        {{ failedBatchesCount }}
                    </span>
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-medium transition"
                    :class="
                        activePane === 'jobs'
                            ? 'bg-blue-600 text-white dark:bg-blue-500'
                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                    "
                    @click="activePane = 'jobs'"
                >
                    Jobs
                    <span
                        v-if="horizonStats.failedJobs > 0"
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none font-semibold"
                        :class="
                            activePane === 'jobs'
                                ? 'bg-red-500/40 text-red-100'
                                : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'
                        "
                    >
                        {{ horizonStats.failedJobs }}
                    </span>
                    <span
                        v-if="pendingJobsCount > 0"
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none font-semibold"
                        :class="
                            activePane === 'jobs'
                                ? 'bg-blue-500/40 text-blue-100'
                                : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                        "
                    >
                        {{ pendingJobsCount }}
                    </span>
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-medium transition"
                    :class="
                        activePane === 'search'
                            ? 'bg-blue-600 text-white dark:bg-blue-500'
                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                    "
                    @click="activePane = 'search'"
                >
                    Search
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded px-4 py-2 text-sm font-medium transition"
                    :class="
                        activePane === 'metrics'
                            ? 'bg-blue-600 text-white dark:bg-blue-500'
                            : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                    "
                    @click="activePane = 'metrics'"
                >
                    Queues
                    <span
                        v-if="queueMetrics.workload.length > 0"
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none font-semibold"
                        :class="
                            activePane === 'metrics'
                                ? 'bg-blue-500/40 text-blue-100'
                                : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                        "
                    >
                        {{ queueMetrics.workload.length }}
                    </span>
                </button>

                <!-- Jobs/min, Status & Controls -->
                <div class="ml-auto flex items-center gap-3">
                    <div
                        class="flex items-center gap-1.5 rounded bg-blue-100 px-2 py-1 dark:bg-blue-900/30"
                    >
                        <Activity
                            class="h-3.5 w-3.5 text-blue-600 dark:text-blue-400"
                        />
                        <span
                            class="text-xs font-semibold text-blue-600 tabular-nums dark:text-blue-400"
                        >
                            {{ horizonStats.jobsPerMinute.toFixed(2) }}
                        </span>
                    </div>
                    <span
                        class="rounded px-2.5 py-1 text-xs font-medium capitalize"
                        :class="{
                            'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200':
                                horizonStats.status === 'running',
                            'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200':
                                horizonStats.status === 'paused',
                            'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200':
                                horizonStats.status === 'inactive',
                        }"
                    >
                        {{ horizonStats.status }}
                    </span>
                    <HorizonControls
                        :status="horizonStats.status"
                        :routes="routes"
                    />
                </div>
            </div>

            <!-- Pane Content -->
            <div class="p-6">
                <QueueMetrics
                    v-if="activePane === 'metrics'"
                    :queues="queueMetrics.queues"
                    :workload="queueMetrics.workload"
                    :supervisors="supervisors"
                    :routes="routes"
                />
                <JobsList
                    v-else-if="activePane === 'jobs'"
                    :initial-jobs="recentJobs"
                    initial-type="pending"
                    :pending-count="pendingJobsCount"
                    :completed-count="horizonStats.recentJobs"
                    :failed-count="horizonStats.failedJobs"
                    :routes="routes"
                />
                <BatchesList
                    v-else-if="activePane === 'batches'"
                    :initial-batches="recentBatches"
                    :routes="routes"
                />
                <JobSearchBar
                    v-else-if="activePane === 'search'"
                    :routes="routes"
                />
            </div>
        </div>
    </div>
</template>
