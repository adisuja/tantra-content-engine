<script setup lang="ts">
// tantra: "Two-minute Monday" (PRD 10.2 screen 6). Full screen, one post per
// screen, swipe up for the next, a big Approve and a short changes note.
import { IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import { sendReviewAction } from '@/components/tantra/review/reviewApi';
import type { ReviewItemRow } from '@/components/tantra/review/types';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    items: ReviewItemRow[];
    viewer: { name?: string; avatar?: string | null };
}>();
const emit = defineEmits<{ close: []; changed: [] }>();

const scroller = ref<HTMLElement | null>(null);
const index = ref(0);
const busy = ref(false);
const noteFor = ref<string | null>(null);
const note = ref('');
const viewport = ref(window.innerHeight);

const phoneHeight = computed(() => Math.max(320, viewport.value - 190));

const onScroll = (): void => {
    const el = scroller.value;
    if (el) {
        index.value = Math.round(el.scrollTop / el.clientHeight);
    }
};

const next = (): void => {
    const el = scroller.value;
    nextTick(() => el?.scrollBy({ top: el.clientHeight, behavior: 'smooth' }));
};

const waiting = (item: ReviewItemRow): boolean =>
    item.review_status === 'in_review' ||
    item.review_status === 'changes_requested';

const approve = async (item: ReviewItemRow): Promise<void> => {
    busy.value = true;
    const result = await sendReviewAction(item.id, 'approve');
    busy.value = false;
    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return;
    }
    toast.success(result.message || trans('tantra.review.saved'));
    emit('changed');
    next();
};

const sendChanges = async (item: ReviewItemRow): Promise<void> => {
    busy.value = true;
    const result = await sendReviewAction(item.id, 'request-changes', {
        note: note.value,
    });
    busy.value = false;
    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return;
    }
    toast.success(result.message || trans('tantra.review.saved'));
    noteFor.value = null;
    note.value = '';
    emit('changed');
    next();
};

const openNumber = (item: ReviewItemRow) =>
    item.numbers_for_signoff.find((n) => !n.signed_at);

const onResize = (): void => {
    viewport.value = window.innerHeight;
};

onMounted(() => {
    document.body.style.overflow = 'hidden';
    window.addEventListener('resize', onResize);
});
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    window.removeEventListener('resize', onResize);
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex flex-col bg-[#f4f2ee] text-[#1d1d1f]"
        data-testid="tantra-mobile-review"
    >
        <header class="flex items-center gap-3 px-4 pt-3 pb-2">
            <span class="text-sm font-medium">
                {{
                    $t('tantra.review.position', {
                        n: String(Math.min(index + 1, items.length)),
                        total: String(items.length),
                    })
                }}
            </span>
            <button
                class="ml-auto flex size-9 items-center justify-center rounded-full bg-white shadow"
                :aria-label="$t('tantra.review.close')"
                @click="emit('close')"
            >
                <IconX class="size-5" />
            </button>
        </header>

        <div
            ref="scroller"
            class="min-h-0 flex-1 snap-y snap-mandatory overflow-y-auto"
            @scroll.passive="onScroll"
        >
            <section
                v-for="item in props.items"
                :key="item.id"
                class="flex h-full snap-start flex-col items-center gap-3 px-4 pb-4"
            >
                <PhonePreview
                    :item="item"
                    :viewer="viewer"
                    :max-scale="1"
                    :fit-height="phoneHeight"
                />

                <div class="flex w-full max-w-[420px] flex-col gap-2">
                    <div class="flex items-center gap-2 text-sm">
                        <b>{{ item.engine_post_id }}</b>
                        <span class="text-[#6b6b6b]">
                            {{
                                $t(`tantra.review.status.${item.review_status}`)
                            }}
                        </span>
                    </div>

                    <template v-if="item.can_review && waiting(item)">
                        <p
                            v-if="openNumber(item)"
                            class="text-xs text-[#6b6b6b]"
                        >
                            {{
                                $t('tantra.review.sign_first', {
                                    text: openNumber(item)?.text ?? '',
                                })
                            }}
                        </p>
                        <form
                            v-if="noteFor === item.id"
                            class="flex flex-col gap-2"
                            @submit.prevent="sendChanges(item)"
                        >
                            <Textarea
                                v-model="note"
                                rows="2"
                                class="bg-white"
                                :placeholder="
                                    $t('tantra.review.changes_placeholder')
                                "
                            />
                            <div class="flex gap-2">
                                <Button
                                    type="submit"
                                    class="h-12 flex-1"
                                    :disabled="busy || !note.trim()"
                                >
                                    {{ $t('tantra.review.send_changes') }}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    class="h-12"
                                    @click="noteFor = null"
                                >
                                    {{ $t('tantra.review.cancel') }}
                                </Button>
                            </div>
                        </form>
                        <div v-else class="flex gap-2">
                            <Button
                                class="h-14 flex-1 text-base"
                                :disabled="busy || !!openNumber(item)"
                                @click="approve(item)"
                            >
                                {{ $t('tantra.review.approve') }}
                            </Button>
                            <Button
                                variant="outline"
                                class="h-14 bg-white"
                                :disabled="busy"
                                @click="noteFor = item.id"
                            >
                                {{ $t('tantra.review.request_changes') }}
                            </Button>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </div>
</template>
