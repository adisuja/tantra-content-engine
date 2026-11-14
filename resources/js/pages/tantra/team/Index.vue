<script setup lang="ts">
// tantra: the Team page (PRD 10.5, 10.9 and 25.6): consent to post approved
// team comments in your name, your expertise line, the posting days and times
// of every channel you review, and for the Account owner, which Unipile
// LinkedIn account belongs to which teammate.
import { Head, router } from '@inertiajs/vue3';
import { IconUsersGroup } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import { postJson } from '@/components/tantra/review/reviewApi';
import ChannelScheduleCard, {
    type TeamChannel,
} from '@/components/tantra/team/ChannelScheduleCard.vue';
import RebrandPanel from '@/components/tantra/team/RebrandPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import type { TimezoneOption } from '@/types/posting-schedule';

interface Member {
    id: string;
    name: string;
    email: string;
    is_me: boolean;
    unipile_account_id: string | null;
    unipile_name: string | null;
    confirmed: boolean;
    consent: boolean;
    paused_until: string | null;
    expertise: string | null;
    checklist: Record<ChecklistStep, boolean>;
    linkedin: {
        name: string | null;
        headline: string | null;
        photo: string | null;
        url: string | null;
        status: string | null;
        synced_at: string | null;
    } | null;
    clerk_linked?: boolean;
    channels: TeamChannel[];
}

type ChecklistStep =
    | 'joined'
    | 'linkedin'
    | 'expertise'
    | 'unipile'
    | 'consent'
    | 'api_key'
    | 'schedule';

// Where each step is done. Steps done on this page, or by Adi, have no link.
const STEP_LINKS: Partial<Record<ChecklistStep, string>> = {
    linkedin: '/connect/linkedin',
    api_key: '/settings/workspace/api-keys',
    schedule: '#posting-times',
};
const STEPS: ChecklistStep[] = [
    'joined',
    'linkedin',
    'expertise',
    'unipile',
    'consent',
    'api_key',
    'schedule',
];
const doneCount = (m: Member): number =>
    STEPS.filter((step) => m.checklist[step]).length;

interface UnipileAccount {
    id: string;
    name: string;
    status: string;
    suggested_for: string | null;
}

const props = defineProps<{
    members: Member[];
    isOwner: boolean;
    boostEnabled: boolean;
    unipileAccounts: UnipileAccount[];
    timezones: TimezoneOption[];
}>();

// Channels whose times came from a default nobody has checked yet.
const unchecked = (m: Member): TeamChannel[] =>
    m.channels.filter((c) => !c.confirmed);

const reloadMembers = (): void => {
    router.reload({ only: ['members'] });
};

const me = props.members.find((m) => m.is_me);
const expertise = ref(me?.expertise ?? '');
const picks = ref<Record<string, string>>(
    Object.fromEntries(
        props.members.map((m) => [
            m.id,
            m.unipile_account_id ??
                props.unipileAccounts.find((a) => a.suggested_for === m.name)
                    ?.id ??
                '',
        ]),
    ),
);

const send = async (url: string, body: Record<string, unknown>) => {
    const result = await postJson(url, body);
    if (result.ok) {
        toast.success(result.message || trans('tantra.review.saved'));
        router.reload({ only: ['members'] });
    } else {
        toast.error(result.message || trans('tantra.review.not_saved'));
    }
};
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.team.title')" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex flex-col gap-2">
                <HeaderTitle
                    :title="$t('tantra.team.title')"
                    :icon="IconUsersGroup"
                />
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.team.intro') }}
                </p>
                <Badge v-if="!boostEnabled" variant="outline" class="w-fit">{{
                    $t('tantra.team.boost_off')
                }}</Badge>
            </header>

            <section
                v-if="me && doneCount(me) < STEPS.length"
                class="flex max-w-2xl flex-col gap-2 rounded-xl border border-border bg-card p-4 text-sm"
                data-testid="tantra-checklist"
            >
                <div class="font-medium">
                    {{ $t('tantra.team.checklist.title') }}
                </div>
                <div
                    v-for="step in STEPS"
                    :key="step"
                    class="flex items-center gap-2"
                >
                    <span
                        :class="
                            me.checklist[step]
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-muted-foreground'
                        "
                        >{{ me.checklist[step] ? '✓' : '○' }}</span
                    >
                    <span
                        :class="{
                            'text-muted-foreground line-through':
                                me.checklist[step],
                        }"
                        >{{ $t(`tantra.team.checklist.${step}`) }}</span
                    >
                    <a
                        v-if="!me.checklist[step] && STEP_LINKS[step]"
                        :href="STEP_LINKS[step]"
                        class="text-xs underline"
                        >{{ $t('tantra.team.checklist.do_it') }}</a
                    >
                </div>
                <a
                    v-for="c in me.checklist.schedule ? [] : unchecked(me)"
                    :key="c.id"
                    :href="`#channel-${c.id}`"
                    class="ms-6 text-xs underline"
                    >{{
                        $t('tantra.team.channels.check', {
                            channel: c.display_name || c.username || '',
                        })
                    }}</a
                >
            </section>

            <section
                v-if="me"
                class="flex max-w-2xl flex-col gap-4 rounded-xl border border-border bg-card p-4 text-sm"
            >
                <label class="flex items-start gap-3">
                    <Switch
                        :model-value="me.consent"
                        @update:model-value="
                            (on: boolean) =>
                                send('/tantra/team/consent', { on })
                        "
                    />
                    <span>
                        <b>{{ $t('tantra.team.consent') }}</b>
                        <span class="block text-muted-foreground">{{
                            $t('tantra.team.consent_help')
                        }}</span>
                    </span>
                </label>
                <div class="text-muted-foreground">
                    {{
                        me.confirmed
                            ? $t('tantra.team.my_account', {
                                  name: me.unipile_name ?? '',
                              })
                            : $t('tantra.team.no_account')
                    }}
                </div>
                <form
                    class="flex flex-col gap-2"
                    @submit.prevent="
                        send('/tantra/team/expertise', { expertise })
                    "
                >
                    <b>{{ $t('tantra.team.expertise') }}</b>
                    <Textarea
                        v-model="expertise"
                        rows="3"
                        :placeholder="$t('tantra.team.expertise_placeholder')"
                    />
                    <div>
                        <Button size="sm" variant="outline" type="submit">{{
                            $t('tantra.team.save')
                        }}</Button>
                    </div>
                </form>
            </section>

            <section
                id="posting-times"
                class="flex max-w-4xl flex-col gap-4"
                data-testid="tantra-posting-times"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="font-heading text-base font-medium">
                        {{ $t('tantra.team.channels.title') }}
                    </h2>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        {{ $t('tantra.team.channels.help') }}
                    </p>
                </div>
                <div
                    v-for="m in members"
                    :key="m.id"
                    class="flex flex-col gap-3"
                >
                    <h3 v-if="isOwner" class="text-sm font-medium">
                        {{ m.linkedin?.name || m.name }}
                    </h3>
                    <p
                        v-if="m.channels.length === 0"
                        class="rounded-lg border border-dashed border-border-strong px-4 py-3 text-sm text-muted-foreground"
                    >
                        {{ $t('tantra.team.channels.none') }}
                        <a
                            v-if="m.is_me"
                            href="/settings/workspace/channels"
                            class="underline"
                            >{{ $t('tantra.team.channels.connect') }}</a
                        >
                    </p>
                    <ChannelScheduleCard
                        v-for="c in m.channels"
                        :key="c.id"
                        :channel="c"
                        :timezones="timezones"
                        @saved="reloadMembers"
                    />
                </div>
            </section>

            <RebrandPanel
                :people="
                    members.map((m) => ({
                        id: m.id,
                        name: m.name,
                        is_me: m.is_me,
                    }))
                "
                :can-start-for-others="isOwner"
            />

            <section v-if="isOwner" class="flex flex-col gap-3">
                <h2 class="font-heading text-base font-medium">
                    {{ $t('tantra.team.matches') }}
                </h2>
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.team.matches_help') }}
                </p>
                <div
                    v-for="m in members"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 text-sm"
                >
                    <img
                        v-if="m.linkedin?.photo"
                        :src="m.linkedin.photo"
                        :alt="m.linkedin.name ?? m.name"
                        class="size-10 shrink-0 rounded-full object-cover"
                    />
                    <div class="min-w-48">
                        <b>{{ m.linkedin?.name || m.name }}</b>
                        <div
                            v-if="m.linkedin?.headline"
                            class="max-w-80 truncate text-xs text-muted-foreground"
                            :title="$t('tantra.team.linkedin_from')"
                        >
                            {{ m.linkedin.headline }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ m.email }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{
                                $t('tantra.team.checklist.progress', {
                                    done: String(doneCount(m)),
                                    total: String(STEPS.length),
                                })
                            }}
                        </div>
                    </div>
                    <select
                        v-model="picks[m.id]"
                        class="h-9 rounded-md border border-border bg-background px-2"
                        :aria-label="
                            $t('tantra.team.account_for', { name: m.name })
                        "
                    >
                        <option value="">{{ $t('tantra.team.none') }}</option>
                        <option
                            v-for="a in unipileAccounts"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.name }}{{ a.status ? ` (${a.status})` : '' }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            send('/tantra/team/match', {
                                user_id: m.id,
                                unipile_account_id: picks[m.id] || null,
                            })
                        "
                        >{{ $t('tantra.team.confirm') }}</Button
                    >
                    <Badge v-if="m.confirmed" variant="default">{{
                        $t('tantra.team.confirmed')
                    }}</Badge>
                    <Badge :variant="m.consent ? 'default' : 'outline'">{{
                        m.consent
                            ? $t('tantra.team.consent_on')
                            : $t('tantra.team.consent_off')
                    }}</Badge>
                    <Badge v-if="m.paused_until" variant="destructive">{{
                        $t('tantra.team.paused')
                    }}</Badge>
                    <Badge
                        v-if="m.linkedin?.status === 'disconnected'"
                        variant="destructive"
                        >{{ $t('tantra.team.linkedin_disconnected') }}</Badge
                    >
                    <Badge
                        v-else-if="m.linkedin && !m.linkedin.synced_at"
                        variant="outline"
                        >{{ $t('tantra.team.linkedin_waiting') }}</Badge
                    >
                    <!-- tantra: clear the usetantra.com (Clerk) login this teammate signs in as. -->
                    <Button
                        v-if="m.clerk_linked"
                        size="sm"
                        variant="ghost"
                        data-testid="tantra-clerk-unlink"
                        @click="send(`/tantra/team/${m.id}/clerk-unlink`, {})"
                        >Unlink usetantra.com login</Button
                    >
                </div>
            </section>
        </div>
    </AppLayout>
</template>
