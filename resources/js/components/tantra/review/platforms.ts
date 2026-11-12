// tantra: which phone preview each channel gets (PRD 10.2). Every post is
// reviewed as it will look in that network's iOS app. Add a network by adding
// an entry: its key, label and screen renderer. Anything not listed yet gets a
// plain preview, so a new channel can be reviewed before its look is built.
import {
    linkedinScreen,
    phone,
    plainScreen,
    xScreen,
} from '@/components/tantra/review/studioRender';
import type { ReviewItemRow } from '@/components/tantra/review/types';

export interface Viewer {
    name?: string;
    avatar?: string | null;
}

export interface PreviewPlatform {
    key: string;
    label: string;
    /** CSS class on the phone screen (the studio's per-app styles). */
    screen: string;
    render: (item: ReviewItemRow, viewer: Viewer) => string;
}

const PLATFORMS: Record<string, PreviewPlatform> = {
    linkedin: {
        key: 'linkedin',
        label: 'LinkedIn',
        screen: 'linkedin',
        render: linkedinScreen,
    },
    // The Tantra page: a company post (followers line, Follow, no degree badge).
    linkedin_page: {
        key: 'linkedin_page',
        label: 'LinkedIn page',
        screen: 'linkedin',
        render: linkedinScreen,
    },
    x: { key: 'x', label: 'X', screen: 'x', render: (item) => xScreen(item) },
};

/** The preview for one post: by its platform, and for LinkedIn by person or page. */
export function previewFor(item: ReviewItemRow): PreviewPlatform {
    if (item.platform === 'linkedin' && item.profile.kind === 'company') {
        return PLATFORMS.linkedin_page;
    }

    return (
        PLATFORMS[item.platform] ?? {
            key: item.platform,
            label: item.platform,
            screen: 'plain',
            render: (row) => plainScreen(row),
        }
    );
}

export function renderPreview(item: ReviewItemRow, viewer: Viewer): string {
    const platform = previewFor(item);

    return phone(platform.render(item, viewer), platform.screen);
}
