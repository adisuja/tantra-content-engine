<script setup lang="ts">
// tantra: a small, still phone for an idea card (PRD 25.8): the same screens
// as Review (platforms.ts), drawn at a fixed small scale. Clicks go through to
// the card; the full size preview opens from the card's Preview button.
import { computed } from 'vue';

import '@/components/tantra/review/css/studio.css';

import { renderPreview } from '@/components/tantra/review/platforms';
import type { ReviewItemRow } from '@/components/tantra/review/types';

const props = withDefaults(
    defineProps<{
        item: ReviewItemRow;
        viewer: { name?: string; avatar?: string | null };
        scale?: number;
    }>(),
    { scale: 0.34 },
);

const html = computed(() => renderPreview(props.item, props.viewer));
</script>

<template>
    <div
        class="tantra-studio tantra-idea-phone"
        :style="{ '--s': scale.toFixed(3) }"
        aria-hidden="true"
    >
        <!-- eslint-disable-next-line vue/no-v-html -- every value is escaped in studioRender.ts -->
        <div style="display: flex; justify-content: center" v-html="html" />
    </div>
</template>

<style scoped>
.tantra-idea-phone {
    background: transparent;
    pointer-events: none;
    user-select: none;
}
</style>
