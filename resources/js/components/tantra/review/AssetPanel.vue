<script setup lang="ts">
// tantra: the middle column of a review card. The asset this post gives away (its
// resource) or, without one, the post's own slides or image: every page in a
// scroll box, the public preview link (/previewlink/...) to open or copy, the
// full page and the PDF.
import {
    IconCopy,
    IconDownload,
    IconExternalLink,
    IconEye,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{ item: ReviewItemRow }>();

const hasResource = computed(() => Boolean(props.item.resource?.name));
const pages = computed<string[]>(() =>
    hasResource.value && props.item.resource_pages.length
        ? props.item.resource_pages
        : props.item.media.files,
);
const title = computed(
    () =>
        (hasResource.value
            ? props.item.resource?.name
            : props.item.media.title) ?? '',
);
const files = computed(() =>
    (props.item.resource?.files ?? []).filter(
        (f) => f.href && f.href.startsWith('https://'),
    ),
);
const isVideo = computed(
    () => !hasResource.value && Boolean(props.item.media.video),
);
</script>

<template>
    <section
        class="flex w-full flex-col gap-3 rounded-2xl border border-border bg-card p-4"
        data-testid="tantra-asset"
    >
        <div class="flex flex-col gap-1">
            <div
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{
                    hasResource
                        ? $t('tantra.review.asset.resource')
                        : $t('tantra.review.asset.post_media')
                }}
            </div>
            <div v-if="title" class="text-base leading-snug font-semibold">
                {{ title }}
            </div>
            <div
                v-if="hasResource && item.resource?.what"
                class="text-sm text-muted-foreground"
            >
                {{ item.resource.what }}
            </div>
            <div v-if="hasResource && item.resource?.keyword" class="text-sm">
                {{
                    $t('tantra.review.asset.keyword', {
                        keyword: item.resource.keyword,
                    })
                }}
            </div>
        </div>

        <div
            v-if="isVideo && item.media.video"
            class="overflow-hidden rounded-xl border border-border bg-black"
        >
            <video
                :src="item.media.video"
                controls
                playsinline
                class="max-h-[60vh] w-full"
            />
        </div>

        <div
            v-else-if="pages.length"
            class="max-h-[62vh] overflow-y-auto overscroll-contain rounded-xl border border-border bg-muted/40 p-2"
            data-testid="tantra-asset-pages"
        >
            <div class="flex flex-col gap-2">
                <figure
                    v-for="(src, n) in pages"
                    :key="src"
                    class="overflow-hidden rounded-lg border border-border bg-white"
                >
                    <a :href="item.preview_url" target="_blank" rel="noopener">
                        <img
                            :src="src"
                            :alt="
                                trans('tantra.review.asset.page_of', {
                                    n: String(n + 1),
                                    total: String(pages.length),
                                })
                            "
                            :loading="n < 2 ? 'eager' : 'lazy'"
                            class="block h-auto w-full"
                        />
                    </a>
                    <figcaption
                        v-if="pages.length > 1"
                        class="border-t border-border px-2 py-1 text-center text-xs text-muted-foreground"
                    >
                        {{
                            $t('tantra.review.asset.page_of', {
                                n: String(n + 1),
                                total: String(pages.length),
                            })
                        }}
                    </figcaption>
                </figure>
            </div>
        </div>

        <div
            v-else
            class="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            {{ $t('tantra.review.asset.no_pages') }}
        </div>

        <div class="flex flex-wrap gap-2">
            <Button size="sm" as-child>
                <a
                    :href="item.preview_url"
                    target="_blank"
                    rel="noopener"
                    data-testid="tantra-preview-link"
                >
                    <IconEye class="size-4" />
                    {{ $t('tantra.review.asset.open_preview') }}
                </a>
            </Button>
            <Button
                size="sm"
                variant="outline"
                @click="
                    copyToClipboard(
                        item.preview_url,
                        trans('tantra.review.asset.preview_copied'),
                    )
                "
            >
                <IconCopy class="size-4" />
                {{ $t('tantra.review.asset.copy_preview') }}
            </Button>
            <Button
                v-if="hasResource && item.resource?.url"
                size="sm"
                variant="outline"
                as-child
            >
                <a :href="item.resource.url" target="_blank" rel="noopener">
                    <IconExternalLink class="size-4" />
                    {{ $t('tantra.review.asset.open_page') }}
                </a>
            </Button>
            <Button
                v-for="f in files"
                :key="f.href"
                size="sm"
                variant="outline"
                as-child
            >
                <a :href="f.href" target="_blank" rel="noopener">
                    <IconDownload class="size-4" />
                    {{
                        f.label === 'PDF' || !f.label
                            ? $t('tantra.review.asset.download_pdf')
                            : f.label
                    }}
                </a>
            </Button>
        </div>
    </section>
</template>
