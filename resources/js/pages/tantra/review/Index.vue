<script setup lang="ts">
// tantra: the one review screen (PRD 10.2, 2026-11-12). One post at a time,
// as its phone preview, with an arrow on each side (or a swipe) to move to the
// previous or next post. Three tabs: Review, Scheduled and Published.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    IconChevronLeft,
    IconChevronRight,
    IconPlugConnectedX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import ReviewCard from '@/components/tantra/review/ReviewCard.vue';
import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

type Tab = 'review' | 'scheduled' | 'published';

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
    tab: Tab;
    items: ReviewItemRow[];
    batch: { id: string; title: string } | null;
    counts: Record<Tab, number>;
    reconnect: Reconnect[];
    composer: ComposerWaiting[];
}>();

const TABS: Tab[] = ['review', 'scheduled', 'published'];

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

/* One post at a time. The post's id sits in the address (#EA1) so a refresh
   or a shared link opens the same post. */
const startAt = (): number => {
    const id = window.location.hash.slice(1);
    const found = props.items.findIndex((i) => i.engine_post_id === id);

    return found >= 0 ? found : 0;
};
const index = ref(startAt());
const current = computed<ReviewItemRow | null>(
    () => props.items[index.value] ?? null,
);

watch(
    () => props.items,
    (items) => {
        // After an approval or a revision the post leaves this tab: stay at the
        // same place, which is now the next post.
        if (index.value > items.length - 1) {
            index.value = Math.max(0, items.length - 1);
        }
    },
);
watch(current, (item) => {
    if (item) {
        history.replaceState(history.state, '', `#${item.engine_post_id}`);
    }
});

const go = (step: number): void => {
    const next = index.value + step;
    if (next >= 0 && next < props.items.length) {
        index.value = next;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
};

const onKey = (e: KeyboardEvent): void => {
    const target = e.target as HTMLElement | null;
    if (target && ['TEXTAREA', 'INPUT', 'SELECT'].includes(target.tagName)) {
        return;
    }
    if (e.key === 'ArrowLeft') go(-1);
    if (e.key === 'ArrowRight') go(1);
};

let touchX: number | null = null;
let touchY = 0;
const onTouchStart = (e: TouchEvent): void => {
    touchX = e.touches[0].clientX;
    touchY = e.touches[0].clientY;
};
const onTouchEnd = (e: TouchEvent): void => {
    if (touchX === null) return;
    const dx = e.changedTouches[0].clientX - touchX;
    const dy = e.changedTouches[0].clientY - touchY;
    touchX = null;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
        go(dx < 0 ? 1 : -1);
    }
};

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

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

const tabHref = (tab: Tab): string => {
    const query = new URLSearchParams();
    if (tab !== 'review') query.set('tab', tab);
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
            class="mx-auto flex min-h-full w-full max-w-5xl min-w-0 flex-col gap-4 px-3 pt-4 pb-12 md:px-8 md:pt-6"
        >
            <nav
                class="flex items-center gap-1 self-center rounded-full border border-border bg-card p-1"
                :aria-label="$t('tantra.review.title')"
            >
                <Link
                    v-for="t in TABS"
                    :key="t"
                    :href="tabHref(t)"
                    class="rounded-full px-3 py-1.5 text-sm font-medium transition sm:px-4"
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
                        tab === 'review'
                            ? '/tantra/review'
                            : `/tantra/review?tab=${tab}`
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
                v-if="!current"
                class="rounded-xl border border-dashed border-border p-10 text-center"
            >
                <div class="font-medium">
                    {{ $t(`tantra.review.feed.empty_${tab}_title`) }}
                </div>
                <div class="text-sm text-muted-foreground">
                    {{ $t(`tantra.review.feed.empty_${tab}_body`) }}
                </div>
            </div>

            <template v-else>
                <div
                    class="text-center text-xs text-muted-foreground"
                    data-testid="tantra-position"
                >
                    {{
                        $t('tantra.review.feed.position', {
                            n: String(index + 1),
                            total: String(items.length),
                        })
                    }}
                </div>

                <div
                    class="relative flex items-center justify-center gap-3 md:gap-6"
                    @touchstart.passive="onTouchStart"
                    @touchend.passive="onTouchEnd"
                >
                    <button
                        type="button"
                        class="z-10 flex size-14 shrink-0 items-center justify-center self-center rounded-full bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500 text-white shadow-lg ring-4 shadow-indigo-500/30 ring-white/70 transition hover:scale-105 hover:shadow-xl disabled:pointer-events-none disabled:opacity-25 max-sm:absolute max-sm:top-[30vh] max-sm:left-1 md:size-16 dark:ring-black/40"
                        :disabled="index === 0"
                        :aria-label="$t('tantra.review.feed.previous')"
                        data-testid="tantra-prev"
                        @click="go(-1)"
                    >
                        <IconChevronLeft
                            class="size-8 md:size-9"
                            stroke-width="2.5"
                        />
                    </button>

                    <Transition
                        mode="out-in"
                        enter-active-class="transition duration-150 ease-out"
                        enter-from-class="opacity-0 translate-y-1"
                        leave-active-class="transition duration-100 ease-in"
                        leave-to-class="opacity-0"
                    >
                        <ReviewCard
                            :key="current.id"
                            :item="current"
                            :viewer="viewer"
                            :mode="tab"
                            @changed="reload"
                        />
                    </Transition>

                    <button
                        type="button"
                        class="z-10 flex size-14 shrink-0 items-center justify-center self-center rounded-full bg-gradient-to-br from-violet-500 via-indigo-500 to-sky-500 text-white shadow-lg ring-4 shadow-indigo-500/30 ring-white/70 transition hover:scale-105 hover:shadow-xl disabled:pointer-events-none disabled:opacity-25 max-sm:absolute max-sm:top-[30vh] max-sm:right-1 md:size-16 dark:ring-black/40"
                        :disabled="index >= items.length - 1"
                        :aria-label="$t('tantra.review.feed.next')"
                        data-testid="tantra-next"
                        @click="go(1)"
                    >
                        <IconChevronRight
                            class="size-8 md:size-9"
                            stroke-width="2.5"
                        />
                    </button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
