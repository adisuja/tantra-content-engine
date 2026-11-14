// tantra: the Tantra ideas on the upstream Ideas board (PRD 25.8). Ideas.vue
// provides the board's Tantra props once; each card reads them here, so the
// upstream column and board components stay untouched.
import { inject, provide, type ComputedRef, type InjectionKey } from 'vue';

import type {
    TantraIdeaCard,
    TantraIdeasBoard,
    TantraNetwork,
} from '@/components/tantra/ideas/types';
import type { ReviewItemRow } from '@/components/tantra/review/types';

interface TantraIdeasContext {
    board: ComputedRef<TantraIdeasBoard | null>;
    reload: () => void;
}

const key: InjectionKey<TantraIdeasContext> = Symbol('tantraIdeas');

export function provideTantraIdeas(
    board: ComputedRef<TantraIdeasBoard | null>,
    reload: () => void,
): void {
    provide(key, { board, reload });
}

export function useTantraIdeas(): TantraIdeasContext | null {
    return inject(key, null);
}

/** A network the board does not know yet still gets a name and a plain preview. */
export function networkOf(
    board: TantraIdeasBoard | null,
    platform: string,
): TantraNetwork {
    return (
        board?.networks[platform] ?? {
            key: platform,
            label: platform,
            connected: false,
            by_hand: false,
            profile: {
                name: '',
                headline: '',
                avatar: null,
                kind: 'person',
                followers_line: null,
                x_handle: null,
            },
        }
    );
}

/** The idea as a review row, so it renders through the same phone registry as Review. */
export function ideaRow(
    id: string,
    title: string | null,
    idea: TantraIdeaCard,
    network: TantraNetwork,
): ReviewItemRow {
    const p = idea.preview;
    const media = p.media;

    return {
        id,
        engine_post_id: idea.external_key,
        platform: idea.platform,
        review_status: 'in_review',
        revision: 1,
        assisted_manual: network.by_hand,
        planned: { date: idea.target_date, time: null, tz: null },
        scheduled_at: null,
        post_url: null,
        post_id: null,
        slot_fallback: false,
        format: idea.format,
        pillar: null,
        short: title,
        idea: p.title ?? title,
        sample: {},
        copy_basis: null,
        sources: [],
        flags: [],
        numbers_for_signoff: [],
        cta: null,
        cta_date: null,
        text: p.text,
        first_comment: p.first_comment,
        thread: p.thread,
        visual_copy: [],
        resource: null,
        team_comments: [],
        author_key: '',
        dm_list: [],
        mentions: [],
        media: {
            type: media.type,
            title: media.title,
            // A video's poster shows while the video loads, and stands in when there is none.
            files: media.files.length
                ? media.files
                : media.poster
                  ? [media.poster]
                  : [],
            video: media.video,
            poll: null,
            tall: media.tall,
            download: null,
        },
        profile: network.profile,
        can_review: false,
        events: [],
    };
}

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

/** Send to review. Resolves with ok=false and the server's reason when it refuses. */
export async function sendIdeaToReview(
    ideaId: string,
): Promise<{ ok: boolean; message: string }> {
    try {
        const response = await fetch(
            `/tantra/ideas/${encodeURIComponent(ideaId)}/to-review`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
            },
        );
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const firstError = data.errors
            ? Object.values(data.errors).flat()[0]
            : undefined;

        return {
            ok: response.ok,
            message:
                (response.ok ? data.message : (firstError ?? data.message)) ??
                '',
        };
    } catch {
        return { ok: false, message: '' };
    }
}
