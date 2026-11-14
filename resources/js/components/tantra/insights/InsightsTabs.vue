<script setup lang="ts">
// tantra: the two halves of Insights: traffic and leads (the CRM), and posts and channels (the networks).
import { Link } from '@inertiajs/vue3';

defineProps<{ active: 'traffic' | 'posts' }>();
</script>

<template>
    <nav class="flex gap-1" data-testid="insights-tabs">
        <Link
            href="/tantra/insights"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="
                active === 'traffic'
                    ? 'bg-secondary text-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            >{{ $t('tantra.traffic.tab_traffic') }}</Link
        >
        <Link
            href="/insights"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="
                active === 'posts'
                    ? 'bg-secondary text-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            >{{ $t('tantra.traffic.tab_posts') }}</Link
        >
    </nav>
</template>
