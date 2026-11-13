import { ContentType } from '@/types/content-type';

export const YouTubePrivacyStatus = {
    Public: 'public',
    Unlisted: 'unlisted',
    Private: 'private',
} as const;

export const YouTubeLicense = {
    YouTube: 'youtube',
    CreativeCommon: 'creativeCommon',
} as const;

export const YOUTUBE_TITLE_MAX = 100;
export const THREADS_TOPIC_TAG_MAX = 50;

/** The content type a thread reply publishes as, per network that chains replies. */
export const THREAD_REPLY_CONTENT_TYPES: Readonly<Record<string, string>> = {
    bluesky: ContentType.BlueskyPost,
    mastodon: ContentType.MastodonPost,
    x: ContentType.XPost,
    linkedin: ContentType.LinkedInComment,
    'linkedin-page': ContentType.LinkedInComment,
    'zernio-linkedin-page': ContentType.LinkedInComment, // tantra:
    'unipile-linkedin': ContentType.LinkedInComment, // tantra:
    // tantra: first comments and the Threads reply chain.
    instagram: ContentType.LinkedInComment,
    'instagram-facebook': ContentType.LinkedInComment,
    facebook: ContentType.LinkedInComment,
    youtube: ContentType.LinkedInComment,
    threads: ContentType.ThreadsPost,
};
export const THREAD_PLATFORMS: readonly string[] = Object.keys(
    THREAD_REPLY_CONTENT_TYPES,
);
export const THREAD_MAX_REPLIES: Readonly<Record<string, number>> = {
    bluesky: 24,
    mastodon: 24,
    x: 24,
    linkedin: 1,
    'linkedin-page': 1,
    'zernio-linkedin-page': 1, // tantra:
    'unipile-linkedin': 1, // tantra:
    instagram: 1, // tantra:
    'instagram-facebook': 1, // tantra:
    facebook: 1, // tantra:
    youtube: 1, // tantra:
    threads: 24, // tantra:
};
