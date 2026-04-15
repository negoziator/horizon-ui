<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pause, Play, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface Props {
    status: string;
    routes: Record<string, string>;
}

const props = defineProps<Props>();

const pausing = ref(false);
const continuing = ref(false);
const terminating = ref(false);

const handlePause = () => {
    if (pausing.value) {
        return;
    }

    pausing.value = true;
    router.post(
        props.routes.pause,
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                pausing.value = false;
            },
        },
    );
};

const handleContinue = () => {
    if (continuing.value) {
        return;
    }

    continuing.value = true;
    router.post(
        props.routes.continue,
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                continuing.value = false;
            },
        },
    );
};

const handleTerminate = () => {
    if (terminating.value) {
        return;
    }

    if (
        !confirm(
            'Are you sure you want to terminate Horizon? It will restart automatically.',
        )
    ) {
        return;
    }

    terminating.value = true;
    router.post(
        props.routes.terminate,
        {},
        {
            preserveScroll: true,
            preserveState: false,
            onFinish: () => {
                terminating.value = false;
            },
        },
    );
};
</script>

<template>
    <div class="flex items-center gap-2">
        <button
            v-if="status === 'running'"
            type="button"
            class="inline-flex items-center justify-center rounded bg-orange-100 p-1.5 transition hover:bg-orange-200 disabled:opacity-50 dark:bg-orange-900/30 dark:hover:bg-orange-900/50"
            :disabled="pausing"
            @click="handlePause"
            title="Pause"
        >
            <Pause class="h-3.5 w-3.5 text-orange-600 dark:text-orange-400" />
        </button>

        <button
            v-if="status === 'paused'"
            type="button"
            class="inline-flex items-center justify-center rounded bg-green-100 p-1.5 transition hover:bg-green-200 disabled:opacity-50 dark:bg-green-900/30 dark:hover:bg-green-900/50"
            :disabled="continuing"
            @click="handleContinue"
            title="Continue"
        >
            <Play class="h-3.5 w-3.5 text-green-600 dark:text-green-400" />
        </button>

        <button
            type="button"
            class="inline-flex items-center justify-center rounded bg-red-100 p-1.5 transition hover:bg-red-200 disabled:opacity-50 dark:bg-red-900/30 dark:hover:bg-red-900/50"
            :disabled="terminating"
            @click="handleTerminate"
            title="Terminate"
        >
            <XCircle class="h-3.5 w-3.5 text-red-600 dark:text-red-400" />
        </button>
    </div>
</template>
