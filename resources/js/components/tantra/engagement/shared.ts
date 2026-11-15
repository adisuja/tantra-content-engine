// tantra: shapes and words shared by the Engagement tab. They mirror the CRM's Engagement screen
// (tantra-crm crm-app/src/pages/EngagementPage.tsx and EngagementV2.tsx) so both apps read the same.
import { trans } from 'laravel-vue-i18n';

export type TriggerKey =
    | 'keyword'
    | 'commenters'
    | 'likers'
    | 'sharers'
    | 'visitors';

export interface Asset {
    name: string;
    keyword?: string | null;
    url?: string | null;
    dm_message?: string | null;
    connection_note?: string | null;
    email_subject?: string | null;
    email_body?: string | null;
    first_comment?: string | null;
}

export interface Source {
    id: string;
    platform: string;
    kind: 'post' | 'account';
    title: string | null;
    post_url: string | null;
    sender_id: string | null;
    triggers: Record<TriggerKey, boolean>;
    keyword: string | null;
    asset: Asset | null;
    enabled: boolean;
    sequence_id: string | null;
    engaged_sequence_id?: string | null;
    last_polled_at: string | null;
    poll_error: string | null;
    options?: { started_at: string | null; backfill?: string };
    stats?: {
        people: number;
        reactions: number;
        comments: number;
        keywords: number;
        shares: number;
        team?: number;
        prospects?: number;
    };
}

export interface SeqBrief {
    id: string;
    name: string;
    status: string;
    dry_run: boolean;
    steps?: number;
}

export interface Preview {
    adapter: string;
    platforms: string[];
    live: boolean;
    route: string;
    comment_reply: string | null;
    dm: string | null;
    connection_note: string | null;
}

export interface SendPreview {
    dry_run: boolean;
    sequences: SeqBrief[];
    counts: { people: number; would_send: number; skipped: number };
    people: {
        name: string | null;
        url: string | null;
        trigger: string;
        degree: string | null;
        backfilled: boolean;
        action: string | null;
        message: string | null;
        skip: string | null;
        skip_reason: string | null;
        steps?: {
            channel: string;
            action: string;
            subject: string | null;
            message: string;
            waits: string | null;
        }[];
    }[];
}

export interface Quota {
    account_id: string;
    account_name: string;
    caps: Record<string, number>;
    used: Record<string, number>;
}

export interface Pending {
    state:
        | 'due'
        | 'waiting'
        | 'done'
        | 'replied'
        | 'booked'
        | 'stopped'
        | 'team'
        | 'none';
    label: string;
    channel: string | null;
    action: string | null;
    due_at: string | null;
    test_mode: boolean;
    sequence_id: string | null;
    sequence_name: string | null;
}

export interface PostRef {
    id: string;
    title: string;
    post_url: string | null;
}

export interface Enrichment {
    company?: string;
    title?: string;
    best_email?: string;
    email_confidence?: number;
}

export type Kind = 'requested' | 'commenters' | 'likers' | 'sharers' | 'team';

export interface PersonRow {
    pkey: string;
    name: string | null;
    headline: string | null;
    url: string | null;
    deal_id: string | null;
    kinds: string[];
    network_distance: string | null;
    team: boolean;
    comment: string | null;
    stage: string | null;
    pending: Pending | null;
    suppressed: boolean;
    enrichment: Enrichment | null;
    activity?: {
        source: PostRef;
        kind: string;
        text: string | null;
        at: string;
    }[];
}

export interface PeopleResp {
    kind: string;
    counts: Record<Kind | 'all', number>;
    total: number;
    people: PersonRow[];
}

export interface Card {
    deal_id: string;
    name: string | null;
    url: string | null;
    stage_id: string;
    trigger: string | null;
    post: PostRef | null;
    pending: Pending;
}

export interface Board {
    pipeline_id: string;
    total: number;
    stages: {
        id: string;
        name: string;
        color: string | null;
        count: number;
        cards: Card[];
        starts: { id: string; name: string }[];
    }[];
}

export interface Step {
    id: string;
    channel: string;
    action: string;
    wait: { amount: number; unit: 'minutes' | 'hours' | 'days' | 'weeks' };
    body?: string;
    subject?: string;
    [key: string]: unknown;
}

export interface Sequence {
    id: string;
    name: string;
    status: 'draft' | 'active' | 'paused';
    steps: Step[];
    settings: { dry_run?: boolean; [key: string]: unknown };
    stage_name?: string;
    active_count?: number;
    sent_count?: number;
}

export type BoardSeq = Sequence & {
    posts: { id: string; title: string; enabled: boolean; role: string }[];
    all_posts: boolean;
};

export interface SeqBoard {
    pipeline_id: string;
    stages: {
        id: string;
        name: string;
        color: string | null;
        deal_count: number;
        sequences: BoardSeq[];
    }[];
}

export const STAGES = [
    'Engaged',
    'Requested',
    'Invite sent',
    'Connected',
    'Delivered',
    'Replied',
    'Booked',
] as const;

export const STAGE_TONE: Record<string, string> = {
    Engaged: 'bg-slate-100 text-slate-700',
    Requested: 'bg-blue-50 text-blue-700',
    'Invite sent': 'bg-violet-50 text-violet-700',
    Connected: 'bg-sky-50 text-sky-700',
    Delivered: 'bg-teal-50 text-teal-700',
    Replied: 'bg-amber-50 text-amber-800',
    Booked: 'bg-emerald-50 text-emerald-700',
};

export const DEGREE: Record<string, string> = {
    first: '1st',
    second: '2nd',
    third: '3rd',
    out_of_network: '3rd+',
};

export const kindLabel = (k: string): string => {
    const key = `tantra.engagement.kinds.${k}`;
    const t = trans(key);

    return t === key ? k : t;
};

export const sourceTitle = (s: {
    title: string | null;
    post_url: string | null;
    asset?: { name: string } | null;
}): string =>
    s.title ||
    s.asset?.name ||
    s.post_url ||
    trans('tantra.engagement.posts.untitled');

const noun = (channel: string | null, action: string | null): string => {
    const k =
        action === 'comment_reply'
            ? 'reply'
            : action === 'connection_request'
              ? 'invite'
              : action === 'visit_profile'
                ? 'visit'
                : channel === 'linkedin'
                  ? 'dm'
                  : channel === 'email'
                    ? 'email'
                    : channel === 'whatsapp'
                      ? 'whatsapp'
                      : channel === 'sms'
                        ? 'sms'
                        : channel === 'call'
                          ? 'call'
                          : channel === 'task'
                            ? 'task'
                            : 'message';

    return trans(`tantra.engagement.nouns.${k}`);
};
export const stepNoun = noun;

/** "due now", "due 9:00", "due tomorrow", "in 2 days", in the viewer's own time. */
export function dueLocal(at: string | null): string {
    if (!at) {
        return '';
    }
    const t = new Date(at);
    const d = t.getTime() - Date.now();
    if (d <= 60_000) {
        return trans('tantra.engagement.due.now');
    }
    if (d < 3_600_000) {
        return trans('tantra.engagement.due.minutes', {
            count: String(Math.ceil(d / 60_000)),
        });
    }
    const start = (x: Date) =>
        new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const days = Math.round((start(t) - start(new Date())) / 86_400_000);
    if (days === 0) {
        return trans('tantra.engagement.due.at', {
            time: t.toLocaleTimeString([], {
                hour: 'numeric',
                minute: '2-digit',
            }),
        });
    }
    if (days === 1) {
        return trans('tantra.engagement.due.tomorrow');
    }

    return trans('tantra.engagement.due.days', { count: String(days) });
}

export const pendingText = (p: Pending): string =>
    p.state === 'due' && p.due_at
        ? `${noun(p.channel, p.action)} ${dueLocal(p.due_at)}`
        : p.label;

export const PENDING_TONE: Record<Pending['state'], string> = {
    due: 'text-primary-strong',
    waiting: 'text-amber-700',
    done: 'text-emerald-700',
    replied: 'text-emerald-700',
    booked: 'text-emerald-700',
    stopped: 'text-muted-foreground',
    team: 'text-muted-foreground',
    none: 'text-muted-foreground',
};

export const confidenceTone = (v: number): string =>
    v >= 75
        ? 'bg-emerald-50 text-emerald-700'
        : v >= 55
          ? 'bg-amber-50 text-amber-800'
          : 'bg-slate-100 text-muted-foreground';

export const when = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleString([], {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
