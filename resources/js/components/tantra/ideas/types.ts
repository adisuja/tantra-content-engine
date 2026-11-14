// tantra: shapes sent by App\Tantra\Ideas\IdeasBoard for the Ideas board (PRD 25.8).
import type { ReviewProfile } from '@/components/tantra/review/types';

export type TantraIdeaKind = 'new' | 'repurpose' | 'repost';

export type TantraIdeaState = 'idea' | 'in_review' | 'dropped';

export interface TantraIdeaPreview {
    text: string;
    title: string | null;
    first_comment: string | null;
    thread: string[];
    media: {
        type: string | null;
        title: string | null;
        files: string[];
        video: string | null;
        poster: string | null;
        tall: boolean;
    };
}

/** The Tantra part of an idea card (IdeaCardResource "tantra"). */
export interface TantraIdeaCard {
    external_key: string;
    platform: string;
    channel_id: string | null;
    kind: TantraIdeaKind;
    state: TantraIdeaState;
    format: string | null;
    cadence_week: number | null;
    target_date: string | null;
    preview: TantraIdeaPreview;
    review_item_id: string | null;
}

export interface TantraNetwork {
    key: string;
    label: string;
    connected: boolean;
    by_hand: boolean;
    profile: Omit<ReviewProfile, 'x_handle'> & { x_handle: string | null };
}

export interface TantraReadyCount {
    network: string;
    label: string;
    count: number;
    connected: boolean;
}

/** The board's "tantraIdeas" prop (absent when the workspace has no Tantra idea). */
export interface TantraIdeasBoard {
    networks: Record<string, TantraNetwork>;
    ready: TantraReadyCount[];
    viewer: { name: string; avatar: string | null };
    can_send: boolean;
}
