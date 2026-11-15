<script setup lang="ts">
// tantra: Engagement > Pipeline. The Engagement stages as a board (Engaged, Requested, Invite sent, Connected,
// Delivered, Replied, Booked), each person a card with the post, the trigger and the pending action. Your team is
// not shown. Moving a card into a stage with a live sequence asks first: real messages go out.
import { IconFlask } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { get, post } from '@/components/tantra/engagement/api';
import {
    type Board,
    type Card,
    kindLabel,
    PENDING_TONE,
    pendingText,
    type Source,
    sourceTitle,
} from '@/components/tantra/engagement/shared';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{ canManage: boolean }>();

const b = ref<Board | null>(null);
const sources = ref<Source[]>([]);
const source = ref('');
const q = ref('');
const over = ref<string | null>(null);

onMounted(async () => {
    const r = await get<{ sources: Source[] }>('/sources');
    sources.value = r.data?.sources ?? [];
});

let seq = 0;
const load = async () => {
    const n = ++seq;
    const r = await get<Board>('/pipeline', {
        source_id: source.value || undefined,
        q: q.value || undefined,
    });
    if (n !== seq) {
        return;
    }
    if (r.ok) {
        b.value = r.data;
    } else if (r.code !== 'crm_unreachable' && r.code !== 'crm_not_connected') {
        toast.error(r.message || trans('tantra.engagement.refused'));
    }
};
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    [source, q],
    () => {
        clearTimeout(timer);
        timer = setTimeout(load, 180);
    },
    { immediate: true },
);

const onDragStart = (e: DragEvent, card: Card) => {
    e.dataTransfer?.setData('text/plain', card.deal_id);
};

const onDrop = async (e: DragEvent, toId: string) => {
    over.value = null;
    const id = e.dataTransfer?.getData('text/plain');
    if (!b.value || !id || !props.canManage) {
        return;
    }
    const card = b.value.stages
        .flatMap((s) => s.cards)
        .find((c) => c.deal_id === id);
    const target = b.value.stages.find((s) => s.id === toId);
    if (!card || !target || card.stage_id === toId) {
        return;
    }
    if (
        target.starts.length &&
        !window.confirm(
            trans('tantra.engagement.pipeline.confirm', {
                name: card.name ?? trans('tantra.engagement.someone'),
                stage: target.name,
                sequences: target.starts.map((s) => `"${s.name}"`).join(', '),
            }),
        )
    ) {
        return;
    }
    seq++;
    const from = card.stage_id;
    // Optimistic: the card moves now; the CRM runs the stage's sequences.
    b.value = {
        ...b.value,
        stages: b.value.stages.map((s) =>
            s.id === from
                ? {
                      ...s,
                      count: s.count - 1,
                      cards: s.cards.filter((c) => c.deal_id !== id),
                  }
                : s.id === toId
                  ? {
                        ...s,
                        count: s.count + 1,
                        cards: [{ ...card, stage_id: toId }, ...s.cards],
                    }
                  : s,
        ),
    };
    const r = await post(`/pipeline/${id}/move`, { stage_id: toId });
    if (!r.ok) {
        toast.error(r.message || trans('tantra.engagement.refused'));
    } else {
        toast.success(
            trans('tantra.engagement.pipeline.moved', {
                name: card.name ?? trans('tantra.engagement.someone'),
                stage: target.name,
            }),
        );
    }
    await load();
};
</script>

<template>
    <div class="flex h-full flex-col">
        <div
            class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3 md:px-6"
        >
            <Input
                v-model="q"
                class="w-full sm:w-48"
                :placeholder="$t('tantra.engagement.people.search')"
                :aria-label="$t('tantra.engagement.people.search')"
            />
            <NativeSelect
                v-model="source"
                class="w-full sm:w-auto sm:max-w-[16rem]"
                :aria-label="$t('tantra.engagement.people.post')"
            >
                <NativeSelectOption value="">{{
                    $t('tantra.engagement.people.every_post')
                }}</NativeSelectOption>
                <NativeSelectOption
                    v-for="s in sources"
                    :key="s.id"
                    :value="s.id"
                    >{{ sourceTitle(s) }}</NativeSelectOption
                >
            </NativeSelect>
            <span v-if="b" class="ml-auto text-xs text-muted-foreground">{{
                $t('tantra.engagement.pipeline.total', {
                    count: String(b.total),
                })
            }}</span>
        </div>
        <div v-if="!b" class="grid flex-1 place-items-center py-10">
            <Spinner />
        </div>
        <div
            v-else
            class="flex min-h-0 flex-1 snap-x snap-mandatory gap-3 overflow-x-auto px-4 py-4 md:snap-none md:px-6"
            data-testid="engagement-board"
        >
            <div
                v-for="s in b.stages"
                :key="s.id"
                class="flex w-[85vw] shrink-0 snap-start flex-col sm:w-72"
            >
                <div
                    class="mb-2 flex items-center gap-2 px-1 text-sm font-semibold"
                >
                    <span
                        class="size-2 rounded-full"
                        :style="{ background: s.color ?? '#94a3b8' }"
                    />{{ s.name }}
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ s.count }}</span
                    >
                </div>
                <div
                    class="flex min-h-24 flex-1 flex-col gap-2 overflow-y-auto rounded-xl p-1.5 transition"
                    :class="
                        over === s.id
                            ? 'bg-info-subtle ring-2 ring-info'
                            : 'bg-muted/70'
                    "
                    @dragover.prevent="over = s.id"
                    @dragleave="over = over === s.id ? null : over"
                    @drop.prevent="onDrop($event, s.id)"
                >
                    <div
                        v-for="c in s.cards"
                        :key="c.deal_id"
                        :draggable="canManage"
                        class="flex flex-col gap-1.5 rounded-xl border border-border bg-card p-3 text-sm"
                        :class="canManage && 'cursor-grab'"
                        @dragstart="onDragStart($event, c)"
                    >
                        <div class="flex items-center gap-2">
                            <a
                                v-if="c.url"
                                :href="c.url"
                                target="_blank"
                                rel="noopener"
                                class="min-w-0 flex-1 truncate font-semibold hover:underline"
                                >{{
                                    c.name ?? $t('tantra.engagement.someone')
                                }}</a
                            >
                            <p
                                v-else
                                class="min-w-0 flex-1 truncate font-semibold"
                            >
                                {{ c.name ?? $t('tantra.engagement.someone') }}
                            </p>
                            <span
                                v-if="c.trigger"
                                class="shrink-0 rounded-full px-2 py-0.5 text-[11px]"
                                :class="
                                    c.trigger === 'keyword'
                                        ? 'bg-info-subtle text-info-text'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >{{ kindLabel(c.trigger) }}</span
                            >
                        </div>
                        <p
                            v-if="c.post"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ c.post.title }}
                        </p>
                        <span
                            class="inline-flex min-w-0 items-center gap-1.5 text-[11px] font-medium"
                            :class="PENDING_TONE[c.pending.state]"
                        >
                            <span class="truncate">{{
                                pendingText(c.pending)
                            }}</span>
                            <IconFlask
                                v-if="
                                    c.pending.test_mode &&
                                    (c.pending.state === 'due' ||
                                        c.pending.state === 'waiting')
                                "
                                class="size-3 shrink-0 text-amber-600"
                            />
                        </span>
                    </div>
                    <p
                        v-if="!s.cards.length"
                        class="px-2 py-6 text-center text-xs text-muted-foreground"
                    >
                        {{ $t('tantra.engagement.pipeline.nobody') }}
                    </p>
                    <p
                        v-if="s.count > s.cards.length"
                        class="px-2 py-1 text-center text-[11px] text-muted-foreground"
                    >
                        {{
                            $t('tantra.engagement.pipeline.more', {
                                count: String(s.count - s.cards.length),
                            })
                        }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
