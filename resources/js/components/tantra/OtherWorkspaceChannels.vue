<script setup lang="ts">
// tantra: the channels of the member's other workspaces, grouped by workspace
// (PRD D1, Adi 2026-11-13). A click switches to that workspace and opens the channel.
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    SidebarGroupLabel,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { channelName, type SidebarChannel } from '@/types/channel';

type Group = { id: string; name: string; channels: SidebarChannel[] };

const page = usePage();
const groups = computed<Group[]>(
    () => (page.props.tantraOtherChannels as Group[] | undefined) ?? [],
);
const { state, isMobile } = useSidebar();
const isCollapsedRail = computed(
    () => state.value === 'collapsed' && !isMobile.value,
);

const open = (channel: SidebarChannel): void => {
    router.post(`/tantra/channels/${channel.id}/open`);
};
</script>

<template>
    <div
        v-if="groups.length > 0"
        class="-mx-4 flex min-h-0 flex-col overflow-y-auto px-4 pb-4 group-data-[collapsible=icon]:-mx-2.5 group-data-[collapsible=icon]:px-2.5"
        data-testid="sidebar-other-workspaces"
    >
        <section
            v-for="group in groups"
            :key="group.id"
            :data-testid="`sidebar-workspace-${group.id}`"
        >
            <SidebarGroupLabel class="group-data-[collapsible=icon]:hidden">
                {{ group.name }} · {{ group.channels.length }}
            </SidebarGroupLabel>
            <div
                aria-hidden="true"
                class="my-2 hidden h-px bg-sidebar-border group-data-[collapsible=icon]:block"
            />
            <ul class="mt-1 flex flex-col gap-2">
                <SidebarMenuItem
                    v-for="channel in group.channels"
                    :key="channel.id"
                    :data-testid="`sidebar-other-channel-${channel.id}`"
                >
                    <SidebarMenuButton
                        class="h-9 gap-2 py-1 ps-2 group-data-[collapsible=icon]:overflow-visible group-data-[collapsible=icon]:p-0.5!"
                        :tooltip="`${channelName(channel)} · ${group.name}`"
                        @click="open(channel)"
                    >
                        <ChannelAvatar
                            :platform="channel.platform"
                            :src="channel.avatar_url"
                            :verified="channel.verified_badge"
                            :name="channelName(channel)"
                            :size="28"
                            ring="sidebar"
                            :reserve-space="!isCollapsedRail"
                            :status="channel.status"
                            :account-id="channel.id"
                        />
                        <span
                            class="truncate text-sm group-data-[collapsible=icon]:hidden"
                            >{{ channelName(channel) }}</span
                        >
                    </SidebarMenuButton>
                    <SidebarMenuBadge class="top-2 right-0 max-md:hidden">
                        {{ channel.scheduled_posts_count }}
                    </SidebarMenuBadge>
                </SidebarMenuItem>
            </ul>
        </section>
    </div>
</template>
