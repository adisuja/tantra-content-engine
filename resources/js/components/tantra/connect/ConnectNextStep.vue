<script setup lang="ts">
// tantra: under the reason on the connect confirmation page: what to do next,
// what the network said, a link that helps, and the way to tester access
// while the network's app is in testing (app/Tantra/Channels/ConnectFailures.php).
import { IconExternalLink, IconFlask } from '@tabler/icons-vue';

export interface ConnectHelp {
    network: string;
    next: string;
    detail: string | null;
    link: { label: string; url: string } | null;
    testing_url: string | null;
    owner_url: string | null;
}

defineProps<{
    help: ConnectHelp;
}>();
</script>

<template>
    <div
        class="flex w-full flex-col gap-3 text-sm"
        data-testid="connect-next-step"
    >
        <p
            v-if="help.detail"
            class="rounded-lg bg-muted px-3 py-2 text-xs break-words text-muted-foreground"
            data-testid="connect-provider-detail"
        >
            {{
                $t('tantra.connect.detail', {
                    network: help.network,
                    detail: help.detail,
                })
            }}
        </p>
        <p class="text-foreground" data-testid="connect-next">
            {{ help.next }}
        </p>
        <div
            v-if="help.testing_url"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-3"
            data-testid="connect-testing-hint"
        >
            <p class="flex items-start gap-2 text-muted-foreground">
                <IconFlask class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{
                    $t('tantra.connect.testing_hint', { network: help.network })
                }}
            </p>
            <a
                :href="help.testing_url"
                class="font-medium underline underline-offset-2"
                data-testid="connect-testing-link"
                >{{ $t('tantra.connect.testing_link') }}</a
            >
        </div>
        <div class="flex flex-wrap justify-center gap-x-4 gap-y-1">
            <a
                v-if="help.link"
                :href="help.link.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 underline underline-offset-2"
                data-testid="connect-help-link"
                >{{ help.link.label }} <IconExternalLink class="size-3.5"
            /></a>
            <a
                v-if="help.owner_url"
                :href="help.owner_url"
                class="underline underline-offset-2"
                data-testid="connect-owner-link"
                >{{ $t('tantra.connect.owner_link') }}</a
            >
        </div>
    </div>
</template>
