<script setup lang="ts">
// tantra: the review chip on a post card (PRD 10.3): who approved it and
// whether it moved from its planned slot. Links back to the review screen.
import { IconCircleCheck, IconClockShare } from '@tabler/icons-vue';

import type { TantraReviewChip } from '@/types/publish';

defineProps<{ review: TantraReviewChip }>();
</script>

<template>
    <a
        :href="`/tantra/review/${review.batch_id}#${review.engine_post_id}`"
        class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-border bg-muted px-2 py-0.5 text-xs text-muted-foreground hover:text-foreground"
        data-testid="tantra-review-chip"
        @click.stop
    >
        <IconCircleCheck
            v-if="review.approved_by"
            class="size-3.5 shrink-0 text-emerald-600"
        />
        <span class="truncate">
            {{
                review.approved_by
                    ? $t('tantra.review.chip_approved', {
                          name: review.approved_by,
                      })
                    : $t(`tantra.review.status.${review.status}`)
            }}
        </span>
        <template v-if="review.slot_fallback">
            <IconClockShare class="size-3.5 shrink-0 text-amber-600" />
            <span class="shrink-0">{{ $t('tantra.review.chip_moved') }}</span>
        </template>
    </a>
</template>
