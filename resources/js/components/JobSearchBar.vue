<script setup lang="ts">
import {
    ChevronDown,
    ChevronRight,
    Loader2,
    Search,
    XCircle,
} from 'lucide-vue-next';
import { ref, watch } from 'vue';

interface SearchResult {
    id: string;
    name: string;
    queue: string;
    status: string;
    payload: any;
    tags: string;
    failed_at: string | null;
    reserved_at: string | null;
    created_at: number | null;
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
    retried_by: Array<{ id: string; status: string; retried_at: number }> | null;
    payload: {
        id: string | null;
        displayName: string | null;
        tags: string[];
        pushedAt: string | null;
        attempts: number | null;
        maxTries: number | null;
        data: Record<string, unknown> | null;
    };
}

interface Props {
    routes: Record<string, string>;
}

const props = defineProps<Props>();

const query = ref('');
const type = ref('recent');
const queue = ref('');
const results = ref<SearchResult[]>([]);
const nextCursor = ref<number | null>(null);
const totalScanned = ref(0);
const accumulatedMatches = ref(0);
const isExhausted = ref(false);
const estimatedTotal = ref(0);
const totalSetSize = ref(0);
const lastQuery = ref('');
const searching = ref(false);
const loadingMore = ref(false);
const searched = ref(false);
const error = ref<string | null>(null);
const expandedId = ref<string | null>(null);
const jobDetail = ref<JobDetail | null>(null);
const loadingDetail = ref(false);
const showFullException = ref(false);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

const doSearch = async (append = false) => {
    if (query.value.trim().length < 2) return;

    if (append) {
        loadingMore.value = true;
    } else {
        searching.value = true;
        results.value = [];
        nextCursor.value = null;
        totalScanned.value = 0;
        accumulatedMatches.value = 0;
        isExhausted.value = false;
        estimatedTotal.value = 0;
        totalSetSize.value = 0;
        expandedId.value = null;
        jobDetail.value = null;
        searched.value = false;
    }
    error.value = null;

    try {
        const params = new URLSearchParams({ q: query.value.trim(), type: type.value });
        if (queue.value.trim()) params.set('queue', queue.value.trim());
        if (append && nextCursor.value !== null)
            params.set('cursor', String(nextCursor.value));

        const response = await fetch(`${props.routes.jobSearch}?${params}`);
        if (!response.ok) throw new Error('Search failed');
        const data = await response.json();

        results.value = append ? [...results.value, ...data.data] : data.data;
        nextCursor.value = data.next_cursor;
        totalScanned.value += data.total_scanned;
        accumulatedMatches.value = append
            ? accumulatedMatches.value + data.data.length
            : data.data.length;
        isExhausted.value = Boolean(data.exhausted);
        estimatedTotal.value = data.estimated_total ?? 0;
        totalSetSize.value = data.total_set_size ?? 0;
        lastQuery.value = data.query ?? '';
        searched.value = true;
    } catch {
        error.value = 'Search failed. Please try again.';
    } finally {
        searching.value = false;
        loadingMore.value = false;
    }
};

const scheduleSearch = () => {
    if (debounceTimer) clearTimeout(debounceTimer);
    if (query.value.trim().length < 2) {
        results.value = [];
        searched.value = false;
        return;
    }
    debounceTimer = setTimeout(() => doSearch(), 300);
};

watch(query, scheduleSearch);
watch([type, queue], () => {
    if (query.value.trim().length >= 2) {
        if (debounceTimer) clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => doSearch(), 300);
    }
});

const toggleExpand = async (id: string) => {
    if (expandedId.value === id) {
        expandedId.value = null;
        jobDetail.value = null;
        showFullException.value = false;
        return;
    }

    expandedId.value = id;
    jobDetail.value = null;
    loadingDetail.value = true;
    showFullException.value = false;

    try {
        const response = await fetch(`${props.routes.job}/${id}`);
        const data = await response.json();
        jobDetail.value = data.job;
    } catch {
        // detail stays null — error state rendered below
    } finally {
        loadingDetail.value = false;
    }
};

const getDisplayName = (name: string) => {
    const parts = name.split('\\');
    return parts[parts.length - 1];
};

const formatTimestamp = (timestamp?: string | number | null) => {
    if (!timestamp) return '—';
    const ts =
        typeof timestamp === 'number' ? timestamp : parseFloat(String(timestamp));
    const date = new Date(ts * 1000);
    return isNaN(date.getTime()) ? '—' : date.toLocaleString();
};

const statusBadgeClass = (status: string) => ({
    'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300':
        status === 'completed',
    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300':
        status === 'failed',
    'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300':
        status === 'pending',
    'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300':
        !['completed', 'failed', 'pending'].includes(status),
});

const getExceptionSummary = (exception: string) => {
    const firstLine = exception.split('\n')[0];
    return firstLine.length > 200 ? firstLine.substring(0, 200) + '...' : firstLine;
};
</script>

<template>
    <div>
        <!-- Search Controls -->
        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="relative min-w-64 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-neutral-400"
                />
                <input
                    v-model="query"
                    type="search"
                    placeholder="Search by class name, queue, tag, or payload…"
                    class="w-full rounded-md border border-neutral-300 bg-white py-2 pr-3 pl-9 text-sm text-neutral-900 placeholder-neutral-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100 dark:placeholder-neutral-500"
                />
            </div>

            <select
                v-model="type"
                class="rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100"
            >
                <option value="recent">Recent</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
            </select>

            <input
                v-model="queue"
                type="text"
                placeholder="Queue (optional)"
                class="w-40 rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 placeholder-neutral-400 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-100 dark:placeholder-neutral-500"
            />
        </div>

        <!-- Status line -->
        <div
            v-if="searching"
            class="mb-4 flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400"
        >
            <Loader2 class="h-4 w-4 animate-spin" />
            Searching…
        </div>
        <div
            v-else-if="searched"
            class="mb-4 text-sm text-neutral-500 dark:text-neutral-400"
        >
            <span v-if="accumulatedMatches === 0">
                No results — scanned {{ totalScanned }} job{{ totalScanned !== 1 ? 's' : '' }}
            </span>
            <span v-else-if="isExhausted">
                {{ accumulatedMatches }} job{{ accumulatedMatches !== 1 ? 's' : '' }} found
            </span>
            <span v-else>
                ≥ {{ accumulatedMatches }} found
                <span class="text-neutral-500">
                    (estimate: ~{{ estimatedTotal }} of {{ totalSetSize }})
                </span>
            </span>
        </div>
        <div
            v-else-if="query.length > 0 && query.length < 2"
            class="mb-4 text-sm text-neutral-400 dark:text-neutral-500"
        >
            Type at least 2 characters to search.
        </div>

        <!-- Error -->
        <div
            v-if="error"
            class="mb-4 flex items-center gap-2 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/30 dark:text-red-400"
        >
            <XCircle class="h-4 w-4 shrink-0" />
            {{ error }}
        </div>

        <!-- Empty state (after search) -->
        <div
            v-if="searched && !searching && results.length === 0 && !error"
            class="py-12 text-center"
        >
            <p class="text-neutral-500 dark:text-neutral-400">
                No jobs matched
                <span class="font-semibold text-neutral-700 dark:text-neutral-200"
                    >"{{ query }}"</span
                >.
            </p>
        </div>

        <!-- Idle state -->
        <div
            v-if="!searched && !searching && !error"
            class="py-12 text-center"
        >
            <Search class="mx-auto mb-3 h-8 w-8 text-neutral-300 dark:text-neutral-600" />
            <p class="text-sm text-neutral-400 dark:text-neutral-500">
                Search across job class names, queues, tags, and payload content.
            </p>
        </div>

        <!-- Results -->
        <div v-if="results.length > 0" class="space-y-3">
            <div
                v-for="result in results"
                :key="result.id"
                class="rounded-lg border border-neutral-200 bg-white transition dark:border-neutral-700 dark:bg-neutral-900/50"
                :class="
                    expandedId === result.id
                        ? 'border-blue-300 dark:border-blue-700'
                        : 'hover:border-neutral-300 dark:hover:border-neutral-600'
                "
            >
                <!-- Row -->
                <div
                    class="flex cursor-pointer items-start justify-between p-4"
                    @click="toggleExpand(result.id)"
                >
                    <div class="flex min-w-0 flex-1 items-start gap-2">
                        <component
                            :is="expandedId === result.id ? ChevronDown : ChevronRight"
                            class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400 transition-transform"
                        />
                        <div class="min-w-0 flex-1">
                            <h3
                                class="font-semibold text-neutral-900 dark:text-neutral-100"
                            >
                                {{ getDisplayName(result.name) }}
                            </h3>
                            <p
                                class="mt-0.5 truncate font-mono text-xs text-neutral-400 dark:text-neutral-500"
                            >
                                {{ result.name }}
                            </p>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-4 text-sm text-neutral-600 dark:text-neutral-300"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span class="font-medium">Queue:</span>
                                    <span>{{ result.queue }}</span>
                                </div>
                                <div
                                    v-if="result.failed_at"
                                    class="flex items-center gap-1.5"
                                >
                                    <span class="font-medium">Failed:</span>
                                    <span>{{ formatTimestamp(result.failed_at) }}</span>
                                </div>
                                <div
                                    v-else-if="result.created_at"
                                    class="flex items-center gap-1.5"
                                >
                                    <span class="font-medium">Pushed:</span>
                                    <span>{{ formatTimestamp(result.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <span
                        class="ml-4 shrink-0 rounded px-2 py-0.5 text-xs font-medium capitalize"
                        :class="statusBadgeClass(result.status)"
                    >
                        {{ result.status }}
                    </span>
                </div>

                <!-- Expanded detail -->
                <div
                    v-if="expandedId === result.id"
                    class="border-t border-neutral-200 dark:border-neutral-700"
                >
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

                    <div
                        v-else-if="jobDetail"
                        class="divide-y divide-neutral-200 dark:divide-neutral-700"
                    >
                        <!-- Meta -->
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
                                    >Queue</span
                                >
                                <p
                                    class="mt-0.5 text-neutral-900 dark:text-neutral-100"
                                >
                                    {{ jobDetail.queue || 'default' }}
                                </p>
                            </div>
                            <div v-if="jobDetail.payload?.attempts !== null">
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
                                    {{ formatTimestamp(jobDetail.payload.pushedAt) }}
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
                            <div class="rounded-md bg-red-50 p-3 dark:bg-red-950/30">
                                <p
                                    class="font-mono text-xs leading-relaxed text-red-800 dark:text-red-300"
                                >
                                    {{
                                        showFullException
                                            ? jobDetail.exception
                                            : getExceptionSummary(jobDetail.exception)
                                    }}
                                </p>
                                <button
                                    v-if="jobDetail.exception.includes('\n')"
                                    type="button"
                                    class="mt-2 text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                    @click.stop="showFullException = !showFullException"
                                >
                                    {{ showFullException ? 'Show Less' : 'Show Full Stack Trace' }}
                                </button>
                            </div>
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
                                >{{ JSON.stringify(jobDetail.payload.data, null, 2) }}</pre
                            >
                        </div>
                    </div>

                    <div
                        v-else
                        class="p-4 text-center text-sm text-neutral-500 dark:text-neutral-400"
                    >
                        Failed to load job details.
                    </div>
                </div>
            </div>
        </div>

        <!-- Load more -->
        <div v-if="nextCursor !== null && results.length > 0" class="mt-4 text-center">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                :disabled="loadingMore"
                @click="doSearch(true)"
            >
                <Loader2 v-if="loadingMore" class="h-4 w-4 animate-spin" />
                {{ loadingMore ? 'Loading…' : 'Load more' }}
            </button>
        </div>
    </div>
</template>
