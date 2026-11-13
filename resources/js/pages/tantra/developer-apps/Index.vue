<script setup lang="ts">
// tantra: Settings > Admin > Developer apps, instance owner only
// (app/Tantra/Http/Controllers/Web/DeveloperAppsController.php). Everything
// developer related that used to sit on the Connectors tab lives here now.
import { Head } from '@inertiajs/vue3';

import ChannelAppsSection, {
    type ChannelAppEntry,
} from '@/components/tantra/ChannelAppsSection.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

defineProps<{
    channelApps: ChannelAppEntry[];
    linkedAccounts: { total: number; connected: number };
}>();
</script>

<template>
    <Head title="Developer apps" />
    <SettingsLayout
        title="Developer apps"
        description="The keys behind each network's logo. Only you see this page."
    >
        <div class="flex flex-col gap-8" data-testid="developer-apps-page">
            <ChannelAppsSection :apps="channelApps" :can-manage="true" />

            <section
                class="flex flex-col gap-1 rounded-lg border bg-card p-4"
                data-testid="developer-apps-linked"
            >
                <h2 class="text-base font-emphasis text-foreground">
                    Tantra app accounts
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ linkedAccounts.total }} teammate LinkedIn accounts come
                    from the Tantra app through Unipile,
                    {{ linkedAccounts.connected }} connected.
                </p>
            </section>
        </div>
    </SettingsLayout>
</template>
