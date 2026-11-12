<script setup lang="ts">
// tantra: the warning shown where the AI was used. The server's message names
// the provider and the reason (out of credits, rate limited, key rejected...).
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

const props = defineProps<{ error: string; testid?: string }>();

const pointsToConnectors = computed(() => props.error.includes('Connectors'));
const message = computed(() =>
    props.error
        .replace(
            /\s*(Manage providers|Add one) under Settings, Connectors\.?/,
            '',
        )
        .trim(),
);
</script>

<template>
    <div
        role="alert"
        :data-testid="testid"
        class="flex items-start gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
    >
        <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
        <div class="space-y-1">
            <p>{{ message }}</p>
            <a
                v-if="pointsToConnectors"
                href="/settings/workspace/connectors"
                class="font-medium underline underline-offset-2"
                data-testid="ai-error-connectors-link"
                >Open Connectors</a
            >
        </div>
    </div>
</template>
