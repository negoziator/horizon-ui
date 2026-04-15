<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BarChart3, Clock, Database } from 'lucide-vue-next';
import { ref } from 'vue';

interface Queue {
    name: string;
    queues: string[];
    processes: number;
    memory: number;
    tries: number;
    timeout: number;
}

interface Workload {
    name: string;
    length: number;
    wait: number | null;
}

interface Supervisor {
    name: string;
    status: string;
    processes: number[];
}

interface Props {
    queues: Queue[];
    workload: Workload[];
    supervisors: Supervisor[];
    routes: Record<string, string>;
}

const props = defineProps<Props>();

const actioningSupervisor = ref<string | null>(null);

const getWorkloadForQueue = (queueName: string) => {
    return props.workload.find((w) => w.name === queueName);
};

const formatMemory = (mb: number) => {
    if (mb >= 1024) {
        return `${(mb / 1024).toFixed(1)} GB`;
    }
    return `${mb} MB`;
};

const formatWait = (seconds: number | null) => {
    if (seconds === null || seconds === 0) {
        return '—';
    }
    if (seconds < 60) {
        return `${seconds}s`;
    }
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
};

const getSupervisorForQueue = (queueName: string) => {
    return props.supervisors.find((s) => s.name.endsWith(':' + queueName));
};

const isSupervisorPaused = (queueName: string) => {
    return getSupervisorForQueue(queueName)?.status === 'paused';
};

const handlePauseSupervisor = (name: string) => {
    if (actioningSupervisor.value) {
        return;
    }

    actioningSupervisor.value = name;
    router.post(
        `${props.routes.supervisorPause}/${name}/pause`,
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                actioningSupervisor.value = null;
            },
        },
    );
};

const handleContinueSupervisor = (name: string) => {
    if (actioningSupervisor.value) {
        return;
    }

    actioningSupervisor.value = name;
    router.post(
        `${props.routes.supervisorContinue}/${name}/continue`,
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                actioningSupervisor.value = null;
            },
        },
    );
};
</script>

<template>
    <div>
        <div v-if="queues.length === 0" class="py-12 text-center">
            <p class="text-neutral-500 dark:text-neutral-400">
                No queue configuration found.
            </p>
        </div>

        <div v-else class="space-y-4">
            <div
                v-for="queue in queues"
                :key="queue.name"
                class="rounded-lg border p-5 transition-colors duration-300"
                :class="
                    isSupervisorPaused(queue.name)
                        ? 'border-orange-600/50 bg-orange-950/30 dark:border-orange-500/40 dark:bg-orange-950/40'
                        : 'border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900/50'
                "
            >
                <div class="mb-3">
                    <div class="flex items-baseline gap-2">
                        <h3
                            class="text-lg font-semibold"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-200'
                                    : 'text-neutral-900 dark:text-neutral-100'
                            "
                        >
                            {{ queue.name }}
                        </h3>
                        <span
                            class="text-sm"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-300/60'
                                    : 'text-neutral-500 dark:text-neutral-400'
                            "
                        >
                            {{ queue.queues.join(', ') }}
                        </span>
                    </div>

                    <div class="mt-2 flex items-center gap-2">
                        <span
                            v-if="getSupervisorForQueue(queue.name)"
                            class="rounded border px-2 py-0.5 text-xs font-medium"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'bg-orange-800/60 text-orange-200'
                                    : 'border-green-300 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                            "
                        >
                            {{
                                getSupervisorForQueue(queue.name)?.status ||
                                'running'
                            }}
                        </span>

                        <button
                            v-if="!isSupervisorPaused(queue.name)"
                            type="button"
                            class="rounded border border-orange-700/50 bg-orange-900/60 px-2 py-0.5 text-xs text-orange-200 transition hover:bg-orange-800 disabled:opacity-50 dark:border-orange-600/50 dark:bg-orange-900/40 dark:text-orange-300 dark:hover:bg-orange-800/60"
                            :disabled="actioningSupervisor === queue.name"
                            @click="handlePauseSupervisor(queue.name)"
                        >
                            pause
                        </button>
                        <button
                            v-if="isSupervisorPaused(queue.name)"
                            type="button"
                            class="rounded border border-green-700/50 bg-green-900/60 px-2 py-0.5 text-xs text-green-200 transition hover:bg-green-800 disabled:opacity-50 dark:border-green-600/50 dark:bg-green-900/40 dark:text-green-300 dark:hover:bg-green-800/60"
                            :disabled="actioningSupervisor === queue.name"
                            @click="handleContinueSupervisor(queue.name)"
                        >
                            continue
                        </button>

                        <span
                            v-if="getWorkloadForQueue(queue.queues[0])"
                            class="ml-auto text-xs"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-300/60'
                                    : 'text-neutral-500 dark:text-neutral-400'
                            "
                        >
                            <span
                                class="font-semibold"
                                :class="
                                    isSupervisorPaused(queue.name)
                                        ? 'text-orange-300'
                                        : 'text-blue-600 dark:text-blue-400'
                                "
                                >{{
                                    getWorkloadForQueue(queue.queues[0])
                                        ?.length || 0
                                }}</span
                            >
                            pending
                        </span>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div
                        class="rounded-lg p-3"
                        :class="
                            isSupervisorPaused(queue.name)
                                ? 'bg-orange-900/30'
                                : 'bg-neutral-50 dark:bg-neutral-800/50'
                        "
                    >
                        <div
                            class="flex items-center gap-2"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-400/70'
                                    : 'text-neutral-600 dark:text-neutral-400'
                            "
                        >
                            <BarChart3 class="h-4 w-4" />
                            <span class="text-xs font-medium">Processes</span>
                        </div>
                        <p
                            class="mt-1 text-lg font-semibold"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-200'
                                    : 'text-neutral-900 dark:text-neutral-100'
                            "
                        >
                            {{ queue.processes }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg p-3"
                        :class="
                            isSupervisorPaused(queue.name)
                                ? 'bg-orange-900/30'
                                : 'bg-neutral-50 dark:bg-neutral-800/50'
                        "
                    >
                        <div
                            class="flex items-center gap-2"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-400/70'
                                    : 'text-neutral-600 dark:text-neutral-400'
                            "
                        >
                            <Database class="h-4 w-4" />
                            <span class="text-xs font-medium">Memory</span>
                        </div>
                        <p
                            class="mt-1 text-lg font-semibold"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-200'
                                    : 'text-neutral-900 dark:text-neutral-100'
                            "
                        >
                            {{ formatMemory(queue.memory) }}
                        </p>
                    </div>

                    <div
                        class="rounded-lg p-3"
                        :class="
                            isSupervisorPaused(queue.name)
                                ? 'bg-orange-900/30'
                                : 'bg-neutral-50 dark:bg-neutral-800/50'
                        "
                    >
                        <div
                            class="flex items-center gap-2"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-400/70'
                                    : 'text-neutral-600 dark:text-neutral-400'
                            "
                        >
                            <Clock class="h-4 w-4" />
                            <span class="text-xs font-medium">Timeout</span>
                        </div>
                        <p
                            class="mt-1 text-lg font-semibold"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-200'
                                    : 'text-neutral-900 dark:text-neutral-100'
                            "
                        >
                            {{ queue.timeout }}s
                        </p>
                    </div>

                    <div
                        class="rounded-lg p-3"
                        :class="
                            isSupervisorPaused(queue.name)
                                ? 'bg-orange-900/30'
                                : 'bg-neutral-50 dark:bg-neutral-800/50'
                        "
                    >
                        <div
                            class="flex items-center gap-2"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-400/70'
                                    : 'text-neutral-600 dark:text-neutral-400'
                            "
                        >
                            <Clock class="h-4 w-4" />
                            <span class="text-xs font-medium">Wait Time</span>
                        </div>
                        <p
                            class="mt-1 text-lg font-semibold"
                            :class="
                                isSupervisorPaused(queue.name)
                                    ? 'text-orange-200'
                                    : 'text-neutral-900 dark:text-neutral-100'
                            "
                        >
                            {{
                                formatWait(
                                    getWorkloadForQueue(queue.queues[0])
                                        ?.wait || null,
                                )
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
