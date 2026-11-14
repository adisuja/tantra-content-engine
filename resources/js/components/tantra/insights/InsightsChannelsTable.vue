<script setup lang="ts">
// tantra: every channel the viewer can see in one table (PRD 26): its person, the
// latest follower count, the change in the period, its posts, and in plain words
// why its numbers are missing when they are.
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { formatNumber } from '@/lib/utils';
import type { InsightsChannel, InsightsNotice } from '@/types/tantra-insights';

defineProps<{ channels: InsightsChannel[] }>();

const signed = (value: number): string =>
    value > 0 ? `+${formatNumber(value)}` : formatNumber(value);

const noticeParams = (
    channel: InsightsChannel,
    notice: InsightsNotice,
): Record<string, string> => ({
    network: getPlatformLabel(channel.platform),
    count: String(notice.count ?? 0),
});
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-xl border border-border bg-card"
        data-testid="tantra-insights-channels"
    >
        <div class="flex flex-col gap-1 px-4 pt-4 pb-4 sm:px-5">
            <h2
                class="font-heading text-base leading-6 font-medium text-foreground"
            >
                {{ $t('tantra.insights_all.channels_title') }}
            </h2>
            <p class="text-xs text-muted-foreground">
                {{ $t('tantra.insights_all.channels_about') }}
            </p>
        </div>
        <p
            v-if="channels.length === 0"
            class="px-4 pb-5 text-sm text-muted-foreground sm:px-5"
            data-testid="tantra-insights-channels-empty"
        >
            {{ $t('tantra.insights_all.no_channels') }}
        </p>
        <div v-else class="min-w-0 overflow-x-auto border-t border-border">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs text-muted-foreground">
                    <tr class="border-b border-border">
                        <th scope="col" class="px-4 py-2 font-medium sm:px-5">
                            {{ $t('tantra.insights_all.channel') }}
                        </th>
                        <th scope="col" class="px-3 py-2 font-medium">
                            {{ $t('tantra.insights_all.person') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ $t('tantra.insights_all.followers') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ $t('tantra.insights_all.change') }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ $t('tantra.insights_all.posts') }}
                        </th>
                        <th scope="col" class="px-4 py-2 font-medium sm:px-5">
                            {{ $t('tantra.insights_all.status') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="channel in channels"
                        :key="channel.id"
                        class="border-b border-border last:border-b-0"
                        :data-testid="`tantra-insights-channel-${channel.id}`"
                    >
                        <td class="px-4 py-3 sm:px-5">
                            <span class="flex min-w-0 items-center gap-3">
                                <ChannelAvatar
                                    :platform="channel.platform"
                                    :src="channel.avatar_url"
                                    :name="channel.display_label"
                                    ring="card"
                                    :size="32"
                                    :status="channel.status"
                                    :account-id="channel.id"
                                    :verified="channel.verified_badge"
                                />
                                <span class="flex min-w-0 flex-col">
                                    <span
                                        class="truncate text-sm font-emphasis text-foreground"
                                        >{{ channel.display_label }}</span
                                    >
                                    <span
                                        class="truncate text-xs text-muted-foreground"
                                        >{{ getPlatformLabel(channel.platform)
                                        }}<template v-if="channel.username">
                                            · @{{ channel.username }}</template
                                        ></span
                                    >
                                </span>
                            </span>
                        </td>
                        <td class="px-3 py-3 text-foreground">
                            {{ channel.person }}
                        </td>
                        <td
                            class="px-3 py-3 text-right text-foreground tabular-nums"
                            :data-testid="`tantra-insights-followers-${channel.id}`"
                        >
                            <span
                                v-if="channel.followers"
                                :title="
                                    $t('tantra.insights_all.counted_on', {
                                        date: date.formatDayMonthYear(
                                            channel.followers.date,
                                        ),
                                    })
                                "
                                >{{
                                    formatNumber(channel.followers.value)
                                }}</span
                            >
                            <span v-else class="text-muted-foreground">{{
                                $t('tantra.insights_all.no_followers')
                            }}</span>
                        </td>
                        <td
                            class="px-3 py-3 text-right tabular-nums"
                            :data-testid="`tantra-insights-change-${channel.id}`"
                        >
                            <span
                                v-if="channel.followers?.change != null"
                                :class="
                                    channel.followers.change < 0
                                        ? 'text-destructive'
                                        : 'text-foreground'
                                "
                                >{{ signed(channel.followers.change) }}</span
                            >
                            <span
                                v-else-if="channel.followers"
                                class="text-xs text-muted-foreground"
                                >{{
                                    $t('tantra.insights_all.first_reading')
                                }}</span
                            >
                        </td>
                        <td
                            class="px-3 py-3 text-right text-foreground tabular-nums"
                        >
                            {{ channel.posts ?? '' }}
                        </td>
                        <td class="px-4 py-3 sm:px-5">
                            <ul
                                v-if="channel.notices.length"
                                class="flex max-w-80 flex-col gap-1 text-xs text-muted-foreground"
                            >
                                <li
                                    v-for="notice in channel.notices"
                                    :key="notice.key"
                                    :data-testid="`tantra-insights-notice-${channel.id}-${notice.key}`"
                                >
                                    {{
                                        $t(
                                            `tantra.insights_all.reasons.${notice.key}`,
                                            noticeParams(channel, notice),
                                        )
                                    }}
                                </li>
                            </ul>
                            <span
                                v-else
                                class="text-xs text-muted-foreground"
                                >{{ $t('tantra.insights_all.reading') }}</span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
