// tantra: the phone feed preview, ported from tantra-content kits/content/studio
// (core.js, app.js and x.js) so a post here looks exactly like it did in the
// static studio. Every value is escaped before it lands in the markup.

import { CORE_I, LI_I, X_I } from '@/components/tantra/review/studioIcons';
import type {
    ReviewItemRow,
    ReviewProfile,
    TeamComment,
} from '@/components/tantra/review/types';

export const esc = (s: unknown): string =>
    String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

const URL_RE =
    /(https?:\/\/[^\s<]+|\b(?:[a-z0-9-]+\.)+(?:com|ai|io|net|org|co|uk|app|dev)\b(?:\/[^\s<]*)?)/gi;

function linkify(html: string, cls: string): string {
    return html.replace(URL_RE, (match: string) => {
        let m = match;
        let trail = '';
        const t = m.match(/[.,;:!?)]+$/);
        if (t) {
            trail = t[0];
            m = m.slice(0, -trail.length);
        }
        if (!m) {
            return m + trail;
        }
        const href = /^https?:/i.test(m) ? m : 'https://' + m;

        return `<a class="${cls || 'lnk'}" href="${esc(href)}" target="_blank" rel="noopener">${esc(m)}</a>${trail}`;
    });
}

/* "@Name" for a tagged page renders bold blue without the @, the way LinkedIn shows a tag. */
function rich(text: string, cls: string, mentions: string[]): string {
    const names = mentions
        .filter(Boolean)
        .map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    let html = esc(text);
    if (names.length) {
        const re = new RegExp(`@(${names.join('|')})`, 'g');
        html = html
            .replace(re, '\u0001$1\u0002')
            .replace(/\u0001[^\u0002]*\u0002/g, (m) =>
                m.replace(/\./g, '\u0003'),
            );
    }
    html = linkify(html, cls);

    return html.replace(
        /\u0001([^\u0002]*)\u0002/g,
        (_, n: string) =>
            `<span class="mention" title="Tag: company page">${n.replace(/\u0003/g, '.')}</span>`,
    );
}

const statusBar = (dark = false): string =>
    `<div class="sb${dark ? ' dark' : ''}"><span class="sb-time">9:41</span><span class="sb-icons">${CORE_I.signal}${CORE_I.wifi}${CORE_I.battery}</span></div>`;
const homeBar = (dark = false): string =>
    `<div class="home${dark ? ' dark' : ''}"></div>`;

export const phone = (inner: string, cls: string): string =>
    `<div class="phone-wrap"><div class="phone"><div class="screen ${cls}">${inner}</div><div class="island"></div></div></div>`;

interface AvatarSource {
    name?: string;
    avatar?: string | null;
    kind?: string;
}

const avatar = (pr: AvatarSource, size: number): string => {
    const co = pr.kind === 'company';

    return `<span class="av${co ? ' sq' : ''}" style="width:${size}px;height:${size}px;border-radius:${co ? '6px' : '50%'};background:#9aa3b2">${pr.avatar ? `<img src="${esc(pr.avatar)}" alt="">` : `<b style="color:#fff;font-size:${Math.round(size * 0.4)}px">${esc((pr.name || '?')[0])}</b>`}</span>`;
};

/* ---------- LinkedIn for iOS ---------- */

function mediaHtml(p: ReviewItemRow): string {
    const m = p.media;
    const files = m.files ?? [];
    if (m.type === 'image' && files.length) {
        return `<div class="li-imgpost${m.tall ? ' tall' : ''}"><img loading="lazy" src="${esc(files[0])}" alt="${esc(p.engine_post_id)}" data-lb="${esc(files[0])}" data-lb-caption="${esc(p.engine_post_id)} · ${esc(p.idea || '')}"></div>`;
    }
    if (m.type === 'video' && m.video) {
        return `<div class="li-imgpost li-vid${m.tall ? ' tall' : ''}"><video src="${esc(m.video)}"${files[0] ? ` poster="${esc(files[0])}"` : ''} autoplay loop muted playsinline preload="metadata" aria-label="${esc(p.engine_post_id)} animation"></video><span class="li-gif">GIF</span></div>`;
    }
    // A video whose file is not reachable yet still shows its first frame.
    if (m.type === 'video' && files.length) {
        return `<div class="li-imgpost${m.tall ? ' tall' : ''}"><img loading="lazy" src="${esc(files[0])}" alt="${esc(p.engine_post_id)}" data-lb="${esc(files[0])}"></div>`;
    }
    if (m.type === 'carousel' && files.length) {
        const g = 'car-' + p.id;
        const n = files.length;
        const slides = files
            .map(
                (f, i) =>
                    `<div class="li-slide"><img loading="lazy" src="${esc(f)}" alt="${esc(p.engine_post_id)} page ${i + 1}" data-lb="${esc(f)}" data-lb-group="${g}" data-lb-caption="${esc(m.title || p.engine_post_id)} · page ${i + 1} of ${n}"></div>`,
            )
            .join('');

        return `<div class="li-doc"><div class="li-doc-head"><span class="li-doc-title">${esc(m.title || p.idea || '')}</span><span class="li-doc-pages">${n} pages</span></div>
        <div class="li-doc-view"><div class="li-slides" data-pages="${n}">${slides}</div><span class="li-pg">1 / ${n}</span><span class="li-exp" title="View full screen">${LI_I.expand}</span><button class="li-arr prev" data-dir="-1" title="Previous slide">‹</button><button class="li-arr next" data-dir="1" title="Next slide">›</button></div></div>`;
    }
    if (m.type === 'poll' && m.poll) {
        return `<div class="li-poll"><div class="li-poll-q">${esc(m.poll.question)}</div><div class="li-poll-s">The author can see how you vote. <span style="color:#0a66c2;font-weight:600">Learn more</span></div>${(m.poll.options || []).map((o) => `<button class="li-poll-o">${esc(o)}</button>`).join('')}<div class="li-poll-f">0 votes · 1w left</div></div>`;
    }
    if (m.type === 'pending') {
        return `<div class="wipbox"><b>Media in production</b>${p.format ? `<span>${esc(p.format)}</span>` : ''}${p.visual_copy.length ? `<ol>${p.visual_copy.map((v) => `<li>${esc(v)}</li>`).join('')}</ol>` : ''}</div>`;
    }

    return '';
}

function postText(p: ReviewItemRow): string {
    const html = rich(p.text, 'li-link', p.mentions)
        .replace(/(^|\s)(#[A-Za-z0-9_]+)/g, '$1<span class="li-tag">$2</span>')
        .replace(/\n/g, '<br>');

    return `<div class="li-text clamp">${html}<span class="li-more" title="Show the full post">…more</span></div>`;
}

function commentHtml(p: ReviewItemRow, pr: ReviewProfile): string {
    const tc = p.team_comments.filter((c) => !c.excluded);
    if (!p.first_comment && !tc.length) {
        return '';
    }

    const bubble = (
        who: AvatarSource & { headline?: string },
        isAuthor: boolean,
        text: string,
        reply: boolean,
    ): string =>
        `<div class="li-cm${reply ? ' li-rep' : ''}">${avatar(who, reply ? 24 : 32)}<div class="li-cm-b"><div class="li-cm-bubble"><div class="li-cm-n">${esc(who.name || '')}${isAuthor ? ' <span class="li-auth">Author</span>' : ' <span class="li-deg">· 1st</span>'}</div><div class="li-cm-h">${esc(who.headline || '')}</div><div class="li-cm-t">${rich(text, 'li-link', p.mentions).replace(/\n/g, '<br>')}</div></div><div class="li-cm-a">Like · Reply</div></div></div>`;

    const person = (c: TeamComment): AvatarSource & { headline?: string } =>
        c.by === p.author_key
            ? {
                  name: pr.name,
                  avatar: pr.avatar,
                  kind: pr.kind,
                  headline: pr.headline,
              }
            : {
                  name: c.name || c.by,
                  avatar: c.avatar ?? null,
                  kind: 'person',
                  headline: c.headline,
              };

    // Each team comment, then the replies in its thread (the author answers every one, RULES 0c).
    const tops = tc.filter(
        (c) => c.reply_to === null || c.reply_to === undefined,
    );
    const thread = tops
        .map((c) => {
            const replies = tc.filter((r) => r.reply_to === c.index);

            return (
                bubble(person(c), c.by === p.author_key, c.text, false) +
                replies
                    .map((r) =>
                        bubble(person(r), r.by === p.author_key, r.text, true),
                    )
                    .join('')
            );
        })
        .join('');
    const first = p.first_comment
        ? bubble(
              {
                  name: pr.name,
                  avatar: pr.avatar,
                  kind: pr.kind,
                  headline: pr.headline,
              },
              true,
              p.first_comment,
              false,
          )
        : '';

    return `<div class="li-cmts"><div class="li-cm-sort">Most relevant ▾</div>${first}${thread}</div>`;
}

export function linkedinScreen(p: ReviewItemRow, viewer: AvatarSource): string {
    const pr = p.profile;
    const isCo = pr.kind === 'company';
    const top = `<div class="li-top">${avatar(viewer, 32)}<div class="li-search">${LI_I.search}<span>Search</span></div><span class="li-ic">${LI_I.msg}</span></div>`;
    const tabs = `<div class="li-tabs"><div class="li-tab on">${LI_I.home}<span>Home</span></div><div class="li-tab">${LI_I.net}<span>My Network</span></div><div class="li-tab">${LI_I.post}<span>Post</span></div><div class="li-tab">${LI_I.bell}<span>Notifications</span></div><div class="li-tab">${LI_I.jobs}<span>Jobs</span></div></div>`;
    const post = `<article class="li-post">
      <div class="li-head">${avatar(pr, 48)}<div class="li-who"><div class="li-name">${esc(pr.name)}${isCo ? '' : ' <span class="li-deg">· 1st</span>'}</div><div class="li-sub">${esc(isCo ? pr.followers_line || pr.headline || '' : pr.headline || '')}</div><div class="li-sub">Now · ${LI_I.globe}</div></div>${isCo ? '<span class="li-follow" style="color:#0a66c2;font-weight:600;font-size:15px;white-space:nowrap;align-self:flex-start;padding-top:2px">+ Follow</span>' : `<span class="li-ic">${LI_I.more}</span><span class="li-ic">${LI_I.x}</span>`}</div>
      ${postText(p)}${mediaHtml(p)}
      <div class="li-actions"><span>${LI_I.like}Like</span><span>${LI_I.comment}Comment</span><span>${LI_I.repost}Repost</span><span>${LI_I.send}Send</span></div>
      ${commentHtml(p, pr)}
    </article>`;

    return `${statusBar()}${top}<div class="scroll li-feed">${post}</div>${tabs}${homeBar()}`;
}

/* ---------- X for iOS ---------- */

/* X weighted length (twitter-text v3): URLs count 23; U+0000-10FF, 2000-200D, 2010-201F, 2032-2037 weigh 1, the rest 2. */
const LIGHT: Array<[number, number]> = [
    [0x0000, 0x10ff],
    [0x2000, 0x200d],
    [0x2010, 0x201f],
    [0x2032, 0x2037],
];
const wch = (s: string): number =>
    [...s].reduce((n, c) => {
        const cp = c.codePointAt(0) ?? 0;

        return n + (LIGHT.some(([a, b]) => cp >= a && cp <= b) ? 1 : 2);
    }, 0);

export function xWeight(text: string): number {
    let n = 0;
    let last = 0;
    const re = /https?:\/\/\S+/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(text))) {
        n += wch(text.slice(last, m.index)) + 23;
        last = m.index + m[0].length;
    }

    return n + wch(text.slice(last));
}

function xBody(text: string, mentions: string[]): string {
    let html = rich(text, 'x-link', mentions);
    html = html.replace(
        /(<a class="x-link"[^>]*>)([^<]+)(<\/a>)/g,
        (_, a: string, t: string, z: string) => {
            let d = t.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
            if (d.length > 26) {
                d = d.slice(0, 25) + '…';
            }

            return a + esc(d).replace(/&amp;/g, '&') + z;
        },
    );

    return html
        .replace(
            /(^|\s)([#@][A-Za-z0-9_]+)/g,
            '$1<span class="x-tag">$2</span>',
        )
        .replace(/\n/g, '<br>');
}

function xMediaFor(p: ReviewItemRow, idx: number): string {
    const m = p.media;
    const files = m.files ?? [];
    const g = 'x-' + p.id;
    const im = (f: string, i: number): string =>
        `<img loading="lazy" src="${esc(f)}" alt="${esc(p.engine_post_id)} image ${i + 1}" data-lb="${esc(f)}" data-lb-group="${g}" data-lb-caption="${esc(p.engine_post_id)} · image ${i + 1} of ${files.length}">`;
    if (m.type === 'pending') {
        return idx === 0 ? '<div class="wipbox">Media in production</div>' : '';
    }
    if (m.type === 'images' && m.placement === 'thread') {
        return files[idx]
            ? `<div class="x-media">${im(files[idx], idx)}</div>`
            : '';
    }
    if (idx !== 0 || !files.length) {
        return '';
    }
    if (files.length === 1) {
        return `<div class="x-media">${im(files[0], 0)}</div>`;
    }

    return `<div class="x-media x-grid n${Math.min(files.length, 4)}">${files.slice(0, 4).map(im).join('')}</div>`;
}

function xOne(p: ReviewItemRow, text: string, i: number, n: number): string {
    const pr = p.profile;
    const w = xWeight(text);
    const over = w > 280;

    return `<article class="x-post${i < n - 1 ? ' has-next' : ''}">
      <div class="x-l"><span class="x-av">${pr.avatar ? `<img src="${esc(pr.avatar)}" alt="">` : `<b>${esc((pr.name || '?')[0])}</b>`}</span></div>
      <div class="x-r">
        <div class="x-h"><span class="x-name">${esc(pr.name)}</span><span class="x-handle">${esc(pr.x_handle || '')} · 1m</span><span class="x-more">${X_I.more}</span></div>
        <div class="x-text">${xBody(text, p.mentions)}</div>
        ${xMediaFor(p, i)}
        <div class="x-acts"><span>${X_I.reply}</span><span>${X_I.repost}</span><span>${X_I.like}</span><span>${X_I.views}</span><span class="x-end">${X_I.mark}${X_I.share}</span></div>
        <div class="x-cc${over ? ' over' : ''}" title="Review aid, not part of X: weighted X character count (URLs count 23)">${n > 1 ? `${i + 1}/${n} · ` : ''}${w} / 280${over ? ' · over the limit' : ''}</div>
      </div>
    </article>`;
}

export function xScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const posts = [p.text, ...p.thread];
    const top = `<div class="x-top"><span class="x-av sm">${pr.avatar ? `<img src="${esc(pr.avatar)}" alt="">` : ''}</span><span class="x-logo">${X_I.logo}</span><span class="x-sp"></span></div>
      <div class="x-tabs"><span class="on">For you</span><span>Following</span></div>`;
    const tabs = `<div class="x-nav"><span>${X_I.home}</span><span>${X_I.search}</span><span>${X_I.people}</span><span>${X_I.bell}</span><span>${X_I.mail}</span></div>`;

    return `${statusBar()}${top}<div class="scroll x-feed">${posts.map((t, i) => xOne(p, t, i, posts.length)).join('')}<div class="x-endpad"></div></div><span class="x-fab">${X_I.plus}</span>${tabs}${homeBar()}`;
}

/* ---------- Any other network, until its own look is built ---------- */

export function plainScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const files = p.media.files ?? [];

    return `${statusBar()}<div class="scroll" style="background:#fff;padding:16px;font:15px/1.45 -apple-system,system-ui,sans-serif;color:#111">
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:12px">${avatar(pr, 40)}<b>${esc(pr.name)}</b></div>
      <div>${esc(p.text).replace(/\n/g, '<br>')}</div>
      ${files[0] ? `<img src="${esc(files[0])}" alt="" style="width:100%;border-radius:10px;margin-top:12px" data-lb="${esc(files[0])}">` : ''}
    </div>${homeBar()}`;
}

/* ---------- Facebook, Instagram, YouTube, Threads and TikTok (PRD 25.8) ----------
   Simple, recognisable screens for the small previews on the Ideas board and for
   review: who posts, the text with "more", and the media in that app's frame.
   Styles in css/studio-networks.css. */

const NET_I = {
    heart: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>',
    bubble: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-3.3-6.5A8 8 0 0 1 20 12zM20 20l-3.3-2.4"/></svg>',
    plane: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M21 3L3 10l7 3 3 7z"/><path d="M10 13l11-10"/></svg>',
    save: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M6 3h12v18l-6-5-6 5z"/></svg>',
    repost: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V7a2 2 0 0 1 2-2h11l-3-3M20 15v2a2 2 0 0 1-2 2H7l3 3"/></svg>',
    thumb: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M7 10v11H3V10zM7 10l4.5-7a2 2 0 0 1 2 2.5L12.7 10H19a2 2 0 0 1 2 2.4l-1.6 6.4A2.5 2.5 0 0 1 17 21H7"/></svg>',
    share: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M14 4l7 7-7 7v-4c-5 0-8 1.5-11 5 1-5 4-9 11-10z"/></svg>',
    search: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
    play: '<svg width="44" height="44" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>',
};

const firstLine = (s: string): string =>
    (s || '').split('\n').find((l) => l.trim()) ?? '';

const lines = (text: string): string => esc(text).replace(/\n/g, '<br>');

/** Escaped text, line breaks kept, clamped by CSS with a "more" at the end. */
function netText(text: string, cls: string, more: string): string {
    if (!text) {
        return '';
    }

    return `<div class="${cls} nclamp">${lines(text)}<span class="net-more" title="Show the full post">${esc(more)}</span></div>`;
}

type Frame = 'wide' | 'square' | 'portrait' | 'tall' | 'free';

/** The media in a frame of the given shape: the video, the first image or a placeholder. */
function netMedia(p: ReviewItemRow, shape: Frame, dark = false): string {
    const m = p.media;
    const files = m.files ?? [];
    const cls = `net-media ${shape}${dark ? ' dark' : ''}`;
    if (m.type === 'video' && m.video) {
        return `<div class="${cls}"><video src="${esc(m.video)}"${files[0] ? ` poster="${esc(files[0])}"` : ''} muted playsinline loop autoplay preload="metadata"></video></div>`;
    }
    if (files.length) {
        const count =
            files.length > 1
                ? `<span class="net-count">1/${files.length}</span>`
                : '';
        const play =
            m.type === 'video'
                ? `<span class="net-play">${NET_I.play}</span>`
                : '';

        return `<div class="${cls}"><img loading="lazy" src="${esc(files[0])}" alt="${esc(p.engine_post_id)}" data-lb="${esc(files[0])}">${count}${play}</div>`;
    }
    if (m.type && m.type !== 'text') {
        const label =
            m.type === 'pending' ? 'Media in production' : p.format || m.type;

        return `<div class="${cls} empty"><span>${esc(label)}</span></div>`;
    }

    return '';
}

const netName = (pr: ReviewProfile): string => esc(pr.name || '');
const netHandle = (pr: ReviewProfile): string =>
    esc(
        (pr.x_handle || pr.name || '')
            .replace(/^@/, '')
            .replace(/\s+/g, '')
            .toLowerCase(),
    );

export function facebookScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const top = `<div class="fb-top"><span class="fb-logo">facebook</span><span class="net-sp"></span><span class="fb-ic">${NET_I.search}</span></div>`;
    const post = `<article class="fb-post">
      <div class="fb-head">${avatar(pr, 40)}<div class="fb-who"><div class="fb-name">${netName(pr)}</div><div class="fb-sub">Just now · ${LI_I.globe}</div></div><span class="net-dots">···</span></div>
      ${netText(p.text, 'fb-text', '... See more')}
      ${netMedia(p, p.media.tall ? 'portrait' : 'free')}
      <div class="fb-acts"><span>${NET_I.thumb}Like</span><span>${NET_I.bubble}Comment</span><span>${NET_I.share}Share</span></div>
    </article>`;

    return `${statusBar()}${top}<div class="scroll fb-feed">${post}</div>${homeBar()}`;
}

/** Instagram leads with the media (square, or 4:5 for a tall post), the caption under it. */
export function instagramScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const top = `<div class="ig-top"><span class="ig-logo">Instagram</span><span class="net-sp"></span>${NET_I.heart}${NET_I.plane}</div>`;
    const media =
        netMedia(p, p.media.tall ? 'portrait' : 'square') ||
        `<div class="net-media square empty"><span>${esc(firstLine(p.text))}</span></div>`;
    const caption = p.text
        ? `<div class="ig-cap nclamp"><b>${netHandle(pr)}</b> ${lines(p.text)}<span class="net-more">... more</span></div>`
        : '';
    const post = `<article class="ig-post">
      <div class="ig-head">${avatar(pr, 32)}<span class="ig-name">${netHandle(pr)}</span><span class="net-sp"></span><span class="net-dots">···</span></div>
      ${media}
      <div class="ig-acts">${NET_I.heart}${NET_I.bubble}${NET_I.plane}<span class="net-sp"></span>${NET_I.save}</div>
      ${caption}
    </article>`;

    return `${statusBar()}${top}<div class="scroll ig-feed">${post}</div>${homeBar()}`;
}

/** YouTube: a Short (9:16, dark) for a tall or short-format post, else a 16:9 video with its title. */
export function youtubeScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const title = p.media.title || p.idea || firstLine(p.text);
    const short = p.media.tall || /short/i.test(p.format || '');
    if (short) {
        const frame =
            netMedia(p, 'tall', true) ||
            '<div class="net-media tall dark empty"></div>';

        return `${statusBar(true)}<div class="scroll net-full">${frame}
          <div class="net-side">${NET_I.thumb}<span>Like</span>${NET_I.bubble}<span>0</span>${NET_I.share}<span>Share</span></div>
          <div class="net-over"><div class="yt-who">${avatar(pr, 32)}<b>@${netHandle(pr)}</b><span class="yt-sub">Subscribe</span></div><div class="yt-short-title">${esc(title)}</div></div>
        </div>${homeBar(true)}`;
    }
    const frame =
        netMedia(p, 'wide') ||
        `<div class="net-media wide empty"><span class="net-play">${NET_I.play}</span></div>`;

    return `${statusBar()}<div class="yt-top"><span class="yt-logo"><i></i>YouTube</span></div><div class="scroll yt-feed">
      ${frame}
      <div class="yt-title">${esc(title)}</div>
      <div class="yt-meta">No views · Just now</div>
      <div class="yt-chan">${avatar(pr, 36)}<b>${netName(pr)}</b><span class="net-sp"></span><span class="yt-sub">Subscribe</span></div>
      ${netText(p.text, 'yt-desc', '...more')}
    </div>${homeBar()}`;
}

/** Threads: like X, with 500 characters a post. */
export function threadsScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const posts = [p.text, ...p.thread].filter((t) => t !== '');
    const one = (text: string, i: number, all: string[]): string => {
        const n = [...text].length;
        const over = n > 500;

        return `<article class="th-post${i < all.length - 1 ? ' has-next' : ''}">
          <div class="th-l">${avatar(pr, 36)}</div>
          <div class="th-r"><div class="th-h"><b>${netHandle(pr)}</b><span class="th-time">1m</span></div>
          <div class="th-text">${lines(text)}</div>
          ${i === 0 ? netMedia(p, 'free') : ''}
          <div class="th-acts">${NET_I.heart}${NET_I.bubble}${NET_I.repost}${NET_I.plane}</div>
          <div class="th-cc${over ? ' over' : ''}" title="Review aid, not part of Threads: characters in this post">${n} / 500${over ? ' · over the limit' : ''}</div></div>
        </article>`;
    };

    return `${statusBar()}<div class="th-top"><span class="th-logo">@</span></div><div class="scroll th-feed">${(posts.length ? posts : ['']).map(one).join('')}</div>${homeBar()}`;
}

/** TikTok: a 9:16 video with the caption over it. */
export function tiktokScreen(p: ReviewItemRow): string {
    const pr = p.profile;
    const frame =
        netMedia(p, 'tall', true) ||
        '<div class="net-media tall dark empty"></div>';
    const caption = p.text
        ? `<div class="tt-cap nclamp">${lines(p.text)}<span class="net-more">more</span></div>`
        : '';

    return `${statusBar(true)}<div class="scroll net-full">${frame}
      <div class="tt-tabs"><span>Following</span><span class="on">For You</span></div>
      <div class="net-side">${avatar(pr, 44)}${NET_I.heart}<span>0</span>${NET_I.bubble}<span>0</span>${NET_I.save}<span>0</span>${NET_I.share}<span>Share</span></div>
      <div class="net-over"><b>@${netHandle(pr)}</b>${caption}</div>
    </div>${homeBar(true)}`;
}
