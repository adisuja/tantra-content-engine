<script setup lang="ts">
// tantra: Preview sends (a dry run): each person, the messages that would go, or why they are skipped. Approve and
// start takes the post's sequences out of test mode. It runs only when the owner clicks it and confirms; nothing
// approves on its own.
import { IconCheck } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { get, post } from '@/components/tantra/engagement/api';
import {
    DEGREE,
    kindLabel,
    type SendPreview,
} from '@/components/tantra/engagement/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{ sourceId: string; canManage: boolean }>();
const emit = defineEmits<{ close: []; approved: [] }>();

const p = ref<SendPreview | null>(null);
const failed = ref('');
const busy = ref(false);

onMounted(async () => {
    const r = await get<SendPreview>(`/sources/${props.sourceId}/preview`);
    if (r.ok) {
        p.value = r.data;
    } else {
        failed.value = r.message || trans('tantra.engagement.refused');
    }
});

const approve = async () => {
    if (!window.confirm(trans('tantra.engagement.preview.confirm'))) {
        return;
    }
    busy.value = true;
    const r = await post<{ restarted: number }>(
        `/sources/${props.sourceId}/approve`,
        { confirm: true },
    );
    busy.value = false;
    if (!r.ok) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    const n = r.data?.restarted ?? 0;
    toast.success(
        n
            ? trans('tantra.engagement.preview.live_people', {
                  count: String(n),
              })
            : trans('tantra.engagement.preview.live'),
    );
    emit('approved');
};
</script>

<template>
    <Dialog :open="true" @update:open="(o: boolean) => !o && emit('close')">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    $t('tantra.engagement.preview.title')
                }}</DialogTitle>
                <DialogDescription v-if="p">
                    {{
                        $t('tantra.engagement.preview.summary', {
                            send: String(p.counts.would_send),
                            skipped: String(p.counts.skipped),
                        })
                    }}
                    {{
                        p.dry_run
                            ? $t('tantra.engagement.preview.nothing_sent')
                            : $t('tantra.engagement.preview.is_live')
                    }}
                </DialogDescription>
            </DialogHeader>
            <div
                v-if="failed"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                {{ failed }}
            </div>
            <div v-else-if="!p" class="grid place-items-center py-8">
                <Spinner />
            </div>
            <p
                v-else-if="!p.people.length"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                {{ $t('tantra.engagement.preview.nobody') }}
            </p>
            <ul
                v-else
                class="max-h-[60vh] divide-y divide-border overflow-y-auto rounded-xl border border-border text-sm"
            >
                <li
                    v-for="(x, i) in p.people"
                    :key="i"
                    class="px-3 py-2.5"
                    :class="x.skip && 'bg-muted/40'"
                >
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                x.name ?? $t('tantra.engagement.someone')
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ kindLabel(x.trigger)
                                }}<template v-if="x.degree">
                                    ·
                                    {{ DEGREE[x.degree] ?? x.degree }}</template
                                ><template v-if="x.backfilled">
                                    ·
                                    {{
                                        $t(
                                            'tantra.engagement.preview.before_on',
                                        )
                                    }}</template
                                ></span
                            >
                        </span>
                        <Badge
                            v-if="x.skip"
                            variant="secondary"
                            class="h-auto px-1.5"
                            >{{ x.skip_reason }}</Badge
                        >
                        <Badge v-else variant="info" class="h-auto px-1.5">{{
                            x.action
                        }}</Badge>
                    </div>
                    <ol
                        v-if="!x.skip && x.steps?.length"
                        class="mt-2 flex flex-col gap-1.5 border-l border-border pl-3"
                    >
                        <li v-for="(st, j) in x.steps" :key="j">
                            <p
                                class="text-[11px] font-medium text-muted-foreground"
                            >
                                {{ j + 1 }}. {{ st.action
                                }}<template v-if="st.waits"
                                    >, {{ st.waits.toLowerCase() }}</template
                                >
                            </p>
                            <p v-if="st.subject" class="text-xs font-medium">
                                {{ st.subject }}
                            </p>
                            <p
                                class="mt-0.5 rounded-lg bg-muted/50 px-3 py-2 leading-relaxed whitespace-pre-wrap"
                            >
                                {{ st.message }}
                            </p>
                        </li>
                    </ol>
                    <p
                        v-else-if="!x.skip && x.message"
                        class="mt-1.5 rounded-lg bg-muted/50 px-3 py-2 leading-relaxed whitespace-pre-wrap"
                    >
                        {{ x.message }}
                    </p>
                </li>
            </ul>
            <DialogFooter>
                <Button variant="outline" @click="emit('close')">{{
                    $t('tantra.engagement.close')
                }}</Button>
                <Button
                    v-if="p?.dry_run && canManage"
                    :disabled="busy"
                    data-testid="engagement-approve"
                    @click="approve"
                    ><IconCheck class="size-4" />{{
                        busy
                            ? $t('tantra.engagement.preview.starting')
                            : $t('tantra.engagement.preview.approve')
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
