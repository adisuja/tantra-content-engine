<script setup lang="ts">
// tantra: one post on the review screen (PRD 10.2, 2026-11-12): the full phone
// preview as the network will show it, and next to it only what the reviewer
// needs: Approve and schedule, or feedback for the next round of the content
// engine. Published posts show their link, numbers and the DMs to send.
import { IconDownload, IconExternalLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import { previewFor } from '@/components/tantra/review/platforms';
import type { ReviewAction } from '@/components/tantra/review/reviewApi';
import { sendReviewAction } from '@/components/tantra/review/reviewApi';
import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    item: ReviewItemRow;
    viewer: { name?: string; avatar?: string | null };
    mode: 'review' | 'published';
    showWorkspace?: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();

const busy = ref(false);
const feedbackOpen = ref(false);
const note = ref('');

const platform = computed(() => previewFor(props.item));
const status = computed(() => props.item.review_status);
const waiting = computed(() => status.value === 'in_review');
const scheduled = computed(
    () => status.value === 'approved' || status.value === 'scheduled',
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
const sendFeedback = async (): Promise<void> => {
    if (await run('request-changes', { note: note.value })) {
        note.value = '';
        feedbackOpen.value = false;
    }
};
const markDm = (id: string, sent: boolean): Promise<boolean> =>
    run(`dms/${id}`, { sent });

const metric = (v: number | null | undefined): string =>
    v === null || v === undefined
        ? trans('tantra.review.feed.not_available')
        : v.toLocaleString();
</script>

<template>
    <article
        :id="item.engine_post_id"
        class="flex scroll-mt-6 flex-col items-center gap-5 lg:flex-row lg:items-start lg:justify-center"
        :data-testid="`tantra-card-${item.engine_post_id}`"
    >
        <div class="w-full max-w-[372px] shrink-0">
            <PhonePreview :item="item" :viewer="viewer" />
        </div>

        <aside
            class="flex w-full max-w-[372px] flex-col gap-4 rounded-xl border border-border bg-card p-4 text-sm lg:sticky lg:top-6 lg:max-w-sm"
        >
            <div class="flex flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <b class="text-base">{{ item.profile.name }}</b>
                    <Badge variant="outline">{{ platform.label }}</Badge>
                </div>
                <div class="text-muted-foreground">
                    {{
                        [showWorkspace ? item.workspace : null, planned]
                            .filter(Boolean)
                            .join(' · ')
                    }}
                </div>
            </div>

            <!-- To review -->
            <template v-if="mode === 'review'">
                <div
                    v-if="scheduled"
                    class="rounded-lg bg-emerald-50 p-3 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                >
                    {{
                        item.assisted_manual
                            ? $t('tantra.review.feed.approved_manual')
                            : $t('tantra.review.feed.scheduled_for', {
                                  when:
                                      when(item.scheduled_at) || planned || '',
                              })
                    }}
                </div>
                <div
                    v-else-if="status === 'changes_requested'"
                    class="rounded-lg bg-amber-50 p-3 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <div>{{ $t('tantra.review.feed.feedback_sent') }}</div>
                    <div v-if="lastFeedback" class="mt-1 italic opacity-80">
                        “{{ lastFeedback }}”
                    </div>
                </div>

                <div
                    v-if="item.can_review && openNumbers.length && !scheduled"
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
                    <div v-if="!scheduled" class="flex flex-col gap-2">
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
                        <Button
                            v-if="!feedbackOpen"
                            variant="outline"
                            class="w-full"
                            :disabled="busy"
                            @click="feedbackOpen = true"
                        >
                            {{
                                waiting
                                    ? $t('tantra.review.feed.give_feedback')
                                    : $t('tantra.review.feed.more_feedback')
                            }}
                        </Button>
                        <div v-else class="flex flex-col gap-2">
                            <Textarea
                                v-model="note"
                                rows="4"
                                :placeholder="
                                    $t(
                                        'tantra.review.feed.feedback_placeholder',
                                    )
                                "
                                data-testid="tantra-feedback"
                            />
                            <div class="text-xs text-muted-foreground">
                                {{ $t('tantra.review.feed.feedback_help') }}
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    class="flex-1"
                                    :disabled="busy || !note.trim()"
                                    @click="sendFeedback"
                                    >{{
                                        $t('tantra.review.feed.send_feedback')
                                    }}</Button
                                >
                                <Button
                                    variant="ghost"
                                    :disabled="busy"
                                    @click="feedbackOpen = false"
                                    >{{
                                        $t('tantra.review.feed.cancel')
                                    }}</Button
                                >
                            </div>
                        </div>
                    </div>
                    <Button
                        v-else
                        variant="outline"
                        class="w-full"
                        :disabled="busy"
                        @click="undo"
                        >{{ $t('tantra.review.feed.undo') }}</Button
                    >
                </template>
                <div v-else class="text-muted-foreground">
                    {{ $t('tantra.review.feed.not_yours') }}
                </div>
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
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ $t('tantra.review.feed.reactions') }}
                        </dt>
                        <dd class="font-medium">
                            {{ metric(item.metrics?.reactions) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ $t('tantra.review.feed.comments') }}
                        </dt>
                        <dd class="font-medium">
                            {{ metric(item.metrics?.comments) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            {{ $t('tantra.review.feed.impressions') }}
                        </dt>
                        <dd class="font-medium">
                            {{ metric(item.metrics?.impressions) }}
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
