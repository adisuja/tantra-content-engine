// tantra: the Insights tab across every workspace the viewer can see (PRD 26).
import type { InjectionKey } from 'vue';

import type { TopPost } from '@/types/analytics';
import type { VerifiedBadge } from '@/types/social-account';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export const TOP_SORTS = [
    'engagement',
    'impressions',
    'reactions',
    'comments',
    'shares',
    'engagement_rate',
] as const;

export type TopSort = (typeof TOP_SORTS)[number];

export type PostSort = 'published_at' | TopSort;

export interface InsightsNotice {
    key: string;
    count?: number;
}

export interface InsightsChannel {
    id: string;
    workspace_id: string;
    person: string | null;
    platform: string;
    network: string;
    display_label: string;
    username: string | null;
    avatar_url: string | null;
    status: SocialAccountStatusValue;
    verified_badge: VerifiedBadge | null;
    analytics_key: string | null;
    followers: { value: number; date: string; change: number | null } | null;
    posts: number | null;
    notices: InsightsNotice[];
}

export interface InsightsPost extends TopPost {
    workspace_id: string;
    person: string | null;
    engagement: number | null;
    impressions: number | null;
    shares: number | null;
    engagement_rate: number | null;
}

export interface TantraInsights {
    workspaces: { id: string; name: string }[];
    networks: { value: string; platform: string }[];
    channels: InsightsChannel[];
    filters: { workspaces: string[]; networks: string[]; channels: string[] };
    filtered: boolean;
    top_posts: Record<TopSort, InsightsPost[]>;
    posts: {
        data: InsightsPost[];
        sort: PostSort;
        direction: 'asc' | 'desc';
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

/** The person each analytics key belongs to, shown next to a channel's name. */
export const insightsPeopleKey: InjectionKey<Record<string, string>> = Symbol(
    'tantraInsightsPeople',
);
