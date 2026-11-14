<script setup lang="ts">
// tantra: one number with its averages per day, week and month, or why it is missing.
import { computed } from 'vue';

import { avg, fmt, type Num } from '@/components/tantra/insights/traffic';

const props = defineProps<{
    label: string;
    value: Num;
    display?: string;
    sub?: string;
    note?: string | null;
    days?: number;
    weeks?: number;
    months?: number;
    averages?: boolean;
    testId?: string;
}>();

const shown = computed(() => props.display ?? fmt(props.value));
</script>

<template>
    <div
        class="flex min-w-0 flex-col gap-1 rounded-xl border border-border bg-card p-4"
        :data-testid="testId"
    >
        <div
            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
        >
            {{ label }}
        </div>
        <template v-if="value !== null">
            <div class="text-2xl font-semibold tabular-nums">{{ shown }}</div>
            <div v-if="sub" class="text-xs text-muted-foreground">
                {{ sub }}
            </div>
            <div
                v-if="averages !== false"
                class="mt-auto pt-1 text-[11px] text-muted-foreground tabular-nums"
            >
                {{ avg(value, days ?? 1) }} {{ $t('tantra.traffic.per_day') }} ·
                {{ avg(value, weeks ?? 1) }}
                {{ $t('tantra.traffic.per_week') }} ·
                {{ avg(value, months ?? 1) }}
                {{ $t('tantra.traffic.per_month') }}
            </div>
        </template>
        <div v-else class="text-sm text-muted-foreground" :title="note || ''">
            <div class="text-base font-medium text-foreground/60">
                {{ $t('tantra.traffic.not_given') }}
            </div>
            <div v-if="note" class="mt-1 text-xs leading-snug">{{ note }}</div>
        </div>
    </div>
</template>
