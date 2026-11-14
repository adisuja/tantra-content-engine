<script setup lang="ts">
// tantra: Results, built around traffic and leads (PRD 10.6, card 29). Per person and platform:
// social numbers from the Engine, clicks to sales from the Tantra CRM.
import { Head, router } from '@inertiajs/vue3';
import { IconChartBar } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import InsightsTabs from '@/components/tantra/insights/InsightsTabs.vue';
import StatCard from '@/components/tantra/insights/StatCard.vue';
import {
    fmt,
    money,
    type Member,
    type Num,
    type Traffic,
} from '@/components/tantra/insights/traffic';
import TrendBars from '@/components/tantra/insights/TrendBars.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

type InsightPost = {
    post_id: string;
    key: string | null;
    profile: string;
    platform: 'linkedin' | 'linkedin_page';
    format: string | null;
    pillar: string | null;
    short: string | null;
    url: string | null;
    impressions: number | null;
    engagement: number | null;
    engagement_rate: number | null;
};

type Formula = {
    profile: string;
    posts: number;
    winners: {
        kind: 'format' | 'sample';
        value: string;
        posts: number;
        lift: number;
    }[];
    weak_signal: boolean;
};

const props = defineProps<{
    insights: {
        days: number;
        best: InsightPost[];
        worst: InsightPost[];
        formula: Formula[];
    };
    traffic: Traffic;
    member: string | null;
    ranges: number[];
}>();

const num = (v: number | null): string =>
    v === null ? '' : v.toLocaleString(undefined);

const r = computed(() => props.traffic.range);
const crmLive = computed(() => props.traffic.crm.status === 'live');
const current = computed<Member | null>(
    () => props.traffic.members.find((m) => m.key === props.member) ?? null,
);
const nameOf = (key: string): string =>
    props.traffic.members.find((m) => m.key === key)?.name ?? key;

const go = (q: Record<string, string | number | null>): void => {
    const query: Record<string, string | number> = {
        days: r.value.days,
        bucket: r.value.bucket,
        ...(props.member ? { member: props.member } : {}),
    };
    for (const [k, v] of Object.entries(q)) {
        if (v === null) delete query[k];
        else query[k] = v;
    }
    router.get('/tantra/insights', query, {
        preserveScroll: true,
        preserveState: true,
    });
};

const social = computed(() =>
    current.value ? current.value.social : props.traffic.totals.social,
);
const funnel = computed(() =>
    current.value ? current.value.traffic : props.traffic.totals.traffic,
);
const notes = computed(() => current.value?.social_notes ?? {});

/** A note for a social total that is missing for everyone shown. */
const socialNote = (
    metric: 'impressions' | 'shares' | 'followers',
): string | null => {
    if (current.value) return current.value.social_notes[metric] ?? null;
    const n = props.traffic.members
        .map((m) => m.social_notes[metric])
        .find(Boolean);

    return n ?? null;
};

const avgProps = computed(() => ({
    days: r.value.days,
    weeks: r.value.weeks,
    months: r.value.months,
}));

// Over time: social metrics per person, traffic metrics for the team (the CRM does not split time per person yet).
const trendMetrics = computed(() =>
    current.value
        ? ['posts', 'impressions', 'likes', 'comments', 'shares']
        : [
              'clicks',
              'conversions',
              'visits',
              'posts',
              'impressions',
              'likes',
              'comments',
          ],
);
const trendMetric = ref<string>(current.value ? 'posts' : 'clicks');
const buckets = computed<string[]>(() => {
    const out: string[] = [];
    const d = new Date(`${r.value.from}T00:00:00Z`);
    const end = new Date(`${r.value.to}T00:00:00Z`);
    const key = (x: Date): string => {
        if (r.value.bucket === 'month') return x.toISOString().slice(0, 7);
        if (r.value.bucket === 'week') {
            const m = new Date(x);
            m.setUTCDate(m.getUTCDate() - ((m.getUTCDay() + 6) % 7));
            return m.toISOString().slice(0, 10);
        }
        return x.toISOString().slice(0, 10);
    };
    while (d <= end) {
        const k = key(d);
        if (!out.includes(k)) out.push(k);
        d.setUTCDate(d.getUTCDate() + 1);
    }
    return out;
});
const trafficKeys = [
    'clicks',
    'clicks_human',
    'visits',
    'conversions',
    'registrations',
    'bookings',
    'sales',
];
const points = computed(() => {
    const m = trendMetric.value;
    const isTraffic = trafficKeys.includes(m);
    return buckets.value.map((b) => ({
        bucket: b,
        value: props.traffic.series
            .filter(
                (s) =>
                    s.bucket === b &&
                    (isTraffic
                        ? s.member === '_team'
                        : current.value
                          ? s.member === current.value.key
                          : s.member !== '_team'),
            )
            .reduce((sum, s) => sum + (val(s, m) ?? 0), 0),
    }));
});

// Team comparison: rows are numbers, columns are people.
const compareRows: {
    key: string;
    group: 'traffic' | 'social';
    label: string;
}[] = [
    { key: 'clicks', group: 'traffic', label: 'tantra.traffic.clicks' },
    {
        key: 'conversions',
        group: 'traffic',
        label: 'tantra.traffic.conversions',
    },
    {
        key: 'registrations',
        group: 'traffic',
        label: 'tantra.traffic.registrations',
    },
    { key: 'bookings', group: 'traffic', label: 'tantra.traffic.bookings' },
    { key: 'sales', group: 'traffic', label: 'tantra.traffic.sales' },
    { key: 'revenue', group: 'traffic', label: 'tantra.traffic.revenue' },
    { key: 'posts', group: 'social', label: 'tantra.traffic.posts' },
    {
        key: 'impressions',
        group: 'social',
        label: 'tantra.traffic.impressions',
    },
    { key: 'likes', group: 'social', label: 'tantra.traffic.likes' },
    { key: 'comments', group: 'social', label: 'tantra.traffic.comments' },
    { key: 'shares', group: 'social', label: 'tantra.traffic.shares' },
    { key: 'followers', group: 'social', label: 'tantra.traffic.followers' },
];
const cell = (
    m: Member,
    row: { key: string; group: 'traffic' | 'social' },
): Num => val(row.group === 'traffic' ? m.traffic : m.social, row.key);
const best = (row: { key: string; group: 'traffic' | 'social' }): Num => {
    const vals = props.traffic.members
        .map((m) => cell(m, row))
        .filter((v): v is number => v !== null);
    return vals.length && Math.max(...vals) > 0 ? Math.max(...vals) : null;
};
const show = (key: string, v: Num): string =>
    key === 'revenue' ? money(v, props.traffic.crm.currency) : fmt(v);

const pieces = computed(() =>
    props.traffic.pieces.filter(
        (p) => !current.value || p.member === current.value.key,
    ),
);
const socialPlatforms = computed(() =>
    props.traffic.platforms.filter(
        (p) =>
            p.member !== '_team' &&
            (!current.value || p.member === current.value.key),
    ),
);
const trafficPlatforms = computed(() =>
    props.traffic.platforms.filter((p) => p.member === '_team'),
);
const copied = ref<string | null>(null);
const copy = async (url: string): Promise<void> => {
    await navigator.clipboard.writeText(url);
    copied.value = url;
    setTimeout(() => (copied.value = null), 1500);
};
const t = trans;
/** One metric of a row, null when the row does not have it. */
const val = (row: object, key: string): Num =>
    ((row as Record<string, Num | undefined>)[key] ?? null) as Num;
const noteOf = (m: Member, key: string): string =>
    (m.social_notes as Record<string, string | undefined>)[key] ?? '';
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.insights.title')" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <HeaderTitle
                        :title="$t('channels.insights')"
                        :icon="IconChartBar"
                    />
                    <InsightsTabs active="traffic" />
                    <div class="ml-auto flex flex-wrap gap-2">
                        <div class="flex gap-1" data-testid="traffic-ranges">
                            <Button
                                v-for="d in ranges"
                                :key="d"
                                size="sm"
                                :variant="r.days === d ? 'default' : 'outline'"
                                @click="go({ days: d })"
                                >{{
                                    $t('tantra.insights.range', {
                                        days: String(d),
                                    })
                                }}</Button
                            >
                        </div>
                        <div class="flex gap-1" data-testid="traffic-buckets">
                            <Button
                                v-for="b in traffic.buckets"
                                :key="b"
                                size="sm"
                                :variant="
                                    r.bucket === b ? 'secondary' : 'ghost'
                                "
                                @click="go({ bucket: b })"
                                >{{ $t(`tantra.traffic.bucket.${b}`) }}</Button
                            >
                        </div>
                    </div>
                </div>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    {{ $t('tantra.traffic.intro') }}
                </p>
                <nav
                    class="flex flex-wrap gap-1 border-b border-border"
                    data-testid="traffic-tabs"
                >
                    <button
                        type="button"
                        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                        :class="
                            !current
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        @click="go({ member: null })"
                    >
                        {{ $t('tantra.traffic.team') }}
                    </button>
                    <button
                        v-for="m in traffic.members"
                        :key="m.key"
                        type="button"
                        class="-mb-px border-b-2 px-3 py-2 text-sm font-medium"
                        :class="
                            current?.key === m.key
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        "
                        :data-testid="`traffic-tab-${m.key}`"
                        @click="go({ member: m.key })"
                    >
                        {{ m.name }}
                    </button>
                </nav>
            </header>

            <div
                v-if="!crmLive"
                class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
                data-testid="traffic-crm-status"
            >
                <div class="font-medium">
                    {{ $t('tantra.traffic.crm_waiting') }}
                </div>
                <div>{{ traffic.crm.message }}</div>
            </div>

            <section class="flex flex-col gap-3" data-testid="traffic-funnel">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="text-base font-semibold">
                        {{
                            current
                                ? $t('tantra.traffic.funnel_of', {
                                      name: current.name,
                                  })
                                : $t('tantra.traffic.funnel')
                        }}
                    </h2>
                    <span class="text-xs text-muted-foreground">
                        {{
                            $t('tantra.traffic.from_crm', {
                                from: r.from,
                                to: r.to,
                            })
                        }}
                    </span>
                </div>
                <div
                    class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
                >
                    <StatCard
                        :label="$t('tantra.traffic.clicks')"
                        :value="funnel.clicks"
                        :sub="
                            funnel.clicks_human !== null
                                ? $t('tantra.traffic.clicks_human', {
                                      n: fmt(funnel.clicks_human),
                                  })
                                : undefined
                        "
                        :note="
                            funnel.clicks === null
                                ? props.traffic.crm.message
                                : null
                        "
                        v-bind="avgProps"
                        test-id="stat-clicks"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.visits')"
                        :value="funnel.visits"
                        :note="
                            funnel.visits === null
                                ? crmLive
                                    ? t('tantra.traffic.visits_note')
                                    : null
                                : null
                        "
                        v-bind="avgProps"
                        test-id="stat-visits"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.conversions')"
                        :value="funnel.conversions"
                        :sub="
                            funnel.conversions !== null
                                ? $t('tantra.traffic.conversions_sub', {
                                      r: fmt(funnel.registrations),
                                      b: fmt(funnel.bookings),
                                  })
                                : undefined
                        "
                        v-bind="avgProps"
                        test-id="stat-conversions"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.leads')"
                        :value="funnel.leads"
                        :note="crmLive ? t('tantra.traffic.leads_note') : null"
                        v-bind="avgProps"
                        test-id="stat-leads"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.sales')"
                        :value="funnel.sales"
                        v-bind="avgProps"
                        test-id="stat-sales"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.revenue')"
                        :value="funnel.revenue"
                        :display="
                            money(funnel.revenue, props.traffic.crm.currency)
                        "
                        :averages="false"
                        :sub="$t('tantra.traffic.revenue_sub')"
                        test-id="stat-revenue"
                    />
                </div>
                <div
                    v-if="current?.profile_clicks"
                    class="text-sm text-muted-foreground"
                    data-testid="traffic-profile-clicks"
                >
                    {{
                        $t('tantra.traffic.profile_clicks', {
                            links: String(current.profile_clicks.links),
                            clicks: fmt(current.profile_clicks.clicks),
                            human: fmt(current.profile_clicks.clicks_human),
                        })
                    }}
                </div>
            </section>

            <section class="flex flex-col gap-3" data-testid="traffic-social">
                <h2 class="text-base font-semibold">
                    {{ $t('tantra.traffic.social') }}
                </h2>
                <div
                    class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
                >
                    <StatCard
                        :label="$t('tantra.traffic.posts')"
                        :value="social.posts ?? 0"
                        v-bind="avgProps"
                        test-id="stat-posts"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.impressions')"
                        :value="social.impressions"
                        :note="socialNote('impressions')"
                        v-bind="avgProps"
                        test-id="stat-impressions"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.likes')"
                        :value="social.likes ?? (social.posts ? 0 : null)"
                        v-bind="avgProps"
                        test-id="stat-likes"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.comments')"
                        :value="social.comments ?? (social.posts ? 0 : null)"
                        v-bind="avgProps"
                        test-id="stat-comments"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.shares')"
                        :value="social.shares"
                        :note="socialNote('shares')"
                        v-bind="avgProps"
                        test-id="stat-shares"
                    />
                    <StatCard
                        :label="$t('tantra.traffic.followers')"
                        :value="social.followers"
                        :averages="false"
                        :sub="
                            social.followers_gained !== null
                                ? $t('tantra.traffic.followers_gained', {
                                      n: fmt(social.followers_gained),
                                  })
                                : undefined
                        "
                        :note="socialNote('followers')"
                        test-id="stat-followers"
                    />
                </div>
                <p
                    v-if="notes.impressions"
                    class="text-xs text-muted-foreground"
                >
                    {{ notes.impressions }}
                </p>
            </section>

            <section
                class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="mr-auto text-base font-semibold">
                        {{ $t('tantra.traffic.over_time') }}
                    </h2>
                    <Button
                        v-for="m in trendMetrics"
                        :key="m"
                        size="sm"
                        :variant="trendMetric === m ? 'secondary' : 'ghost'"
                        @click="trendMetric = m"
                        >{{ $t(`tantra.traffic.${m}`) }}</Button
                    >
                </div>
                <TrendBars
                    :points="points"
                    :label="$t(`tantra.traffic.${trendMetric}`)"
                />
                <p v-if="!current" class="text-xs text-muted-foreground">
                    {{ $t('tantra.traffic.over_time_note') }}
                </p>
            </section>

            <section
                v-if="!current && traffic.members.length"
                class="overflow-x-auto rounded-xl border border-border bg-card"
                data-testid="traffic-compare"
            >
                <div class="p-4 pb-0 text-base font-semibold">
                    {{ $t('tantra.traffic.compare') }}
                </div>
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3"></th>
                            <th
                                v-for="m in traffic.members"
                                :key="m.key"
                                class="p-3 text-right"
                            >
                                <button
                                    type="button"
                                    class="font-medium text-foreground hover:underline"
                                    @click="go({ member: m.key })"
                                >
                                    {{ m.name }}
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in compareRows"
                            :key="row.key"
                            class="border-t border-border"
                        >
                            <td class="p-3 text-muted-foreground">
                                {{ $t(row.label) }}
                            </td>
                            <td
                                v-for="m in traffic.members"
                                :key="m.key"
                                class="p-3 text-right tabular-nums"
                                :class="
                                    best(row) !== null &&
                                    cell(m, row) === best(row)
                                        ? 'font-semibold text-primary'
                                        : ''
                                "
                            >
                                <span v-if="cell(m, row) !== null">{{
                                    show(row.key, cell(m, row))
                                }}</span>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    :title="noteOf(m, row.key)"
                                    >{{ $t('tantra.traffic.not_given') }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <div
                    class="overflow-x-auto rounded-xl border border-border bg-card"
                    data-testid="traffic-platforms-social"
                >
                    <div class="p-4 pb-0 text-base font-semibold">
                        {{ $t('tantra.traffic.platforms_social') }}
                    </div>
                    <table class="w-full min-w-[560px] text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="p-3">
                                    {{ $t('tantra.traffic.person') }}
                                </th>
                                <th class="p-3">
                                    {{ $t('tantra.traffic.platform') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.posts') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.impressions') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.likes') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.comments') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.shares') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.followers') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in socialPlatforms"
                                :key="`${p.member}-${p.platform}`"
                                class="border-t border-border"
                            >
                                <td class="p-3 font-medium">
                                    {{ nameOf(p.member) }}
                                </td>
                                <td class="p-3">
                                    {{
                                        $t(
                                            `tantra.traffic.platforms.${p.platform}`,
                                        )
                                    }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ fmt(p.posts ?? 0) }}
                                </td>
                                <td
                                    v-for="k in [
                                        'impressions',
                                        'likes',
                                        'comments',
                                        'shares',
                                        'followers',
                                    ]"
                                    :key="k"
                                    class="p-3 text-right tabular-nums"
                                >
                                    <span v-if="val(p, k) !== null">{{
                                        fmt(val(p, k))
                                    }}</span>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            $t('tantra.traffic.not_given')
                                        }}</span
                                    >
                                </td>
                            </tr>
                            <tr v-if="!socialPlatforms.length">
                                <td
                                    colspan="8"
                                    class="p-6 text-center text-muted-foreground"
                                >
                                    {{ $t('tantra.traffic.no_data') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div
                    class="overflow-x-auto rounded-xl border border-border bg-card"
                    data-testid="traffic-platforms-crm"
                >
                    <div class="p-4 pb-0 text-base font-semibold">
                        {{ $t('tantra.traffic.platforms_traffic') }}
                    </div>
                    <table class="w-full min-w-[480px] text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="p-3">
                                    {{ $t('tantra.traffic.platform') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.clicks') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.visits') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.conversions') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.sales') }}
                                </th>
                                <th class="p-3 text-right">
                                    {{ $t('tantra.traffic.revenue') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in trafficPlatforms"
                                :key="p.platform"
                                class="border-t border-border"
                            >
                                <td class="p-3 font-medium">
                                    {{
                                        $t(
                                            `tantra.traffic.platforms.${p.platform}`,
                                        )
                                    }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ fmt(p.clicks ?? null) }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ fmt(p.visits ?? null) }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ fmt(p.conversions ?? null) }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{ fmt(p.sales ?? null) }}
                                </td>
                                <td class="p-3 text-right tabular-nums">
                                    {{
                                        money(
                                            p.revenue ?? null,
                                            props.traffic.crm.currency,
                                        )
                                    }}
                                </td>
                            </tr>
                            <tr v-if="!trafficPlatforms.length">
                                <td
                                    colspan="6"
                                    class="p-6 text-center text-muted-foreground"
                                >
                                    {{
                                        crmLive
                                            ? $t('tantra.traffic.no_data')
                                            : $t('tantra.traffic.crm_waiting')
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="px-4 pb-4 text-xs text-muted-foreground">
                        {{ $t('tantra.traffic.platforms_traffic_note') }}
                    </p>
                </div>
            </section>

            <section
                class="overflow-x-auto rounded-xl border border-border bg-card"
                data-testid="traffic-pieces"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2 p-4 pb-0"
                >
                    <div class="text-base font-semibold">
                        {{ $t('tantra.traffic.pieces') }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ $t('tantra.traffic.pieces_note') }}
                    </div>
                </div>
                <table class="w-full min-w-[880px] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3">
                                {{ $t('tantra.traffic.piece') }}
                            </th>
                            <th class="p-3">
                                {{ $t('tantra.traffic.person') }}
                            </th>
                            <th class="p-3">
                                {{ $t('tantra.traffic.platform') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.traffic.clicks') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.traffic.visits') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.traffic.conversions') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.traffic.leads') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.traffic.sales') }}
                            </th>
                            <th class="p-3">
                                {{ $t('tantra.traffic.links') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in pieces"
                            :key="`${p.member}-${p.piece}-${p.platform}`"
                            class="border-t border-border align-top"
                        >
                            <td class="max-w-[260px] p-3">
                                <a
                                    v-if="p.review_url"
                                    :href="p.review_url"
                                    class="font-medium hover:underline"
                                    >{{ p.piece }}</a
                                >
                                <span v-else class="font-medium">{{
                                    p.piece
                                }}</span>
                            </td>
                            <td class="p-3">{{ nameOf(p.member) }}</td>
                            <td class="p-3">
                                {{
                                    $t(`tantra.traffic.platforms.${p.platform}`)
                                }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ fmt(p.clicks) }}
                            </td>
                            <td class="p-3 text-right">
                                <span
                                    class="text-xs text-muted-foreground"
                                    :title="props.traffic.crm.not_split.visits"
                                    >{{
                                        p.visits === null
                                            ? $t('tantra.traffic.not_split')
                                            : fmt(p.visits)
                                    }}</span
                                >
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ fmt(p.conversions) }}
                            </td>
                            <td class="p-3 text-right">
                                <span
                                    class="text-xs text-muted-foreground"
                                    :title="props.traffic.crm.not_split.leads"
                                    >{{
                                        p.leads === null
                                            ? $t('tantra.traffic.not_split')
                                            : fmt(p.leads)
                                    }}</span
                                >
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ fmt(p.sales) }}
                            </td>
                            <td class="p-3">
                                <div class="flex flex-col gap-1">
                                    <button
                                        v-for="l in p.links"
                                        :key="l.code"
                                        type="button"
                                        class="flex items-center gap-1 text-left text-xs text-muted-foreground hover:text-foreground"
                                        :title="l.url"
                                        @click="copy(l.url)"
                                    >
                                        <Badge
                                            variant="outline"
                                            class="font-normal"
                                            >{{ l.placement }}</Badge
                                        >
                                        <span class="truncate">{{
                                            copied === l.url
                                                ? $t('tantra.traffic.copied')
                                                : l.code
                                        }}</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!pieces.length">
                            <td
                                colspan="9"
                                class="p-6 text-center text-muted-foreground"
                            >
                                {{ $t('tantra.traffic.no_pieces') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <div class="grid gap-4 lg:grid-cols-2">
                <section
                    v-for="list in [
                        { title: 'tantra.insights.best', posts: insights.best },
                        {
                            title: 'tantra.insights.worst',
                            posts: insights.worst,
                        },
                    ]"
                    v-show="list.posts.length"
                    :key="list.title"
                    class="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
                >
                    <div class="font-medium">{{ $t(list.title) }}</div>
                    <div
                        v-for="post in list.posts"
                        :key="post.post_id"
                        class="flex flex-col gap-1 border-t border-border pt-2 text-sm"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium capitalize">{{
                                post.profile
                            }}</span>
                            <Badge v-if="post.format" variant="outline">{{
                                post.format
                            }}</Badge>
                            <Badge v-if="post.pillar" variant="outline">{{
                                post.pillar
                            }}</Badge>
                            <span class="ml-auto text-muted-foreground"
                                >{{ num(post.engagement) }}
                                {{ $t('tantra.insights.engagement') }}</span
                            >
                        </div>
                        <div class="line-clamp-2 text-muted-foreground">
                            {{ post.short }}
                        </div>
                        <a
                            v-if="post.url"
                            :href="post.url"
                            target="_blank"
                            rel="noopener"
                            class="text-xs underline"
                            >{{ $t('tantra.insights.open_post') }}</a
                        >
                    </div>
                </section>
            </div>

            <section
                class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4"
                data-testid="tantra-formula"
            >
                <div>
                    <div class="font-medium">
                        {{ $t('tantra.insights.formula') }}
                    </div>
                    <div class="text-sm text-muted-foreground">
                        {{ $t('tantra.insights.formula_intro') }}
                    </div>
                </div>
                <div
                    v-for="f in insights.formula"
                    :key="f.profile"
                    class="flex flex-col gap-1 border-t border-border pt-2 text-sm"
                >
                    <div class="font-medium capitalize">{{ f.profile }}</div>
                    <div v-if="!f.winners.length" class="text-muted-foreground">
                        {{ $t('tantra.insights.formula_none') }}
                    </div>
                    <div v-for="w in f.winners" :key="`${w.kind}-${w.value}`">
                        {{ $t(`tantra.insights.kinds.${w.kind}`) }}:
                        <b>{{ w.value }}</b
                        >,
                        {{
                            $t('tantra.insights.lift', { lift: String(w.lift) })
                        }}
                        ({{ w.posts }} {{ $t('tantra.insights.posts') }})
                    </div>
                    <div
                        v-if="f.weak_signal"
                        class="text-xs text-amber-700 dark:text-amber-300"
                    >
                        {{ $t('tantra.insights.weak_signal') }}
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
