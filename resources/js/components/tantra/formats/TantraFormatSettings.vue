<script setup lang="ts">
// tantra: the per-network format settings the Engine adds to the composer
// (Threads, X, Mastodon, Bluesky, YouTube, Facebook, Instagram, Telegram,
// Discord, TikTok, Pinterest). LinkedIn's live in LinkedInSettings.
import { computed, ref, watch } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import BlueskySettings from '@/components/tantra/formats/BlueskySettings.vue';
import MetaPollRow from '@/components/tantra/formats/MetaPollRow.vue';
import MetaSelectRow from '@/components/tantra/formats/MetaSelectRow.vue';
import MetaSwitchRow from '@/components/tantra/formats/MetaSwitchRow.vue';
import MetaTextRow from '@/components/tantra/formats/MetaTextRow.vue';
import {
    REPLY_CONTROL_OPTIONS,
    type MetaOption,
    type PollDurationOption,
} from '@/components/tantra/formats/types';
import XSettings from '@/components/tantra/formats/XSettings.vue';
import { Input } from '@/components/ui/input';
import { isVideo } from '@/lib/mediaType';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

const props = withDefaults(
    defineProps<{
        accountId: string;
        platform: string;
        contentType: string;
        meta?: Record<string, any> | null;
        media: MediaItem[];
        publishConfig?: Record<string, any> | null;
        disabled?: boolean;
    }>(),
    { meta: null, publishConfig: null, disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();
const update = (meta: Record<string, any>): void => emit('update:meta', meta);

const meta = computed((): Record<string, any> => props.meta ?? {});
const hasMedia = computed(() => props.media.length > 0);
const hasVideo = computed(() => props.media.some((item) => isVideo(item)));
const id = (name: string): string => `${name}-${props.accountId}`;

const hours = (count: number): PollDurationOption =>
    count === 1
        ? { value: 3600, labelKey: 'posts.form.formats.hour' }
        : {
              value: count * 3600,
              labelKey: 'posts.form.formats.hours',
              params: { count: String(count) },
          };
const days = (count: number): PollDurationOption =>
    count === 1
        ? { value: 86400, labelKey: 'posts.form.formats.day' }
        : {
              value: count * 86400,
              labelKey: 'posts.form.formats.days',
              params: { count: String(count) },
          };

const MASTODON_DURATIONS: PollDurationOption[] = [
    {
        value: 300,
        labelKey: 'posts.form.formats.minutes',
        params: { count: '5' },
    },
    {
        value: 1800,
        labelKey: 'posts.form.formats.minutes',
        params: { count: '30' },
    },
    hours(1),
    hours(6),
    days(1),
    days(3),
    days(7),
];

const DISCORD_DURATIONS: PollDurationOption[] = [1, 4, 8, 24, 72, 168, 336].map(
    (count) => {
        const option = count < 24 ? hours(count) : days(count / 24);

        return { ...option, value: count };
    },
);

const MASTODON_VISIBILITY: MetaOption[] = [
    { value: 'public', labelKey: 'posts.form.formats.visibility_public' },
    { value: 'unlisted', labelKey: 'posts.form.formats.visibility_unlisted' },
    { value: 'private', labelKey: 'posts.form.formats.visibility_private' },
    { value: 'direct', labelKey: 'posts.form.formats.visibility_direct' },
];

// YouTube tags: typed comma separated, stored as a list.
const YOUTUBE_TAGS_MAX = 500;
const tagsText = ref('');
watch(
    () => meta.value.tags,
    (tags) => {
        const joined = Array.isArray(tags) ? tags.join(', ') : '';
        if (
            joined !==
            tagsText.value
                .split(',')
                .map((tag) => tag.trim())
                .filter(Boolean)
                .join(', ')
        ) {
            tagsText.value = joined;
        }
    },
    { immediate: true },
);
const setTags = (value: string): void => {
    tagsText.value = value;
    const tags = value
        .split(',')
        .map((tag) => tag.trim().replace(/^#/, ''))
        .filter(Boolean);
    update({ ...meta.value, tags: tags.length ? tags : null });
};
const tagsLength = computed(() => {
    const tags: string[] = Array.isArray(meta.value.tags)
        ? meta.value.tags
        : [];

    return (
        tags.reduce(
            (sum, tag) => sum + tag.length + (tag.includes(' ') ? 2 : 0),
            0,
        ) + Math.max(0, tags.length - 1)
    );
});

// Live lists: a YouTube channel's playlists, a Pinterest board's sections.
const playlists = ref<MetaOption[] | null>(null);
const playlistsFailed = ref(false);
const sections = ref<MetaOption[] | null>(null);

const lookup = async (url: string): Promise<Record<string, any> | null> => {
    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.ok ? await response.json() : null;
    } catch {
        return null;
    }
};

watch(
    () => props.platform === Platform.YouTube && props.accountId,
    async (load) => {
        if (!load) {
            return;
        }
        const data = await lookup(
            `/tantra/formats/youtube/${props.accountId}/playlists`,
        );
        playlistsFailed.value = !data || data.failed === true;
        playlists.value = [
            { value: 'none', labelKey: 'posts.form.formats.playlist_none' },
            ...((data?.playlists ?? []) as { id: string; title: string }[]).map(
                (playlist) => ({ value: playlist.id, label: playlist.title }),
            ),
        ];
    },
    { immediate: true },
);

watch(
    () => (props.platform === Platform.Pinterest ? meta.value.board_id : null),
    async (board, previous) => {
        sections.value = null;
        // A section belongs to one board: a new board clears it.
        if (previous && board !== previous && meta.value.board_section_id) {
            update({ ...meta.value, board_section_id: null });
        }
        if (!board) {
            return;
        }
        const data = await lookup(
            `/tantra/formats/pinterest/${props.accountId}/boards/${encodeURIComponent(board)}/sections`,
        );
        const list = (data?.sections ?? []) as { id: string; name: string }[];
        sections.value = list.length
            ? [
                  {
                      value: 'none',
                      labelKey: 'posts.form.formats.board_section_none',
                  },
                  ...list.map((section) => ({
                      value: section.id,
                      label: section.name,
                  })),
              ]
            : null;
    },
    { immediate: true },
);
</script>

<template>
    <!-- Threads -->
    <SettingsSection
        v-if="
            platform === Platform.Threads &&
            contentType !== ContentType.ThreadsGhostPost
        "
    >
        <MetaSelectRow
            :meta="meta"
            meta-key="reply_control"
            :label="$t('posts.form.formats.reply_control')"
            :options="REPLY_CONTROL_OPTIONS"
            :test-id="id('threads-reply-control')"
            :disabled="disabled"
            @update:meta="update"
        />
        <template v-if="!hasMedia">
            <MetaTextRow
                :meta="meta"
                meta-key="link_attachment"
                :label="$t('posts.form.formats.link_attachment')"
                :placeholder="
                    $t('posts.form.formats.link_attachment_placeholder')
                "
                :test-id="id('threads-link-attachment')"
                :disabled="disabled"
                @update:meta="update"
            />
            <MetaPollRow
                :meta="meta"
                meta-key="threads_poll"
                :test-id="id('threads-poll')"
                :min-options="2"
                :max-options="4"
                :option-max-length="25"
                :disabled="disabled"
                @update:meta="update"
            />
        </template>
        <MetaSwitchRow
            v-else
            :meta="meta"
            meta-key="spoiler"
            :label="$t('posts.form.formats.spoiler')"
            :test-id="id('threads-spoiler')"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- X -->
    <XSettings
        v-else-if="platform === Platform.X"
        :meta="meta"
        :account-id="accountId"
        :is-poll="contentType === ContentType.XPoll"
        :quotes-enabled="publishConfig?.quotesEnabled === true"
        :disabled="disabled"
        @update:meta="update"
    />

    <!-- Mastodon -->
    <SettingsSection v-else-if="platform === Platform.Mastodon">
        <MetaSelectRow
            :meta="meta"
            meta-key="visibility"
            :label="$t('posts.form.formats.visibility')"
            :options="MASTODON_VISIBILITY"
            :test-id="id('mastodon-visibility')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaTextRow
            :meta="meta"
            meta-key="language"
            :label="$t('posts.form.formats.language')"
            :placeholder="$t('posts.form.formats.language_placeholder')"
            :test-id="id('mastodon-language')"
            :maxlength="12"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            v-if="hasMedia"
            :meta="meta"
            meta-key="sensitive"
            :label="$t('posts.form.formats.sensitive')"
            :test-id="id('mastodon-sensitive')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaPollRow
            v-else
            :meta="meta"
            meta-key="mastodon_poll"
            :test-id="id('mastodon-poll')"
            :min-options="2"
            :max-options="4"
            :option-max-length="50"
            duration-key="expires_in"
            :durations="MASTODON_DURATIONS"
            :default-duration="86400"
            with-multiple
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- Bluesky -->
    <BlueskySettings
        v-else-if="platform === Platform.Bluesky"
        :meta="meta"
        :account-id="accountId"
        :disabled="disabled"
        @update:meta="update"
    />

    <!-- YouTube -->
    <SettingsSection v-else-if="platform === Platform.YouTube">
        <SettingsRow
            :label="$t('posts.form.formats.tags')"
            :label-for="id('youtube-tags')"
        >
            <Input
                :id="id('youtube-tags')"
                :model-value="tagsText"
                :data-testid="id('youtube-tags')"
                :placeholder="$t('posts.form.formats.tags_placeholder')"
                :disabled="disabled"
                :aria-invalid="tagsLength > YOUTUBE_TAGS_MAX ? true : undefined"
                @update:model-value="setTags(String($event))"
            />
            <p
                class="text-xs"
                :class="
                    tagsLength > YOUTUBE_TAGS_MAX
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{
                    $t('posts.form.formats.tags_count', {
                        count: String(tagsLength),
                        max: String(YOUTUBE_TAGS_MAX),
                    })
                }}
            </p>
        </SettingsRow>
        <MetaSelectRow
            v-if="playlists && !playlistsFailed"
            :meta="meta"
            meta-key="playlist_id"
            :label="$t('posts.form.formats.playlist')"
            :options="playlists"
            :test-id="id('youtube-playlist')"
            :disabled="disabled"
            @update:meta="update"
        />
        <SettingsRow v-else :label="$t('posts.form.formats.playlist')">
            <p class="text-sm text-muted-foreground">
                {{
                    $t(
                        playlistsFailed
                            ? 'posts.form.formats.playlist_failed'
                            : 'posts.form.formats.playlist_loading',
                    )
                }}
            </p>
        </SettingsRow>
        <MetaTextRow
            v-if="contentType === ContentType.YouTubeVideo"
            :meta="meta"
            meta-key="thumbnail_url"
            :label="$t('posts.form.formats.thumbnail')"
            :placeholder="$t('posts.form.formats.thumbnail_placeholder')"
            :test-id="id('youtube-thumbnail')"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- Facebook -->
    <SettingsSection
        v-else-if="
            platform === Platform.Facebook &&
            contentType === ContentType.FacebookPost &&
            hasVideo
        "
    >
        <MetaTextRow
            :meta="meta"
            meta-key="video_title"
            :label="$t('posts.form.formats.video_title')"
            :placeholder="$t('posts.form.formats.video_title_placeholder')"
            :test-id="id('facebook-video-title')"
            :maxlength="255"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- Instagram -->
    <SettingsSection
        v-else-if="
            (platform === Platform.Instagram ||
                platform === Platform.InstagramFacebook) &&
            contentType !== ContentType.InstagramStory
        "
    >
        <MetaTextRow
            :meta="meta"
            meta-key="location_id"
            :label="$t('posts.form.formats.location_id')"
            :placeholder="$t('posts.form.formats.location_id_placeholder')"
            :test-id="id('instagram-location')"
            :disabled="disabled"
            @update:meta="update"
        />
        <template v-if="contentType === ContentType.InstagramReel">
            <MetaTextRow
                :meta="meta"
                meta-key="cover_url"
                :label="$t('posts.form.formats.cover_url')"
                :placeholder="$t('posts.form.formats.cover_url_placeholder')"
                :test-id="id('instagram-cover')"
                :disabled="disabled"
                @update:meta="update"
            />
            <MetaTextRow
                :meta="meta"
                meta-key="audio_name"
                :label="$t('posts.form.formats.audio_name')"
                :test-id="id('instagram-audio-name')"
                :maxlength="100"
                :disabled="disabled"
                @update:meta="update"
            />
        </template>
    </SettingsSection>

    <!-- Telegram -->
    <SettingsSection v-else-if="platform === Platform.Telegram">
        <MetaSwitchRow
            :meta="meta"
            meta-key="disable_notification"
            :label="$t('posts.form.formats.disable_notification')"
            :test-id="id('telegram-silent')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            :meta="meta"
            meta-key="protect_content"
            :label="$t('posts.form.formats.protect_content')"
            :test-id="id('telegram-protect')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            v-if="hasMedia"
            :meta="meta"
            meta-key="has_spoiler"
            :label="$t('posts.form.formats.has_spoiler')"
            :test-id="id('telegram-spoiler')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            :meta="meta"
            meta-key="pin"
            :label="$t('posts.form.formats.pin')"
            :test-id="id('telegram-pin')"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaPollRow
            :meta="meta"
            meta-key="telegram_poll"
            :test-id="id('telegram-poll')"
            :min-options="2"
            :max-options="10"
            :option-max-length="100"
            with-question
            :question-max-length="300"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- Discord -->
    <SettingsSection v-else-if="platform === Platform.Discord">
        <MetaPollRow
            :meta="meta"
            meta-key="discord_poll"
            :test-id="id('discord-poll')"
            :min-options="2"
            :max-options="10"
            :option-max-length="55"
            with-question
            :question-max-length="300"
            duration-key="duration_hours"
            :durations="DISCORD_DURATIONS"
            :default-duration="24"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- TikTok -->
    <SettingsSection v-else-if="platform === Platform.TikTok">
        <MetaTextRow
            v-if="contentType === ContentType.TikTokPhoto"
            :meta="meta"
            meta-key="title"
            :label="$t('posts.form.formats.photo_title')"
            :test-id="id('tiktok-photo-title')"
            :maxlength="90"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            :meta="meta"
            meta-key="upload_to_inbox"
            :label="$t('posts.form.formats.upload_to_inbox')"
            :test-id="id('tiktok-inbox')"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>

    <!-- Pinterest -->
    <SettingsSection v-else-if="platform === Platform.Pinterest && sections">
        <MetaSelectRow
            :meta="meta"
            meta-key="board_section_id"
            :label="$t('posts.form.formats.board_section')"
            :options="sections"
            :test-id="id('pinterest-section')"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>
</template>
