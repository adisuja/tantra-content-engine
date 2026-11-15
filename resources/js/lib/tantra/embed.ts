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
 * control character, resolves to this site, at most 2,000 characters. Same
 * rule as the server (App\Tantra\Embed\Embed::safePath).
 */
export const safePath = (path: unknown): string | null => {
    if (
        typeof path !== 'string' ||
        path === '' ||
        path.length > MAX_PATH ||
        !path.startsWith('/') ||
        path.startsWith('//') ||
        path.includes('\\') ||
        /[\u0000-\u001F\u007F]/.test(path)
    ) {
        return null;
    }
    // A scheme or host only counts where it changes where the path goes,
    // not inside a query value such as ?next=https://example.com.
    try {
        const base = 'https://engine.invalid';
        if (new URL(path, base).origin !== base) {
            return null;
        }
    } catch {
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
let lastBadge: number | null = null;

/**
 * The posts waiting for review (the shared tantraReview prop), for the badge
 * on Tantra's sidebar Content item (S-12). Sent when the number changes.
 */
export const reviewWaiting = (page: Page): number | null => {
    const review = (
        page.props as { tantraReview?: { waiting?: unknown } | null }
    ).tantraReview;
    const waiting = review?.waiting;

    return typeof waiting === 'number' &&
        Number.isInteger(waiting) &&
        waiting >= 0
        ? Math.min(waiting, 9999)
        : null;
};

const reportBadge = (page: Page): void => {
    const waiting = reviewWaiting(page);
    if (waiting === null || waiting === lastBadge) {
        return;
    }
    lastBadge = waiting;
    postToParent({ type: 'tantra:badge', review: waiting });
};

const reportPage = (page: Page): void => {
    reportBadge(page);
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
//
// A network's sign in pages may send Cross-Origin-Opener-Policy. That severs
// the popup from this frame: popup.closed reads true while the person is
// still signing in, and the popup has no window.opener to post to. So the
// popup never depends on its opener. It opens on a same-origin bootstrap page
// (/tantra/connect-window) that marks the tab with a nonce in sessionStorage
// (per tab, survives the round trip through the network) and window.name,
// then goes on to the connect page. When the flow ends the popup reports on a
// same-origin BroadcastChannel and a localStorage `storage` event, and posts
// to its opener too while it still has one. The frame and the popup are on
// the same site as the Tantra app, so they share one storage partition.

/** BroadcastChannel name, localStorage key and sessionStorage marker. */
export const CONNECT_KEY = 'tantra.connect';
export const CONNECT_WINDOW_PATH = '/tantra/connect-window';
const CONNECT_NONCE = /^[A-Za-z0-9_-]{16,64}$/;
const CHANNEL_ID = /^[0-9a-f-]{36}$/i;
/** Popup looked closed but the person may still be in it: wait this long at most. */
export const CLOSED_GRACE_MS = 2 * 60 * 1000;
/** Stop listening for a result after this long. */
export const CONNECT_WAIT_LIMIT_MS = 15 * 60 * 1000;

export const CONNECT_LEFT_TEXT =
    'The sign in window closed before a channel was connected.';
export const CONNECT_CLOSED_TEXT =
    'No channel connected yet. If the sign in window is still open, finish there.';

export type ConnectReport =
    | {
          v: 1;
          type: 'tantra:connected';
          nonce: string;
          account: string;
          created: boolean;
          channels: string[];
      }
    | { v: 1; type: 'tantra:connect-left'; nonce: string };

/** A report from the popup this frame opened (same nonce), else null. */
export const readConnectReport = (
    data: unknown,
    nonce: string,
): ConnectReport | null => {
    const report = data as {
        v?: unknown;
        type?: unknown;
        nonce?: unknown;
        account?: unknown;
        created?: unknown;
        channels?: unknown;
    } | null;
    if (!report || report.v !== 1 || report.nonce !== nonce) {
        return null;
    }
    if (report.type === 'tantra:connect-left') {
        return { v: 1, type: 'tantra:connect-left', nonce };
    }
    if (
        report.type !== 'tantra:connected' ||
        typeof report.account !== 'string' ||
        !CHANNEL_ID.test(report.account)
    ) {
        return null;
    }

    return {
        v: 1,
        type: 'tantra:connected',
        nonce,
        account: report.account,
        created: report.created === true,
        channels: Array.isArray(report.channels)
            ? report.channels
                  .filter(
                      (id): id is string =>
                          typeof id === 'string' && CHANNEL_ID.test(id),
                  )
                  .slice(0, 20)
            : [],
    };
};

const finishConnect = (report: ConnectReport): void => {
    if (report.type !== 'tantra:connected') {
        router.reload();
        toast.info(CONNECT_LEFT_TEXT, { id: 'tantra-connect-closed' });

        return;
    }
    const query = new URLSearchParams({ connected: report.account });
    if (report.created) {
        query.set('created', '1');
    }
    const more = report.channels.filter((id) => id !== report.account);
    if (more.length > 0) {
        query.set('new', more.join(','));
    }
    const channelsUrl = `${CHANNELS_PATH}?${query.toString()}`;
    // A late report (a severed popup) never takes the person off the page
    // they moved on to: it says so and offers the Channels tab.
    if (window.location.pathname !== CHANNELS_PATH) {
        toast.success('Channel connected.', {
            id: 'tantra-connect-late',
            action: {
                label: 'Open Channels',
                onClick: () => router.visit(channelsUrl),
            },
        });

        return;
    }
    router.visit(channelsUrl);
};

export const createConnectNonce = (): string => {
    const bytes = crypto.getRandomValues(new Uint8Array(24));

    return btoa(String.fromCharCode(...bytes))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
};

/** The bootstrap page that marks the popup, then opens `url`. */
export const connectWindowUrl = (url: string, nonce: string): string =>
    `${CONNECT_WINDOW_PATH}?${new URLSearchParams({ nonce, to: url }).toString()}`;

let stopWaiting: (() => void) | undefined;

/**
 * Waits for the popup marked with `nonce` to report. `popup.closed` alone is
 * not a cancel (a severed popup reads closed too): it counts once this frame
 * has the person's attention again (focus, a click or a key), or after
 * CLOSED_GRACE_MS. Then the frame reloads, which shows any channel the server
 * already has, and keeps listening until CONNECT_WAIT_LIMIT_MS in case the
 * popup was only severed and reports later.
 */
const waitForConnect = (popup: Window | null, nonce: string): void => {
    stopWaiting?.();

    let done = false;
    let closedSeen = false;
    let closedHandled = false;
    let pollTimer = 0;
    let graceTimer = 0;
    let limitTimer = 0;
    const channel =
        'BroadcastChannel' in window ? new BroadcastChannel(CONNECT_KEY) : null;
    const attention = ['focus', 'pointerdown', 'keydown'] as const;
    // Assigned below, once closedForReal exists.
    let onAttention: () => void = () => undefined;

    const stop = (): void => {
        if (done) {
            return;
        }
        done = true;
        window.clearInterval(pollTimer);
        window.clearTimeout(graceTimer);
        window.clearTimeout(limitTimer);
        window.removeEventListener('message', onMessage);
        window.removeEventListener('storage', onStorage);
        attention.forEach((name) =>
            window.removeEventListener(name, onAttention, true),
        );
        channel?.close();
        if (stopWaiting === stop) {
            stopWaiting = undefined;
        }
    };

    const deliver = (data: unknown): void => {
        const report = readConnectReport(data, nonce);
        if (done || !report) {
            return;
        }
        stop();
        finishConnect(report);
    };

    const onMessage = (event: MessageEvent): void => {
        if (event.origin === window.location.origin) {
            deliver(event.data);
        }
    };

    const onStorage = (event: StorageEvent): void => {
        if (event.key !== CONNECT_KEY || !event.newValue) {
            return;
        }
        try {
            deliver(JSON.parse(event.newValue));
        } catch {
            // Not a report.
        }
    };

    const closedForReal = (): void => {
        if (done || closedHandled) {
            return;
        }
        closedHandled = true;
        window.clearTimeout(graceTimer);
        attention.forEach((name) =>
            window.removeEventListener(name, onAttention, true),
        );
        router.reload();
        toast.info(CONNECT_CLOSED_TEXT, { id: 'tantra-connect-closed' });
    };

    onAttention = (): void => {
        if (closedSeen) {
            closedForReal();
        }
    };

    window.addEventListener('message', onMessage);
    window.addEventListener('storage', onStorage);
    if (channel) {
        channel.onmessage = (event: MessageEvent) => deliver(event.data);
    }

    if (popup) {
        pollTimer = window.setInterval(() => {
            let closed = false;
            try {
                closed = popup.closed;
            } catch {
                closed = true;
            }
            if (!closed || done) {
                return;
            }
            closedSeen = true;
            window.clearInterval(pollTimer);
            if (document.hasFocus()) {
                closedForReal();

                return;
            }
            attention.forEach((name) =>
                window.addEventListener(name, onAttention, true),
            );
            graceTimer = window.setTimeout(closedForReal, CLOSED_GRACE_MS);
        }, 1000);
    }

    limitTimer = window.setTimeout(stop, CONNECT_WAIT_LIMIT_MS);

    stopWaiting = stop;
};

/**
 * Opens a connect page in a popup (embedded mode). When the popup lands on
 * the connected channel it reports and closes; the frame then shows the
 * Channels tab with the new channels. If the popup is blocked, a toast
 * offers the same flow in a new tab, which reports back the same way.
 */
export const openConnectWindow = (url: string): Window | null => {
    const nonce = createConnectNonce();
    const popup = window.open(
        connectWindowUrl(url, nonce),
        CONNECT_WINDOW,
        'width=600,height=760',
    );

    if (!popup) {
        toast.warning('Your browser blocked the sign in window.', {
            id: 'tantra-connect-blocked',
            action: {
                label: 'Continue in a new tab',
                onClick: () => {
                    const tabNonce = createConnectNonce();
                    window.open(
                        connectWindowUrl(url, tabNonce),
                        '_blank',
                        'noopener',
                    );
                    waitForConnect(null, tabNonce);
                },
            },
        });

        return null;
    }

    try {
        popup.focus();
    } catch {
        // Severed already: nothing to focus.
    }
    waitForConnect(popup, nonce);

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

/**
 * The nonce the bootstrap page marked this tab with, else null. Read from
 * sessionStorage first (kept through a COOP swap), then window.name.
 */
export const connectWindowNonce = (): string | null => {
    if (typeof window === 'undefined' || isEmbedded()) {
        return null;
    }
    try {
        const stored = window.sessionStorage.getItem(CONNECT_KEY);
        if (stored && CONNECT_NONCE.test(stored)) {
            return stored;
        }
    } catch {
        // Storage blocked: fall through.
    }
    const prefix = `${CONNECT_WINDOW}:`;
    const named = window.name.startsWith(prefix)
        ? window.name.slice(prefix.length)
        : '';

    return CONNECT_NONCE.test(named) ? named : null;
};

export const isConnectWindow = (): boolean => connectWindowNonce() !== null;

export type ConnectOutcome =
    | { kind: 'wait' }
    | {
          kind: 'connected';
          account: string;
          created: boolean;
          channels: string[];
      }
    | { kind: 'left' };

/**
 * What a page in the popup means. A connection step or its outcome page
 * (including every failure reason): wait. The connected channel's page,
 * known by the connectedChannel flash or /channels/{id}/publish: connected,
 * with every channel the connection created (connectedChannels flash).
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
    const flashed = flash as
        | {
              connectedChannel?: { accountId?: unknown; created?: unknown };
              connectedChannels?: unknown;
          }
        | undefined;
    const connected = flashed?.connectedChannel;
    const fromFlash =
        typeof connected?.accountId === 'string' &&
        CHANNEL_ID.test(connected.accountId)
            ? connected.accountId
            : undefined;
    const account = fromFlash ?? fromUrl;
    if (!account) {
        return { kind: 'left' };
    }
    const channels = Array.isArray(flashed?.connectedChannels)
        ? flashed.connectedChannels.filter(
              (id): id is string =>
                  typeof id === 'string' && CHANNEL_ID.test(id),
          )
        : [];

    return {
        kind: 'connected',
        account,
        created: connected?.created === true,
        channels,
    };
};

/** Sends the report on every path the frame listens on. */
const sendConnectReport = (report: ConnectReport): void => {
    if ('BroadcastChannel' in window) {
        const channel = new BroadcastChannel(CONNECT_KEY);
        channel.postMessage(report);
        window.setTimeout(() => channel.close(), 1000);
    }
    try {
        window.localStorage.setItem(
            CONNECT_KEY,
            JSON.stringify({ ...report, sentAt: Date.now() }),
        );
        window.localStorage.removeItem(CONNECT_KEY);
    } catch {
        // Storage blocked: the BroadcastChannel carried it.
    }
    try {
        window.opener?.postMessage(report, window.location.origin);
    } catch {
        // Severed or gone: the paths above carried it.
    }
};

/** After a COOP swap the popup may not close itself: say it is safe to. */
const showCloseNotice = (connected: boolean): void => {
    if (document.querySelector('[data-testid="connect-window-done"]')) {
        return;
    }
    const notice = document.createElement('div');
    notice.dataset.testid = 'connect-window-done';
    notice.setAttribute('role', 'status');
    notice.style.cssText =
        'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;padding:24px;background:#ffffff;color:#1f1f1e;font:500 16px/1.5 system-ui,sans-serif;text-align:center;';
    notice.textContent = connected
        ? 'Connected. You can close this window.'
        : 'You can close this window.';
    document.body.appendChild(notice);
};

let connectReported = false;

const reportConnected = (page: Page, nonce: string): void => {
    if (connectReported) {
        return;
    }
    const outcome = connectOutcome(page.component, page.url, page.flash);
    if (outcome.kind === 'wait') {
        return;
    }
    connectReported = true;

    sendConnectReport(
        outcome.kind === 'connected'
            ? {
                  v: 1,
                  type: 'tantra:connected',
                  nonce,
                  account: outcome.account,
                  created: outcome.created,
                  channels: outcome.channels,
              }
            : { v: 1, type: 'tantra:connect-left', nonce },
    );

    // This tab is an ordinary Engine tab from here on.
    try {
        window.sessionStorage.removeItem(CONNECT_KEY);
    } catch {
        // Storage blocked.
    }
    if (window.name.startsWith(`${CONNECT_WINDOW}:`)) {
        window.name = '';
    }

    // A moment first, so the report leaves before the window goes.
    window.setTimeout(() => {
        window.close();
        window.setTimeout(() => {
            if (!window.closed) {
                showCloseNotice(outcome.kind === 'connected');
            }
        }, 300);
    }, 150);
};

/** The connect popup (opened by openConnectWindow): report back and close. */
export const startConnectWindow = (initialPage: Page): void => {
    const nonce = connectWindowNonce();
    if (nonce === null) {
        return;
    }

    router.on('navigate', (event) => reportConnected(event.detail.page, nonce));
    router.on('success', (event) => reportConnected(event.detail.page, nonce));
    reportConnected(initialPage, nonce);
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
