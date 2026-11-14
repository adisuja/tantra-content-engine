<script setup lang="ts">
// tantra: one watched post. Its triggers, resource, the first message per platform, the follow-up sequences, the
// people found so far, Read now, and Preview sends (where Approve and start lives). Turning a post on for the first
// time asks whether people who engaged before are included, as in the CRM.
import { IconExternalLink, IconFlask } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { get, post } from '@/components/tantra/engagement/api';
import PreviewSendsDialog from '@/components/tantra/engagement/PreviewSendsDialog.vue';
import {
    kindLabel,
    type Preview,
    type SeqBrief,
    type Source,
    sourceTitle,
    STAGE_TONE,
    type TriggerKey,
} from '@/components/tantra/engagement/shared';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    id: string | null;
    askEnable: boolean;
    canManage: boolean;
}>();
const emit = defineEmits<{ close: [] }>();

interface Detail {
    source: Source;
    sequence: SeqBrief | null;
    engaged_sequence: SeqBrief | null;
    preview: Preview[];
    people: {
        pkey: string;
        name: string | null;
        url: string | null;
        kinds: string[];
        stage: string | null;
    }[];
}
interface Backlog {
    commenters?: number;
    requesters?: number;
    days?: number;
    error?: string;
}

const d = ref<Detail | null>(null);
const backlog = ref<Backlog | null>(null);
const busy = ref(false);
const previewing = ref(false);

const TRIGGERS: TriggerKey[] = [
    'keyword',
    'commenters',
    'likers',
    'sharers',
    'visitors',
];

const load = async () => {
    if (!props.id) {
        return;
    }
    const r = await get<Detail>(`/sources/${props.id}`);
    if (r.ok) {
        d.value = r.data;
    } else {
        toast.error(r.message || trans('tantra.engagement.refused'));
    }
};

watch(
    () => [props.id, props.askEnable] as const,
    async ([id, ask]) => {
        d.value = null;
        backlog.value = null;
        if (!id) {
            return;
        }
        await load();
        if (ask) {
            const b = await get<Backlog>(`/sources/${id}/backlog`);
            backlog.value = b.ok
                ? (b.data ?? {})
                : { error: b.message || trans('tantra.engagement.refused') };
        }
    },
    { immediate: true },
);

const giveaway = computed(
    () =>
        !!d.value?.source.triggers?.keyword &&
        !!(d.value.source.keyword || d.value.source.asset?.keyword),
);

const enable = async (include: boolean) => {
    if (!d.value) {
        return;
    }
    busy.value = true;
    const r = await post(`/sources/${d.value.source.id}/enable`, {
        enabled: true,
        backfill: include ? (giveaway.value ? 'requesters' : 'all') : 'none',
    });
    busy.value = false;
    if (!r.ok) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    toast.success(trans('tantra.engagement.posts.on'));
    emit('close');
};

const readNow = async () => {
    if (!d.value) {
        return;
    }
    busy.value = true;
    const r = await post<{ new_events: number; people: number }>(
        `/sources/${d.value.source.id}/poll`,
    );
    busy.value = false;
    if (!r.ok) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    toast.success(
        trans('tantra.engagement.posts.read_done', {
            events: String(r.data?.new_events ?? 0),
            people: String(r.data?.people ?? 0),
        }),
    );
    await load();
};

const previewRows = (p: Preview): [string, string][] =>
    (
        [
            [trans('tantra.engagement.preview.reply'), p.comment_reply],
            [trans('tantra.engagement.preview.dm'), p.dm],
            [trans('tantra.engagement.preview.note'), p.connection_note],
        ] as [string, string | null][]
    ).filter((r): r is [string, string] => !!r[1]);
</script>

<template>
    <Sheet :open="!!id" @update:open="(o: boolean) => !o && emit('close')">
        <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>{{
                    d
                        ? sourceTitle(d.source)
                        : $t('tantra.engagement.posts.post')
                }}</SheetTitle>
                <SheetDescription v-if="d?.source.post_url">
                    <a
                        :href="d.source.post_url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 underline"
                        >{{ $t('tantra.engagement.posts.open_post')
                        }}<IconExternalLink class="size-3.5"
                    /></a>
                </SheetDescription>
            </SheetHeader>
            <div v-if="!d" class="grid place-items-center py-10">
                <Spinner />
            </div>
            <div v-else class="flex flex-col gap-6 px-4 pb-6 text-sm">
                <section
                    v-if="askEnable"
                    class="flex flex-col gap-3 rounded-xl border border-border bg-muted/40 p-4"
                    data-testid="engagement-backfill"
                >
                    <b>{{ $t('tantra.engagement.backfill.title') }}</b>
                    <p class="text-muted-foreground">
                        {{
                            backlog === null
                                ? $t('tantra.engagement.backfill.loading')
                                : backlog.error
                                  ? $t('tantra.engagement.backfill.no_count', {
                                        reason: backlog.error,
                                    })
                                  : $t(
                                        giveaway
                                            ? 'tantra.engagement.backfill.giveaway'
                                            : 'tantra.engagement.backfill.all',
                                        {
                                            commenters: String(
                                                backlog.commenters ?? 0,
                                            ),
                                            requesters: String(
                                                backlog.requesters ?? 0,
                                            ),
                                            days: String(backlog.days ?? 30),
                                        },
                                    )
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('tantra.engagement.backfill.test_mode') }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            :disabled="busy || !canManage"
                            @click="enable(true)"
                            >{{
                                $t('tantra.engagement.backfill.include')
                            }}</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="busy || !canManage"
                            @click="enable(false)"
                            >{{
                                $t('tantra.engagement.backfill.new_only')
                            }}</Button
                        >
                    </div>
                </section>

                <section>
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t('tantra.engagement.posts.triggers') }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="t in TRIGGERS"
                            :key="t"
                            :variant="
                                d.source.triggers?.[t] ? 'info' : 'outline'
                            "
                            class="h-auto px-2 py-0.5"
                            >{{ $t(`tantra.engagement.triggers.${t}`) }}</Badge
                        >
                    </div>
                </section>

                <section v-if="d.source.asset">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t('tantra.engagement.posts.resource') }}
                    </p>
                    <div class="rounded-xl border border-border p-3">
                        <p class="font-medium">{{ d.source.asset.name }}</p>
                        <p
                            v-if="d.source.asset.keyword"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                $t('tantra.engagement.posts.keyword', {
                                    keyword: d.source.asset.keyword,
                                })
                            }}
                        </p>
                        <a
                            v-if="d.source.asset.url"
                            :href="d.source.asset.url"
                            target="_blank"
                            rel="noopener"
                            class="text-xs break-all underline"
                            >{{ d.source.asset.url }}</a
                        >
                    </div>
                </section>

                <section>
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t('tantra.engagement.posts.first_message') }}
                    </p>
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="p in d.preview.filter((x) => x.live)"
                            :key="p.adapter"
                            class="rounded-xl border border-border p-3"
                        >
                            <p class="mb-2 text-xs font-medium">
                                {{ p.platforms.join(', ') }}
                            </p>
                            <div
                                v-for="[k, v] in previewRows(p)"
                                :key="k"
                                class="mt-2 first:mt-0"
                            >
                                <p
                                    class="text-[10px] font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ k }}
                                </p>
                                <p
                                    class="mt-0.5 text-sm leading-relaxed whitespace-pre-wrap"
                                >
                                    {{ v }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>

                <section>
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t('tantra.engagement.posts.follow_up') }}
                    </p>
                    <ul class="flex flex-col gap-1.5">
                        <li
                            v-for="[label, s] in [
                                [
                                    $t(
                                        'tantra.engagement.posts.giveaway_sequence',
                                    ),
                                    d.sequence,
                                ],
                                [
                                    $t(
                                        'tantra.engagement.posts.engaged_sequence',
                                    ),
                                    d.engaged_sequence,
                                ],
                            ] as [string, SeqBrief | null][]"
                            :key="label"
                            class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
                        >
                            <span class="min-w-0">
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ label }}</span
                                >
                                <span class="block truncate font-medium">{{
                                    s?.name ??
                                    $t('tantra.engagement.posts.none')
                                }}</span>
                            </span>
                            <Badge
                                v-if="s?.dry_run"
                                variant="warning"
                                class="h-auto px-1.5"
                                ><IconFlask />{{
                                    $t('tantra.engagement.test_mode')
                                }}</Badge
                            >
                            <Badge
                                v-else-if="s"
                                variant="success"
                                class="h-auto px-1.5"
                                >{{ $t('tantra.engagement.live') }}</Badge
                            >
                        </li>
                    </ul>
                </section>

                <section v-if="d.people.length">
                    <p
                        class="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ $t('tantra.engagement.posts.people_found') }}
                    </p>
                    <ul
                        class="divide-y divide-border rounded-xl border border-border"
                    >
                        <li
                            v-for="p in d.people"
                            :key="p.pkey"
                            class="flex items-center gap-2 px-3 py-2"
                        >
                            <a
                                v-if="p.url"
                                :href="p.url"
                                target="_blank"
                                rel="noopener"
                                class="truncate font-medium underline"
                                >{{
                                    p.name ?? $t('tantra.engagement.someone')
                                }}</a
                            >
                            <span v-else class="truncate font-medium">{{
                                p.name ?? $t('tantra.engagement.someone')
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                                >{{ p.kinds.map(kindLabel).join(', ') }}</span
                            >
                            <span
                                v-if="p.stage"
                                class="ml-auto rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap"
                                :class="
                                    STAGE_TONE[p.stage] ??
                                    'bg-slate-100 text-slate-700'
                                "
                                >{{ p.stage }}</span
                            >
                        </li>
                    </ul>
                </section>

                <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                    <Button
                        variant="outline"
                        :disabled="busy || !canManage"
                        @click="readNow"
                        >{{ $t('tantra.engagement.posts.read_now') }}</Button
                    >
                    <Button
                        data-testid="engagement-preview-sends"
                        @click="previewing = true"
                        >{{ $t('tantra.engagement.preview.title') }}</Button
                    >
                </div>
            </div>
        </SheetContent>
    </Sheet>
    <PreviewSendsDialog
        v-if="previewing && d"
        :source-id="d.source.id"
        :can-manage="canManage"
        @close="previewing = false"
        @approved="
            previewing = false;
            load();
        "
    />
</template>
