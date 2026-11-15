<script setup lang="ts">
// tantra: Engagement > People. Sub-tabs Commenters, Requested, Likers and Team (Sharers when there are any). Each
// row: name with LinkedIn link, degree, enrichment chips (company, title, email with a confidence badge), stage,
// pending action and Mark as team. Team people are shown as results and never messaged.
import {
    IconAt,
    IconBrandLinkedin,
    IconFlask,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { get, post } from '@/components/tantra/engagement/api';
import {
    confidenceTone,
    DEGREE,
    type Kind,
    kindLabel,
    PENDING_TONE,
    pendingText,
    type PeopleResp,
    type PersonRow,
    type Source,
    sourceTitle,
    STAGE_TONE,
    STAGES,
} from '@/components/tantra/engagement/shared';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    canManage: boolean;
    initialSource: string | null;
    initialKind: string | null;
}>();

const SUBTABS: Kind[] = [
    'commenters',
    'requested',
    'likers',
    'sharers',
    'team',
];

const kind = ref<Kind>(
    SUBTABS.includes(props.initialKind as Kind)
        ? (props.initialKind as Kind)
        : 'commenters',
);
const d = ref<PeopleResp | null>(null);
const sources = ref<Source[]>([]);
const source = ref(props.initialSource ?? '');
const stage = ref('');
const q = ref('');
const busy = ref<string | null>(null);

onMounted(async () => {
    const r = await get<{ sources: Source[] }>('/sources');
    sources.value = r.data?.sources ?? [];
});

// Only the latest request lands, so a quick switch never shows another sub-tab's people.
let seq = 0;
const load = async () => {
    const n = ++seq;
    const r = await get<PeopleResp>('/people', {
        group: kind.value,
        source_id: source.value || undefined,
        stage: kind.value === 'team' ? undefined : stage.value || undefined,
        q: q.value || undefined,
    });
    if (n !== seq) {
        return;
    }
    if (r.ok) {
        d.value = r.data;
    } else if (r.code !== 'crm_unreachable' && r.code !== 'crm_not_connected') {
        toast.error(r.message || trans('tantra.engagement.refused'));
    }
};
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    [kind, source, stage, q],
    () => {
        clearTimeout(timer);
        timer = setTimeout(load, 180);
    },
    { immediate: true },
);

const shown = computed(() =>
    d.value && d.value.kind === kind.value ? d.value : null,
);
const tabs = computed(() =>
    SUBTABS.filter(
        (t) =>
            t !== 'sharers' ||
            (d.value?.counts.sharers ?? 0) > 0 ||
            kind.value === 'sharers',
    ),
);

const roleLine = (p: PersonRow): string => {
    const e = p.enrichment;
    if (!e) {
        return '';
    }
    // The headline often already says it: the role shows only when it adds something.
    const h = (p.headline ?? '').toLowerCase();

    return [e.title, e.company]
        .filter((x) => x && !h.includes(String(x).toLowerCase()))
        .join(trans('tantra.engagement.people.at'));
};

const mark = async (p: PersonRow, team: boolean) => {
    busy.value = p.pkey;
    // Both keys: the deal may carry no profile link, the person key always resolves.
    const body: Record<string, unknown> = { team };
    if (p.deal_id) {
        body.deal_id = p.deal_id;
    }
    if (p.pkey && p.pkey !== p.deal_id) {
        body.person = p.pkey;
    }
    const r = await post<{ stopped: number; released: number }>(
        '/people/team',
        body,
    );
    busy.value = null;
    if (!r.ok) {
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    toast.success(
        team
            ? trans('tantra.engagement.people.marked', {
                  name: p.name ?? trans('tantra.engagement.someone'),
                  count: String(r.data?.stopped ?? 0),
              })
            : trans('tantra.engagement.people.unmarked', {
                  name: p.name ?? trans('tantra.engagement.someone'),
              }),
    );
    await load();
};
</script>

<template>
    <div class="mx-auto w-full max-w-6xl px-4 py-5 md:px-6">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div
                class="-mx-1 flex max-w-full gap-1 overflow-x-auto rounded-xl bg-muted p-1"
                role="tablist"
            >
                <button
                    v-for="t in tabs"
                    :key="t"
                    type="button"
                    role="tab"
                    :aria-selected="kind === t"
                    class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium"
                    :class="
                        kind === t
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground'
                    "
                    :data-testid="`engagement-people-${t}`"
                    @click="kind = t"
                >
                    <IconUsers v-if="t === 'team'" class="size-3.5" />{{
                        $t(`tantra.engagement.people.tabs.${t}`)
                    }}
                    <span
                        v-if="d"
                        class="text-xs text-muted-foreground tabular-nums"
                        >{{ d.counts[t] ?? 0 }}</span
                    >
                </button>
            </div>
            <div class="flex flex-1 flex-wrap items-center justify-end gap-2">
                <Input
                    v-model="q"
                    class="w-full sm:w-48"
                    :placeholder="$t('tantra.engagement.people.search')"
                    :aria-label="$t('tantra.engagement.people.search')"
                />
                <NativeSelect
                    v-model="source"
                    class="w-full sm:w-auto sm:max-w-[14rem]"
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
                <NativeSelect
                    v-if="kind !== 'team'"
                    v-model="stage"
                    class="w-full sm:w-auto"
                    :aria-label="$t('tantra.engagement.people.stage')"
                >
                    <NativeSelectOption value="">{{
                        $t('tantra.engagement.people.every_stage')
                    }}</NativeSelectOption>
                    <NativeSelectOption
                        v-for="s in STAGES"
                        :key="s"
                        :value="s"
                        >{{ s }}</NativeSelectOption
                    >
                </NativeSelect>
            </div>
        </div>

        <div v-if="!shown" class="grid place-items-center py-10">
            <Spinner />
        </div>
        <div
            v-else-if="!shown.people.length"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm"
        >
            <b class="block">{{
                $t(
                    kind === 'team'
                        ? 'tantra.engagement.people.empty_team_title'
                        : 'tantra.engagement.people.empty_title',
                )
            }}</b>
            <span class="text-muted-foreground">{{
                $t(
                    kind === 'team'
                        ? 'tantra.engagement.people.empty_team'
                        : 'tantra.engagement.people.empty',
                )
            }}</span>
        </div>
        <div
            v-else
            class="rounded-xl border border-border bg-card"
            data-testid="engagement-people"
        >
            <div
                class="hidden gap-4 border-b border-border px-4 py-2.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase md:grid"
                :class="
                    kind === 'team'
                        ? 'md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_8rem]'
                        : 'md:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_7rem_minmax(0,1.5fr)_8rem]'
                "
            >
                <span>{{ $t('tantra.engagement.people.person') }}</span>
                <span>{{
                    $t(
                        kind === 'team'
                            ? 'tantra.engagement.people.what'
                            : kind === 'commenters' || kind === 'requested'
                              ? 'tantra.engagement.people.comment'
                              : 'tantra.engagement.people.engaged',
                    )
                }}</span>
                <template v-if="kind !== 'team'">
                    <span>{{ $t('tantra.engagement.people.stage') }}</span>
                    <span>{{ $t('tantra.engagement.people.pending') }}</span>
                </template>
                <span />
            </div>
            <ul class="divide-y divide-border">
                <li
                    v-for="p in shown.people"
                    :key="p.pkey"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 px-4 py-3"
                    :class="
                        kind === 'team'
                            ? 'md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)_8rem]'
                            : 'md:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_7rem_minmax(0,1.5fr)_8rem]'
                    "
                    :data-testid="`engagement-person-${p.pkey}`"
                >
                    <div class="min-w-0">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <span class="truncate text-sm font-medium">{{
                                p.name ?? $t('tantra.engagement.someone')
                            }}</span>
                            <a
                                v-if="p.url"
                                :href="p.url"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0 text-[#0a66c2] hover:opacity-80"
                                :aria-label="
                                    $t('tantra.engagement.people.on_linkedin', {
                                        name: p.name ?? '',
                                    })
                                "
                                ><IconBrandLinkedin class="size-3.5"
                            /></a>
                            <span
                                v-if="
                                    p.network_distance &&
                                    DEGREE[p.network_distance]
                                "
                                class="shrink-0 text-[11px] text-muted-foreground"
                                >{{ DEGREE[p.network_distance] }}</span
                            >
                            <span
                                v-if="p.suppressed"
                                class="shrink-0 rounded-full bg-red-50 px-1.5 text-[11px] text-red-700"
                                >{{
                                    $t(
                                        'tantra.engagement.people.do_not_contact',
                                    )
                                }}</span
                            >
                        </span>
                        <span
                            v-if="p.headline"
                            class="block truncate text-xs text-muted-foreground"
                            >{{ p.headline }}</span
                        >
                        <span
                            v-if="roleLine(p) || p.enrichment?.best_email"
                            class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground"
                        >
                            <span v-if="roleLine(p)" class="truncate">{{
                                roleLine(p)
                            }}</span>
                            <span
                                v-if="p.enrichment?.best_email"
                                class="inline-flex min-w-0 items-center gap-1"
                            >
                                <IconAt class="size-3 shrink-0" /><span
                                    class="truncate"
                                    >{{ p.enrichment.best_email }}</span
                                >
                                <span
                                    v-if="
                                        typeof p.enrichment.email_confidence ===
                                        'number'
                                    "
                                    class="rounded px-1 text-[10px] font-semibold tabular-nums"
                                    :class="
                                        confidenceTone(
                                            p.enrichment.email_confidence,
                                        )
                                    "
                                    :title="
                                        $t(
                                            'tantra.engagement.people.confidence',
                                        )
                                    "
                                    >{{ p.enrichment.email_confidence }}</span
                                >
                            </span>
                        </span>
                    </div>
                    <div
                        class="order-last col-span-2 min-w-0 md:order-none md:col-span-1"
                    >
                        <ul
                            v-if="kind === 'team'"
                            class="flex flex-col gap-0.5 text-xs"
                        >
                            <li
                                v-for="a in (p.activity ?? []).slice(0, 3)"
                                :key="`${a.source.id}-${a.kind}-${a.at}`"
                                class="flex min-w-0 gap-1.5"
                            >
                                <span class="shrink-0 text-muted-foreground">{{
                                    kindLabel(a.kind)
                                }}</span>
                                <span class="truncate font-medium">{{
                                    a.source.title
                                }}</span>
                            </li>
                        </ul>
                        <p
                            v-else-if="
                                (kind === 'commenters' ||
                                    kind === 'requested') &&
                                p.comment
                            "
                            class="line-clamp-2 text-xs"
                        >
                            "{{ p.comment }}"
                        </p>
                        <div v-else class="flex flex-wrap gap-1">
                            <span
                                v-for="k in p.kinds"
                                :key="k"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :class="
                                    k === 'keyword'
                                        ? 'bg-info-subtle text-info-text'
                                        : 'bg-muted text-muted-foreground'
                                "
                                >{{ kindLabel(k) }}</span
                            >
                        </div>
                    </div>
                    <div v-if="kind !== 'team'" class="hidden md:block">
                        <span
                            v-if="p.stage"
                            class="rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap"
                            :class="
                                STAGE_TONE[p.stage] ??
                                'bg-slate-100 text-slate-700'
                            "
                            >{{ p.stage }}</span
                        >
                    </div>
                    <div v-if="kind !== 'team'" class="hidden min-w-0 md:block">
                        <span
                            v-if="p.pending"
                            class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium"
                            :class="PENDING_TONE[p.pending.state]"
                            :title="p.pending.sequence_name ?? undefined"
                        >
                            <span class="truncate">{{
                                pendingText(p.pending)
                            }}</span>
                            <IconFlask
                                v-if="
                                    p.pending.test_mode &&
                                    (p.pending.state === 'due' ||
                                        p.pending.state === 'waiting')
                                "
                                class="size-3 shrink-0 text-amber-600"
                                :aria-label="$t('tantra.engagement.test_mode')"
                            />
                        </span>
                    </div>
                    <div class="flex justify-end">
                        <Button
                            v-if="canManage"
                            size="sm"
                            variant="outline"
                            :disabled="busy === p.pkey"
                            :data-testid="`engagement-team-${p.pkey}`"
                            @click="mark(p, !p.team)"
                            >{{
                                $t(
                                    p.team
                                        ? 'tantra.engagement.people.not_team'
                                        : 'tantra.engagement.people.mark_team',
                                )
                            }}</Button
                        >
                    </div>
                </li>
            </ul>
            <p
                v-if="shown.total > shown.people.length"
                class="border-t border-border px-4 py-2.5 text-center text-xs text-muted-foreground"
            >
                {{
                    $t('tantra.engagement.people.showing', {
                        shown: String(shown.people.length),
                        total: String(shown.total),
                    })
                }}
            </p>
        </div>
    </div>
</template>
