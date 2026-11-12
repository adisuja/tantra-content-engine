<script setup lang="ts">
// tantra: the one review screen (PRD 10.2, redesigned 2026-11-12). People land
// here. "To review" shows each post as its phone preview with Approve and
// schedule, or feedback for the next round of the content engine; "Published"
// shows what went out. Nothing else to learn.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { IconPlugConnectedX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import ReviewCard from '@/components/tantra/review/ReviewCard.vue';
import type { ReviewItemRow } from '@/components/tantra/review/types';
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
    tab: 'review' | 'published';
    items: ReviewItemRow[];
    batch: { id: string; title: string } | null;
    counts: { review: number; published: number };
    reconnect: Reconnect[];
    composer: ComposerWaiting[];
}>();

const page = usePage();
const viewer = computed(() => {
    const user = page.props.auth?.user as
        | { name?: string; photo_url?: string | null }
        | undefined;

    return { name: user?.name ?? '', avatar: user?.photo_url ?? null };
});
const me = computed(
    () => (page.props.auth?.user as { id?: string } | undefined)?.id,
);

const showWorkspace = computed(
    () => new Set(props.items.map((i) => i.workspace)).size > 1,
);

const reload = (): void => {
    router.reload({ only: ['items', 'counts', 'tantraReview'] });
};

/* Someone else acting on one of these posts updates this screen too. */
for (const batchId of new Set(
    props.items.map((i) => i.batch_id).filter(Boolean),
)) {
    useEcho<{ user_id: string | null }>(
        `tantra.review.batch.${batchId}`,
        '.tantra.review.changed',
        (payload) => {
            if (!payload.user_id || payload.user_id !== me.value) reload();
        },
    );
}

const tabHref = (tab: 'review' | 'published'): string => {
    const query = new URLSearchParams();
    if (tab === 'published') query.set('tab', 'published');
    if (props.batch) query.set('batch', props.batch.id);
    const q = query.toString();

    return `/tantra/review${q ? `?${q}` : ''}`;
};

const reconnectLine = (r: Reconnect): string => {
    const values = {
        name: r.name,
        workspace: r.workspace ?? '',
        days: String(r.days_left ?? 0),
    };
    if (r.state === 'dropped') {
        return trans('tantra.review.reconnect.dropped', values);
    }

    return trans(
        (r.days_left ?? 0) <= 1
            ? 'tantra.review.reconnect.expiring_soon'
            : 'tantra.review.reconnect.expiring',
        values,
    );
};
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.review.title')" />
        <div
            class="mx-auto flex min-h-full w-full max-w-4xl min-w-0 flex-col gap-6 px-4 pt-6 pb-16 md:px-8"
        >
            <nav
                class="flex items-center gap-1 self-center rounded-full border border-border bg-card p-1"
                :aria-label="$t('tantra.review.title')"
            >
                <Link
                    v-for="t in ['review', 'published'] as const"
                    :key="t"
                    :href="tabHref(t)"
                    class="rounded-full px-4 py-1.5 text-sm font-medium transition"
                    :class="
                        tab === t
                            ? 'bg-foreground text-background'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :data-testid="`tantra-tab-${t}`"
                >
                    {{ $t(`tantra.review.feed.tab_${t}`) }}
                    <span class="ml-1 opacity-70">{{ counts[t] }}</span>
                </Link>
            </nav>

            <div
                v-if="batch"
                class="flex items-center justify-center gap-2 text-sm text-muted-foreground"
            >
                {{
                    $t('tantra.review.feed.batch_only', { batch: batch.title })
                }}
                <Link
                    :href="
                        tab === 'published'
                            ? '/tantra/review?tab=published'
                            : '/tantra/review'
                    "
                    class="underline"
                    >{{ $t('tantra.review.feed.show_all') }}</Link
                >
            </div>

            <section
                v-for="r in reconnect"
                :key="r.id"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                data-testid="tantra-reconnect"
            >
                <IconPlugConnectedX class="size-4" />
                <span class="min-w-0 flex-1">{{ reconnectLine(r) }}</span>
                <Button size="sm" as-child>
                    <a :href="`/tantra/reconnect/${r.id}`">{{
                        $t('tantra.review.reconnect.action')
                    }}</a>
                </Button>
            </section>

            <section
                v-for="c in composer"
                :key="c.workspace_id"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3 text-sm"
                data-testid="tantra-composer-waiting"
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
            </section>

            <div
                v-if="!items.length"
                class="rounded-xl border border-dashed border-border p-10 text-center"
            >
                <div class="font-medium">
                    {{ $t(`tantra.review.feed.empty_${tab}_title`) }}
                </div>
                <div class="text-sm text-muted-foreground">
                    {{ $t(`tantra.review.feed.empty_${tab}_body`) }}
                </div>
            </div>

            <ReviewCard
                v-for="item in items"
                :key="item.id"
                :item="item"
                :viewer="viewer"
                :mode="tab"
                :show-workspace="showWorkspace"
                @changed="reload"
            />
        </div>
    </AppLayout>
</template>
