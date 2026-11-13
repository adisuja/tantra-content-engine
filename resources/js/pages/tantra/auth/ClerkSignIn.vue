<script setup lang="ts">
// tantra: sign in with the usetantra.com login (Clerk, 2026-11-14). Mirrors
// usetantra.com/sign-in: Clerk's SignIn on the left, the brand panel on the
// right, "Back to home" as the only navigation. Clerk's script is loaded from
// the instance's own Frontend API (as Clerk's SDKs do), so the session started
// on usetantra.com carries over to this subdomain. English only, like the
// main app's sign in.
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{
    state: 'sign-in' | 'no-access';
    email: string | null;
    signedOut: boolean;
    publishableKey: string;
    frontendApi: string;
    sessionUrl: string;
    passwordUrl: string;
    loginUrl: string;
    homeUrl: string;
}>();

interface ClerkInstance {
    load: (options?: Record<string, unknown>) => Promise<void>;
    session: { getToken: () => Promise<string | null> } | null;
    user: unknown;
    signOut: () => Promise<void>;
    mountSignIn: (el: HTMLDivElement, props?: Record<string, unknown>) => void;
}

declare global {
    interface Window {
        Clerk?: ClerkInstance;
    }
}

const page = usePage();
const serverError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.clerk,
);

const mountPoint = ref<HTMLDivElement | null>(null);
const loading = ref(true);
const working = ref(false);
const loadError = ref<string | null>(null);
let clerk: ClerkInstance | null = null;

const appearance = {
    variables: {
        colorPrimary: '#6d4aff',
        fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
    },
    // The Engine is invite only: no sign up link under the form.
    elements: { footerAction: { display: 'none' } },
};

const steps = [
    'Sign in with the login you use on usetantra.com.',
    'Open Review to approve the posts written for you.',
    'Connect LinkedIn once, so approved posts go out on time.',
];

function loadClerk(): Promise<ClerkInstance> {
    if (window.Clerk) {
        return Promise.resolve(window.Clerk);
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `${props.frontendApi}/npm/@clerk/clerk-js@5/dist/clerk.browser.js`;
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.setAttribute('data-clerk-publishable-key', props.publishableKey);
        script.onload = () =>
            window.Clerk
                ? resolve(window.Clerk)
                : reject(new Error('no Clerk'));
        script.onerror = () => reject(new Error('Clerk did not load'));
        document.head.appendChild(script);
    });
}

async function exchange(): Promise<void> {
    const token = await clerk?.session?.getToken();
    if (!token) {
        return;
    }
    working.value = true;
    router.post(
        props.sessionUrl,
        { token },
        { onFinish: () => (working.value = false) },
    );
}

async function useAnotherAccount(): Promise<void> {
    working.value = true;
    await clerk?.signOut();
    window.location.assign(props.loginUrl);
}

onMounted(async () => {
    try {
        clerk = await loadClerk();
        await clerk.load({ appearance });
    } catch {
        loadError.value =
            'Sign in could not load. Check your connection and refresh the page.';
        loading.value = false;

        return;
    }

    if (props.signedOut && clerk.user) {
        await clerk.signOut();
    }

    loading.value = false;
    if (props.state === 'no-access') {
        return;
    }

    // Signed in on usetantra.com already: start the Engine session straight away.
    if (clerk.session && !props.signedOut && !serverError.value) {
        await exchange();

        return;
    }

    if (!clerk.session && mountPoint.value) {
        clerk.mountSignIn(mountPoint.value, {
            appearance,
            forceRedirectUrl: props.loginUrl,
            signUpForceRedirectUrl: props.loginUrl,
        });
    }
});
</script>

<template>
    <div class="min-h-svh bg-[#f7f8fb] font-sans text-[#0b0d12]">
        <Head title="Sign in" />
        <main class="flex min-h-svh flex-col">
            <h1 class="sr-only">Sign in to Tantra</h1>
            <div class="mx-auto w-full max-w-[1280px] px-5 pt-8 sm:px-8">
                <a
                    :href="homeUrl"
                    class="inline-flex items-center gap-2 text-[14px] font-medium text-[#5b6472] transition hover:text-[#0b0d12]"
                >
                    <IconArrowLeft class="size-4" aria-hidden="true" />
                    Back to home
                </a>
            </div>

            <div class="py-16 sm:py-24">
                <div class="mx-auto w-full max-w-[1280px] px-5 sm:px-8">
                    <div
                        class="mx-auto grid max-w-[920px] items-stretch gap-10 lg:grid-cols-[auto_1fr]"
                    >
                        <div
                            class="mx-auto flex w-full max-w-[400px] flex-col items-center justify-center gap-4"
                        >
                            <div
                                v-if="state === 'no-access'"
                                class="w-full rounded-2xl border border-[#e7eaf0] bg-white p-8 shadow-sm"
                                data-testid="clerk-no-access"
                            >
                                <h2
                                    class="text-[20px] font-semibold tracking-tight"
                                >
                                    Ask your admin for access
                                </h2>
                                <p
                                    v-if="email"
                                    class="mt-2 text-[14px] leading-relaxed text-[#5b6472]"
                                >
                                    You are signed in as
                                    <span class="font-medium text-[#0b0d12]">{{
                                        email
                                    }}</span
                                    >, but this login has no Content Engine
                                    access yet. Ask your admin to invite this
                                    email, then sign in again.
                                </p>
                                <p
                                    v-else
                                    class="mt-2 text-[14px] leading-relaxed text-[#5b6472]"
                                >
                                    We could not read a verified email for this
                                    login. Ask your admin for access.
                                </p>
                                <button
                                    type="button"
                                    class="mt-6 w-full rounded-lg bg-[#6d4aff] px-4 py-2.5 text-[14px] font-medium text-white transition hover:bg-[#5b3ae6] disabled:opacity-60"
                                    :disabled="working || loading"
                                    @click="useAnotherAccount"
                                >
                                    Use another account
                                </button>
                            </div>

                            <template v-else>
                                <p
                                    v-if="serverError"
                                    class="w-full rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[14px] text-red-700"
                                    role="alert"
                                >
                                    {{ serverError }}
                                    <button
                                        type="button"
                                        class="ms-1 font-medium underline"
                                        :disabled="working"
                                        @click="exchange"
                                    >
                                        Try again
                                    </button>
                                </p>
                                <p
                                    v-if="loadError"
                                    class="w-full rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[14px] text-red-700"
                                    role="alert"
                                >
                                    {{ loadError }}
                                </p>
                                <p
                                    v-if="loading || working"
                                    class="py-10 text-[14px] text-[#5b6472]"
                                    data-testid="clerk-loading"
                                >
                                    {{ working ? 'Signing you in' : 'Loading' }}
                                </p>
                                <div
                                    ref="mountPoint"
                                    data-testid="clerk-sign-in"
                                />
                            </template>

                            <a
                                :href="passwordUrl"
                                class="text-[13px] text-[#5b6472] underline-offset-4 hover:text-[#0b0d12] hover:underline"
                                data-testid="clerk-password-link"
                            >
                                Other sign in options
                            </a>
                        </div>

                        <div
                            class="relative hidden overflow-hidden rounded-[24px] bg-[linear-gradient(120deg,#2e1b7b_0%,#4930c2_34%,#6d4aff_62%,#9e8cff_100%)] p-10 text-white lg:flex lg:flex-col lg:justify-center"
                        >
                            <div
                                class="pointer-events-none absolute inset-0 bg-[radial-gradient(52%_68%_at_18%_8%,rgb(255_255_255/0.16)_0%,transparent_60%),radial-gradient(42%_52%_at_85%_90%,rgb(255_255_255/0.12)_0%,transparent_65%)]"
                                aria-hidden="true"
                            />
                            <h2
                                class="relative text-[24px] leading-[1.08] font-[650] tracking-[-0.035em]"
                            >
                                Every teammate's posts in one engine
                            </h2>
                            <p
                                class="relative mt-3 max-w-[380px] text-[14px] leading-relaxed text-white/80"
                            >
                                Review, approve and schedule posts for every
                                teammate and the Tantra page, with every channel
                                in one sidebar.
                            </p>
                            <p
                                class="relative mt-7 text-[12px] font-semibold tracking-[0.16em] text-white/60 uppercase"
                            >
                                What happens next
                            </p>
                            <ol class="relative mt-3 space-y-3">
                                <li
                                    v-for="(step, index) in steps"
                                    :key="step"
                                    class="flex gap-3 text-[13.5px] leading-relaxed text-white/90"
                                >
                                    <span
                                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-white/15 text-[11px] font-bold"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    {{ step }}
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
