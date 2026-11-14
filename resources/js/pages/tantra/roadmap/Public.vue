<script setup lang="ts">
// tantra: the public roadmap page at /roadmap (RoadmapPageController). No
// login, read only. The board and the form are the portable components in
// components/tantra/roadmap; this page only frames them.
import { ref } from 'vue';

import RoadmapRequestForm from '@/components/tantra/roadmap/RoadmapRequestForm.vue';
import TantraRoadmap from '@/components/tantra/roadmap/TantraRoadmap.vue';
import type {
    RoadmapItem,
    RoadmapPayload,
} from '@/components/tantra/roadmap/types';

defineProps<{
    roadmap: RoadmapPayload;
    endpoints: { json: string; requests: string; feed: string; llms: string };
}>();

const board = ref<InstanceType<typeof TantraRoadmap> | null>(null);
const lastAdded = ref<RoadmapItem | null>(null);

const onSubmitted = (item: RoadmapItem | null): void => {
    if (item) {
        lastAdded.value = item;
        // On the board at once; the thank you screen stays in view.
        board.value?.add(item, false);
    }
};

const show = (id: string): void => {
    if (lastAdded.value && lastAdded.value.id === id)
        board.value?.add(lastAdded.value);
};

const year = new Date().getFullYear();
</script>

<template>
    <div class="rp-page">
        <header class="rp-bar">
            <div class="rp-wrap rp-bar-inner">
                <a
                    href="https://usetantra.com"
                    class="rp-logo"
                    aria-label="Tantra"
                >
                    <img
                        src="/tantra/mark-ink.png"
                        alt=""
                        width="36"
                        height="24"
                    />
                    <img
                        src="/tantra/word-ink.png"
                        alt=""
                        width="77"
                        height="14"
                    />
                </a>
                <span class="rp-crumb">Roadmap</span>
                <a href="#request" class="rp-cta">Ask for a feature</a>
            </div>
        </header>

        <main>
            <section class="rp-wrap rp-hero">
                <p class="rp-eyebrow">Content Engine</p>
                <h1>What we are building next</h1>
                <p class="rp-lede">
                    Everything we are working on, in the open. Ask for what you
                    need and watch it move from Q to Shipped.
                </p>
            </section>

            <section class="rp-wrap rp-board">
                <TantraRoadmap ref="board" :data="roadmap" />
            </section>

            <section id="request" class="rp-wrap rp-request">
                <div class="rp-request-copy">
                    <h2>Ask for a feature</h2>
                    <p>
                        Tell us what would save you time. It goes straight into
                        Q: Requests, where the whole team sees it.
                    </p>
                    <ul>
                        <li>Five questions, about a minute.</li>
                        <li>Only your first name shows on the board.</li>
                        <li>We read every request.</li>
                    </ul>
                </div>
                <RoadmapRequestForm
                    class="rp-form"
                    :endpoint="endpoints.requests"
                    :products="roadmap.products"
                    @submitted="onSubmitted"
                    @show="show"
                />
            </section>

            <section
                class="rp-wrap rp-agents"
                aria-label="For agents and tools"
            >
                <p>
                    For agents and tools:
                    <a :href="endpoints.json">JSON API</a>
                    <a :href="endpoints.feed">Atom feed</a>
                    <a :href="endpoints.llms">llms.txt</a>
                    <a :href="endpoints.llms">MCP tools</a>
                </p>
            </section>
        </main>

        <footer class="rp-wrap rp-footer">
            <span>&copy; {{ year }} Tantra</span>
            <a href="https://usetantra.com">usetantra.com</a>
        </footer>
    </div>
</template>

<style scoped>
.rp-page {
    min-height: 100vh;
    background: #f7f8fb;
    color: #0b0d12;
    font-family:
        'Inter',
        ui-sans-serif,
        system-ui,
        -apple-system,
        sans-serif;
    -webkit-font-smoothing: antialiased;
}

.rp-wrap {
    width: 100%;
    max-width: 1360px;
    margin: 0 auto;
    padding: 0 20px;
    box-sizing: border-box;
}

.rp-bar {
    position: sticky;
    top: 0;
    z-index: 10;
    background: rgba(247, 248, 251, 0.82);
    backdrop-filter: saturate(1.4) blur(12px);
    -webkit-backdrop-filter: saturate(1.4) blur(12px);
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
}

.rp-bar-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    height: 58px;
}

.rp-logo {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.rp-logo img:first-child {
    height: 24px;
    width: auto;
}

.rp-logo img:last-child {
    height: 14px;
    width: auto;
}

.rp-crumb {
    padding-left: 12px;
    border-left: 1px solid #e2e8f0;
    font-size: 14px;
    font-weight: 500;
    color: #5b6472;
}

.rp-cta {
    margin-left: auto;
    padding: 8px 14px;
    border-radius: 9px;
    background: #0b0d12;
    color: #ffffff;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.15s;
}

.rp-cta:hover {
    background: #1f2430;
}

.rp-hero {
    padding-top: 56px;
    padding-bottom: 28px;
}

.rp-eyebrow {
    display: inline-flex;
    margin: 0 0 16px;
    padding: 4px 10px;
    border-radius: 999px;
    background: #f1efff;
    color: #4b2fd6;
    font-size: 12.5px;
    font-weight: 600;
}

.rp-hero h1 {
    margin: 0;
    max-width: 16ch;
    font-size: clamp(32px, 5.2vw, 52px);
    font-weight: 700;
    line-height: 1.05;
    letter-spacing: -0.035em;
}

.rp-lede {
    margin: 16px 0 0;
    max-width: 52ch;
    font-size: clamp(16px, 1.6vw, 18px);
    line-height: 1.55;
    color: #5b6472;
}

.rp-board {
    padding-bottom: 72px;
}

.rp-request {
    display: grid;
    gap: 28px;
    padding-top: 64px;
    padding-bottom: 72px;
    border-top: 1px solid #e2e8f0;
    scroll-margin-top: 72px;
}

.rp-request-copy h2 {
    margin: 0;
    font-size: clamp(26px, 3.4vw, 36px);
    font-weight: 700;
    letter-spacing: -0.028em;
    line-height: 1.1;
}

.rp-request-copy p {
    margin: 14px 0 0;
    max-width: 40ch;
    font-size: 16px;
    line-height: 1.55;
    color: #5b6472;
}

.rp-request-copy ul {
    margin: 22px 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 10px;
    font-size: 14.5px;
}

.rp-request-copy li {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rp-request-copy li::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #6d4aff;
    flex: none;
}

.rp-agents {
    padding-bottom: 40px;
}

.rp-agents p {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 16px;
    margin: 0;
    padding: 16px 18px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    font-size: 13.5px;
    color: #5b6472;
}

.rp-agents a {
    color: #4b2fd6;
    font-weight: 500;
    text-decoration: none;
}

.rp-agents a:hover {
    text-decoration: underline;
    text-underline-offset: 3px;
}

.rp-footer {
    display: flex;
    justify-content: space-between;
    padding-top: 20px;
    padding-bottom: 32px;
    font-size: 13px;
    color: #5b6472;
}

.rp-footer a {
    color: inherit;
    text-decoration: none;
}

@media (min-width: 960px) {
    .rp-wrap {
        padding: 0 32px;
    }

    .rp-hero {
        padding-top: 80px;
        padding-bottom: 36px;
    }

    .rp-request {
        grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
        align-items: center;
        gap: 56px;
        padding-top: 88px;
        padding-bottom: 96px;
    }
}

@media (max-width: 480px) {
    .rp-crumb {
        display: none;
    }
}
</style>
