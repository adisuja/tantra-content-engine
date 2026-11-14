<script setup lang="ts">
import { computed, inject } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import { accountLabel } from '@/lib/analyticsAccounts';
import type { AccountIdentityData } from '@/types/analytics';
import { insightsPeopleKey } from '@/types/tantra-insights';

const props = defineProps<{
    account: AccountIdentityData;
    withAvatar?: boolean;
    avatarSize?: 24 | 32;
    color?: string;
    /** The name on top and the @handle below it. */
    withName?: boolean;
}>();
const label = computed(() => accountLabel(props.account));
// tantra: whose channel it is, on the Insights tab across workspaces (PRD 26)
const people = inject(insightsPeopleKey, null);
const person = computed(
    () => people?.[props.account.social_account_key] ?? null,
);
const handle = computed(
    () =>
        [
            props.account.name && props.account.username
                ? `@${props.account.username}`
                : null,
            person.value,
        ]
            .filter(Boolean)
            .join(' · ') || null,
);
</script>

<template>
    <span
        class="inline-flex min-w-0 items-center"
        :class="withAvatar && !withName ? 'gap-3' : 'gap-2'"
    >
        <span
            v-if="color"
            data-testid="analytics-legend-swatch"
            class="-mr-0.5 size-2.5 shrink-0 rounded-[3px]"
            :style="{ backgroundColor: color }"
        />
        <ChannelAvatar
            v-if="withAvatar"
            :platform="account.platform"
            :src="account.avatar_url"
            :name="label"
            ring="card"
            :size="avatarSize ?? 32"
            :status="account.status"
            :account-id="account.social_account_key"
            :verified="account.verified_badge"
        />
        <img
            v-else
            :src="getPlatformLogo(account.platform)"
            :alt="getPlatformLabel(account.platform)"
            class="size-4 shrink-0 rounded-sm object-contain"
        />
        <span v-if="withName" class="flex min-w-0 flex-col">
            <span class="truncate text-xs font-emphasis text-foreground">{{
                label
            }}</span>
            <span
                v-if="handle"
                class="truncate text-xs text-muted-foreground"
                >{{ handle }}</span
            >
        </span>
        <span
            v-else
            class="truncate text-sm text-foreground"
            :title="`${label} · ${getPlatformLabel(account.platform)}`"
            >{{ label
            }}<span
                v-if="person"
                class="text-muted-foreground"
                data-testid="tantra-insights-person"
            >
                · {{ person }}</span
            ></span
        >
        <span class="sr-only">{{ getPlatformLabel(account.platform) }}</span>
    </span>
</template>
