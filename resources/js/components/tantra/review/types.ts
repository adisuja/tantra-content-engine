// tantra: shapes sent by App\Tantra\Http\Controllers\Web\ReviewPageController.

export type ReviewStatus =
    | 'in_review'
    | 'changes_requested'
    | 'approved'
    | 'scheduled'
    | 'published'
    | 'failed'
    | 'rejected'
    | 'superseded';

export interface SignoffNumber {
    index: number;
    text: string;
    signer: 'owner' | 'adi';
    signed_by: string | null;
    signed_at: string | null;
    can_sign: boolean;
}

export interface TeamComment {
    by: string;
    name?: string;
    headline?: string;
    avatar?: string | null;
    text: string;
    reply_to?: number | null;
    tone?: string;
}

export interface ReviewProfile {
    name: string;
    headline: string;
    avatar: string | null;
    kind: 'person' | 'company';
    followers_line: string | null;
    x_handle?: string | null;
}

export interface ReviewMedia {
    type: string | null;
    title: string | null;
    files: string[];
    video: string | null;
    poll: { question?: string; options?: string[] } | null;
    placement?: string | null;
    tall?: boolean;
    download: { url: string; label: string } | null;
}

export interface ReviewEventRow {
    kind: string;
    body: string | null;
    by: string | null;
    at: string | null;
}

export interface ReviewItemRow {
    id: string;
    engine_post_id: string;
    platform: string;
    review_status: ReviewStatus;
    revision: number;
    assisted_manual: boolean;
    planned: { date: string | null; time: string | null; tz: string | null };
    scheduled_at: string | null;
    post_url: string | null;
    post_id: string | null;
    slot_fallback: boolean;
    format: string | null;
    pillar: string | null;
    short: string | null;
    idea: string | null;
    sample: {
        id?: string;
        creator?: string;
        url?: string;
        what?: string;
        thumb?: string;
    };
    copy_basis: string | null;
    sources: string[];
    flags: string[];
    numbers_for_signoff: SignoffNumber[];
    cta: string | null;
    cta_date: string | null;
    text: string;
    first_comment: string | null;
    thread: string[];
    visual_copy: string[];
    resource: {
        name?: string;
        keyword?: string;
        what?: string;
        url?: string;
    } | null;
    team_comments: TeamComment[];
    mentions: string[];
    media: ReviewMedia;
    profile: ReviewProfile;
    can_review: boolean;
    events: ReviewEventRow[];
}

export interface BatchSummary {
    id: string;
    engine_batch_id: string;
    title: string;
    dates_label: string | null;
    workspace: string | null;
    imported_at: string | null;
    counts: {
        waiting: number;
        changes: number;
        approved: number;
        scheduled: number;
        total: number;
    };
}
