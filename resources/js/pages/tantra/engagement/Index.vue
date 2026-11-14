<script setup lang="ts">
// tantra: the Engagement tab (PRD Engagement, phase 4). A view and editor on the Tantra CRM, which runs the
// pipeline, the stage sequences, enrichment and sending. Same layout as the CRM: Posts, People, Pipeline, Sequences.
import { Head, Link } from '@inertiajs/vue3';
import { IconBrandLinkedin, IconMessageCircleBolt } from '@tabler/icons-vue';
import { onMounted, onUnmounted, ref } from 'vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
import { crmProblem, get } from '@/components/tantra/engagement/api';
import PeopleTab from '@/components/tantra/engagement/PeopleTab.vue';
import PipelineTab from '@/components/tantra/engagement/PipelineTab.vue';
import PostsTab from '@/components/tantra/engagement/PostsTab.vue';
import SequencesTab from '@/components/tantra/engagement/SequencesTab.vue';
import type { Quota } from '@/components/tantra/engagement/shared';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{
    tab: 'posts' | 'people' | 'pipeline' | 'sequences';
    crm: { connected: boolean; reason: string | null; url: string };
    canManage: boolean;
    source: string | null;
    kind: string | null;
}>();

const TABS = ['posts', 'people', 'pipeline', 'sequences'] as const;

// A retry remounts the tab, which loads again.
const attempt = ref(0);
const retry = () => {
    crmProblem.value = null;
    attempt.value++;
    void loadQuotas();
};

const quotas = ref<Quota[]>([]);
const loadQuotas = async () => {
    if (!props.crm.connected) {
        return;
    }
    const r = await get<{ quotas: Quota[] }>('/quotas');
    quotas.value = r.data?.quotas ?? [];
};
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    crmProblem.value = null;
    void loadQuotas();
    timer = setInterval(loadQuotas, 60_000);
});
onUnmounted(() => clearInterval(timer));

const pct = (used: number, cap: number) =>
    cap ? Math.min(100, Math.round((used / cap) * 100)) : 100;
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.engagement.title')" />
        <div class="flex min-h-full min-w-0 flex-col">
            <header
                class="flex flex-wrap items-start justify-between gap-4 px-4 pt-6 pb-4 md:px-8"
            >
                <div class="flex flex-col gap-1">
                    <HeaderTitle
                        :title="$t('tantra.engagement.title')"
                        :icon="IconMessageCircleBolt"
                    />
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        {{ $t('tantra.engagement.intro') }}
                    </p>
                </div>
                <div
                    v-if="quotas.length"
                    class="flex flex-wrap items-center gap-3"
                    data-testid="engagement-quotas"
                >
                    <div
                        v-for="a in quotas.slice(0, 3)"
                        :key="a.account_id"
                        class="flex items-center gap-3 rounded-xl border border-border px-3 py-1.5"
                    >
                        <IconBrandLinkedin class="size-3.5 text-[#0a66c2]" />
                        <span
                            class="max-w-[9rem] truncate text-xs font-medium"
                            >{{ a.account_name }}</span
                        >
                        <span
                            v-for="m in [
                                ['invites', 'invites_day'],
                                ['week', 'invites_week'],
                                ['dms', 'messages_day'],
                            ]"
                            :key="m[1]"
                            class="flex flex-col gap-0.5"
                        >
                            <span
                                class="flex items-baseline justify-between gap-2 text-[10px] text-muted-foreground"
                                ><span>{{
                                    $t(`tantra.engagement.meters.${m[0]}`)
                                }}</span
                                ><span class="tabular-nums"
                                    >{{ a.used[m[1]] ?? 0 }}/{{
                                        a.caps[m[1]] ?? 0
                                    }}</span
                                ></span
                            >
                            <span
                                class="h-1 w-16 overflow-hidden rounded-full bg-muted"
                                ><span
                                    class="block h-full rounded-full"
                                    :class="
                                        pct(
                                            a.used[m[1]] ?? 0,
                                            a.caps[m[1]] ?? 0,
                                        ) >= 90
                                            ? 'bg-red-500'
                                            : pct(
                                                    a.used[m[1]] ?? 0,
                                                    a.caps[m[1]] ?? 0,
                                                ) >= 70
                                              ? 'bg-amber-500'
                                              : 'bg-emerald-500'
                                    "
                                    :style="{
                                        width: `${pct(a.used[m[1]] ?? 0, a.caps[m[1]] ?? 0)}%`,
                                    }"
                            /></span>
                        </span>
                    </div>
                </div>
            </header>

            <nav
                class="flex gap-1 overflow-x-auto border-b border-border px-4 md:px-8"
                :aria-label="$t('tantra.engagement.title')"
            >
                <Link
                    v-for="t in TABS"
                    :key="t"
                    :href="`/tantra/engagement/${t}`"
                    preserve-scroll
                    class="relative shrink-0 px-3 py-2.5 text-sm font-medium transition-colors"
                    :class="
                        tab === t
                            ? 'text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :data-testid="`engagement-tab-${t}`"
                >
                    {{ $t(`tantra.engagement.tabs.${t}`) }}
                    <span
                        v-if="tab === t"
                        class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-primary-strong"
                    />
                </Link>
            </nav>

            <section
                v-if="!crm.connected || crmProblem"
                class="mx-4 mt-6 flex max-w-2xl flex-col gap-3 rounded-xl border border-dashed border-border p-6 text-sm md:mx-8"
                data-testid="engagement-crm-state"
            >
                <b class="font-heading text-base">{{
                    !crm.connected
                        ? $t('tantra.engagement.not_connected.title')
                        : crmProblem?.code === 'crm_not_connected'
                          ? $t('tantra.engagement.not_connected.title')
                          : $t('tantra.engagement.unreachable_title')
                }}</b>
                <p class="text-muted-foreground">
                    {{
                        !crm.connected
                            ? $t(
                                  `tantra.engagement.not_connected.${crm.reason ?? 'no_workspace'}`,
                              )
                            : crmProblem?.message ||
                              $t('tantra.engagement.unreachable')
                    }}
                </p>
                <div v-if="crm.connected" class="flex gap-2">
                    <Button size="sm" @click="retry">{{
                        $t('tantra.engagement.retry')
                    }}</Button>
                    <Button size="sm" variant="outline" as-child>
                        <a :href="crm.url" target="_blank" rel="noopener">{{
                            $t('tantra.engagement.open_crm')
                        }}</a>
                    </Button>
                </div>
            </section>

            <div
                v-if="crm.connected"
                v-show="!crmProblem"
                :key="`${tab}-${attempt}`"
                class="min-h-0 flex-1"
            >
                <PostsTab v-if="tab === 'posts'" :can-manage="canManage" />
                <PeopleTab
                    v-else-if="tab === 'people'"
                    :can-manage="canManage"
                    :initial-source="source"
                    :initial-kind="kind"
                />
                <PipelineTab
                    v-else-if="tab === 'pipeline'"
                    :can-manage="canManage"
                />
                <SequencesTab v-else :can-manage="canManage" />
            </div>
        </div>
    </AppLayout>
</template>
