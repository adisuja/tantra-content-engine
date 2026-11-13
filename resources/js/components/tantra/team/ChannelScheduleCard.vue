<script setup lang="ts">
// tantra: one channel's posting days and times on the Team page (PRD 25.6),
// built from the same pieces as the channel settings page.
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import ChannelTimezoneSetting from '@/components/channels/ChannelTimezoneSetting.vue';
import PostingScheduleAddSlot from '@/components/channels/PostingScheduleAddSlot.vue';
import PostingScheduleGrid from '@/components/channels/PostingScheduleGrid.vue';
import { Button } from '@/components/ui/button';
import {
    cleared,
    emptySchedule,
    slotCount,
    withTime,
} from '@/lib/postingSchedule';
import type {
    ChannelScheduleState,
    PostingSchedule,
    TimezoneOption,
} from '@/types/posting-schedule';
import type { VerifiedBadge } from '@/types/social-account';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface TeamChannel {
    id: string;
    platform: string;
    display_name: string | null;
    username: string | null;
    avatar_url: string | null;
    verified_badge: VerifiedBadge | null;
    status: SocialAccountStatusValue | null;
    workspace: string | null;
    confirmed: boolean;
    schedule: ChannelScheduleState;
}

const props = defineProps<{
    channel: TeamChannel;
    timezones: TimezoneOption[];
}>();

const emit = defineEmits<{
    saved: [];
}>();

const name = computed(
    () => props.channel.display_name || props.channel.username || '',
);

const state = ref<ChannelScheduleState>({ ...props.channel.schedule });
const http = useHttp<Record<string, any>, ChannelScheduleState>({});

const currentSchedule = computed<PostingSchedule>(
    () => state.value.posting_schedule ?? emptySchedule(),
);
const hasTimes = computed(() => slotCount(currentSchedule.value) > 0);
// Unipile and Zernio channels arrive with default times nobody chose yet.
const confirmed = ref(props.channel.confirmed);

// Saves run one after another; the last answer wins and a failure goes back
// to what the server last confirmed, as on the channel settings page. The
// timezone and goal always go along, since upstream clears what is missing.
let saved: ChannelScheduleState = { ...props.channel.schedule };
let queue: Promise<void> = Promise.resolve();
let queued = 0;

const save = (next: ChannelScheduleState): void => {
    state.value = next;
    queued += 1;

    const run = async (): Promise<void> => {
        try {
            const response = await http
                .transform(() => ({
                    timezone: next.timezone,
                    posting_goal: next.posting_goal,
                    posting_schedule: next.posting_schedule,
                }))
                .put(
                    `/tantra/team/channels/${encodeURIComponent(props.channel.id)}/schedule`,
                );

            if (!response) {
                throw new Error('invalid');
            }

            saved = response;

            if (queued === 1) {
                state.value = response;
                confirmed.value = true;
                emit('saved');
            }
        } catch {
            toast.error(trans('tantra.team.channels.save_failed'));

            if (queued === 1) {
                state.value = saved;
            }
        } finally {
            queued -= 1;
        }
    };

    queue = queue.then(run);
};

const changeTimezone = (timezone: string): void => {
    save({ ...state.value, timezone });
};

const changeSchedule = (next: PostingSchedule): void => {
    save({ ...state.value, posting_schedule: next });
};

const addSlot = (days: number[], time: string): void => {
    changeSchedule(
        days.reduce(
            (schedule, day) => withTime(schedule, day, time),
            currentSchedule.value,
        ),
    );
};

const clearAll = (): void => {
    changeSchedule(cleared(currentSchedule.value));
};

const keepTimes = (): void => {
    save({ ...state.value });
};
</script>

<template>
    <article
        class="flex flex-col gap-6 rounded-xl border border-border bg-card p-4"
        :id="`channel-${channel.id}`"
        :data-testid="`tantra-channel-schedule-${channel.id}`"
    >
        <div class="flex min-w-0 items-center gap-3">
            <ChannelAvatar
                :status="channel.status"
                :account-id="channel.id"
                :platform="channel.platform"
                :src="channel.avatar_url"
                :verified="channel.verified_badge"
                :name="name"
                :size="40"
                ring="card"
            />
            <div class="min-w-0">
                <div class="truncate font-medium text-foreground">
                    {{ name }}
                </div>
                <div
                    v-if="channel.workspace"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{
                        $t('tantra.team.channels.in_workspace', {
                            workspace: channel.workspace,
                        })
                    }}
                </div>
            </div>
        </div>

        <div
            v-if="!confirmed"
            class="flex flex-col gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:border-amber-700 dark:bg-amber-950/40"
            data-testid="tantra-channel-schedule-check"
        >
            <span>{{
                $t('tantra.team.channels.check', { channel: name })
            }}</span>
            <Button
                size="sm"
                variant="outline"
                :disabled="!hasTimes"
                @click="keepTimes"
                >{{ $t('tantra.team.channels.keep') }}</Button
            >
        </div>

        <ChannelTimezoneSetting
            :timezone="state.timezone"
            :timezones="timezones"
            @change="changeTimezone"
        />

        <hr class="border-border" />

        <section class="flex flex-col gap-4">
            <div class="min-w-0">
                <h3 class="text-base leading-5 font-emphasis text-foreground">
                    {{ $t('channels.settings_page.slots_title') }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    {{ $t('channels.settings_page.slots_description') }}
                </p>
            </div>

            <p
                v-if="!hasTimes"
                class="rounded-lg border border-dashed border-border-strong px-4 py-3 text-sm text-muted-foreground"
                data-testid="tantra-channel-schedule-empty"
            >
                {{ $t('tantra.team.channels.no_times') }}
            </p>

            <PostingScheduleGrid
                :schedule="currentSchedule"
                @change="changeSchedule"
            />

            <PostingScheduleAddSlot
                :schedule="currentSchedule"
                :timezone="state.timezone"
                @add="addSlot"
                @clear="clearAll"
            />

            <p class="text-xs text-muted-foreground">
                {{ $t('tantra.team.channels.reflow') }}
            </p>
        </section>
    </article>
</template>
