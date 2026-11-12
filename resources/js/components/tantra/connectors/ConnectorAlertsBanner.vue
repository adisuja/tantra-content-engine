<script setup lang="ts">
// tantra: the app-wide warning when an AI provider or the Tantra API stops
// working (out of credits, rate limited, key rejected, signed out). Data comes
// from the shared `tantraAlerts` prop (app/Tantra/Ai/AiAlerts.php).
import { usePage } from '@inertiajs/vue3';
import { IconAlertTriangle, IconX } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

interface Alert {
    level: 'warning' | 'error';
    provider: string | null;
    label: string | null;
    reason: string;
    message: string;
    since: string | null;
}

const page = usePage();
const dismissed = ref<string[]>(
    JSON.parse(sessionStorage.getItem('tantra-alerts-dismissed') ?? '[]'),
);

const alerts = computed(() =>
    ((page.props.tantraAlerts as Alert[] | undefined) ?? []).filter(
        (a) =>
            !dismissed.value.includes(`${a.provider}:${a.reason}:${a.since}`),
    ),
);

const onConnectorsPage = computed(() =>
    page.url.startsWith('/settings/workspace/connectors'),
);

const dismiss = (a: Alert): void => {
    dismissed.value = [
        ...dismissed.value,
        `${a.provider}:${a.reason}:${a.since}`,
    ];
    sessionStorage.setItem(
        'tantra-alerts-dismissed',
        JSON.stringify(dismissed.value),
    );
};
</script>

<template>
    <div
        v-if="alerts.length && !onConnectorsPage"
        class="flex flex-col gap-2 px-4 pt-3"
        data-testid="connector-alerts-banner"
    >
        <div
            v-for="a in alerts"
            :key="`${a.provider}:${a.reason}`"
            role="alert"
            class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm"
            :class="
                a.level === 'error'
                    ? 'border-destructive/30 bg-destructive/5 text-destructive'
                    : 'border-warning/40 bg-warning/5 text-foreground'
            "
            :data-testid="`connector-alert-banner-${a.provider ?? 'none'}`"
        >
            <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
            <p class="flex-1">
                {{ a.message }}
                <a
                    href="/settings/workspace/connectors"
                    class="ms-1 font-medium underline underline-offset-2"
                    >Open Connectors</a
                >
            </p>
            <button
                type="button"
                aria-label="Dismiss"
                class="opacity-70 hover:opacity-100"
                @click="dismiss(a)"
            >
                <IconX class="size-4" />
            </button>
        </div>
    </div>
</template>
