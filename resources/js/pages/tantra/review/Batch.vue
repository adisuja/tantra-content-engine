<script setup lang="ts">
// tantra: feed review for one batch (PRD 10.2 screen 2).
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { IconArrowLeft, IconDeviceMobile, IconEye } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import MobileReview from '@/components/tantra/review/MobileReview.vue';
import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import ReviewPanel from '@/components/tantra/review/ReviewPanel.vue';
import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    batch: {
        id: string;
        title: string;
        engine_batch_id: string;
        dates_label: string | null;
        workspace: string | null;
    };
    items: ReviewItemRow[];
}>();

const page = usePage();
const viewer = computed(() => {
    const user = page.props.auth?.user as
        | { id?: string; name?: string; photo_url?: string | null }
        | undefined;

    return { name: user?.name ?? '', avatar: user?.photo_url ?? null };
});

type Filter = 'all' | 'waiting' | 'changes' | 'approved' | 'done';
const filter = ref<Filter>('all');
const format = ref<string>('all');

const isWaiting = (i: ReviewItemRow): boolean =>
    i.review_status === 'in_review';

const matches: Record<Filter, (i: ReviewItemRow) => boolean> = {
    all: () => true,
    waiting: isWaiting,
    changes: (i) => i.review_status === 'changes_requested',
    approved: (i) =>
        i.review_status === 'approved' || i.review_status === 'scheduled',
    done: (i) => ['published', 'rejected', 'failed'].includes(i.review_status),
};

const formats = computed(
    () =>
        [
            ...new Set(
                props.items
                    .map((i) => i.media.type || i.format)
                    .filter(Boolean),
            ),
        ] as string[],
);

const shown = computed(() =>
    props.items.filter(
        (i) =>
            matches[filter.value](i) &&
            (format.value === 'all' ||
                (i.media.type || i.format) === format.value),
    ),
);

const counts = computed(
    () =>
        Object.fromEntries(
            (Object.keys(matches) as Filter[]).map((k) => [
                k,
                props.items.filter(matches[k]).length,
            ]),
        ) as Record<Filter, number>,
);

const reload = (): void => {
    router.reload({ only: ['items'] });
};

/* Two reviewers at once: reload when anyone else acts on this batch. */
useEcho<{ item_id: string; kind: string; user_id: string | null }>(
    `tantra.review.batch.${props.batch.id}`,
    '.tantra.review.changed',
    (payload) => {
        const me = (page.props.auth?.user as { id?: string } | undefined)?.id;
        if (payload.user_id && payload.user_id === me) return;
        reload();
        const item = props.items.find((i) => i.id === payload.item_id);
        if (item) {
            toast(
                trans('tantra.review.updated_live', {
                    post: item.engine_post_id,
                }),
            );
        }
    },
);

const mobileIds = ref<string[] | null>(null);
const mobileItems = computed(() =>
    mobileIds.value
        ? mobileIds.value
              .map((id) => props.items.find((i) => i.id === id))
              .filter((i): i is ReviewItemRow => !!i)
        : [],
);
const openMobile = (): void => {
    const waiting = props.items.filter(
        (i) =>
            i.can_review &&
            (isWaiting(i) || i.review_status === 'changes_requested'),
    );
    mobileIds.value = (waiting.length ? waiting : props.items).map((i) => i.id);
};

const tabs: Filter[] = ['all', 'waiting', 'changes', 'approved', 'done'];
</script>

<template>
    <AppLayout full-width>
        <Head :title="batch.title" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex min-w-0 flex-col gap-3">
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <Link
                        href="/tantra/review"
                        class="flex size-9 items-center justify-center rounded-lg border border-border"
                        :aria-label="$t('tantra.review.back')"
                    >
                        <IconArrowLeft class="size-5" />
                    </Link>
                    <HeaderTitle :title="batch.title" :icon="IconEye" />
                    <span class="text-sm text-muted-foreground">
                        {{
                            [batch.workspace, batch.dates_label]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </span>
                    <Button
                        class="ml-auto"
                        variant="outline"
                        data-testid="tantra-open-mobile"
                        @click="openMobile"
                    >
                        <IconDeviceMobile class="size-4" />
                        {{ $t('tantra.review.phone_mode') }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="tab in tabs"
                        :key="tab"
                        size="sm"
                        :variant="filter === tab ? 'default' : 'outline'"
                        @click="filter = tab"
                    >
                        {{ $t(`tantra.review.filters.${tab}`) }}
                        <span class="opacity-70">{{ counts[tab] }}</span>
                    </Button>
                    <select
                        v-if="formats.length > 1"
                        v-model="format"
                        class="h-8 rounded-md border border-border bg-card px-2 text-sm"
                        :aria-label="$t('tantra.review.format')"
                    >
                        <option value="all">
                            {{ $t('tantra.review.filters.all_formats') }}
                        </option>
                        <option v-for="f in formats" :key="f" :value="f">
                            {{ f }}
                        </option>
                    </select>
                </div>
            </header>

            <p
                v-if="!shown.length"
                class="rounded-xl border border-dashed border-border p-8 text-center text-muted-foreground"
            >
                {{ $t('tantra.review.nothing_here') }}
            </p>

            <article
                v-for="item in shown"
                :id="item.engine_post_id"
                :key="item.id"
                class="grid min-w-0 scroll-mt-6 items-start gap-6 lg:grid-cols-[372px_minmax(0,1fr)]"
            >
                <PhonePreview :item="item" :viewer="viewer" />
                <ReviewPanel :item="item" @changed="reload" />
            </article>
        </div>

        <MobileReview
            v-if="mobileIds"
            :items="mobileItems"
            :viewer="viewer"
            @changed="reload"
            @close="mobileIds = null"
        />
    </AppLayout>
</template>
