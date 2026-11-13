<script setup lang="ts">
// tantra: one post on the review screen (PRD 10.2, 2026-11-12): the full phone
// preview and, beside it, only the controls: Approve and schedule, and a
// feedback field with Revise. Approving also confirms the numbers this person
// may sign off. Scheduled posts get Undo; published posts open on the network.
import { IconLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import AssetPanel from '@/components/tantra/review/AssetPanel.vue';
import MessagesPanel from '@/components/tantra/review/MessagesPanel.vue';
import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import { previewFor } from '@/components/tantra/review/platforms';
import type { ReviewAction } from '@/components/tantra/review/reviewApi';
import { sendReviewAction } from '@/components/tantra/review/reviewApi';
import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    item: ReviewItemRow;
    viewer: { name?: string; avatar?: string | null };
    mode: 'review' | 'scheduled' | 'published';
}>();
const emit = defineEmits<{ changed: [] }>();

const busy = ref(false);
const note = ref('');

const pending = computed(
    () => props.item.review_status === 'changes_requested',
);
// Numbers only Adi may confirm on a teammate's post: Adi gets one button for them.
const adiNumbers = computed(() =>
    props.item.numbers_for_signoff.filter((n) => !n.signed_at && n.can_sign),
);

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

const revise = async (): Promise<void> => {
    if (await run('request-changes', { note: note.value })) {
        note.value = '';
    }
};
const confirmNumbers = async (): Promise<void> => {
    for (const n of adiNumbers.value) {
        if (!(await run('sign-off', { index: n.index }))) return;
    }
};
</script>

<template>
    <article
        class="grid w-full grid-cols-1 justify-items-center gap-5 xl:grid-cols-[minmax(0,464px)_minmax(300px,1fr)_288px] xl:items-start xl:justify-items-stretch xl:gap-6"
        :data-testid="`tantra-card-${item.engine_post_id}`"
    >
        <div class="w-full max-w-[464px] xl:justify-self-start">
            <PhonePreview :item="item" :viewer="viewer" />
        </div>

        <div
            class="order-3 flex w-full max-w-[464px] flex-col gap-4 xl:order-none xl:max-w-none"
        >
            <AssetPanel :item="item" />
            <MessagesPanel
                :item="item"
                :editable="item.can_review && mode !== 'published'"
                @changed="emit('changed')"
            />
        </div>

        <aside
            class="flex w-full max-w-[464px] flex-col gap-3 xl:sticky xl:top-6 xl:max-w-none xl:justify-self-end"
        >
            <template v-if="mode === 'review' && item.can_review">
                <Button
                    size="lg"
                    class="h-12 w-full text-base"
                    :disabled="busy"
                    data-testid="tantra-approve"
                    @click="run('approve')"
                >
                    {{ $t('tantra.review.feed.approve') }}
                </Button>
                <Textarea
                    v-model="note"
                    rows="4"
                    :placeholder="$t('tantra.review.feed.feedback_placeholder')"
                    data-testid="tantra-feedback"
                />
                <Button
                    variant="outline"
                    size="lg"
                    class="h-12 w-full text-base"
                    :disabled="busy || !note.trim()"
                    data-testid="tantra-revise"
                    @click="revise"
                >
                    {{
                        pending && !note.trim()
                            ? $t('tantra.review.feed.revision_pending')
                            : $t('tantra.review.feed.revise')
                    }}
                </Button>
            </template>

            <Button
                v-else-if="mode === 'review' && adiNumbers.length"
                size="lg"
                class="h-12 w-full text-base"
                :disabled="busy"
                @click="confirmNumbers"
            >
                {{ $t('tantra.review.feed.confirm_numbers_button') }}
            </Button>

            <Button
                v-else-if="mode === 'scheduled' && item.can_review"
                variant="outline"
                size="lg"
                class="h-12 w-full text-base"
                :disabled="busy"
                @click="run('unapprove')"
            >
                {{ $t('tantra.review.feed.undo') }}
            </Button>

            <Button
                v-else-if="mode === 'published' && item.post_url"
                size="lg"
                class="h-12 w-full text-base"
                as-child
            >
                <a :href="item.post_url" target="_blank" rel="noopener">{{
                    $t('tantra.review.feed.open_post', {
                        network: previewFor(item).label,
                    })
                }}</a>
            </Button>

            <Button
                variant="ghost"
                size="sm"
                class="w-full"
                data-testid="tantra-share-link"
                @click="
                    copyToClipboard(
                        item.share_url,
                        trans('tantra.review.share.copied'),
                    )
                "
            >
                <IconLink class="size-4" />
                {{ $t('tantra.review.share.copy') }}
            </Button>

            <div
                v-if="mode === 'published' && item.dm_list.length"
                class="flex flex-col gap-2 text-sm"
            >
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
                            run(`dms/${dm.id}`, {
                                sent: ($event.target as HTMLInputElement)
                                    .checked,
                            })
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
                    <button
                        v-if="dm.message"
                        type="button"
                        class="ml-auto text-xs text-muted-foreground underline"
                        @click.prevent="
                            copyToClipboard(
                                dm.message,
                                trans('tantra.review.messages.copied'),
                            )
                        "
                    >
                        {{ $t('tantra.review.messages.copy_dm') }}
                    </button>
                </label>
            </div>
        </aside>
    </article>
</template>
