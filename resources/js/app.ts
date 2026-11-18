import '@/echo';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18nVue } from 'laravel-vue-i18n';
import { ConfigProvider } from 'reka-ui';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';

import { initializeDataLayer } from '@/datalayer';
import { bootLocale, i18nConfig, syncLocale, textDirection } from '@/language';
import { syncContentTypeMediaRules } from '@/lib/contentTypeMediaRules';
import { trackInAppPushes } from '@/lib/inAppHistory';
import {
    isConnectWindow,
    isEmbedded,
    setEmbedParents,
    startConnectWindow,
    startEmbedBridge,
} from '@/lib/tantra/embed';
import {
    capturePageview,
    initializePostHog,
    syncPostHogContext,
} from '@/posthog';
import { syncPreferences } from '@/preferences';
import type { Auth } from '@/types';

const engineName = import.meta.env.VITE_APP_NAME || 'TryPost.it';

// tantra: AF-10, inside Tantra (framed, or a session the Tantra embed started) the engine's own name never shows.
const nameFor = (page: unknown): string =>
    isEmbedded() ||
    (
        (page as { props?: { tantraView?: { active?: boolean } } } | undefined)
            ?.props?.tantraView
    )?.active === true
        ? 'Tantra'
        : engineName;

// tantra: after a deploy an open page can ask for a chunk that no longer
// exists. Reload once to pick up the new build; the sessionStorage guard
// stops a loop when the chunk is missing for another reason.
const PRELOAD_RELOAD_KEY = 'tantra.preloadReload';
window.addEventListener('vite:preloadError', (event) => {
    try {
        if (sessionStorage.getItem(PRELOAD_RELOAD_KEY)) {
            return;
        }
        sessionStorage.setItem(PRELOAD_RELOAD_KEY, String(Date.now()));
    } catch {
        // No sessionStorage (a locked down frame): never reload blindly.
        return;
    }
    event.preventDefault();
    window.location.reload();
});

createInertiaApp({
    title: (title, page) =>
        // tantra: a public page (the roadmap) names its own full title.
        (page?.props?.tantraTitle as string | undefined) ??
        (title ? `${title} - ${nameFor(page)}` : nameFor(page)),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const locale = bootLocale(props.initialPage.props);
        syncPreferences(props.initialPage.props);

        const auth = props.initialPage.props.auth as Auth | undefined;
        const flash = props.initialPage.props.flash as
            | { conversion_event?: string; [key: string]: unknown }
            | undefined;

        initializeDataLayer(
            auth,
            flash,
            props.initialPage.props.applicationUrl as string,
            props.initialPage.props.env as string,
        );

        // Initial PostHog identify + dual-group context + first pageview.
        // The same hooks fire on every Inertia navigation below so the
        // account group counts stay reactive and workspace switches
        // re-attach the right workspace group.
        initializePostHog();
        syncPostHogContext(props.initialPage);
        syncContentTypeMediaRules(props.initialPage);
        capturePageview();

        // `success`, not `navigate`: switching language answers with `back()`,
        // which keeps the same URL, so Inertia updates the props without
        // reporting a navigation.
        router.on('success', (event) => {
            syncLocale(event.detail.page.props);
            syncPreferences(event.detail.page.props);
        });

        trackInAppPushes();

        router.on('navigate', (event) => {
            syncPostHogContext(event.detail.page);
            syncContentTypeMediaRules(event.detail.page);
            capturePageview();
        });

        let mounted = false;

        const app = createApp({
            render: () =>
                h(ConfigProvider, { dir: textDirection.value }, () =>
                    h(App, props),
                ),
        });

        app.use(i18nVue, {
            ...i18nConfig(locale),
            onLoad: () => {
                if (mounted) {
                    return;
                }

                mounted = true;
                app.mount(el);
                // tantra: the page loaded, so a later missing chunk may reload once again.
                window.setTimeout(() => {
                    try {
                        sessionStorage.removeItem(PRELOAD_RELOAD_KEY);
                    } catch {
                        // No sessionStorage: nothing to clear.
                    }
                }, 10_000);

                // tantra: inside the Tantra CRM's Content tab (PRD 2026-11-15).
                if (isEmbedded()) {
                    setEmbedParents(props.initialPage.props.tantraEmbedParents);
                    router.on('navigate', (event) =>
                        setEmbedParents(
                            event.detail.page.props.tantraEmbedParents,
                        ),
                    );
                    startEmbedBridge(props.initialPage);
                }
                // tantra: the connect popup opened from the Content tab reports back and closes.
                if (isConnectWindow()) {
                    startConnectWindow(props.initialPage);
                }
            },
        }).use(plugin);
    },
    progress: {
        color: '#4B5563',
    },
});
