import { router } from '@inertiajs/vue3';
import { computed, toValue, type MaybeRefOrGetter } from 'vue';

import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { connect as blueskyConnect } from '@/routes/app/social/bluesky';
import { connect as discordConnect } from '@/routes/app/social/discord';
import { connect as facebookConnect } from '@/routes/app/social/facebook';
import { connect as googleBusinessConnect } from '@/routes/app/social/google-business';
import { connect as instagramConnect } from '@/routes/app/social/instagram';
import { connect as instagramFacebookConnect } from '@/routes/app/social/instagram-facebook';
import { connect as linkedinConnect } from '@/routes/app/social/linkedin';
import { connect as mastodonConnect } from '@/routes/app/social/mastodon';
import { connect as pinterestConnect } from '@/routes/app/social/pinterest';
import { connect as threadsConnect } from '@/routes/app/social/threads';
import { connect as tiktokConnect } from '@/routes/app/social/tiktok';
import { connect as xConnect } from '@/routes/app/social/x';
import { connect as youtubeConnect } from '@/routes/app/social/youtube';
import { Platform } from '@/types/platform';
import type { AvailablePlatform } from '@/types/social-account';

const CONNECT_ROUTES: Record<
    string,
    { url: (options?: { query?: Record<string, string> }) => string }
> = {
    [Platform.Bluesky]: blueskyConnect,
    [Platform.Discord]: discordConnect,
    [Platform.Facebook]: facebookConnect,
    [Platform.GoogleBusiness]: googleBusinessConnect,
    [Platform.Instagram]: instagramConnect,
    [Platform.InstagramFacebook]: instagramFacebookConnect,
    [Platform.LinkedIn]: linkedinConnect,
    [Platform.Mastodon]: mastodonConnect,
    [Platform.Pinterest]: pinterestConnect,
    [Platform.Threads]: threadsConnect,
    [Platform.TikTok]: tiktokConnect,
    [Platform.X]: xConnect,
    [Platform.YouTube]: youtubeConnect,
};

export const connectUrl = (
    platform: string,
    reconnectId?: string,
    extra: Record<string, string> = {},
): string | undefined =>
    CONNECT_ROUTES[platform]?.url({
        query: {
            return_to: window.location.pathname,
            ...(reconnectId ? { reconnect: reconnectId } : {}),
            ...extra,
        },
    });

// tantra: the pre-connect step (app/Tantra/Channels/PreConnect.php). A network
// with no keys yet goes straight to it; one in testing reaches it through the
// connect route itself (PreConnectGate), so every entry point shows one step.
export const prepareUrl = (platform: string, reconnectId?: string): string => {
    const query = new URLSearchParams({
        return_to: window.location.pathname,
        ...(reconnectId ? { reconnect: reconnectId } : {}),
    });
    // The Instagram card's keys come from the Meta: Facebook Pages app first.
    const network =
        platform === Platform.Instagram ? Platform.InstagramFacebook : platform;

    return `/connect/${network.replace(/_/g, '-')}/prepare?${query.toString()}`;
};

export const useNetworkConnect = (
    platforms: MaybeRefOrGetter<AvailablePlatform[]>,
) => {
    const dialog = useConnectChannelDialog();

    const connectEntry = (platform: string): string =>
        platform === Platform.LinkedInPage ? Platform.LinkedIn : platform;

    const openConnect = (platform: string, reconnectId?: string) => {
        const url = connectUrl(platform, reconnectId);

        if (url) {
            router.visit(url);
        }
    };

    // Variants have no card of their own: their network's card decides.
    const isReady = (platform: string): boolean => {
        const card =
            platform === Platform.InstagramFacebook
                ? Platform.Instagram
                : platform;

        return (
            toValue(platforms).find((p) => p.value === card)?.ready !== false
        );
    };

    const startConnect = (platform: string, reconnectId?: string) => {
        const entry = connectEntry(platform);

        // tantra: a network that cannot connect yet opens the logo grid,
        // where it shows as "Coming soon" (Adi 2026-11-14).
        if (!isReady(entry)) {
            dialog.open();
            return;
        }

        if (entry === Platform.Telegram) {
            dialog.openAt('telegram', reconnectId);
            return;
        }

        // A new connection starts on the network's panel in the dialog.
        if (!reconnectId) {
            dialog.showNetwork(platform, false);
            return;
        }

        openConnect(entry, reconnectId);
    };

    const instagramMethods = computed(
        () =>
            toValue(platforms).find(
                (platform) => platform.value === Platform.Instagram,
            )?.connect_methods ?? [
                Platform.Instagram,
                Platform.InstagramFacebook,
            ],
    );

    return { startConnect, openConnect, instagramMethods, isReady };
};
