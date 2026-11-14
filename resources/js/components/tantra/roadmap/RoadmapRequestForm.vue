<script setup lang="ts">
// tantra: the feature request form under the roadmap, one question per
// screen. Self contained like TantraRoadmap.vue: it posts JSON to `endpoint`
// (POST /api/roadmap/requests), which needs no session, so it also works
// from usetantra.com. Enter goes on, Shift+Enter is a new line.
import { computed, nextTick, ref, watch } from 'vue';

import type {
    RoadmapItem,
    RoadmapProduct,
} from '@/components/tantra/roadmap/types';

const props = withDefaults(
    defineProps<{
        endpoint: string;
        products?: RoadmapProduct[];
        defaultProduct?: string;
    }>(),
    {
        products: () => [
            { key: 'content-engine', label: 'Content Engine' },
            { key: 'tantra-app', label: 'Tantra app' },
            { key: 'crm', label: 'Tantra CRM' },
        ],
        defaultProduct: 'content-engine',
    },
);

const emit = defineEmits<{
    submitted: [item: RoadmapItem | null];
    show: [id: string];
}>();

type Step = 'title' | 'description' | 'product' | 'name' | 'email';
const steps: Step[] = ['title', 'description', 'product', 'name', 'email'];

const form = ref({
    title: '',
    description: '',
    product: props.defaultProduct,
    name: '',
    email: '',
    website: '',
});
const index = ref(0);
const direction = ref<'next' | 'back'>('next');
const error = ref('');
const sending = ref(false);
const done = ref<RoadmapItem | null | false>(false);
const root = ref<HTMLElement | null>(null);

const step = computed(() => steps[index.value]);
const progress = computed(() =>
    done.value !== false ? 100 : (index.value / steps.length) * 100,
);
const letters = 'ABCDEFGH';

/** Focuses the new question once its screen has finished coming in. */
const focusField = (): void => {
    // The leaving screen is still in the DOM: pick the field of the new one.
    const screen = root.value?.querySelector<HTMLElement>(
        '.rf-screen:not([class*="-leave-"])',
    );
    const target =
        step.value === 'product'
            ? screen?.querySelector<HTMLElement>('[aria-checked="true"]')
            : screen?.querySelector<HTMLElement>('.rf-input');
    target?.focus({ preventScroll: true });
};

// The next screen comes in while the last one leaves, so its field takes
// the keyboard at once and nothing typed during the move is lost.
watch([index, done], () => void nextTick(focusField));

const validate = (): boolean => {
    error.value = '';
    if (step.value === 'title' && form.value.title.trim().length < 3) {
        error.value = 'Tell us in a few words.';
    }
    if (
        step.value === 'email' &&
        form.value.email.trim() !== '' &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email.trim())
    ) {
        error.value = 'That email does not look right.';
    }

    return error.value === '';
};

const next = (): void => {
    if (!validate()) return;
    if (index.value === steps.length - 1) {
        void submit();

        return;
    }
    direction.value = 'next';
    index.value += 1;
};

const back = (): void => {
    if (index.value === 0) return;
    error.value = '';
    direction.value = 'back';
    index.value -= 1;
};

const choose = (key: string): void => {
    form.value.product = key;
    window.setTimeout(next, 180);
};

const onKey = (event: KeyboardEvent): void => {
    if (event.isComposing || done.value !== false) return;
    // Enter on a button presses that button.
    if (
        (event.target as HTMLElement | null)?.tagName === 'BUTTON' &&
        event.key === 'Enter'
    )
        return;
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        next();

        return;
    }
    if (step.value === 'product' && !event.metaKey && !event.ctrlKey) {
        const at = letters.indexOf(event.key.toUpperCase());
        if (at >= 0 && at < props.products.length) {
            event.preventDefault();
            choose(props.products[at].key);
        }
    }
};

const submit = async (): Promise<void> => {
    if (sending.value) return;
    sending.value = true;
    error.value = '';
    try {
        const response = await fetch(props.endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                title: form.value.title,
                description: form.value.description || null,
                product: form.value.product,
                name: form.value.name || null,
                email: form.value.email || null,
                website: form.value.website,
            }),
        });
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            item?: RoadmapItem | null;
            errors?: Record<string, string[]>;
        };
        if (response.ok) {
            done.value = data.item ?? null;
            emit('submitted', data.item ?? null);

            return;
        }
        if (data.errors?.title) {
            direction.value = 'back';
            index.value = 0;
        } else if (data.errors?.email) {
            index.value = steps.indexOf('email');
        }
        error.value =
            (data.errors ? Object.values(data.errors).flat()[0] : undefined) ??
            data.message ??
            'That did not go through. Try again.';
    } catch {
        error.value = 'That did not go through. Check your connection.';
    } finally {
        sending.value = false;
    }
};

const again = (): void => {
    form.value = {
        title: '',
        description: '',
        product: props.defaultProduct,
        name: '',
        email: '',
        website: '',
    };
    done.value = false;
    index.value = 0;
    direction.value = 'back';
};

const firstName = computed(() => form.value.name.trim().split(/\s+/)[0] ?? '');
</script>

<template>
    <div ref="root" class="rf-root" @keydown="onKey">
        <div
            class="rf-progress"
            role="progressbar"
            :aria-valuenow="Math.round(progress)"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="Progress"
        >
            <span :style="{ width: `${progress}%` }" />
        </div>

        <!-- Bots fill every field; people never see this one. -->
        <div class="rf-trap" aria-hidden="true">
            <label>
                Website
                <input
                    v-model="form.website"
                    type="text"
                    name="website"
                    tabindex="-1"
                    autocomplete="off"
                />
            </label>
        </div>

        <div class="rf-stage">
            <Transition :name="`rf-${direction}`" @after-enter="focusField">
                <div v-if="done !== false" key="done" class="rf-screen is-done">
                    <span class="rf-check" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M5 12.5l4.2 4.2L19 7"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </span>
                    <h3 class="rf-question">
                        Thanks{{ firstName ? `, ${firstName}` : '' }}. It is now
                        in Q: Requests.
                    </h3>
                    <p class="rf-help">
                        We read every request. When it moves to Planned, you
                        will see it on the board{{
                            form.email
                                ? ', and we will email you when it ships'
                                : ''
                        }}.
                    </p>
                    <div class="rf-actions">
                        <button
                            v-if="done"
                            type="button"
                            class="rf-primary"
                            @click="emit('show', (done as RoadmapItem).id)"
                        >
                            See it on the board
                        </button>
                        <button type="button" class="rf-ghost" @click="again">
                            Ask for something else
                        </button>
                    </div>
                </div>

                <div v-else :key="step" class="rf-screen">
                    <p class="rf-step">
                        Question {{ index + 1 }} of {{ steps.length }}
                    </p>

                    <template v-if="step === 'title'">
                        <label class="rf-question" for="rf-title"
                            >What should Tantra do for you?</label
                        >
                        <p class="rf-help">
                            One line is plenty. You can add more next.
                        </p>
                        <input
                            id="rf-title"
                            v-model="form.title"
                            class="rf-input"
                            type="text"
                            maxlength="120"
                            placeholder="Post to TikTok from the calendar"
                            autocomplete="off"
                        />
                        <p class="rf-counter">{{ form.title.length }} / 120</p>
                    </template>

                    <template v-else-if="step === 'description'">
                        <label class="rf-question" for="rf-description"
                            >Why does it matter? What do you do today?</label
                        >
                        <p class="rf-help">
                            Optional. The workaround you use now helps us most.
                            Shift and Enter for a new line.
                        </p>
                        <textarea
                            id="rf-description"
                            v-model="form.description"
                            class="rf-input is-area"
                            rows="3"
                            maxlength="2000"
                            placeholder="Today I export the post and upload it by hand..."
                        />
                    </template>

                    <template v-else-if="step === 'product'">
                        <p id="rf-product-q" class="rf-question">
                            Which product is it for?
                        </p>
                        <div
                            class="rf-choices"
                            role="radiogroup"
                            aria-labelledby="rf-product-q"
                        >
                            <button
                                v-for="(p, i) in products"
                                :key="p.key"
                                type="button"
                                role="radio"
                                class="rf-choice"
                                :aria-checked="form.product === p.key"
                                @click="choose(p.key)"
                            >
                                <kbd>{{ letters[i] }}</kbd>
                                {{ p.label }}
                            </button>
                        </div>
                    </template>

                    <template v-else-if="step === 'name'">
                        <label class="rf-question" for="rf-name"
                            >What should we call you?</label
                        >
                        <p class="rf-help">
                            Optional. Only your first name shows on the board.
                        </p>
                        <input
                            id="rf-name"
                            v-model="form.name"
                            class="rf-input"
                            type="text"
                            maxlength="120"
                            placeholder="Your name"
                            autocomplete="name"
                        />
                    </template>

                    <template v-else-if="step === 'email'">
                        <label class="rf-question" for="rf-email"
                            >Where can we tell you when it ships?</label
                        >
                        <p class="rf-help">
                            Optional. We email you only about this request. Your
                            email is never shown.
                        </p>
                        <input
                            id="rf-email"
                            v-model="form.email"
                            class="rf-input"
                            type="email"
                            maxlength="190"
                            placeholder="you@company.com"
                            autocomplete="email"
                            inputmode="email"
                        />
                    </template>

                    <p v-if="error" class="rf-error" role="alert">
                        {{ error }}
                    </p>

                    <div class="rf-actions">
                        <button
                            type="button"
                            class="rf-primary"
                            :disabled="sending"
                            @click="next"
                        >
                            {{
                                index === steps.length - 1
                                    ? sending
                                        ? 'Sending'
                                        : 'Send request'
                                    : 'OK'
                            }}
                        </button>
                        <span class="rf-hint">press <kbd>Enter</kbd></span>
                    </div>
                </div>
            </Transition>
        </div>

        <div v-if="done === false" class="rf-nav">
            <button
                type="button"
                aria-label="Previous question"
                :disabled="index === 0"
                @click="back"
            >
                <svg viewBox="0 0 16 16" aria-hidden="true">
                    <path
                        d="M4 10l4-4 4 4"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </button>
            <button type="button" aria-label="Next question" @click="next">
                <svg viewBox="0 0 16 16" aria-hidden="true">
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
        </div>
    </div>
</template>

<style scoped>
.rf-root {
    --rf-ink: #0b0d12;
    --rf-slate: #5b6472;
    --rf-line: #e2e8f0;
    --rf-volt: #6d4aff;
    --rf-volt-deep: #4b2fd6;
    --rf-lilac: #f1efff;
    position: relative;
    overflow: hidden;
    font-family:
        'Inter',
        ui-sans-serif,
        system-ui,
        -apple-system,
        sans-serif;
    color: var(--rf-ink);
    background: #ffffff;
    border: 1px solid var(--rf-line);
    border-radius: 20px;
    box-shadow: 0 24px 60px -32px rgba(26, 16, 51, 0.28);
}

.rf-progress {
    height: 3px;
    background: #eef1f6;
}

.rf-progress span {
    display: block;
    height: 100%;
    background: var(--rf-volt);
    transition: width 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}

.rf-trap {
    position: absolute;
    left: -10000px;
    width: 1px;
    height: 1px;
    overflow: hidden;
}

.rf-stage {
    position: relative;
    min-height: 360px;
    display: flex;
    align-items: center;
    padding: 40px 28px 72px;
}

.rf-screen {
    width: 100%;
    max-width: 560px;
    margin: 0 auto;
}

.rf-step {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--rf-volt);
}

.rf-question {
    display: block;
    margin: 0;
    font-size: clamp(21px, 3.4vw, 27px);
    font-weight: 600;
    line-height: 1.25;
    letter-spacing: -0.018em;
}

.rf-help {
    margin: 10px 0 0;
    font-size: 14.5px;
    line-height: 1.5;
    color: var(--rf-slate);
}

.rf-input {
    display: block;
    width: 100%;
    margin-top: 24px;
    padding: 8px 0 10px;
    border: 0;
    border-bottom: 1.5px solid #cbd5e1;
    border-radius: 0;
    background: transparent;
    font: inherit;
    font-size: clamp(18px, 2.6vw, 22px);
    color: var(--rf-ink);
    outline: none;
    transition: border-color 0.2s;
}

.rf-input::placeholder {
    color: #a3acb9;
}

.rf-input:focus {
    border-bottom-color: var(--rf-volt);
}

.rf-input.is-area {
    resize: none;
    min-height: 96px;
    line-height: 1.45;
}

.rf-counter {
    margin: 6px 0 0;
    text-align: right;
    font-size: 12px;
    color: #94a3b8;
    font-variant-numeric: tabular-nums;
}

.rf-choices {
    display: grid;
    gap: 8px;
    margin-top: 22px;
    max-width: 360px;
}

.rf-choice {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border: 1px solid var(--rf-line);
    border-radius: 10px;
    background: #ffffff;
    font: inherit;
    font-size: 16px;
    font-weight: 500;
    color: var(--rf-ink);
    text-align: left;
    cursor: pointer;
    transition:
        border-color 0.15s,
        background 0.15s;
}

.rf-choice:hover {
    border-color: #cbd5e1;
}

.rf-choice[aria-checked='true'] {
    border-color: var(--rf-volt);
    background: var(--rf-lilac);
}

.rf-choice:focus-visible,
.rf-primary:focus-visible,
.rf-ghost:focus-visible,
.rf-nav button:focus-visible {
    outline: 2px solid var(--rf-volt);
    outline-offset: 2px;
}

kbd {
    display: inline-grid;
    place-items: center;
    min-width: 22px;
    height: 22px;
    padding: 0 5px;
    border: 1px solid var(--rf-line);
    border-radius: 5px;
    background: #ffffff;
    font: inherit;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--rf-slate);
}

.rf-choice[aria-checked='true'] kbd {
    border-color: var(--rf-volt);
    background: var(--rf-volt);
    color: #ffffff;
}

.rf-error {
    margin: 12px 0 0;
    font-size: 14px;
    color: #b42318;
}

.rf-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 28px;
}

.rf-primary {
    border: 0;
    border-radius: 10px;
    background: var(--rf-volt);
    color: #ffffff;
    padding: 11px 20px;
    font: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 6px 18px -8px rgba(109, 74, 255, 0.7);
    transition:
        background 0.15s,
        transform 0.15s;
}

.rf-primary:hover {
    background: var(--rf-volt-deep);
}

.rf-primary:active {
    transform: translateY(1px);
}

.rf-primary:disabled {
    opacity: 0.6;
    cursor: progress;
}

.rf-ghost {
    border: 1px solid var(--rf-line);
    border-radius: 10px;
    background: #ffffff;
    color: var(--rf-ink);
    padding: 10px 18px;
    font: inherit;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
}

.rf-hint {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12.5px;
    color: var(--rf-slate);
}

.rf-nav {
    position: absolute;
    right: 18px;
    bottom: 18px;
    display: flex;
    overflow: hidden;
    border-radius: 9px;
    box-shadow: 0 0 0 1px var(--rf-line);
}

.rf-nav button {
    display: grid;
    place-items: center;
    width: 36px;
    height: 34px;
    border: 0;
    background: #ffffff;
    color: var(--rf-ink);
    cursor: pointer;
}

.rf-nav button + button {
    border-left: 1px solid var(--rf-line);
}

.rf-nav button:disabled {
    color: #cbd5e1;
    cursor: default;
}

.rf-nav svg {
    width: 16px;
    height: 16px;
}

.rf-check {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    margin-bottom: 18px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
}

.rf-check svg {
    width: 22px;
    height: 22px;
}

.rf-next-enter-active,
.rf-next-leave-active,
.rf-back-enter-active,
.rf-back-leave-active {
    transition:
        opacity 0.24s ease,
        transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.rf-next-leave-active,
.rf-back-leave-active {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 28px;
    right: 28px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 40px 0 72px;
    pointer-events: none;
}

.rf-next-enter-from,
.rf-back-leave-to {
    opacity: 0;
    transform: translateY(24px);
}

.rf-next-leave-to,
.rf-back-enter-from {
    opacity: 0;
    transform: translateY(-24px);
}

@media (max-width: 640px) {
    .rf-stage {
        min-height: 400px;
        padding: 32px 20px 76px;
    }

    .rf-next-leave-active,
    .rf-back-leave-active {
        left: 20px;
        right: 20px;
        padding: 32px 0 76px;
    }

    .rf-hint {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .rf-next-enter-active,
    .rf-next-leave-active,
    .rf-back-enter-active,
    .rf-back-leave-active,
    .rf-progress span {
        transition: none;
    }
}
</style>
