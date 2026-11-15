<script setup lang="ts">
// tantra: Engagement > Sequences. The Engagement stages as columns, each with its sequence cards, edited in place
// through the CRM's sequence API. Engaged and Requested start the moment someone engages; later stages start when a
// person reaches them. A sequence in test mode goes live only through Approve and start on its post.
import { IconFlask, IconPlus } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { get, patch, post } from '@/components/tantra/engagement/api';
import SequenceEditor from '@/components/tantra/engagement/SequenceEditor.vue';
import {
    type BoardSeq,
    type SeqBoard,
    type Sequence,
    stepNoun,
} from '@/components/tantra/engagement/shared';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';

const props = defineProps<{ canManage: boolean }>();

const b = ref<SeqBoard | null>(null);
const editing = ref<string | null>(null);
const creating = ref(false);

const load = async () => {
    const r = await get<SeqBoard>('/sequence-board');
    if (r.ok) {
        b.value = r.data;
    } else if (r.code !== 'crm_unreachable' && r.code !== 'crm_not_connected') {
        toast.error(r.message || trans('tantra.engagement.refused'));
    }
};
onMounted(load);

const create = async (stageId: string) => {
    if (creating.value || !props.canManage) {
        return;
    }
    creating.value = true;
    const r = await post<{ sequence: Sequence }>('/sequences', {
        stage_id: stageId,
    });
    creating.value = false;
    if (!r.ok || !r.data) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    editing.value = r.data.sequence.id;
};

const toggle = async (s: BoardSeq) => {
    const status = s.status === 'active' ? 'paused' : 'active';
    const before = s.status;
    s.status = status;
    const r = await patch(`/sequences/${s.id}`, { status });
    if (!r.ok) {
        s.status = before;
        toast.error(r.message || trans('tantra.engagement.refused'));
    }
};
</script>

<template>
    <div class="flex h-full flex-col">
        <p
            class="border-b border-border px-4 py-3 text-xs text-muted-foreground md:px-6"
        >
            {{ $t('tantra.engagement.sequences.intro') }}
        </p>
        <div v-if="!b" class="grid flex-1 place-items-center py-10">
            <Spinner />
        </div>
        <div
            v-else
            class="flex min-h-0 flex-1 snap-x snap-mandatory gap-3 overflow-x-auto px-4 py-4 md:snap-none md:px-6"
            data-testid="engagement-sequences"
        >
            <div
                v-for="st in b.stages"
                :key="st.id"
                class="flex w-[85vw] shrink-0 snap-start flex-col sm:w-72"
            >
                <div class="mb-2 flex items-center gap-2 px-1">
                    <span
                        class="size-2 rounded-full"
                        :style="{ background: st.color ?? '#94a3b8' }"
                    />
                    <span class="text-sm font-semibold">{{ st.name }}</span>
                    <span
                        v-if="st.deal_count > 0"
                        class="text-xs text-muted-foreground tabular-nums"
                        >{{ st.deal_count }}</span
                    >
                    <Button
                        v-if="canManage"
                        size="sm"
                        variant="ghost"
                        class="ml-auto h-7 px-2 text-xs"
                        :aria-label="
                            $t('tantra.engagement.sequences.new_for', {
                                stage: st.name,
                            })
                        "
                        @click="create(st.id)"
                        ><IconPlus class="size-3.5" />{{
                            $t('tantra.engagement.sequences.sequence')
                        }}</Button
                    >
                </div>
                <div
                    class="flex min-h-24 flex-1 flex-col gap-2 overflow-y-auto rounded-xl bg-muted/70 p-1.5"
                >
                    <div
                        v-for="s in st.sequences"
                        :key="s.id"
                        role="button"
                        tabindex="0"
                        class="flex cursor-pointer flex-col gap-2.5 rounded-xl border border-border bg-card p-3 transition hover:shadow-sm"
                        :data-testid="`engagement-sequence-${s.id}`"
                        @click="editing = s.id"
                        @keydown.enter="editing = s.id"
                    >
                        <div class="flex items-start gap-2">
                            <p
                                class="min-w-0 flex-1 text-sm leading-snug font-medium"
                            >
                                {{ s.name }}
                            </p>
                            <span @click.stop>
                                <Switch
                                    size="sm"
                                    :model-value="s.status === 'active'"
                                    :disabled="!canManage || !s.steps.length"
                                    :aria-label="
                                        $t(
                                            s.status === 'active'
                                                ? 'tantra.engagement.sequences.turn_off'
                                                : 'tantra.engagement.sequences.turn_on',
                                            { name: s.name },
                                        )
                                    "
                                    @update:model-value="toggle(s)"
                                />
                            </span>
                        </div>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                s.steps.length
                                    ? s.steps
                                          .slice(0, 6)
                                          .map((x) =>
                                              stepNoun(x.channel, x.action),
                                          )
                                          .join(' · ')
                                    : $t('tantra.engagement.sequences.no_steps')
                            }}
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{
                                $t('tantra.engagement.sequences.counts', {
                                    running: String(s.active_count ?? 0),
                                    sent: String(s.sent_count ?? 0),
                                })
                            }}
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <span
                                v-if="s.settings.dry_run"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-800"
                                ><IconFlask class="size-3" />{{
                                    $t('tantra.engagement.test_mode')
                                }}</span
                            >
                            <span
                                v-if="s.all_posts"
                                class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                >{{
                                    $t('tantra.engagement.people.every_post')
                                }}</span
                            >
                            <template v-else>
                                <span
                                    v-for="p in s.posts.slice(0, 2)"
                                    :key="p.id"
                                    class="max-w-full truncate rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                    :title="p.title"
                                    >{{ p.title }}</span
                                >
                                <span
                                    v-if="s.posts.length > 2"
                                    class="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground"
                                    >+{{ s.posts.length - 2 }}</span
                                >
                            </template>
                        </div>
                    </div>
                    <button
                        v-if="!st.sequences.length"
                        type="button"
                        class="rounded-xl border border-dashed border-border px-3 py-6 text-center text-xs text-muted-foreground transition hover:text-foreground disabled:cursor-default"
                        :disabled="!canManage"
                        @click="create(st.id)"
                    >
                        {{ $t('tantra.engagement.sequences.none') }}<br /><span
                            v-if="canManage"
                            class="font-medium"
                            >{{ $t('tantra.engagement.sequences.add') }}</span
                        >
                    </button>
                </div>
            </div>
        </div>
        <SequenceEditor
            :id="editing"
            :can-manage="canManage"
            @close="
                editing = null;
                load();
            "
        />
    </div>
</template>
