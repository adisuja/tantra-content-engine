<script setup lang="ts">
// tantra: the public roadmap board. Self contained (Vue only, scoped CSS, no
// app imports) so usetantra.com can drop it in: pass `data`, or a `source`
// URL that answers like GET /api/roadmap. Read only by design.
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import type {
    RoadmapColumnKey,
    RoadmapItem,
    RoadmapPayload,
} from '@/components/tantra/roadmap/types';

const props = withDefaults(
    defineProps<{
        data?: RoadmapPayload | null;
        source?: string | null;
        /** A card to bring into view and mark, e.g. a request just sent. */
        highlight?: string | null;
    }>(),
    { data: null, source: null, highlight: null },
);

const payload = ref<RoadmapPayload | null>(props.data);
const failed = ref(false);
const product = ref<string>('all');
const category = ref<string>('all');
const open = ref<Set<string>>(new Set());
const board = ref<HTMLElement | null>(null);
const tabs = ref<HTMLElement | null>(null);
const activeColumn = ref(0);

watch(
    () => props.data,
    (value) => {
        if (value) payload.value = value;
    },
);

onMounted(async () => {
    if (!payload.value && props.source) {
        try {
            const response = await fetch(props.source, {
                headers: { Accept: 'application/json' },
            });
            payload.value = (await response.json()) as RoadmapPayload;
        } catch {
            failed.value = true;
        }
    }
    if (props.highlight) void reveal(props.highlight);
});

const products = computed(() => {
    const present = new Set(payload.value?.items.map((i) => i.product) ?? []);

    return (payload.value?.products ?? []).filter((p) => present.has(p.key));
});

const categories = computed(() => {
    const items = (payload.value?.items ?? []).filter(
        (i) => product.value === 'all' || i.product === product.value,
    );

    return [
        ...new Set(items.map((i) => i.category).filter(Boolean)),
    ].sort() as string[];
});

watch(categories, (list) => {
    if (category.value !== 'all' && !list.includes(category.value)) {
        category.value = 'all';
    }
});

const visible = computed(() =>
    (payload.value?.items ?? []).filter(
        (i) =>
            (product.value === 'all' || i.product === product.value) &&
            (category.value === 'all' || i.category === category.value),
    ),
);

const columns = computed(() =>
    (payload.value?.columns ?? []).map((column) => ({
        ...column,
        items: visible.value
            .filter((i) => i.column === column.key)
            .sort((a, b) =>
                column.key === 'shipped'
                    ? (b.shipped_at ?? '').localeCompare(a.shipped_at ?? '')
                    : a.position - b.position,
            ),
    })),
);

const total = computed(() => visible.value.length);
const shipped = computed(
    () => visible.value.filter((i) => i.column === 'shipped').length,
);

const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});
const date = (value: string | null): string =>
    value ? dateFormat.format(new Date(value)) : '';

const toggle = (id: string): void => {
    const next = new Set(open.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    open.value = next;
};

const scrollToColumn = (index: number): void => {
    const el = board.value?.children[index] as HTMLElement | undefined;
    if (!el || !board.value) return;
    board.value.scrollTo({
        left: el.offsetLeft - board.value.offsetLeft,
        behavior: 'smooth',
    });
};

const onBoardScroll = (): void => {
    const el = board.value;
    if (!el || el.children.length === 0) return;
    const width = (el.children[0] as HTMLElement).offsetWidth || 1;
    activeColumn.value = Math.min(
        el.children.length - 1,
        Math.round(el.scrollLeft / width),
    );
};

// Keep the column tab of what is on screen visible in the tab strip.
watch(activeColumn, (index) => {
    const tab = tabs.value?.children[index] as HTMLElement | undefined;
    if (!tab || !tabs.value) return;
    // Scroll the strip only, never the page.
    tabs.value.scrollTo({
        left: tab.offsetLeft - tabs.value.offsetLeft - 20,
        behavior: 'smooth',
    });
});

/** Adds a card (a request just sent) without a reload; shows it unless told not to. */
const add = (item: RoadmapItem, show = true): void => {
    if (!payload.value) return;
    if (!payload.value.items.some((i) => i.id === item.id)) {
        payload.value.items.push(item);
        const column = payload.value.columns.find((c) => c.key === item.column);
        if (column) column.count += 1;
    }
    product.value = 'all';
    category.value = 'all';
    if (show) void reveal(item.id);
};

const flash = ref<string | null>(null);
const reveal = async (id: string): Promise<void> => {
    await nextTick();
    const card = document.getElementById(`item-${id}`);
    if (!card) return;
    const columnIndex = columns.value.findIndex((c) =>
        c.items.some((i) => i.id === id),
    );
    if (columnIndex >= 0) scrollToColumn(columnIndex);
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    flash.value = id;
    window.setTimeout(() => {
        if (flash.value === id) flash.value = null;
    }, 2400);
};

defineExpose({ add });

const dot: Record<RoadmapColumnKey, string> = {
    q: '#5b6472',
    planned: '#a78bfa',
    in_progress: '#6d4aff',
    beta: '#f59e0b',
    shipped: '#047857',
};
</script>

<template>
    <section class="tr-root" aria-label="Product roadmap">
        <div class="tr-toolbar">
            <div
                v-if="products.length > 1"
                class="tr-segment"
                role="group"
                aria-label="Product"
            >
                <button
                    type="button"
                    :aria-pressed="product === 'all'"
                    @click="product = 'all'"
                >
                    All products
                </button>
                <button
                    v-for="p in products"
                    :key="p.key"
                    type="button"
                    :aria-pressed="product === p.key"
                    @click="product = p.key"
                >
                    {{ p.label }}
                </button>
            </div>
            <div class="tr-chips" role="group" aria-label="Category">
                <button
                    type="button"
                    class="tr-chip"
                    :aria-pressed="category === 'all'"
                    @click="category = 'all'"
                >
                    All
                </button>
                <button
                    v-for="c in categories"
                    :key="c"
                    type="button"
                    class="tr-chip"
                    :aria-pressed="category === c"
                    @click="category = category === c ? 'all' : c"
                >
                    {{ c }}
                </button>
            </div>
            <p v-if="payload" class="tr-tally">
                {{ total }} {{ total === 1 ? 'card' : 'cards' }},
                {{ shipped }} shipped
            </p>
        </div>

        <p v-if="failed" class="tr-empty">
            The roadmap did not load. Refresh to try again.
        </p>

        <template v-else-if="payload">
            <nav ref="tabs" class="tr-tabs" aria-label="Columns">
                <button
                    v-for="(column, index) in columns"
                    :key="column.key"
                    type="button"
                    :aria-current="activeColumn === index ? 'true' : undefined"
                    @click="scrollToColumn(index)"
                >
                    <span
                        class="tr-dot"
                        :style="{ background: dot[column.key] }"
                    />
                    {{ column.label }}
                    <span class="tr-count">{{ column.items.length }}</span>
                </button>
            </nav>

            <div ref="board" class="tr-board" @scroll.passive="onBoardScroll">
                <section
                    v-for="column in columns"
                    :key="column.key"
                    class="tr-column"
                    :aria-labelledby="`col-${column.key}`"
                >
                    <header class="tr-column-head">
                        <h2 :id="`col-${column.key}`">
                            <span
                                class="tr-dot"
                                :style="{ background: dot[column.key] }"
                            />
                            {{ column.label }}
                            <span class="tr-count">{{
                                column.items.length
                            }}</span>
                        </h2>
                        <p>{{ column.blurb }}</p>
                    </header>

                    <ol class="tr-cards">
                        <li
                            v-for="item in column.items"
                            :id="`item-${item.id}`"
                            :key="item.id"
                            class="tr-card"
                            :class="{
                                'is-open': open.has(item.id),
                                'is-flash': flash === item.id,
                            }"
                        >
                            <button
                                type="button"
                                class="tr-card-button"
                                :aria-expanded="open.has(item.id)"
                                :aria-controls="`detail-${item.id}`"
                                @click="toggle(item.id)"
                            >
                                <span class="tr-card-meta">
                                    <span v-if="item.category" class="tr-tag">{{
                                        item.category
                                    }}</span>
                                    <span
                                        v-if="item.source === 'request'"
                                        class="tr-tag is-request"
                                        >Request</span
                                    >
                                    <span
                                        v-if="products.length > 1"
                                        class="tr-product"
                                        >{{ item.product_label }}</span
                                    >
                                </span>
                                <span class="tr-card-title">{{
                                    item.title
                                }}</span>
                                <span
                                    v-if="item.summary"
                                    class="tr-card-summary"
                                    >{{ item.summary }}</span
                                >
                                <svg
                                    class="tr-chevron"
                                    viewBox="0 0 16 16"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M4 6l4 4 4-4"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>
                            </button>
                            <div
                                v-show="open.has(item.id)"
                                :id="`detail-${item.id}`"
                                class="tr-card-detail"
                            >
                                <p v-if="item.detail">{{ item.detail }}</p>
                                <dl>
                                    <template v-if="item.requested_by">
                                        <dt>Asked by</dt>
                                        <dd>{{ item.requested_by }}</dd>
                                    </template>
                                    <dt>Added</dt>
                                    <dd>{{ date(item.created_at) }}</dd>
                                    <template v-if="item.shipped_at">
                                        <dt>Shipped</dt>
                                        <dd>{{ date(item.shipped_at) }}</dd>
                                    </template>
                                </dl>
                            </div>
                        </li>
                    </ol>
                    <p v-if="column.items.length === 0" class="tr-column-empty">
                        Nothing here yet.
                    </p>
                </section>
            </div>
        </template>

        <div v-else class="tr-board is-loading" aria-busy="true">
            <div v-for="n in 5" :key="n" class="tr-column">
                <div class="tr-skeleton" />
                <div class="tr-skeleton is-card" />
                <div class="tr-skeleton is-card" />
            </div>
        </div>
    </section>
</template>

<style scoped>
.tr-root {
    --tr-ink: #0b0d12;
    --tr-paper: #f7f8fb;
    --tr-white: #ffffff;
    --tr-slate: #5b6472;
    --tr-line: #e2e8f0;
    --tr-volt: #6d4aff;
    --tr-volt-deep: #4b2fd6;
    --tr-lilac: #f1efff;
    font-family:
        'Inter',
        ui-sans-serif,
        system-ui,
        -apple-system,
        sans-serif;
    color: var(--tr-ink);
    font-feature-settings: 'cv11', 'ss01';
}

.tr-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 16px;
    margin-bottom: 20px;
}

.tr-segment {
    display: inline-flex;
    padding: 3px;
    border: 1px solid var(--tr-line);
    border-radius: 10px;
    background: var(--tr-white);
}

.tr-segment button {
    border: 0;
    background: transparent;
    padding: 6px 12px;
    border-radius: 7px;
    font: inherit;
    font-size: 13px;
    font-weight: 500;
    color: var(--tr-slate);
    cursor: pointer;
    transition:
        background 0.15s,
        color 0.15s;
}

.tr-segment button[aria-pressed='true'] {
    background: var(--tr-ink);
    color: var(--tr-white);
}

.tr-chips {
    display: flex;
    flex-wrap: nowrap;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
    max-width: 100%;
}

.tr-chips::-webkit-scrollbar {
    display: none;
}

.tr-chip {
    flex: none;
    border: 1px solid var(--tr-line);
    background: var(--tr-white);
    color: var(--tr-slate);
    border-radius: 999px;
    padding: 5px 12px;
    font: inherit;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition:
        border-color 0.15s,
        color 0.15s,
        background 0.15s;
}

.tr-chip:hover {
    border-color: #cbd5e1;
    color: var(--tr-ink);
}

.tr-chip[aria-pressed='true'] {
    border-color: var(--tr-volt);
    background: var(--tr-lilac);
    color: var(--tr-volt-deep);
}

.tr-tally {
    margin: 0 0 0 auto;
    font-size: 13px;
    color: var(--tr-slate);
    font-variant-numeric: tabular-nums;
}

.tr-tabs {
    display: flex;
    gap: 4px;
    overflow-x: auto;
    scrollbar-width: none;
    margin: 0 -20px 12px;
    padding: 0 20px;
}

.tr-tabs::-webkit-scrollbar {
    display: none;
}

.tr-tabs button {
    flex: none;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 0;
    background: transparent;
    padding: 7px 10px;
    border-radius: 8px;
    font: inherit;
    font-size: 13px;
    font-weight: 500;
    color: var(--tr-slate);
    cursor: pointer;
}

.tr-tabs button[aria-current='true'] {
    background: var(--tr-white);
    color: var(--tr-ink);
    box-shadow: 0 0 0 1px var(--tr-line);
}

.tr-board {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scroll-padding: 0 20px;
    margin: 0 -20px;
    padding: 2px 20px 16px;
    scrollbar-width: thin;
}

.tr-column {
    flex: 0 0 min(84vw, 320px);
    scroll-snap-align: start;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: rgba(226, 232, 240, 0.38);
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 14px;
    padding: 12px;
}

.tr-column-head {
    padding: 2px 4px 12px;
}

.tr-column-head h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: -0.005em;
}

.tr-column-head p {
    margin: 4px 0 0;
    font-size: 12.5px;
    color: var(--tr-slate);
}

.tr-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    flex: none;
}

.tr-count {
    margin-left: auto;
    font-size: 12px;
    font-weight: 500;
    color: var(--tr-slate);
    font-variant-numeric: tabular-nums;
}

.tr-tabs .tr-count {
    margin-left: 0;
}

.tr-cards {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.tr-card {
    background: var(--tr-white);
    border: 1px solid var(--tr-line);
    border-radius: 11px;
    box-shadow: 0 1px 2px rgba(11, 13, 18, 0.04);
    transition:
        border-color 0.15s,
        box-shadow 0.2s;
}

.tr-card:hover {
    border-color: #cbd5e1;
    box-shadow: 0 4px 14px -6px rgba(11, 13, 18, 0.12);
}

.tr-card.is-flash {
    border-color: var(--tr-volt);
    box-shadow: 0 0 0 4px rgba(109, 74, 255, 0.14);
}

.tr-card-button {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    width: 100%;
    border: 0;
    background: transparent;
    padding: 13px 34px 13px 14px;
    text-align: left;
    font: inherit;
    color: inherit;
    cursor: pointer;
    border-radius: 11px;
}

.tr-card-button:focus-visible {
    outline: 2px solid var(--tr-volt);
    outline-offset: 2px;
}

.tr-card-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}

.tr-card-meta:empty {
    display: none;
}

.tr-tag {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 6px;
    background: var(--tr-lilac);
    color: var(--tr-volt-deep);
    font-size: 11.5px;
    font-weight: 500;
    line-height: 18px;
}

.tr-tag.is-request {
    background: var(--tr-paper);
    color: var(--tr-slate);
    box-shadow: inset 0 0 0 1px var(--tr-line);
}

.tr-product {
    font-size: 11.5px;
    color: var(--tr-slate);
}

.tr-card-title {
    font-size: 14.5px;
    font-weight: 600;
    line-height: 1.35;
    letter-spacing: -0.006em;
}

.tr-card-summary {
    font-size: 13px;
    line-height: 1.5;
    color: var(--tr-slate);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.tr-card.is-open .tr-card-summary {
    -webkit-line-clamp: unset;
    display: block;
}

.tr-chevron {
    position: absolute;
    top: 14px;
    right: 12px;
    width: 16px;
    height: 16px;
    color: #94a3b8;
    transition: transform 0.2s ease;
}

.tr-card.is-open .tr-chevron {
    transform: rotate(180deg);
}

.tr-card-detail {
    padding: 0 14px 14px;
    font-size: 13px;
    line-height: 1.55;
    color: var(--tr-ink);
}

.tr-card-detail p {
    margin: 0 0 10px;
    white-space: pre-line;
}

.tr-card-detail dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2px 12px;
    margin: 0;
    padding-top: 10px;
    border-top: 1px solid var(--tr-line);
    font-size: 12px;
}

.tr-card-detail dt {
    color: var(--tr-slate);
}

.tr-card-detail dd {
    margin: 0;
    font-variant-numeric: tabular-nums;
}

.tr-column-empty,
.tr-empty {
    margin: 4px;
    padding: 18px 12px;
    border: 1px dashed #cbd5e1;
    border-radius: 11px;
    text-align: center;
    font-size: 13px;
    color: var(--tr-slate);
}

.tr-skeleton {
    height: 18px;
    width: 60%;
    border-radius: 6px;
    background: linear-gradient(90deg, #eef2f7, #f6f8fb, #eef2f7);
    background-size: 200% 100%;
    animation: tr-shimmer 1.4s infinite;
    margin-bottom: 12px;
}

.tr-skeleton.is-card {
    height: 76px;
    width: 100%;
    border-radius: 11px;
}

@keyframes tr-shimmer {
    to {
        background-position: -200% 0;
    }
}

@media (min-width: 1180px) {
    .tr-tabs {
        display: none;
    }

    .tr-board {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        align-items: stretch;
        overflow: visible;
        margin: 0;
        padding: 0;
    }

    .tr-column {
        flex: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tr-card,
    .tr-chevron,
    .tr-skeleton {
        transition: none;
        animation: none;
    }
}
</style>
