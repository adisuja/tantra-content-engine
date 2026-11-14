<script setup lang="ts">
// tantra: the "Connect a channel" logo grid (Adi 2026-11-14). One tile per
// network: logo, name, and whether it is connected. Nothing else. Tiles come
// from the shared tantraChannels.networks prop (app/Tantra/Channels/ConnectNetworks.php).
import { Link, usePage } from '@inertiajs/vue3';
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import type { ConnectNetwork } from '@/types/social-account';

const emit = defineEmits<{
    select: [network: ConnectNetwork];
}>();

const page = usePage();
const networks = computed<ConnectNetwork[]>(
    () =>
        (
            page.props.tantraChannels as
                | { networks?: ConnectNetwork[] }
                | undefined
        )?.networks ?? [],
);

const status = (n: ConnectNetwork): string =>
    n.count > 1 ? `${n.count} connected` : 'Connected';

const setupUrl = (n: ConnectNetwork): string =>
    `/settings/developer-apps?channel=${n.setup_app ?? ''}#channels`;
</script>

<template>
    <ul
        class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4"
        aria-label="Channels you can connect"
        data-testid="connect-grid"
    >
        <li v-for="n in networks" :key="n.value" class="flex">
            <!-- Ready: the whole tile is the button. -->
            <button
                v-if="n.ready"
                type="button"
                class="group relative flex min-h-[132px] w-full cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border border-border bg-card px-3 py-5 text-center transition-[border-color,box-shadow,transform] duration-150 ease-out hover:border-border-strong hover:shadow-[0_6px_20px_-14px_rgb(26_16_51/0.45)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:translate-y-px motion-reduce:transition-none"
                :aria-label="
                    n.connected
                        ? `${n.label}, ${status(n).toLowerCase()}. Connect another`
                        : `Connect ${n.label}`
                "
                :data-testid="`connect-channel-${n.value}`"
                @click="emit('select', n)"
            >
                <span
                    class="relative flex size-12 items-center justify-center rounded-xl bg-muted [&>svg]:size-7"
                    aria-hidden="true"
                >
                    <PlatformBrandIcon :platform="n.value" colored />
                    <span
                        v-if="n.connected"
                        class="absolute -right-1.5 -bottom-1.5 flex size-5 items-center justify-center overflow-hidden rounded-full bg-success text-white ring-2 ring-card"
                    >
                        <img
                            v-if="n.avatar_url"
                            :src="n.avatar_url"
                            alt=""
                            class="size-full object-cover"
                        />
                        <IconCheck v-else class="size-3" stroke-width="3" />
                    </span>
                </span>
                <span class="flex flex-col gap-0.5">
                    <span
                        class="text-sm leading-5 font-medium text-foreground"
                        >{{ n.label }}</span
                    >
                    <span
                        v-if="n.connected"
                        class="inline-flex items-center justify-center gap-1 text-xs leading-4 text-success-text"
                        :data-testid="`connect-channel-status-${n.value}`"
                    >
                        <IconCheck class="size-3" aria-hidden="true" />{{
                            status(n)
                        }}
                    </span>
                </span>
            </button>

            <!-- Not ready: muted. The owner gets a "Set up" link. -->
            <div
                v-else
                class="relative flex min-h-[132px] w-full flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-card/50 px-3 py-5 text-center"
                :data-testid="`connect-channel-${n.value}`"
                data-ready="false"
            >
                <span
                    class="flex size-12 items-center justify-center rounded-xl bg-muted opacity-50 grayscale [&>svg]:size-7"
                    aria-hidden="true"
                >
                    <PlatformBrandIcon :platform="n.value" colored />
                </span>
                <span class="flex flex-col gap-0.5">
                    <span
                        class="text-sm leading-5 font-medium text-muted-foreground"
                        >{{ n.label }}</span
                    >
                    <Link
                        v-if="n.setup_app"
                        :href="setupUrl(n)"
                        class="rounded text-xs leading-4 font-medium text-primary-text underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                        :aria-label="`Set up ${n.label}`"
                        :data-testid="`connect-channel-setup-${n.value}`"
                        >Set up</Link
                    >
                    <span
                        v-else
                        class="text-xs leading-4 text-muted-foreground"
                        :data-testid="`connect-channel-soon-${n.value}`"
                        >Coming soon</span
                    >
                </span>
            </div>
        </li>
    </ul>
</template>
