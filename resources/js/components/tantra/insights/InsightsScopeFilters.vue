<script setup lang="ts">
// tantra: the people and network filters of the Insights tab (PRD 26). Each shows
// only when there is more than one to pick from.
import { IconUsers, IconWorld } from '@tabler/icons-vue';
import { computed } from 'vue';

import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import type { TantraInsights } from '@/types/tantra-insights';

const props = defineProps<{
    workspaceList: TantraInsights['workspaces'];
    networkList: TantraInsights['networks'];
}>();
const people = defineModel<string[]>('people', { required: true });
const networks = defineModel<string[]>('networks', { required: true });

const personOptions = computed(() =>
    props.workspaceList.map((workspace) => ({
        id: workspace.id,
        label: workspace.name,
    })),
);
const networkOptions = computed(() =>
    props.networkList.map((network) => ({
        id: network.value,
        label: getPlatformLabel(network.platform),
    })),
);
const platformOf = (value: string): string =>
    props.networkList.find((network) => network.value === value)?.platform ??
    value;
</script>

<template>
    <MultiSelectFilter
        v-if="workspaceList.length > 1"
        v-model="people"
        :options="personOptions"
        :label="$t('tantra.insights_all.people')"
        :search-placeholder="$t('tantra.insights_all.people_search')"
        :empty-message="$t('tantra.insights_all.no_people')"
        :select-all-label="$t('tantra.insights_all.select_all')"
        :deselect-all-label="$t('tantra.insights_all.deselect_all')"
        test-id="tantra-insights-people"
        icon-only-on-mobile
        sheet-below-sm
        compact
    >
        <template #icon>
            <IconUsers class="size-4" />
        </template>
    </MultiSelectFilter>
    <MultiSelectFilter
        v-if="networkList.length > 1"
        v-model="networks"
        :options="networkOptions"
        :label="$t('tantra.insights_all.networks')"
        :search-placeholder="$t('tantra.insights_all.networks_search')"
        :empty-message="$t('tantra.insights_all.no_networks')"
        :select-all-label="$t('tantra.insights_all.select_all')"
        :deselect-all-label="$t('tantra.insights_all.deselect_all')"
        test-id="tantra-insights-networks"
        icon-only-on-mobile
        sheet-below-sm
        compact
    >
        <template #icon>
            <IconWorld class="size-4" />
        </template>
        <template #option="{ option }">
            <span class="flex min-w-0 items-center gap-2">
                <img
                    :src="getPlatformLogo(platformOf(option.id))"
                    alt=""
                    class="size-4 shrink-0 rounded-sm object-contain"
                    :data-testid="`tantra-insights-network-logo-${option.id}`"
                />
                <span class="min-w-0 truncate">{{ option.label }}</span>
            </span>
        </template>
    </MultiSelectFilter>
</template>
