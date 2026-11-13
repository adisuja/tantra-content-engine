<script setup lang="ts">
// tantra: the pop-up behind one logo of the "Connect a channel" grid
// (Adi 2026-11-14). Sign-in networks show one "Continue with" button (with a
// plain two-way choice where a network has one); Bluesky and Mastodon ask
// only for what they need. Telegram uses its bot step (TelegramConnectStep).
import { router, useForm } from '@inertiajs/vue3';
import { IconExternalLink, IconLoader2 } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { connectUrl } from '@/composables/useNetworkConnect';
import { store as storeBluesky } from '@/routes/app/social/bluesky';
import { authorize as authorizeMastodon } from '@/routes/app/social/mastodon';
import { Platform } from '@/types/platform';
import type { ConnectNetwork } from '@/types/social-account';

const props = defineProps<{
    network: ConnectNetwork;
}>();

const { close } = useConnectChannelDialog();

// What each sign-in network says under its name: one short line.
const LINES: Record<string, string> = {
    linkedin: 'Sign in to LinkedIn and pick your profile.',
    'linkedin-page':
        'Sign in to LinkedIn as a page admin, then pick your page.',
    x: 'Sign in to X and approve access.',
    facebook: 'Sign in to Facebook and pick your Pages.',
    instagram: 'Choose how your Instagram account signs in.',
    threads: 'Sign in to Threads and approve access.',
    youtube: 'Sign in with Google and pick your channel.',
    tiktok: 'Sign in to TikTok and approve access.',
    pinterest: 'Sign in to Pinterest and approve access.',
    discord: 'Sign in to Discord and pick your server.',
    google_business: 'Sign in with Google and pick your business.',
    bluesky: 'Use your handle and an app password.',
    mastodon: 'Enter your server, then sign in there.',
};

const line = computed(() => LINES[props.network.value] ?? '');

// ---- sign-in networks ----------------------------------------------------

// Instagram: business or creator accounts sign in with Instagram; accounts
// linked to a Facebook Page sign in with Facebook. Only ready routes show.
const INSTAGRAM_CHOICES = [
    {
        value: Platform.Instagram,
        title: 'Business or creator account',
        hint: 'Sign in with Instagram',
        brand: 'Instagram',
    },
    {
        value: Platform.InstagramFacebook,
        title: 'Linked to a Facebook Page',
        hint: 'Sign in with Facebook',
        brand: 'Facebook',
    },
];

const choices = computed(() =>
    props.network.value === Platform.Instagram
        ? INSTAGRAM_CHOICES.filter((c) =>
              props.network.methods.includes(c.value),
          )
        : [],
);
const choice = ref<string>(choices.value[0]?.value ?? '');

// LinkedIn pages sign in through LinkedIn and are picked after.
const route = computed(() => {
    if (props.network.value === Platform.LinkedInPage) {
        return Platform.LinkedIn;
    }
    if (props.network.value === Platform.Instagram) {
        return choice.value || Platform.Instagram;
    }

    return props.network.value;
});

const brand = computed(() => {
    if (props.network.value === Platform.LinkedInPage) return 'LinkedIn';
    if (props.network.value === Platform.Instagram) {
        return (
            choices.value.find((c) => c.value === choice.value)?.brand ??
            'Instagram'
        );
    }
    if (props.network.value === Platform.YouTube) return 'Google';
    if (props.network.value === Platform.GoogleBusiness) return 'Google';

    return props.network.label;
});

const leaving = ref(false);

const continueOAuth = (): void => {
    // LinkedIn: the profile and company pages may use separate apps
    // (LinkedInPageApp::kindFor); each tile asks for its own consent screen.
    const kind: Record<string, string> =
        props.network.value === Platform.LinkedInPage
            ? { kind: 'page' }
            : props.network.value === Platform.LinkedIn
              ? { kind: 'profile' }
              : {};
    const url = connectUrl(route.value, undefined, kind);
    if (!url) return;
    leaving.value = true;
    // The server answers with the network's sign-in page (another site).
    router.visit(url, { onCancel: () => (leaving.value = false) });
};

// ---- Bluesky -------------------------------------------------------------

const bluesky = useForm({ identifier: '', password: '' });

const connectBluesky = (): void => {
    bluesky.post(
        storeBluesky.url({ query: { return_to: window.location.pathname } }),
        {
            preserveScroll: true,
            onSuccess: () => close(),
        },
    );
};

// ---- Mastodon ------------------------------------------------------------

const mastodon = useForm({ instance: '' });

const connectMastodon = (): void => {
    mastodon
        .transform((data) => {
            const server = data.instance.trim().replace(/\/+$/, '');

            return {
                instance: /^https?:\/\//i.test(server)
                    ? server
                    : `https://${server || 'mastodon.social'}`,
            };
        })
        .post(
            authorizeMastodon.url({
                query: { return_to: window.location.pathname },
            }),
            { preserveScroll: true },
        );
};

const firstError = (errors: Record<string, string>): string =>
    Object.values(errors)[0] ?? '';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[400px] flex-col gap-6 px-5 pt-16 pb-8 sm:pt-12"
            >
                <header class="flex flex-col items-center gap-4 text-center">
                    <span
                        class="flex size-16 items-center justify-center rounded-2xl bg-muted [&>svg]:size-9"
                        aria-hidden="true"
                    >
                        <PlatformBrandIcon :platform="network.value" colored />
                    </span>
                    <div class="flex flex-col gap-1.5">
                        <DialogTitle
                            class="font-sans text-xl leading-tight font-medium text-foreground"
                            data-testid="connect-panel-title"
                        >
                            Connect {{ network.label }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-sm text-muted-foreground"
                        >
                            {{ line }}
                        </DialogDescription>
                    </div>
                </header>

                <!-- Bluesky: handle and app password. -->
                <form
                    v-if="network.kind === 'credentials'"
                    class="flex flex-col gap-4"
                    data-testid="connect-panel-bluesky"
                    @submit.prevent="connectBluesky"
                >
                    <div class="grid gap-2">
                        <Label for="bsky-handle">Handle</Label>
                        <Input
                            id="bsky-handle"
                            v-model="bluesky.identifier"
                            type="text"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="you.bsky.social"
                            required
                            :aria-invalid="Boolean(bluesky.errors.identifier)"
                            data-testid="connect-bluesky-handle"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="bsky-password">App password</Label>
                        <Input
                            id="bsky-password"
                            v-model="bluesky.password"
                            type="password"
                            autocomplete="off"
                            placeholder="xxxx-xxxx-xxxx-xxxx"
                            required
                            :aria-invalid="Boolean(bluesky.errors.password)"
                            aria-describedby="bsky-password-hint"
                            data-testid="connect-bluesky-password"
                        />
                        <a
                            id="bsky-password-hint"
                            href="https://bsky.app/settings/app-passwords"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex w-fit items-center gap-1 rounded text-sm text-primary-text underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            data-testid="connect-bluesky-app-password-link"
                            >Create an app password
                            <IconExternalLink
                                class="size-3.5"
                                aria-hidden="true"
                        /></a>
                    </div>
                    <p
                        v-if="firstError(bluesky.errors)"
                        role="alert"
                        class="text-sm text-destructive-text"
                        data-testid="connect-panel-error"
                    >
                        {{ firstError(bluesky.errors) }}
                    </p>
                    <Button
                        type="submit"
                        size="lg"
                        class="w-full"
                        :disabled="bluesky.processing"
                        data-testid="connect-panel-submit"
                    >
                        <IconLoader2
                            v-if="bluesky.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        {{
                            bluesky.processing
                                ? 'Connecting'
                                : 'Connect Bluesky'
                        }}
                    </Button>
                </form>

                <!-- Mastodon: the server, then its own sign-in page. -->
                <form
                    v-else-if="network.kind === 'server'"
                    class="flex flex-col gap-4"
                    data-testid="connect-panel-mastodon"
                    @submit.prevent="connectMastodon"
                >
                    <div class="grid gap-2">
                        <Label for="mastodon-server">Your server</Label>
                        <Input
                            id="mastodon-server"
                            v-model="mastodon.instance"
                            type="text"
                            inputmode="url"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="mastodon.social"
                            :aria-invalid="Boolean(mastodon.errors.instance)"
                            data-testid="connect-mastodon-server"
                        />
                    </div>
                    <p
                        v-if="firstError(mastodon.errors)"
                        role="alert"
                        class="text-sm text-destructive-text"
                        data-testid="connect-panel-error"
                    >
                        {{ firstError(mastodon.errors) }}
                    </p>
                    <Button
                        type="submit"
                        size="lg"
                        class="w-full"
                        :disabled="mastodon.processing"
                        data-testid="connect-panel-submit"
                    >
                        <IconLoader2
                            v-if="mastodon.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Continue with Mastodon
                    </Button>
                </form>

                <!-- Sign-in networks: one button, a choice only where needed. -->
                <div v-else class="flex flex-col gap-4">
                    <fieldset
                        v-if="choices.length > 1"
                        class="flex flex-col gap-2"
                        data-testid="connect-panel-choices"
                    >
                        <legend class="sr-only">
                            How your account signs in
                        </legend>
                        <label
                            v-for="c in choices"
                            :key="c.value"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border border-border bg-card p-4 transition-colors has-checked:border-primary-text has-checked:bg-primary/15 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-ring"
                            :data-testid="`connect-choice-${c.value}`"
                        >
                            <input
                                v-model="choice"
                                type="radio"
                                name="connect-choice"
                                :value="c.value"
                                class="size-4 accent-[var(--primary-text)]"
                            />
                            <span class="flex flex-col">
                                <span
                                    class="text-sm font-medium text-foreground"
                                    >{{ c.title }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    c.hint
                                }}</span>
                            </span>
                        </label>
                    </fieldset>
                    <Button
                        size="lg"
                        class="w-full"
                        :disabled="leaving"
                        data-testid="connect-panel-continue"
                        @click="continueOAuth"
                    >
                        <IconLoader2
                            v-if="leaving"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Continue with {{ brand }}
                    </Button>
                    <p class="text-center text-xs text-muted-foreground">
                        You come back to Tantra when you are done.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
