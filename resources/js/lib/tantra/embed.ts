// tantra: the Engine inside the Tantra CRM's Content tab (PRD 2026-11-15, 4.3).
//
// The CRM frames the Engine and owns the shell (sidebar, tabs, sign in). In a
// frame the Engine hides its own sidebar, talks to the CRM by postMessage and
// runs every connect flow in a popup, because LinkedIn, X and Meta refuse to
// load inside frames. Embedded mode is decided here alone, on the client:
// window.self !== window.top. A page opened in its own tab never changes.
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

export const PARENT_KEY = 'tantra.parent';
export const CONNECT_WINDOW = 'tantra-connect';
export const CHANNELS_PATH = '/settings/workspace/channels';
const MAX_PATH = 2000;

type EmbedMessage = { type: string; [key: string]: unknown };

export const isEmbedded = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.self !== window.top;
    } catch {
        // A cross-origin top that cannot even be compared: still a frame.
        return true;
    }
};

/** Reactive flag for templates; it never changes during a page's life. */
export const embedded = ref(isEmbedded());

let parents: string[] = [];

/** The CRM origins this Engine may talk to (shared prop tantraEmbedParents). */
export const setEmbedParents = (value: unknown): void => {
    parents = Array.isArray(value)
        ? value.filter((origin): origin is string => typeof origin === 'string')
        : [];
};

const allowed = (origin: string | null | undefined): origin is string =>
    typeof origin === 'string' && parents.includes(origin);

/**
 * The CRM origin to post to: the one the boot page saw hand over the token
 * (sessionStorage is per frame and survives reloads inside it), else the
 * frame's first ancestor, else the only configured parent. Never "*".
 */
export const parentOrigin = (): string | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const stored = window.sessionStorage.getItem(PARENT_KEY);
        if (allowed(stored)) {
            return stored;
        }
    } catch {
        // Storage blocked: fall through.
    }

    const ancestor = window.location.ancestorOrigins?.[0];
    if (allowed(ancestor)) {
        return ancestor;
    }

    return parents.length === 1 ? parents[0] : null;
};

export const postToParent = (message: EmbedMessage): void => {
    const origin = parentOrigin();
    if (!origin || !isEmbedded()) {
        return;
    }

    window.parent.postMessage({ v: 1, ...message }, origin);
};

/**
 * A path on this site only: starts with "/", not "//", no backslash, no
 * scheme, no control character, at most 2,000 characters. Same rule as the
 * server (App\Tantra\Embed\Embed::safePath) and the CRM.
 */
export const safePath = (path: unknown): string | null => {
    if (
        typeof path !== 'string' ||
        path === '' ||
        path.length > MAX_PATH ||
        !path.startsWith('/') ||
        path.startsWith('//') ||
        path.includes('\\') ||
        path.includes('://') ||
        /[\u0000-\u001F\u007F]/.test(path)
    ) {
        return null;
    }

    return path;
};

const pathOf = (page: Page): string => {
    const url = new URL(page.url, window.location.origin);

    return `${url.pathname}${url.search}`;
};

// Pages that mean the Engine session is gone or the account is not ready:
// the CRM signs the frame in again.
const needsReauth = (component: string): boolean =>
    component.startsWith('auth/') ||
    component.startsWith('tantra/auth/') ||
    component.startsWith('welcome/');

let bridgeStarted = false;
let reauthSent = false;
let lastPath: string | null = null;

const reportPage = (page: Page): void => {
    if (needsReauth(page.component)) {
        if (!reauthSent) {
            reauthSent = true;
            postToParent({ type: 'tantra:reauth' });
        }

        return;
    }

    const path = pathOf(page);
    if (path === lastPath) {
        return;
    }
    lastPath = path;
    // The title is set by <Head> after the page renders.
    window.setTimeout(
        () =>
            postToParent({
                type: 'tantra:navigate',
                path,
                title: document.title,
            }),
        0,
    );
};

/**
 * Embedded only, once: tab clicks in the CRM (tantra:go) visit the page,
 * and every Inertia navigation tells the CRM where the frame is.
 */
export const startEmbedBridge = (initialPage: Page): void => {
    if (bridgeStarted || !isEmbedded()) {
        return;
    }
    bridgeStarted = true;

    window.addEventListener('message', (event: MessageEvent) => {
        if (event.source !== window.parent) {
            return;
        }
        const origin = parentOrigin();
        if (!origin || event.origin !== origin) {
            return;
        }
        const data = event.data as {
            v?: unknown;
            type?: unknown;
            path?: unknown;
        };
        if (!data || data.v !== 1 || data.type !== 'tantra:go') {
            return;
        }
        const path = safePath(data.path);
        if (path) {
            router.visit(path);
        }
    });

    // navigate skips visits made with replace: true, so success (server
    // visits) and clientVisit (router.replace) report too; reportPage only
    // posts when the path changed.
    router.on('navigate', (event) => reportPage(event.detail.page));
    router.on('success', (event) => reportPage(event.detail.page));
    router.on('clientVisit', (event) => reportPage(event.detail.page));
    reportPage(initialPage);
};

// ---- Connect flows in a popup (PRD 4.4) -------------------------------------

let pollTimer: number | undefined;
let removeListener: (() => void) | undefined;

const finishConnect = (account: string | null, created: boolean): void => {
    if (!account) {
        router.reload();

        return;
    }
    const query = new URLSearchParams({ connected: account });
    if (created) {
        query.set('created', '1');
    }
    router.visit(`${CHANNELS_PATH}?${query.toString()}`);
};

/**
 * Opens a connect page in a popup (embedded mode). When the popup lands on
 * the connected channel it posts tantra:connected and closes; the frame then
 * shows the Channels tab with the new channel. If the popup is blocked, a
 * toast offers the same page in a new tab.
 */
export const openConnectWindow = (url: string): Window | null => {
    const popup = window.open(url, CONNECT_WINDOW, 'width=600,height=760');

    if (!popup) {
        toast.warning('Your browser blocked the sign in window.', {
            id: 'tantra-connect-blocked',
            action: {
                label: 'Continue in a new tab',
                onClick: () => window.open(url, '_blank', 'noopener'),
            },
        });

        return null;
    }

    popup.focus();
    window.clearInterval(pollTimer);
    removeListener?.();

    let done = false;
    const stop = (): void => {
        done = true;
        window.clearInterval(pollTimer);
        removeListener?.();
        removeListener = undefined;
    };

    const onMessage = (event: MessageEvent): void => {
        if (event.origin !== window.location.origin || event.source !== popup) {
            return;
        }
        const data = event.data as {
            v?: unknown;
            type?: unknown;
            account?: unknown;
            created?: unknown;
        };
        if (!data || data.v !== 1 || data.type !== 'tantra:connected' || done) {
            return;
        }
        stop();
        finishConnect(
            typeof data.account === 'string' ? data.account : null,
            data.created === true,
        );
    };
    window.addEventListener('message', onMessage);
    removeListener = () => window.removeEventListener('message', onMessage);

    // Closed without a message (cancelled, or a page this side cannot read).
    pollTimer = window.setInterval(() => {
        if (popup.closed && !done) {
            stop();
            router.reload();
        }
    }, 1000);

    return popup;
};

/** For plain links to a connect page: a popup in embedded mode, else the link. */
export const connectLinkClick = (event: MouseEvent, url: string): void => {
    if (!isEmbedded()) {
        return;
    }
    event.preventDefault();
    openConnectWindow(url);
};

// ---- The popup side ---------------------------------------------------------

// Pages the popup waits on: the steps of a connection, and its outcome page.
// Every callback failure (ConnectFailures, ConnectSafetyNet) lands on
// accounts/ConnectFinish with its named reason and next step, so the popup
// stays open there for the person to read it.
export const CONNECT_STEPS = [
    'accounts/ConnectFinish',
    'tantra/connect/Prepare',
    'accounts/MastodonConnect',
    'accounts/BlueskyConnect',
];

const CHANNEL_URL = /^\/channels\/([0-9a-f-]{36})\/publish(?:[/?#]|$)/i;

export const isConnectWindow = (): boolean =>
    typeof window !== 'undefined' &&
    Boolean(window.opener) &&
    window.name === CONNECT_WINDOW;

export type ConnectOutcome =
    | { kind: 'wait' }
    | { kind: 'connected'; account: string; created: boolean }
    | { kind: 'left' };

/**
 * What a page in the popup means. A connection step or its outcome page
 * (including every failure reason): wait. The connected channel's page,
 * known by the connectedChannel flash or /channels/{id}/publish: connected.
 * Anything else (the person went back, or signed out): left the flow.
 */
export const connectOutcome = (
    component: string,
    url: string,
    flash: unknown,
): ConnectOutcome => {
    if (CONNECT_STEPS.includes(component)) {
        return { kind: 'wait' };
    }
    const fromUrl = new URL(url, window.location.origin).pathname.match(
        CHANNEL_URL,
    )?.[1];
    const connected = (
        flash as
            | { connectedChannel?: { accountId?: unknown; created?: unknown } }
            | undefined
    )?.connectedChannel;
    const fromFlash =
        typeof connected?.accountId === 'string' &&
        /^[0-9a-f-]{36}$/i.test(connected.accountId)
            ? connected.accountId
            : undefined;
    const account = fromFlash ?? fromUrl;
    if (!account) {
        return { kind: 'left' };
    }

    return { kind: 'connected', account, created: connected?.created === true };
};

let connectReported = false;

const reportConnected = (page: Page): void => {
    if (connectReported) {
        return;
    }
    const outcome = connectOutcome(page.component, page.url, page.flash);
    if (outcome.kind === 'wait') {
        return;
    }
    connectReported = true;

    // Only a real connected channel is reported; leaving the flow just
    // closes, and the frame reloads when it sees the popup closed.
    if (outcome.kind === 'connected') {
        try {
            window.opener?.postMessage(
                {
                    v: 1,
                    type: 'tantra:connected',
                    account: outcome.account,
                    created: outcome.created,
                },
                window.location.origin,
            );
        } catch {
            // The opener went away: the frame polls for the closed popup.
        }
    }
    window.close();
};

/** The connect popup (opened by openConnectWindow): report back and close. */
export const startConnectWindow = (initialPage: Page): void => {
    if (!isConnectWindow()) {
        return;
    }

    router.on('navigate', (event) => reportConnected(event.detail.page));
    router.on('success', (event) => reportConnected(event.detail.page));
    reportConnected(initialPage);
};

// ---- Connections only on Content > Channels (PRD 4.5) -----------------------

/**
 * Embedded and not on the Channels page: go there with the same request,
 * so connections only ever happen on the CRM's Content > Channels tab.
 * Returns true when it navigated (the caller then does nothing else).
 */
export const routeConnectToChannels = (
    params: Record<string, string | undefined>,
): boolean => {
    if (!isEmbedded() || window.location.pathname === CHANNELS_PATH) {
        return false;
    }
    const query = new URLSearchParams({ connect: '1' });
    for (const [key, value] of Object.entries(params)) {
        if (value) {
            query.set(key, value);
        }
    }
    router.visit(`${CHANNELS_PATH}?${query.toString()}`);

    return true;
};
