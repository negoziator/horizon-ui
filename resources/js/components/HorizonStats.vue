<script setup lang="ts">
import { Activity, AlertTriangle, Cpu } from 'lucide-vue-next';
import { computed } from 'vue';
import HorizonControls from './HorizonControls.vue';

interface Props {
    stats: {
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
    };
    routes: Record<string, string>;
}

const props = defineProps<Props>();

const statusColor = computed(() => {
    switch (props.stats.status) {
        case 'running':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'paused':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        case 'inactive':
            return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        default:
            return 'bg-neutral-100 text-neutral-800 dark:bg-neutral-900 dark:text-neutral-200';
    }
});

const statusLabel = computed(() => {
    return (
        props.stats.status.charAt(0).toUpperCase() + props.stats.status.slice(1)
    );
});
</script>

<template>
    <div class="flex items-center gap-6">
        <!-- Jobs Per Minute -->
        <div class="flex items-center gap-2">
            <div class="rounded bg-blue-100 p-2 dark:bg-blue-900/30">
                <Activity class="h-5 w-5 text-blue-600 dark:text-blue-400" />
            </div>
            <div>
                <p
                    class="text-2xl font-semibold text-blue-600 dark:text-blue-400"
                >
                    {{ stats.jobsPerMinute.toFixed(2) }}
                </p>
            </div>
        </div>

        <!-- Failed Jobs -->
        <div class="flex items-center gap-2">
            <div
                class="rounded p-2"
                :class="
                    stats.failedJobs > 0
                        ? 'bg-red-100 dark:bg-red-900/30'
                        : 'bg-green-100 dark:bg-green-900/30'
                "
            >
                <AlertTriangle
                    class="h-5 w-5"
                    :class="
                        stats.failedJobs > 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-green-600 dark:text-green-400'
                    "
                />
            </div>
            <div>
                <p
                    class="text-2xl font-semibold"
                    :class="
                        stats.failedJobs > 0
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-green-600 dark:text-green-400'
                    "
                >
                    {{ stats.failedJobs }}
                </p>
            </div>
        </div>

        <!-- Active Processes -->
        <div class="flex items-center gap-2">
            <div class="rounded bg-purple-100 p-2 dark:bg-purple-900/30">
                <Cpu class="h-5 w-5 text-purple-600 dark:text-purple-400" />
            </div>
            <div>
                <p
                    class="text-2xl font-semibold text-purple-600 dark:text-purple-400"
                >
                    {{ stats.processes }}
                </p>
            </div>
        </div>

        <!-- Status Badge & Controls -->
        <div class="ml-auto flex items-center gap-3">
            <span
                class="rounded px-3 py-1 text-sm font-medium"
                :class="statusColor"
            >
                {{ statusLabel }}
            </span>
            <HorizonControls :status="stats.status" :routes="routes" />
        </div>
    </div>
</template>
