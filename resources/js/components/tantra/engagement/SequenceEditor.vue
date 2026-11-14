<script setup lang="ts">
// tantra: one Engagement sequence, edited in place through the CRM's sequence API: its name and its steps (when,
// subject, message). Each step goes back whole, so settings the form does not show (conditions, sender, schedule)
// are kept. Test mode is not changed here; Approve and start on the post takes it live.
import {
    IconArrowDown,
    IconArrowUp,
    IconFlask,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { get, patch } from '@/components/tantra/engagement/api';
import {
    type Sequence,
    type Step,
    stepNoun,
} from '@/components/tantra/engagement/shared';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{ id: string | null; canManage: boolean }>();
const emit = defineEmits<{ close: [] }>();

const seq = ref<Sequence | null>(null);
const name = ref('');
const steps = ref<Step[]>([]);
const saving = ref(false);

// The step kinds the form adds; the CRM checks each against its channel and action list.
const ADDABLE: { channel: string; action: string }[] = [
    { channel: 'linkedin', action: 'connection_request' },
    { channel: 'linkedin', action: 'send_message' },
    { channel: 'linkedin', action: 'comment_reply' },
    { channel: 'email', action: 'send_email' },
    { channel: 'whatsapp', action: 'send_message' },
    { channel: 'sms', action: 'send_sms' },
    { channel: 'task', action: 'create_task' },
];
const adding = ref('linkedin:send_message');
const UNITS = ['minutes', 'hours', 'days', 'weeks'] as const;

watch(
    () => props.id,
    async (id) => {
        seq.value = null;
        if (!id) {
            return;
        }
        const r = await get<{ sequence: Sequence }>(`/sequences/${id}`);
        if (!r.ok || !r.data) {
            toast.error(r.message || trans('tantra.engagement.refused'));

            return;
        }
        seq.value = r.data.sequence;
        name.value = r.data.sequence.name;
        steps.value = JSON.parse(
            JSON.stringify(r.data.sequence.steps),
        ) as Step[];
    },
    { immediate: true },
);

const dirty = computed(
    () =>
        !!seq.value &&
        (name.value !== seq.value.name ||
            JSON.stringify(steps.value) !== JSON.stringify(seq.value.steps)),
);
const hasSubject = (s: Step) => s.channel === 'email' || s.channel === 'task';
const limit = (s: Step): number | null =>
    s.channel === 'linkedin' && s.action === 'connection_request'
        ? 300
        : s.channel === 'linkedin' && s.action === 'comment_reply'
          ? 1250
          : null;
const tooLong = computed(() =>
    steps.value.some(
        (s) =>
            limit(s) !== null && (s.body ?? '').length > (limit(s) as number),
    ),
);

const add = () => {
    const [channel, action] = adding.value.split(':');
    steps.value.push({
        // A new id the CRM accepts (st_ plus 6 to 24 lowercase letters or digits).
        id: `st_${Math.random().toString(36).slice(2, 12).padEnd(10, '0')}`,
        channel,
        action,
        wait: steps.value.length
            ? { amount: 1, unit: 'days' }
            : { amount: 0, unit: 'minutes' },
        body: '',
        ...(channel === 'email' || channel === 'task' ? { subject: '' } : {}),
    });
};
const move = (i: number, by: number) => {
    const j = i + by;
    if (j < 0 || j >= steps.value.length) {
        return;
    }
    const copy = [...steps.value];
    [copy[i], copy[j]] = [copy[j], copy[i]];
    steps.value = copy;
};
const remove = (i: number) => steps.value.splice(i, 1);

const save = async () => {
    if (!seq.value || !props.canManage) {
        return;
    }
    saving.value = true;
    const r = await patch<{ sequence: Sequence }>(
        `/sequences/${seq.value.id}`,
        { name: name.value, steps: steps.value },
    );
    saving.value = false;
    if (!r.ok || !r.data) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    seq.value = r.data.sequence;
    steps.value = JSON.parse(JSON.stringify(r.data.sequence.steps)) as Step[];
    name.value = r.data.sequence.name;
    toast.success(trans('tantra.engagement.sequences.saved'));
};

const close = () => {
    if (
        dirty.value &&
        !window.confirm(trans('tantra.engagement.sequences.discard'))
    ) {
        return;
    }
    emit('close');
};
</script>

<template>
    <Sheet :open="!!id" @update:open="(o: boolean) => !o && close()">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>{{
                    seq?.name ?? $t('tantra.engagement.sequences.sequence')
                }}</SheetTitle>
                <SheetDescription v-if="seq">
                    {{ seq.stage_name }}
                    <span
                        v-if="seq.settings.dry_run"
                        class="ml-1 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-800"
                        ><IconFlask class="size-3" />{{
                            $t('tantra.engagement.test_mode')
                        }}</span
                    >
                </SheetDescription>
            </SheetHeader>
            <div v-if="!seq" class="grid place-items-center py-10">
                <Spinner />
            </div>
            <form
                v-else
                class="flex flex-col gap-5 px-4 pb-6 text-sm"
                data-testid="engagement-sequence-editor"
                @submit.prevent="save"
            >
                <label class="flex flex-col gap-1.5">
                    <span class="text-xs font-medium text-muted-foreground">{{
                        $t('tantra.engagement.sequences.name')
                    }}</span>
                    <Input
                        v-model="name"
                        :disabled="!canManage"
                        maxlength="120"
                    />
                </label>
                <ol class="flex flex-col gap-3">
                    <li
                        v-for="(s, i) in steps"
                        :key="s.id"
                        class="flex flex-col gap-2 rounded-xl border border-border p-3"
                    >
                        <div class="flex items-center gap-2">
                            <span class="font-medium"
                                >{{ i + 1 }}.
                                {{ stepNoun(s.channel, s.action) }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                s.channel
                            }}</span>
                            <span v-if="canManage" class="ml-auto flex gap-1">
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    :aria-label="
                                        $t('tantra.engagement.sequences.up')
                                    "
                                    @click="move(i, -1)"
                                    ><IconArrowUp class="size-3.5"
                                /></Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    :aria-label="
                                        $t('tantra.engagement.sequences.down')
                                    "
                                    @click="move(i, 1)"
                                    ><IconArrowDown class="size-3.5"
                                /></Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    class="size-7"
                                    :aria-label="
                                        $t('tantra.engagement.sequences.remove')
                                    "
                                    @click="remove(i)"
                                    ><IconTrash class="size-3.5"
                                /></Button>
                            </span>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-muted-foreground">{{
                                $t(
                                    i === 0
                                        ? 'tantra.engagement.sequences.first_wait'
                                        : 'tantra.engagement.sequences.wait',
                                )
                            }}</span>
                            <Input
                                v-model.number="s.wait.amount"
                                type="number"
                                min="0"
                                max="1000"
                                class="h-8 w-20"
                                :disabled="!canManage"
                            />
                            <NativeSelect
                                v-model="s.wait.unit"
                                class="h-8"
                                :disabled="!canManage"
                            >
                                <NativeSelectOption
                                    v-for="u in UNITS"
                                    :key="u"
                                    :value="u"
                                    >{{
                                        $t(`tantra.engagement.units.${u}`)
                                    }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </div>
                        <Input
                            v-if="hasSubject(s)"
                            v-model="s.subject as string"
                            :placeholder="
                                $t('tantra.engagement.sequences.subject')
                            "
                            :disabled="!canManage"
                            maxlength="300"
                        />
                        <Textarea
                            v-if="s.action !== 'visit_profile'"
                            v-model="s.body as string"
                            rows="4"
                            :placeholder="
                                $t('tantra.engagement.sequences.message')
                            "
                            :disabled="!canManage"
                        />
                        <p
                            v-if="limit(s) !== null"
                            class="text-right text-[11px]"
                            :class="
                                (s.body ?? '').length > (limit(s) as number)
                                    ? 'text-destructive-text'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ (s.body ?? '').length }}/{{ limit(s) }}
                        </p>
                    </li>
                </ol>
                <p v-if="!steps.length" class="text-muted-foreground">
                    {{ $t('tantra.engagement.sequences.no_steps') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t('tantra.engagement.sequences.merge_fields') }}
                </p>
                <div v-if="canManage" class="flex flex-wrap items-center gap-2">
                    <NativeSelect v-model="adding" class="h-9">
                        <NativeSelectOption
                            v-for="a in ADDABLE"
                            :key="`${a.channel}:${a.action}`"
                            :value="`${a.channel}:${a.action}`"
                            >{{
                                stepNoun(a.channel, a.action)
                            }}</NativeSelectOption
                        >
                    </NativeSelect>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="steps.length >= 20"
                        @click="add"
                        ><IconPlus class="size-4" />{{
                            $t('tantra.engagement.sequences.add_step')
                        }}</Button
                    >
                </div>
                <div class="flex gap-2 border-t border-border pt-4">
                    <Button
                        v-if="canManage"
                        type="submit"
                        :disabled="saving || !dirty || tooLong"
                        >{{
                            saving
                                ? $t('tantra.engagement.sequences.saving')
                                : $t('tantra.engagement.sequences.save')
                        }}</Button
                    >
                    <Button type="button" variant="outline" @click="close">{{
                        $t('tantra.engagement.close')
                    }}</Button>
                </div>
            </form>
        </SheetContent>
    </Sheet>
</template>
