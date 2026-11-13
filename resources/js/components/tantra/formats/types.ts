// tantra: shared shapes for the per-network format settings.

export interface MetaOption {
    value: string;
    labelKey?: string;
    label?: string;
    params?: Record<string, string>;
}

/** A poll length choice: the stored value, and how it reads. */
export interface PollDurationOption {
    value: string | number;
    labelKey: string;
    params?: Record<string, string>;
}

export const REPLY_CONTROL_OPTIONS: MetaOption[] = [
    { value: 'everyone', labelKey: 'posts.form.formats.reply_everyone' },
    {
        value: 'accounts_you_follow',
        labelKey: 'posts.form.formats.reply_accounts_you_follow',
    },
    {
        value: 'mentioned_only',
        labelKey: 'posts.form.formats.reply_mentioned_only',
    },
    {
        value: 'followers_only',
        labelKey: 'posts.form.formats.reply_followers_only',
    },
    {
        value: 'parent_post_author_only',
        labelKey: 'posts.form.formats.reply_parent_post_author_only',
    },
];
