<script setup lang="ts">
// tantra: the team dashboard (PRD 10.6).
import { Head, Link } from '@inertiajs/vue3';
import { IconChartBar } from '@tabler/icons-vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
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

type Person = {
    profile: string;
    platform: 'linkedin' | 'linkedin_page';
    posts: number;
    impressions: number | null;
    clicks: number | null;
    engagement: number;
    engagement_rate: number | null;
    boost_coverage: number;
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

defineProps<{
    insights: {
        days: number;
        people: Person[];
        page: { followers_gained: number | null; page_views: number | null };
        best: InsightPost[];
        worst: InsightPost[];
        formula: Formula[];
    };
    ranges: number[];
}>();

const num = (v: number | null): string =>
    v === null ? '' : v.toLocaleString(undefined);
const pct = (v: number | null): string =>
    v === null ? '' : `${(v * 100).toFixed(1)}%`;
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
                        :title="$t('tantra.insights.title')"
                        :icon="IconChartBar"
                    />
                    <div class="ml-auto flex gap-2">
                        <Button
                            v-for="d in ranges"
                            :key="d"
                            size="sm"
                            :variant="
                                insights.days === d ? 'default' : 'outline'
                            "
                            as-child
                        >
                            <Link
                                :href="`/tantra/insights?days=${d}`"
                                preserve-scroll
                                >{{
                                    $t('tantra.insights.range', {
                                        days: String(d),
                                    })
                                }}</Link
                            >
                        </Button>
                    </div>
                </div>
                <p class="max-w-3xl text-sm text-muted-foreground">
                    {{ $t('tantra.insights.intro') }}
                    {{ $t('tantra.insights.no_impressions') }}
                </p>
            </header>

            <div
                v-if="!insights.people.length"
                class="rounded-xl border border-dashed border-border p-10 text-center text-sm text-muted-foreground"
            >
                {{ $t('tantra.insights.empty') }}
            </div>

            <section
                v-else
                class="overflow-x-auto rounded-xl border border-border bg-card"
            >
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="p-3">
                                {{ $t('tantra.insights.person') }}
                            </th>
                            <th class="p-3">
                                {{ $t('tantra.insights.platform') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.posts') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.impressions') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.engagement') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.engagement_rate') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.clicks') }}
                            </th>
                            <th class="p-3 text-right">
                                {{ $t('tantra.insights.boost_coverage') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in insights.people"
                            :key="`${p.profile}-${p.platform}`"
                            class="border-t border-border"
                        >
                            <td class="p-3 font-medium capitalize">
                                {{ p.profile }}
                            </td>
                            <td class="p-3">
                                {{
                                    $t(
                                        `tantra.insights.platforms.${p.platform}`,
                                    )
                                }}
                            </td>
                            <td class="p-3 text-right">{{ p.posts }}</td>
                            <td class="p-3 text-right">
                                {{
                                    num(p.impressions) ||
                                    $t('tantra.insights.not_available')
                                }}
                            </td>
                            <td class="p-3 text-right">
                                {{ num(p.engagement) }}
                            </td>
                            <td class="p-3 text-right">
                                {{
                                    pct(p.engagement_rate) ||
                                    $t('tantra.insights.not_available')
                                }}
                            </td>
                            <td class="p-3 text-right">
                                {{
                                    num(p.clicks) ||
                                    $t('tantra.insights.not_available')
                                }}
                            </td>
                            <td class="p-3 text-right">
                                {{ pct(p.boost_coverage) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section
                v-if="
                    insights.page.followers_gained !== null ||
                    insights.page.page_views !== null
                "
                class="flex flex-wrap gap-6 rounded-xl border border-border bg-card p-4"
            >
                <div class="font-medium">{{ $t('tantra.insights.page') }}</div>
                <div class="text-sm">
                    <span class="text-muted-foreground"
                        >{{ $t('tantra.insights.followers_gained') }}:</span
                    >
                    {{ num(insights.page.followers_gained) }}
                </div>
                <div class="text-sm">
                    <span class="text-muted-foreground"
                        >{{ $t('tantra.insights.page_views') }}:</span
                    >
                    {{ num(insights.page.page_views) }}
                </div>
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
