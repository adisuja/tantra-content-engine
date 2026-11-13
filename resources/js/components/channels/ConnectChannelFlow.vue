<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed } from 'vue';

import InstagramConnectStep from '@/components/channels/InstagramConnectStep.vue';
import InstagramFacebookRequirementsStep from '@/components/channels/InstagramFacebookRequirementsStep.vue';
import TelegramConnectStep from '@/components/channels/TelegramConnectStep.vue';
import ConnectGrid from '@/components/tantra/connect/ConnectGrid.vue';
import ConnectNetworkPanel from '@/components/tantra/connect/ConnectNetworkPanel.vue';
import { Button } from '@/components/ui/button';
import {
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    useConnectChannelDialog,
    type ConnectChannelState,
} from '@/composables/useConnectChannelDialog';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { Platform } from '@/types/platform';
import type { AvailablePlatform, ConnectNetwork } from '@/types/social-account';

const props = defineProps<{
    state: ConnectChannelState;
    platforms: AvailablePlatform[];
}>();

const emit = defineEmits<{
    close: [];
    connected: [{ accountId: string | null; created: boolean }];
}>();

const { back, showNetwork } = useConnectChannelDialog();
const { openConnect, instagramMethods } = useNetworkConnect(
    computed(() => props.platforms),
);

// tantra: the logo grid and its per-network panel (Adi 2026-11-14).
const page = usePage();
const networks = computed<ConnectNetwork[]>(
    () =>
        (
            page.props.tantraChannels as
                | { networks?: ConnectNetwork[] }
                | undefined
        )?.networks ?? [],
);

const network = computed(() =>
    props.state.step === 'network'
        ? networks.value.find((n) => n.value === props.state.network)
        : undefined,
);

const connectInstagramFacebook = (): void => {
    emit('close');
    openConnect(Platform.InstagramFacebook, props.state.reconnectId);
};

const selectInstagramMethod = (method: string): void => {
    emit('close');
    openConnect(method, props.state.reconnectId);
};
</script>

<template>
    <div
        :key="`${state.step}-${state.network ?? ''}`"
        class="motion-view-swap relative flex min-h-0 min-w-0 flex-1 flex-col"
    >
        <div
            v-if="state.step === 'grid'"
            class="flex min-h-0 flex-1 flex-col pt-8"
        >
            <DialogHeader
                class="shrink-0 items-center gap-1.5 px-14 pb-6 text-center sm:text-center"
            >
                <DialogTitle
                    class="font-sans text-xl leading-tight font-medium"
                >
                    Connect a channel
                </DialogTitle>
                <DialogDescription class="text-sm text-muted-foreground">
                    Pick a network and sign in.
                </DialogDescription>
            </DialogHeader>
            <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-6 sm:px-8">
                <ConnectGrid @select="(n) => showNetwork(n.value)" />
            </div>
        </div>

        <template v-else>
            <Button
                v-if="state.canGoBack"
                variant="ghost"
                size="sm"
                class="absolute start-3 top-3 z-10 gap-1.5 text-muted-foreground"
                data-testid="connect-channel-back"
                @click="back"
            >
                <IconArrowLeft
                    class="size-4 rtl:rotate-180"
                    aria-hidden="true"
                />
                All channels
            </Button>
            <InstagramConnectStep
                v-if="state.step === 'instagram'"
                :methods="instagramMethods"
                @select="selectInstagramMethod"
            />
            <InstagramFacebookRequirementsStep
                v-else-if="state.step === 'instagram-facebook'"
                @connect="connectInstagramFacebook"
            />
            <div
                v-else-if="
                    state.step === 'telegram' ||
                    (network && network.kind === 'bot')
                "
                class="flex min-h-0 flex-1 flex-col pt-12"
            >
                <TelegramConnectStep
                    :reconnect-id="state.reconnectId"
                    @connected="(result) => emit('connected', result)"
                />
            </div>
            <ConnectNetworkPanel v-else-if="network" :network="network" />
        </template>
    </div>
</template>
