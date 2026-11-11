<script setup lang="ts">
// tantra: the review inbox (PRD 10.2 screen 1).
import { Head, Link } from '@inertiajs/vue3';
import { IconEye } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import type { BatchSummary } from '@/components/tantra/review/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ batches: BatchSummary[] }>();

const workspace = ref('all');
const status = ref<'all' | 'waiting' | 'changes' | 'done'>('all');

const workspaces = computed(
    () =>
        [
            ...new Set(props.batches.map((b) => b.workspace).filter(Boolean)),
        ] as string[],
);

const shown = computed(() =>
    props.batches.filter((b) => {
        if (workspace.value !== 'all' && b.workspace !== workspace.value) {
            return false;
        }
        if (status.value === 'waiting') return b.counts.waiting > 0;
        if (status.value === 'changes') return b.counts.changes > 0;
        if (status.value === 'done') return b.counts.waiting === 0;

        return true;
    }),
);

const imported = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';

const statuses = ['all', 'waiting', 'changes', 'done'] as const;
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.review.title')" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <HeaderTitle
                        :title="$t('tantra.review.title')"
                        :icon="IconEye"
                    />
                    <Link
                        href="/tantra/tasks"
                        class="ml-auto text-sm underline"
                        >{{ $t('tantra.tasks.nav') }}</Link
                    >
                    <Link href="/tantra/team" class="text-sm underline">{{
                        $t('tantra.team.title')
                    }}</Link>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="s in statuses"
                        :key="s"
                        size="sm"
                        :variant="status === s ? 'default' : 'outline'"
                        @click="status = s"
                    >
                        {{ $t(`tantra.review.inbox_filters.${s}`) }}
                    </Button>
                    <select
                        v-if="workspaces.length > 1"
                        v-model="workspace"
                        class="h-8 rounded-md border border-border bg-card px-2 text-sm"
                        :aria-label="$t('tantra.review.workspace')"
                    >
                        <option value="all">
                            {{ $t('tantra.review.all_workspaces') }}
                        </option>
                        <option v-for="w in workspaces" :key="w" :value="w">
                            {{ w }}
                        </option>
                    </select>
                </div>
            </header>

            <div
                v-if="!shown.length"
                class="rounded-xl border border-dashed border-border p-10 text-center"
            >
                <div class="font-medium">
                    {{ $t('tantra.review.empty_title') }}
                </div>
                <div class="text-sm text-muted-foreground">
                    {{ $t('tantra.review.empty_body') }}
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="b in shown"
                    :key="b.id"
                    :href="`/tantra/review/${b.id}`"
                    class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 transition hover:border-border-strong"
                    :data-testid="`tantra-batch-${b.engine_batch_id}`"
                >
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-heading font-medium">
                                {{ b.title }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{
                                    [b.workspace, b.dates_label]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </div>
                        </div>
                        <Badge v-if="b.counts.waiting" variant="default">
                            {{
                                $t('tantra.review.waiting_count', {
                                    count: String(b.counts.waiting),
                                })
                            }}
                        </Badge>
                    </div>
                    <dl class="grid grid-cols-4 gap-2 text-center text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ $t('tantra.review.counts.waiting') }}
                            </dt>
                            <dd class="font-medium">{{ b.counts.waiting }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ $t('tantra.review.counts.changes') }}
                            </dt>
                            <dd class="font-medium">{{ b.counts.changes }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ $t('tantra.review.counts.approved') }}
                            </dt>
                            <dd class="font-medium">{{ b.counts.approved }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ $t('tantra.review.counts.scheduled') }}
                            </dt>
                            <dd class="font-medium">
                                {{ b.counts.scheduled }}
                            </dd>
                        </div>
                    </dl>
                    <div class="text-xs text-muted-foreground">
                        {{
                            $t('tantra.review.imported', {
                                date: imported(b.imported_at),
                                total: String(b.counts.total),
                            })
                        }}
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
