<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Clock,
    RotateCw,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';

interface Job {
    id: string;
    name: string;
    status: string;
    queue: string;
    payload: any;
    exception?: string;
    context?: string;
    failed_at?: string;
    completed_at?: string;
    reserved_at?: string;
}

interface JobDetail {
    id: string;
    name: string;
    status: string;
    queue: string;
    connection: string | null;
    exception: string | null;
    context: string | null;
    failed_at: string | null;
    completed_at: string | null;
    reserved_at: string | null;
    retried_by: Array<{
        id: string;
        status: string;
        retried_at: number;
    }> | null;
    payload: {
        id: string | null;
        displayName: string | null;
        tags: string[];
        pushedAt: string | null;
        attempts: number | null;
        maxTries: number | null;
        maxExceptions: number | null;
        timeout: number | null;
        failOnTimeout: boolean;
        backoff: number | null;
        data: Record<string, unknown> | null;
    };
}

interface Props {
    initialJobs?: Job[];
    initialType?: string;
    pendingCount?: number;
    completedCount?: number;
    failedCount?: number;
    routes: Record<string, string>;
}

const props = withDefaults(defineProps<Props>(), {
    initialJobs: () => [],
    initialType: 'completed',
    pendingCount: 0,
    completedCount: 0,
    failedCount: 0,
});

const activeTab = ref(props.initialType);
const jobs = ref<Job[]>(props.initialJobs);
const loading = ref(false);
const retrying = ref<string | null>(null);
const deleting = ref<string | null>(null);
const flushing = ref(false);
const flushingPending = ref(false);
const flushingCompleted = ref(false);
const expandedJobId = ref<string | null>(null);
const jobDetail = ref<JobDetail | null>(null);
const loadingDetail = ref(false);
const showFullException = ref(false);

const tabs = [
    {
        id: 'pending',
        label: 'Pending',
        icon: Clock,
        countKey: 'pendingCount' as const,
    },
    {
        id: 'failed',
        label: 'Failed',
        icon: XCircle,
        countKey: 'failedCount' as const,
    },
    {
        id: 'completed',
        label: 'Completed',
        icon: CheckCircle2,
        countKey: 'completedCount' as const,
    },
];

const loadJobs = async (type: string) => {
    loading.value = true;
    expandedJobId.value = null;
    jobDetail.value = null;
    try {
        const response = await fetch(`${props.routes.jobs}/${type}`);
        const data = await response.json();
        jobs.value = data.jobs || [];
    } catch (error) {
        console.error('Failed to load jobs:', error);
        jobs.value = [];
    } finally {
        loading.value = false;
    }
};

const toggleJob = async (jobId: string) => {
    if (expandedJobId.value === jobId) {
        expandedJobId.value = null;
        jobDetail.value = null;
        showFullException.value = false;
        return;
    }

    expandedJobId.value = jobId;
    jobDetail.value = null;
    loadingDetail.value = true;
    showFullException.value = false;

    try {
        const response = await fetch(`${props.routes.job}/${jobId}`);
        const data = await response.json();
        jobDetail.value = data.job;
    } catch (error) {
        console.error('Failed to load job detail:', error);
    } finally {
        loadingDetail.value = false;
    }
};

watch(activeTab, (newType) => {
    loadJobs(newType);
});

onMounted(() => {
    loadJobs(activeTab.value);
});

const getJobDisplayName = (job: Job) => {
    const parts = job.name.split('\\');
    return parts[parts.length - 1];
};

const formatTimestamp = (timestamp?: string | number) => {
    if (!timestamp) {
        return '—';
    }
    const date =
        typeof timestamp === 'number'
            ? new Date(timestamp * 1000)
            : new Date(parseFloat(timestamp) * 1000);

    if (isNaN(date.getTime())) {
        return '—';
    }

    return date.toLocaleString();
};

const getExceptionSummary = (exception: string): string => {
    const firstLine = exception.split('\n')[0];
    return firstLine.length > 200
        ? firstLine.substring(0, 200) + '...'
        : firstLine;
};

const handleRetry = (jobId: string, event: Event) => {
    event.stopPropagation();

    if (retrying.value) {
        return;
    }

    retrying.value = jobId;
    router.post(
        `${props.routes.retry}/${jobId}`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                console.error('Retry job failed:', errors);
            },
            onSuccess: () => {
                loadJobs(activeTab.value);
            },
            onFinish: () => {
                retrying.value = null;
            },
        },
    );
};

const handleDelete = (jobId: string, event: Event) => {
    event.stopPropagation();

    if (deleting.value) {
        return;
    }

    if (!confirm('Are you sure you want to delete this job?')) {
        return;
    }

    deleting.value = jobId;
    router.delete(`${props.routes.forget}/${jobId}`, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            console.error('Delete job failed:', errors);
        },
        onSuccess: () => {
            loadJobs(activeTab.value);
        },
        onFinish: () => {
            deleting.value = null;
        },
    });
};

const handleFlushFailed = () => {
    if (flushing.value) {
        return;
    }

    if (!confirm('Are you sure you want to delete all failed jobs?')) {
        return;
    }

    flushing.value = true;
    router.delete(props.routes.flushFailed, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            console.error('Flush failed jobs failed:', errors);
        },
        onSuccess: () => {
            loadJobs(activeTab.value);
        },
        onFinish: () => {
            flushing.value = false;
        },
    });
};

const handleFlushPending = () => {
    if (flushingPending.value) {
        return;
    }

    if (
        !confirm(
            'Are you sure you want to clear all pending jobs from all queues?',
        )
    ) {
        return;
    }

    flushingPending.value = true;
    router.delete(props.routes.flushPending, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            console.error('Flush pending jobs failed:', errors);
        },
        onSuccess: () => {
            loadJobs(activeTab.value);
        },
        onFinish: () => {
            flushingPending.value = false;
        },
    });
};

const handleFlushCompleted = () => {
    if (flushingCompleted.value) {
        return;
    }

    if (!confirm('Are you sure you want to clear all completed job records?')) {
        return;
    }

    flushingCompleted.value = true;
    router.delete(props.routes.flushCompleted, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            console.error('Flush completed jobs failed:', errors);
        },
        onSuccess: () => {
            loadJobs(activeTab.value);
        },
        onFinish: () => {
            flushingCompleted.value = false;
        },
    });
};
</script>

<template>
    <div>
        <!-- Tabs -->
        <div
            class="mb-6 flex items-center justify-between border-b border-neutral-200 dark:border-neutral-700"
        >
            <div class="flex gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    class="flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="
                        activeTab === tab.id
                            ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                            : 'border-transparent text-neutral-600 hover:text-neutral-900 dark:text-neutral-400 dark:hover:text-neutral-100'
                    "
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                    <span
                        v-if="props[tab.countKey] > 0"
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none font-semibold"
                        :class="
                            activeTab === tab.id
                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/30 dark:text-blue-300'
                                : 'bg-neutral-200 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-300'
                        "
                    >
                        {{ props[tab.countKey] }}
                    </span>
                </button>
            </div>
            <button
                v-if="activeTab === 'pending' && jobs.length > 0"
                type="button"
                class="mb-1 flex items-center gap-1.5 rounded-md bg-orange-100 px-3 py-1.5 text-xs font-medium text-orange-700 transition hover:bg-orange-200 disabled:opacity-50 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50"
                :disabled="flushingPending"
                title="Clear all pending jobs"
                @click="handleFlushPending"
            >
                <Trash2
                    class="h-3.5 w-3.5"
                    :class="{ 'animate-pulse': flushingPending }"
                />
                {{ flushingPending ? 'Flushing...' : 'Flush Pending' }}
            </button>
            <button
                v-if="activeTab === 'completed' && jobs.length > 0"
                type="button"
                class="mb-1 flex items-center gap-1.5 rounded-md bg-orange-100 px-3 py-1.5 text-xs font-medium text-orange-700 transition hover:bg-orange-200 disabled:opacity-50 dark:bg-orange-900/30 dark:text-orange-400 dark:hover:bg-orange-900/50"
                :disabled="flushingCompleted"
                title="Clear all completed job records"
                @click="handleFlushCompleted"
            >
                <Trash2
                    class="h-3.5 w-3.5"
                    :class="{ 'animate-pulse': flushingCompleted }"
                />
                {{ flushingCompleted ? 'Flushing...' : 'Flush Completed' }}
            </button>
            <button
                v-if="activeTab === 'failed' && jobs.length > 0"
                type="button"
                class="mb-1 flex items-center gap-1.5 rounded-md bg-red-100 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-200 disabled:opacity-50 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50"
                :disabled="flushing"
                title="Delete all failed jobs"
                @click="handleFlushFailed"
            >
                <Trash2
                    class="h-3.5 w-3.5"
                    :class="{ 'animate-pulse': flushing }"
                />
                {{ flushing ? 'Flushing...' : 'Flush All' }}
            </button>
        </div>

        <!-- Jobs List -->
        <div v-if="loading" class="py-12 text-center">
            <p class="text-neutral-500 dark:text-neutral-400">
                Loading jobs...
            </p>
        </div>

        <div v-else-if="jobs.length === 0" class="py-12 text-center">
            <p class="text-neutral-500 dark:text-neutral-400">
                No {{ activeTab }} jobs found.
            </p>
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="job in jobs"
                :key="job.id"
                class="rounded-lg border border-neutral-200 bg-white transition dark:border-neutral-700 dark:bg-neutral-900/50"
                :class="
                    expandedJobId === job.id
                        ? 'border-blue-300 dark:border-blue-700'
                        : 'hover:border-neutral-300 dark:hover:border-neutral-600'
                "
            >
                <!-- Job Header (clickable) -->
                <div
                    class="flex cursor-pointer items-start justify-between p-4"
                    @click="toggleJob(job.id)"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-2">
                        <component
                            :is="
                                expandedJobId === job.id
                                    ? ChevronDown
                                    : ChevronRight
                            "
                            class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400 transition-transform"
                        />
                        <div class="min-w-0 flex-1">
                            <h3
                                class="font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                {{ getJobDisplayName(job) }}
                            </h3>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-4 text-sm text-neutral-600 dark:text-neutral-300"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">Queue:</span>
                                    <span>{{ job.queue || 'default' }}</span>
                                </div>
                                <div
                                    v-if="job.failed_at"
                                    class="flex items-center gap-2"
                                >
                                    <span class="font-medium">Failed:</span>
                                    <span>{{
                                        formatTimestamp(job.failed_at)
                                    }}</span>
                                </div>
                                <div
                                    v-if="job.completed_at"
                                    class="flex items-center gap-2"
                                >
                                    <span class="font-medium">Completed:</span>
                                    <span>{{
                                        formatTimestamp(job.completed_at)
                                    }}</span>
                                </div>
                                <div
                                    v-if="job.reserved_at"
                                    class="flex items-center gap-2"
                                >
                                    <span class="font-medium">Reserved:</span>
                                    <span>{{
                                        formatTimestamp(job.reserved_at)
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="activeTab === 'failed'" class="ml-4 flex gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded bg-blue-100 p-1.5 transition hover:bg-blue-200 disabled:opacity-50 dark:bg-blue-900/30 dark:hover:bg-blue-900/50"
                            :disabled="
                                retrying === job.id || deleting === job.id
                            "
                            title="Retry"
                            @click="handleRetry(job.id, $event)"
                        >
                            <RotateCw
                                class="h-3.5 w-3.5 text-blue-600 dark:text-blue-400"
                                :class="{ 'animate-spin': retrying === job.id }"
                            />
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded bg-red-100 p-1.5 transition hover:bg-red-200 disabled:opacity-50 dark:bg-red-900/30 dark:hover:bg-red-900/50"
                            :disabled="
                                retrying === job.id || deleting === job.id
                            "
                            title="Delete"
                            @click="handleDelete(job.id, $event)"
                        >
                            <Trash2
                                class="h-3.5 w-3.5 text-red-600 dark:text-red-400"
                            />
                        </button>
                    </div>
                </div>

                <!-- Expanded Detail -->
                <div
                    v-if="expandedJobId === job.id"
                    class="border-t border-neutral-200 dark:border-neutral-700"
                >
                    <!-- Loading state -->
                    <div v-if="loadingDetail" class="p-4">
                        <div class="space-y-3">
                            <div
                                class="h-4 w-1/3 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"
                            />
                            <div
                                class="h-20 animate-pulse rounded bg-neutral-200 dark:bg-neutral-700"
                            />
                        </div>
                    </div>

                    <!-- Job detail -->
                    <div
                        v-else-if="jobDetail"
                        class="space-y-0 divide-y divide-neutral-200 dark:divide-neutral-700"
                    >
                        <!-- Job Info -->
                        <div
                            class="grid grid-cols-2 gap-x-8 gap-y-2 p-4 text-sm sm:grid-cols-3"
                        >
                            <div>
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >ID</span
                                >
                                <p
                                    class="mt-0.5 font-mono text-xs text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.id }}
                                </p>
                            </div>
                            <div>
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Connection</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.connection || '—' }}
                                </p>
                            </div>
                            <div>
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Queue</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.queue || 'default' }}
                                </p>
                            </div>
                            <div>
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Attempts</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.payload?.attempts ?? '—' }}
                                </p>
                            </div>
                            <div v-if="jobDetail.payload?.maxTries">
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Max Tries</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.payload.maxTries }}
                                </p>
                            </div>
                            <div v-if="jobDetail.payload?.tags?.length">
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Tags</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.payload.tags.join(', ') }}
                                </p>
                            </div>
                            <div v-if="jobDetail.payload?.pushedAt">
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Pushed</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{
                                        formatTimestamp(
                                            jobDetail.payload.pushedAt,
                                        )
                                    }}
                                </p>
                            </div>
                            <div v-if="jobDetail.retried_by?.length">
                                <span
                                    class="font-medium text-neutral-500 dark:text-neutral-400"
                                    >Retries</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.retried_by.length }}
                                </p>
                            </div>
                        </div>

                        <!-- Exception -->
                        <div v-if="jobDetail.exception" class="p-4">
                            <h4
                                class="mb-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                Exception
                            </h4>
                            <div
                                class="rounded-md bg-red-50 p-3 dark:bg-red-950/30"
                            >
                                <p
                                    class="font-mono text-xs leading-relaxed text-red-800 dark:text-red-300"
                                >
                                    {{
                                        showFullException
                                            ? jobDetail.exception
                                            : getExceptionSummary(
                                                  jobDetail.exception,
                                              )
                                    }}
                                </p>
                                <button
                                    v-if="jobDetail.exception.includes('\n')"
                                    type="button"
                                    class="mt-2 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    @click="
                                        showFullException = !showFullException
                                    "
                                >
                                    {{
                                        showFullException
                                            ? 'Show Less'
                                            : 'Show Full Stack Trace'
                                    }}
                                </button>
                            </div>
                        </div>

                        <!-- Exception Context -->
                        <div
                            v-if="
                                jobDetail.context &&
                                jobDetail.context !== '' &&
                                jobDetail.context !== 'null'
                            "
                            class="p-4"
                        >
                            <h4
                                class="mb-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                Exception Context
                            </h4>
                            <pre
                                class="overflow-x-auto rounded-md bg-neutral-100 p-3 font-mono text-xs leading-relaxed text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200"
                                >{{ jobDetail.context }}</pre
                            >
                        </div>

                        <!-- Data -->
                        <div v-if="jobDetail.payload?.data" class="p-4">
                            <h4
                                class="mb-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                Data
                            </h4>
                            <pre
                                class="overflow-x-auto rounded-md bg-neutral-100 p-3 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap text-neutral-800 dark:bg-neutral-800 dark:text-neutral-200"
                                >{{
                                    JSON.stringify(
                                        jobDetail.payload.data,
                                        null,
                                        2,
                                    )
                                }}</pre
                            >
                        </div>
                    </div>

                    <!-- Error state -->
                    <div
                        v-else
                        class="p-4 text-center text-sm text-neutral-500 dark:text-neutral-400"
                    >
                        Failed to load job details.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
