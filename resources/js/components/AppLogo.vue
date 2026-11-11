<script setup lang="ts">
import { useTantraBrand } from '@/composables/tantra/useTantraBrand';

withDefaults(
    defineProps<{
        /** `full` is the mark and the "trypost.it" wordmark, `mark` the round mark alone. */
        variant?: 'full' | 'mark';
    }>(),
    { variant: 'full' },
);

// tantra: the Tantra mark and wordmark replace TryPost's when branding is on.
const { brand, isTantraBranded } = useTantraBrand();
</script>

<template>
    <span
        v-if="isTantraBranded"
        class="inline-flex shrink-0 items-center gap-[0.35em] leading-none"
        role="img"
        :aria-label="brand?.name"
        data-testid="app-logo"
    >
        <img
            src="/tantra/mark-ink.png"
            alt=""
            class="h-[1.3em] w-auto shrink-0 dark:hidden"
        />
        <img
            src="/tantra/mark-white.png"
            alt=""
            class="hidden h-[1.3em] w-auto shrink-0 dark:block"
        />
        <template v-if="variant === 'full'">
            <img
                src="/tantra/word-ink.png"
                alt=""
                class="h-[0.72em] w-auto dark:hidden"
            />
            <img
                src="/tantra/word-white.png"
                alt=""
                class="hidden h-[0.72em] w-auto dark:block"
            />
        </template>
    </span>
    <span
        v-else
        class="inline-flex shrink-0 items-center gap-[0.32em] font-logo leading-none font-bold tracking-[-0.01em] text-foreground"
        role="img"
        aria-label="TryPost"
        data-testid="app-logo"
    >
        <svg
            viewBox="0 0 410 410"
            class="size-[1.15em] shrink-0 [clip-path:circle(50%)]"
            aria-hidden="true"
        >
            <rect
                width="410"
                height="410"
                class="fill-white dark:fill-[#040000]"
            />
            <path d="M410 109.736V410H128.863L410 109.736Z" fill="#3660E0" />
            <path
                d="M410 0H281.138L205 81.3178L410 300.264V0Z"
                fill="#855ED4"
            />
            <path d="M0 0V300.264L205 81.3178L281.138 0H0Z" fill="#FFCC33" />
            <path
                d="M0 109.736V410H128.862L205 328.682L0 109.736Z"
                fill="#F39200"
            />
        </svg>
        <span v-if="variant === 'full'" aria-hidden="true">trypost.it</span>
    </span>
</template>
