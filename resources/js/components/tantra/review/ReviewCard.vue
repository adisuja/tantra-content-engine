<script setup lang="ts">
// tantra: one post on the review screen (PRD 10.2, 2026-11-12): the full phone
// preview, and beside it only Approve and schedule and a feedback box with
// Revise. Under the buttons, folded away, how every part of the post goes out:
// the post and first comment through its channel, each team comment from that
// teammate's Unipile account, each reply from the author (or the page).
import { IconDownload, IconExternalLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import { previewFor } from '@/components/tantra/review/platforms';
import type { ReviewAction } from '@/components/tantra/review/reviewApi';
import { sendReviewAction } from '@/components/tantra/review/reviewApi';
import type {
    DeliveryEntry,
    ReviewItemRow,
} from '@/components/tantra/review/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    item: ReviewItemRow;
    viewer: { name?: string; avatar?: string | null };
    mode: 'review' | 'scheduled' | 'published';
    showWorkspace?: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();

const busy = ref(false);
const note = ref('');

const platform = computed(() => previewFor(props.item));
const revising = computed(
    () => props.item.review_status === 'changes_requested',
);
const openNumbers = computed(() =>
    props.item.numbers_for_signoff.filter((n) => !n.signed_at),
);
const lastFeedback = computed(
    () =>
        [...props.item.events]
            .reverse()
            .find((e) => e.kind === 'request_changes')?.body ?? null,
);

const when = (iso?: string | null): string =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';

const planned = computed(() => {
    const p = props.item.planned;
    if (!p.date) return '';
    const day = new Date(`${p.date}T12:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

    return [day, p.time].filter(Boolean).join(', ');
});

const run = async (
    action: ReviewAction,
    body?: Record<string, unknown>,
): Promise<boolean> => {
    busy.value = true;
    const result = await sendReviewAction(props.item.id, action, body);
    busy.value = false;
    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return false;
    }
    toast.success(result.message || trans('tantra.review.saved'));
    emit('changed');

    return true;
};

const approve = (): Promise<boolean> => run('approve');
const undo = (): Promise<boolean> => run('unapprove');
const confirmNumber = (index: number): Promise<boolean> =>
    run('sign-off', { index });
const revise = async (): Promise<void> => {
    if (await run('request-changes', { note: note.value })) {
        note.value = '';
    }
};
const markDm = (id: string, sent: boolean): Promise<boolean> =>
    run(`dms/${id}`, { sent });

const metric = (v: number | null | undefined): string =>
    v === null || v === undefined
        ? trans('tantra.review.feed.not_available')
        : v.toLocaleString();

/* How it goes out */
const delivery = computed(() => props.item.delivery ?? null);
const textOf = (index: number): string =>
    props.item.team_comments.find((c) => c.index === index)?.text ?? '';
const tops = computed(
    () => delivery.value?.entries.filter((e) => e.kind === 'comment') ?? [],
);
const repliesTo = (index: number): DeliveryEntry[] =>
    delivery.value?.entries.filter((e) => e.reply_to === index) ?? [];
const counts = computed(() => {
    const all = delivery.value?.entries ?? [];
    const good = ['ready', 'queued', 'posted'];

    return {
        comments: tops.value.length,
        replies: all.filter((e) => e.kind === 'reply').length,
        held: all.filter(
            (e) => !good.includes(e.state) && e.state !== 'removed',
        ).length,
    };
});
const stateClass: Record<DeliveryEntry['state'], string> = {
    ready: 'text-emerald-700 dark:text-emerald-400',
    queued: 'text-emerald-700 dark:text-emerald-400',
    posted: 'text-emerald-700 dark:text-emerald-400',
    blocked: 'text-amber-700 dark:text-amber-400',
    off: 'text-muted-foreground',
    removed: 'text-muted-foreground line-through',
    failed: 'text-red-700 dark:text-red-400',
    skipped: 'text-amber-700 dark:text-amber-400',
};
</script>

<template>
    <article
        class="flex w-full flex-col items-center gap-5 lg:flex-row lg:items-start lg:justify-center"
        :data-testid="`tantra-card-${item.engine_post_id}`"
    >
        <div class="w-full max-w-[372px] shrink-0">
            <PhonePreview :item="item" :viewer="viewer" />
        </div>

        <aside
            class="flex w-full max-w-[372px] flex-col gap-4 text-sm lg:max-w-sm"
        >
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <b class="text-base">{{ item.profile.name }}</b>
                    <Badge variant="outline">{{ platform.label }}</Badge>
                    <Badge
                        v-if="mode === 'review' && revising"
                        class="bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100"
                        data-testid="tantra-revision-pending"
                        >{{ $t('tantra.review.feed.revision_pending') }}</Badge
                    >
                </div>
                <div class="text-muted-foreground">
                    {{
                        [showWorkspace ? item.workspace : null, planned]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </div>
            </div>

            <!-- Review: Approve and schedule, or feedback and Revise -->
            <template v-if="mode === 'review'">
                <div
                    v-if="revising && lastFeedback"
                    class="rounded-lg bg-amber-50 p-3 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <div>{{ $t('tantra.review.feed.reworking') }}</div>
                    <div class="mt-1 italic opacity-80">
                        “{{ lastFeedback }}”
                    </div>
                </div>

                <div
                    v-if="item.can_review && openNumbers.length"
                    class="flex flex-col gap-2"
                >
                    <div class="font-medium">
                        {{ $t('tantra.review.feed.confirm_numbers') }}
                    </div>
                    <div
                        v-for="n in openNumbers"
                        :key="n.index"
                        class="flex items-center gap-2"
                    >
                        <span class="min-w-0 flex-1">{{ n.text }}</span>
                        <Button
                            v-if="n.can_sign"
                            size="sm"
                            variant="outline"
                            :disabled="busy"
                            @click="confirmNumber(n.index)"
                            >{{ $t('tantra.review.feed.confirm') }}</Button
                        >
                        <span v-else class="text-xs text-muted-foreground">{{
                            $t('tantra.review.feed.adi_confirms')
                        }}</span>
                    </div>
                </div>

                <template v-if="item.can_review">
                    <Button
                        size="lg"
                        class="w-full"
                        :disabled="busy"
                        data-testid="tantra-approve"
                        @click="approve"
                    >
                        {{
                            item.assisted_manual
                                ? $t('tantra.review.feed.approve_manual')
                                : $t('tantra.review.feed.approve')
                        }}
                    </Button>
                    <div class="flex flex-col gap-2">
                        <Textarea
                            v-model="note"
                            rows="4"
                            :placeholder="
                                $t('tantra.review.feed.feedback_placeholder')
                            "
                            data-testid="tantra-feedback"
                        />
                        <Button
                            variant="outline"
                            class="w-full"
                            :disabled="busy || !note.trim()"
                            data-testid="tantra-revise"
                            @click="revise"
                            >{{ $t('tantra.review.feed.revise') }}</Button
                        >
                        <div class="text-xs text-muted-foreground">
                            {{ $t('tantra.review.feed.feedback_help') }}
                        </div>
                    </div>
                </template>
                <div v-else class="text-muted-foreground">
                    {{ $t('tantra.review.feed.not_yours') }}
                </div>
            </template>

            <!-- Scheduled -->
            <template v-else-if="mode === 'scheduled'">
                <div
                    class="rounded-lg bg-emerald-50 p-3 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                >
                    {{
                        item.assisted_manual
                            ? $t('tantra.review.feed.approved_manual')
                            : $t('tantra.review.feed.scheduled_for', {
                                  when: when(item.scheduled_at) || planned,
                              })
                    }}
                </div>
                <Button
                    v-if="item.can_review"
                    variant="outline"
                    class="w-full"
                    :disabled="busy"
                    @click="undo"
                    >{{ $t('tantra.review.feed.undo') }}</Button
                >
            </template>

            <!-- Published -->
            <template v-else>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-muted-foreground">{{
                        $t('tantra.review.feed.published_on', {
                            when: when(item.published_at) || planned,
                        })
                    }}</span>
                    <a
                        v-if="item.post_url"
                        :href="item.post_url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 underline"
                        ><IconExternalLink class="size-4" />{{
                            $t('tantra.review.feed.open_post', {
                                network: platform.label,
                            })
                        }}</a
                    >
                </div>
                <dl class="grid grid-cols-3 gap-2 text-center">
                    <div
                        v-for="m in [
                            'reactions',
                            'comments',
                            'impressions',
                        ] as const"
                        :key="m"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ $t(`tantra.review.feed.${m}`) }}
                        </dt>
                        <dd class="font-medium">
                            {{ metric(item.metrics?.[m]) }}
                        </dd>
                    </div>
                </dl>
                <div v-if="item.dm_list.length" class="flex flex-col gap-2">
                    <div class="font-medium">
                        {{
                            $t('tantra.review.feed.dms_title', {
                                resource: item.resource?.name ?? '',
                            })
                        }}
                    </div>
                    <label
                        v-for="dm in item.dm_list"
                        :key="dm.id"
                        class="flex items-center gap-2"
                    >
                        <input
                            type="checkbox"
                            :checked="dm.sent"
                            :disabled="busy"
                            @change="
                                markDm(
                                    dm.id,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        <a
                            v-if="dm.url"
                            :href="dm.url"
                            target="_blank"
                            rel="noopener"
                            class="underline"
                            >{{ dm.name }}</a
                        >
                        <span v-else>{{ dm.name }}</span>
                    </label>
                </div>
            </template>

            <!-- How each part goes out, the same rules the Engine uses at go-live -->
            <details
                v-if="delivery"
                class="rounded-lg border border-border p-3"
                data-testid="tantra-delivery"
            >
                <summary class="cursor-pointer font-medium">
                    {{
                        $t('tantra.review.feed.delivery_title', {
                            comments: String(counts.comments),
                            replies: String(counts.replies),
                        })
                    }}
                    <span
                        v-if="counts.held"
                        class="ml-1 text-amber-700 dark:text-amber-400"
                        >·
                        {{
                            $t('tantra.review.feed.delivery_held', {
                                count: String(counts.held),
                            })
                        }}</span
                    >
                </summary>
                <div class="mt-3 flex flex-col gap-3">
                    <div>
                        <div>
                            {{
                                $t(
                                    `tantra.review.feed.post_via.${delivery.post.via}`,
                                    { account: delivery.post.account ?? '' },
                                )
                            }}
                        </div>
                        <div
                            v-if="delivery.post.reason"
                            class="text-xs text-amber-700 dark:text-amber-400"
                        >
                            {{ delivery.post.reason }}
                        </div>
                    </div>
                    <div
                        v-for="c in tops"
                        :key="c.index"
                        class="flex flex-col gap-1"
                    >
                        <div>
                            <b>{{ c.poster }}</b>
                            <span class="text-muted-foreground">
                                {{
                                    $t(
                                        `tantra.review.feed.via.${c.via}_comment`,
                                    )
                                }}</span
                            >
                            ·
                            <span :class="stateClass[c.state]">{{
                                $t(`tantra.review.feed.state.${c.state}`)
                            }}</span>
                        </div>
                        <div class="line-clamp-2 text-xs text-muted-foreground">
                            {{ textOf(c.index) }}
                        </div>
                        <div
                            v-if="c.reason"
                            class="text-xs text-amber-700 dark:text-amber-400"
                        >
                            {{ c.reason }}
                        </div>
                        <div
                            v-for="r in repliesTo(c.index)"
                            :key="r.index"
                            class="ml-4 flex flex-col gap-1 border-l border-border pl-3"
                        >
                            <div>
                                <b>{{ r.poster }}</b>
                                <span class="text-muted-foreground">
                                    {{
                                        $t(
                                            `tantra.review.feed.via.${r.via}_reply`,
                                        )
                                    }}</span
                                >
                                ·
                                <span :class="stateClass[r.state]">{{
                                    $t(`tantra.review.feed.state.${r.state}`)
                                }}</span>
                            </div>
                            <div
                                class="line-clamp-2 text-xs text-muted-foreground"
                            >
                                {{ textOf(r.index) }}
                            </div>
                            <div
                                v-if="r.reason"
                                class="text-xs text-amber-700 dark:text-amber-400"
                            >
                                {{ r.reason }}
                            </div>
                        </div>
                    </div>
                </div>
            </details>

            <a
                v-if="item.media.download"
                :href="item.media.download.url"
                class="inline-flex items-center gap-1 text-xs text-muted-foreground underline"
                download
                ><IconDownload class="size-4" />{{
                    item.media.download.label
                }}</a
            >
        </aside>
    </article>
</template>
