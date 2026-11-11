<script setup lang="ts">
// tantra: X posts and personal polls posted by hand, above the calendar grid (PRD 9, 12).
import { Link } from '@inertiajs/vue3';
import { IconHandFinger } from '@tabler/icons-vue';

export type CalendarManualTask = {
    id: string;
    kind: 'x_post' | 'x_thread' | 'linkedin_poll';
    status: 'waiting' | 'due' | 'posted';
    at: string;
    text: string;
    fallback: boolean;
};

const props = defineProps<{
    tasks: CalendarManualTask[];
    timezone: string;
}>();

const when = (iso: string): string =>
    new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: props.timezone,
    });
</script>

<template>
    <div
        v-if="tasks.length"
        class="mx-4 mb-3 flex flex-wrap items-center gap-2 text-sm md:mx-8"
        data-testid="tantra-calendar-manual"
    >
        <span class="flex items-center gap-1 font-medium">
            <IconHandFinger class="size-4" />
            {{ $t('tantra.tasks.calendar') }}
        </span>
        <Link
            v-for="t in tasks"
            :key="t.id"
            href="/tantra/tasks"
            class="flex max-w-xs items-center gap-1.5 rounded-full border px-3 py-1"
            :class="
                t.status === 'due'
                    ? 'border-amber-400 bg-amber-50 text-amber-950 dark:border-amber-600 dark:bg-amber-950/40 dark:text-amber-100'
                    : t.status === 'posted'
                      ? 'border-border text-muted-foreground line-through'
                      : 'border-border bg-card'
            "
            :title="
                t.fallback ? `${t.text} (${$t('tantra.tasks.moved')})` : t.text
            "
        >
            <span class="font-medium">{{
                $t(`tantra.tasks.kinds.${t.kind}`)
            }}</span>
            <span class="truncate text-muted-foreground">{{ when(t.at) }}</span>
        </Link>
    </div>
</template>
