<script setup lang="ts">
// tantra: shown instead of the OAuth redirect when a network's developer app
// keys are not in yet (PRD prd-engine-all-channels.md, D1).
import { router, usePage } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import type { AvailablePlatform } from '@/types/social-account';

const props = defineProps<{
    platform: AvailablePlatform;
}>();

const emit = defineEmits<{
    close: [];
}>();

const page = usePage();
const canSetUp = computed(
    () =>
        (page.props.tantraChannels as { canSetUp?: boolean } | undefined)
            ?.canSetUp === true,
);

const setup = computed(() => props.platform.setup ?? null);

const addKeys = (): void => {
    emit('close');
    // preserveState false: the Channels section remounts and opens the card
    // even when the Connectors page is already open.
    router.visit(
        `/settings/workspace/connectors?channel=${setup.value?.app ?? ''}#channels`,
        { preserveState: false },
    );
};
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[720px] flex-col gap-8 px-4 pt-2 pb-8 sm:px-6"
            >
                <header
                    class="flex flex-col items-center gap-3 text-center max-sm:px-10"
                >
                    <PlatformLogo :platform="platform.value" size="md" />
                    <DialogTitle
                        class="font-sans text-xl leading-tight font-medium text-foreground"
                    >
                        {{ platform.label }} is not set up yet
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        It connects once the {{ setup?.app_label }} developer
                        app keys are added.
                    </DialogDescription>
                </header>

                <section
                    v-if="setup"
                    class="mx-auto flex w-full max-w-[500px] flex-col gap-4 rounded-xl border border-border bg-card p-6"
                    data-testid="channel-setup"
                >
                    <div class="flex flex-col gap-1">
                        <p class="text-sm font-medium text-foreground">
                            Missing
                        </p>
                        <ul
                            class="list-disc ps-5 text-sm text-muted-foreground"
                        >
                            <li v-for="m in setup.missing" :key="m">
                                {{ m }}
                            </li>
                        </ul>
                    </div>
                    <p
                        v-if="setup.catch"
                        class="border-t border-border pt-4 text-xs text-muted-foreground"
                    >
                        {{ setup.catch }}
                    </p>
                    <a
                        :href="setup.portal"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-2"
                        >Developer portal <IconExternalLink class="size-3"
                    /></a>
                </section>
            </div>
        </div>

        <footer
            class="flex shrink-0 items-center justify-end gap-3 border-t border-border px-4 py-4 sm:px-6"
        >
            <Button
                v-if="canSetUp"
                size="lg"
                data-testid="channel-setup-add-keys"
                @click="addKeys"
            >
                Add the keys
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                Ask Adi to add the keys in Settings, Connectors, Channels.
            </p>
        </footer>
    </div>
</template>
