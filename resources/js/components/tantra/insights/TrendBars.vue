<script setup lang="ts">
// tantra: one metric over time, a bar per day, week or month.
import { computed } from 'vue';

import { fmt } from '@/components/tantra/insights/traffic';

const props = defineProps<{
    points: { bucket: string; value: number }[];
    label: string;
}>();

const max = computed(() => Math.max(1, ...props.points.map((p) => p.value)));
const total = computed(() => props.points.reduce((s, p) => s + p.value, 0));
</script>

<template>
    <div class="flex flex-col gap-2" data-testid="traffic-trend">
        <div class="flex items-baseline gap-2 text-sm">
            <span class="font-medium">{{ label }}</span>
            <span class="text-muted-foreground tabular-nums">{{
                fmt(total)
            }}</span>
        </div>
        <div
            v-if="points.length"
            class="flex h-40 items-end gap-1 border-b border-border"
        >
            <div
                v-for="p in points"
                :key="p.bucket"
                class="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
                :title="`${p.bucket}: ${fmt(p.value)}`"
            >
                <div
                    class="rounded-t bg-primary/80 transition-colors group-hover:bg-primary"
                    :style="{
                        height: `${(p.value / max) * 100}%`,
                        minHeight: p.value > 0 ? '2px' : '0',
                    }"
                />
            </div>
        </div>
        <div
            v-if="points.length"
            class="flex justify-between text-[11px] text-muted-foreground tabular-nums"
        >
            <span>{{ points[0].bucket }}</span>
            <span>{{ points[points.length - 1].bucket }}</span>
        </div>
        <div v-else class="py-10 text-center text-sm text-muted-foreground">
            {{ $t('tantra.traffic.no_data') }}
        </div>
    </div>
</template>
