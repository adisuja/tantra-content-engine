// tantra: shapes and number helpers for the traffic and leads module (/tantra/insights).

export type Num = number | null;

export type SocialTotals = {
    posts: Num;
    impressions: Num;
    likes: Num;
    comments: Num;
    shares: Num;
    followers: Num;
    followers_gained: Num;
};

export type TrafficTotals = {
    clicks: Num;
    clicks_human: Num;
    visits: Num;
    conversions: Num;
    registrations: Num;
    bookings: Num;
    leads: Num;
    sales: Num;
    revenue: Num;
};

export type Member = {
    key: string;
    name: string;
    workspace_id: string;
    platforms: string[];
    social: SocialTotals;
    social_notes: Partial<Record<keyof SocialTotals, string>>;
    traffic: TrafficTotals;
    profile_clicks: {
        links: number;
        clicks: number;
        clicks_human: number;
    } | null;
};

export type PieceRow = TrafficTotals & {
    member: string;
    piece: string;
    platform: string;
    review_url: string | null;
    links: { placement: string; url: string; code: string }[];
};

export type PlatformRow = Partial<SocialTotals & TrafficTotals> & {
    member: string;
    platform: string;
};

export type SeriesRow = Partial<SocialTotals & TrafficTotals> & {
    bucket: string;
    member: string;
};

export type Traffic = {
    range: {
        from: string;
        to: string;
        days: number;
        bucket: 'day' | 'week' | 'month';
        weeks: number;
        months: number;
    };
    crm: {
        status: 'live' | 'not_configured' | 'error';
        message: string | null;
        currency: string | null;
        generated_at: string | null;
        not_split: { visits: string; leads: string };
    };
    members: Member[];
    totals: { social: SocialTotals; traffic: TrafficTotals };
    series: SeriesRow[];
    platforms: PlatformRow[];
    pieces: PieceRow[];
    buckets: string[];
};

export const fmt = (v: Num, digits = 0): string =>
    v === null
        ? ''
        : new Intl.NumberFormat(undefined, {
              maximumFractionDigits: digits,
          }).format(v);

export const money = (v: Num, currency: string | null): string =>
    v === null
        ? ''
        : new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: currency || 'USD',
              maximumFractionDigits: 0,
          }).format(v);

/** Average of a total over the range, per day, week or month (one decimal under 10). */
export const avg = (total: Num, units: number): string => {
    if (total === null || units <= 0) return '';
    const v = total / units;

    return fmt(v, v < 10 ? 1 : 0);
};
