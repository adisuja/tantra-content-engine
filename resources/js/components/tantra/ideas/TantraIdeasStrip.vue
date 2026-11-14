<script setup lang="ts">
// tantra: the strip at the top of the Ideas board (PRD 25.8): how many Tantra
// ideas are ready per network, with Connect for a network with no channel.
import { IconPlugConnected } from '@tabler/icons-vue';
import { computed } from 'vue';

import { useTantraIdeas } from '@/components/tantra/ideas/tantraIdeas';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';

const context = useTantraIdeas();
const ready = computed(() => context?.board.value?.ready ?? []);

const { open: openConnectDialog } = useConnectChannelDialog();
</script>

<template>
    <div
        v-if="ready.length"
        class="flex flex-wrap items-center gap-2 px-4 pt-3 md:px-8"
        data-testid="tantra-ideas-strip"
    >
        <div
            v-for="entry in ready"
            :key="entry.network"
            class="flex items-center gap-2 rounded-full border bg-card py-1 ps-3 pe-1 text-sm"
            :data-testid="`tantra-ideas-ready-${entry.network}`"
        >
            <span>
                {{
                    $t(
                        entry.count === 1
                            ? 'tantra.ideas.ready_one'
                            : 'tantra.ideas.ready_many',
                        { count: String(entry.count), network: entry.label },
                    )
                }}
            </span>
            <Button
                v-if="!entry.connected"
                variant="outline"
                size="sm"
                class="rounded-full"
                @click="openConnectDialog()"
            >
                <IconPlugConnected class="size-3.5" />
                {{ $t('tantra.ideas.connect', { network: entry.label }) }}
            </Button>
        </div>
    </div>
</template>
