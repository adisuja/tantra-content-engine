<script setup lang="ts">
// tantra: one post in the ported studio phone (LinkedIn or X for iOS), with
// the studio's carousel arrows, page counter, "...more" toggle and lightbox.
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

// One entry file keeps the studio's CSS in cascade order.
import '@/components/tantra/review/css/studio.css';

import { renderPhone } from '@/components/tantra/review/studioRender';
import type { ReviewItemRow } from '@/components/tantra/review/types';

const props = withDefaults(
    defineProps<{
        item: ReviewItemRow;
        viewer: { name?: string; avatar?: string | null };
        maxScale?: number;
        fitHeight?: number | null;
    }>(),
    { maxScale: 0.85, fitHeight: null },
);

const PHONE_WIDTH = 417;
const PHONE_HEIGHT = 876;

const root = ref<HTMLElement | null>(null);
const width = ref(PHONE_WIDTH);
const html = computed(() => renderPhone(props.item, props.viewer));

const scale = computed(() => {
    let s = Math.min(props.maxScale, width.value / PHONE_WIDTH);
    if (props.fitHeight) {
        s = Math.min(s, props.fitHeight / PHONE_HEIGHT);
    }

    return Math.max(0.4, s).toFixed(3);
});

interface Slide {
    src: string;
    caption: string;
}

const lightbox = ref<{ items: Slide[]; index: number; zoom: boolean } | null>(
    null,
);
const current = computed(() =>
    lightbox.value ? lightbox.value.items[lightbox.value.index] : null,
);

const openLightbox = (items: Slide[], index: number): void => {
    if (items.length) {
        lightbox.value = { items, index: Math.max(0, index), zoom: false };
    }
};
const step = (delta: number): void => {
    if (!lightbox.value) return;
    const n = lightbox.value.items.length;
    lightbox.value.index = (lightbox.value.index + delta + n) % n;
    lightbox.value.zoom = false;
};
const closeLightbox = (): void => {
    lightbox.value = null;
};

const slideOf = (el: Element): Slide => ({
    src: (el as HTMLElement).dataset.lb ?? '',
    caption:
        (el as HTMLElement).dataset.lbCaption ?? el.getAttribute('alt') ?? '',
});

const onClick = (event: MouseEvent): void => {
    const target = event.target as HTMLElement;

    const more = target.closest('.li-more');
    if (more) {
        more.closest('.li-text')?.classList.remove('clamp');
        more.remove();

        return;
    }

    const arrow = target.closest<HTMLElement>('.li-arr');
    if (arrow) {
        const slides = arrow.parentElement?.querySelector('.li-slides');
        slides?.scrollBy({
            left: Number(arrow.dataset.dir) * slides.clientWidth,
            behavior: 'smooth',
        });

        return;
    }

    const expand = target.closest('.li-exp');
    if (expand) {
        const slides = expand.parentElement?.querySelector('.li-slides');
        if (slides) {
            const imgs = [...slides.querySelectorAll('img')];
            openLightbox(
                imgs.map(slideOf),
                Math.round(slides.scrollLeft / slides.clientWidth),
            );
        }

        return;
    }

    const zoomable = target.closest<HTMLElement>('[data-lb]');
    if (zoomable && root.value) {
        event.preventDefault();
        const group = zoomable.dataset.lbGroup;
        const els = group
            ? [
                  ...root.value.querySelectorAll(
                      `[data-lb][data-lb-group="${CSS.escape(group)}"]`,
                  ),
              ]
            : [zoomable];
        openLightbox(els.map(slideOf), els.indexOf(zoomable));
    }
};

/* Swiping a document carousel keeps its page counter in sync. */
const bindSlides = (): void => {
    root.value?.querySelectorAll<HTMLElement>('.li-slides').forEach((s) => {
        s.onscroll = () => {
            const counter = s.parentElement?.querySelector('.li-pg');
            if (counter) {
                counter.textContent = `${Math.round(s.scrollLeft / s.clientWidth) + 1} / ${s.dataset.pages}`;
            }
        };
    });
};

const onKey = (event: KeyboardEvent): void => {
    if (!lightbox.value) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowLeft') step(-1);
    if (event.key === 'ArrowRight') step(1);
};

let observer: ResizeObserver | null = null;

onMounted(() => {
    if (root.value) {
        observer = new ResizeObserver(([entry]) => {
            width.value = entry.contentRect.width || PHONE_WIDTH;
        });
        observer.observe(root.value);
    }
    window.addEventListener('keydown', onKey);
    bindSlides();
});

watch(html, () => nextTick(bindSlides));

onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div
        ref="root"
        class="tantra-studio tantra-phone"
        :style="{ '--s': scale }"
        data-testid="tantra-phone"
        @click="onClick"
    >
        <!-- eslint-disable-next-line vue/no-v-html -- every value is escaped in studioRender.ts -->
        <div style="display: flex; justify-content: center" v-html="html" />

        <div
            v-if="lightbox && current"
            class="lb open"
            :class="{ zoom: lightbox.zoom }"
            @click.self.stop="closeLightbox"
        >
            <button
                class="lb-x"
                title="Close (Esc)"
                @click.stop="closeLightbox"
            >
                ×
            </button>
            <button
                v-if="lightbox.items.length > 1"
                class="lb-nav lb-prev"
                title="Previous"
                @click.stop="step(-1)"
            >
                ‹
            </button>
            <div class="lb-stage" @click.self.stop="closeLightbox">
                <img
                    :src="current.src"
                    alt=""
                    @click.stop="lightbox.zoom = !lightbox.zoom"
                />
            </div>
            <button
                v-if="lightbox.items.length > 1"
                class="lb-nav lb-next"
                title="Next"
                @click.stop="step(1)"
            >
                ›
            </button>
            <div class="lb-cap">
                {{ current.caption
                }}{{
                    lightbox.items.length > 1
                        ? `  ·  ${lightbox.index + 1} / ${lightbox.items.length}`
                        : ''
                }}
            </div>
        </div>
    </div>
</template>

<style scoped>
/* The studio page background stays on the studio, not around each phone. */
.tantra-phone {
    background: transparent;
}
</style>
