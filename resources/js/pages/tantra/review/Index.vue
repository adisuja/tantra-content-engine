<script setup lang="ts">
// tantra: the review inbox (PRD 10.2 screen 1).
import { Head, Link } from '@inertiajs/vue3';
import { IconEye, IconPlugConnectedX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import type { BatchSummary } from '@/components/tantra/review/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

type Reconnect = {
    id: string;
    workspace: string | null;
    name: string;
    state: 'expiring' | 'dropped';
    days_left: number | null;
};

type ComposerWaiting = {
    workspace_id: string;
    workspace: string | null;
    count: number;
};

const props = defineProps<{
    batches: BatchSummary[];
    reconnect: Reconnect[];
    composer: ComposerWaiting[];
}>();

const reconnectLine = (r: Reconnect): string => {
    const values = {
        name: r.name,
        workspace: r.workspace ?? '',
        days: String(r.days_left ?? 0),
    };
    if (r.state === 'dropped')
        return trans('tantra.review.reconnect.dropped', values);

    return trans(
        (r.days_left ?? 0) <= 1
            ? 'tantra.review.reconnect.expiring_soon'
            : 'tantra.review.reconnect.expiring',
        values,
    );
};

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

            <section
                v-if="reconnect.length"
                class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                data-testid="tantra-reconnect"
            >
                <div class="flex items-center gap-2 font-medium">
                    <IconPlugConnectedX class="size-4" />
                    {{ $t('tantra.review.reconnect.title') }}
                </div>
                <div
                    v-for="r in reconnect"
                    :key="r.id"
                    class="flex flex-wrap items-center gap-3 text-sm"
                >
                    <span class="min-w-0 flex-1">{{ reconnectLine(r) }}</span>
                    <Button size="sm" as-child>
                        <a :href="`/tantra/reconnect/${r.id}`">{{
                            $t('tantra.review.reconnect.action')
                        }}</a>
                    </Button>
                </div>
                <div class="text-xs opacity-80">
                    {{ $t('tantra.review.reconnect.help') }}
                </div>
            </section>

            <section
                v-if="composer.length"
                class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4"
                data-testid="tantra-composer-waiting"
            >
                <div class="font-medium">
                    {{ $t('tantra.review.composer.title') }}
                </div>
                <div
                    v-for="c in composer"
                    :key="c.workspace_id"
                    class="flex flex-wrap items-center gap-3 text-sm"
                >
                    <span class="min-w-0 flex-1">{{
                        $t(
                            c.count === 1
                                ? 'tantra.review.composer.one'
                                : 'tantra.review.composer.many',
                            {
                                count: String(c.count),
                                workspace: c.workspace ?? '',
                            },
                        )
                    }}</span>
                    <Button size="sm" variant="outline" as-child>
                        <a :href="`/tantra/approvals/${c.workspace_id}`">{{
                            $t('tantra.review.composer.action')
                        }}</a>
                    </Button>
                </div>
            </section>

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
