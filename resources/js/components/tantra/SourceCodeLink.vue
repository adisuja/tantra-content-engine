<script setup lang="ts">
import { IconBrandGithub } from '@tabler/icons-vue';

import { useTantraBrand } from '@/composables/tantra/useTantraBrand';

const { brand, isTantraBranded } = useTantraBrand();
</script>

<template>
    <a
        v-if="isTantraBranded && brand"
        :href="brand.sourceUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 transition-control hover:text-foreground hover:underline"
        data-testid="tantra-source-code-link"
    >
        <IconBrandGithub class="size-4" aria-hidden="true" />
        {{ $t('tantra.source_code') }}
    </a>
</template>
