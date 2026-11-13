<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import MetaPollRow from '@/components/tantra/formats/MetaPollRow.vue'; // tantra:
import MetaSelectRow from '@/components/tantra/formats/MetaSelectRow.vue'; // tantra:
import MetaSwitchRow from '@/components/tantra/formats/MetaSwitchRow.vue'; // tantra:
import MetaTextRow from '@/components/tantra/formats/MetaTextRow.vue'; // tantra:
import type { PollDurationOption } from '@/components/tantra/formats/types'; // tantra:
import { Input } from '@/components/ui/input';
import { isDocumentMedia } from '@/composables/useMedia';
import { isVideo } from '@/lib/mediaType'; // tantra:
import { ContentType } from '@/types/content-type'; // tantra:
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform'; // tantra:

interface Props {
    accountId: string;
    media: MediaItem[];
    meta?: Record<string, any>;
    disabled?: boolean;
    // tantra: which LinkedIn route this is (native profile or page, Zernio, Unipile) and its post type.
    platform?: string;
    contentType?: string | null;
    publishConfig?: Record<string, any> | null;
}

const props = withDefaults(defineProps<Props>(), {
    meta: () => ({}),
    disabled: false,
    platform: Platform.LinkedIn,
    contentType: null,
    publishConfig: null,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const pdfDocument = computed(
    () => props.media.find((item) => isDocumentMedia(item)) ?? null,
);

const documentTitle = computed({
    get: () =>
        (props.meta?.document_title as string | undefined) ||
        pdfDocument.value?.original_filename ||
        '',
    set: (value: string) =>
        emit('update:meta', { ...props.meta, document_title: value || null }),
});

const inputId = computed(() => `linkedin-document-title-${props.accountId}`);

// tantra: polls, video title, visibility and reshares are native LinkedIn API settings.
const update = (meta: Record<string, any>): void => emit('update:meta', meta);
const native = computed(
    () =>
        props.platform === Platform.LinkedIn ||
        props.platform === Platform.LinkedInPage,
);
const isPoll = computed(
    () =>
        props.contentType === ContentType.LinkedInPoll ||
        props.contentType === ContentType.LinkedInPagePoll,
);
const pollLimits = computed(
    () =>
        props.publishConfig?.linkedinPoll ?? {
            minOptions: 2,
            maxOptions: 4,
            questionMaxLength: 140,
            optionMaxLength: 30,
            durations: ['ONE_DAY', 'THREE_DAYS', 'SEVEN_DAYS', 'FOURTEEN_DAYS'],
            defaultDuration: 'THREE_DAYS',
        },
);
const DURATION_LABELS: Record<string, { labelKey: string; count?: string }> = {
    ONE_DAY: { labelKey: 'posts.form.formats.day' },
    THREE_DAYS: { labelKey: 'posts.form.formats.days', count: '3' },
    SEVEN_DAYS: { labelKey: 'posts.form.formats.week' },
    FOURTEEN_DAYS: { labelKey: 'posts.form.formats.weeks', count: '2' },
};
const pollDurations = computed((): PollDurationOption[] =>
    (pollLimits.value.durations as string[]).map((value) => {
        const count = DURATION_LABELS[value]?.count;
        const params: Record<string, string> = count ? { count } : {};

        return {
            value,
            labelKey: DURATION_LABELS[value]?.labelKey ?? value,
            params,
        };
    }),
);
const hasVideo = computed(() => props.media.some((item) => isVideo(item)));
const visibilityOptions = [
    { value: 'PUBLIC', labelKey: 'posts.form.formats.visibility_public' },
    {
        value: 'CONNECTIONS',
        labelKey: 'posts.form.formats.visibility_connections',
    },
];
</script>

<template>
    <SettingsSection
        v-if="pdfDocument || native || platform === Platform.UnipileLinkedIn"
    >
        <MetaPollRow
            v-if="native && isPoll"
            :meta="meta"
            meta-key="linkedin_poll"
            :test-id="`linkedin-poll-${accountId}`"
            :min-options="pollLimits.minOptions"
            :max-options="pollLimits.maxOptions"
            :option-max-length="pollLimits.optionMaxLength"
            with-question
            :question-max-length="pollLimits.questionMaxLength"
            duration-key="duration"
            :durations="pollDurations"
            :default-duration="pollLimits.defaultDuration"
            :optional="false"
            :disabled="disabled"
            @update:meta="update"
        />
        <SettingsRow
            v-if="pdfDocument"
            :label="$t('posts.form.linkedin.document_title')"
            :label-for="inputId"
        >
            <Input
                :id="inputId"
                v-model="documentTitle"
                type="text"
                data-testid="linkedin-document-title"
                :placeholder="
                    $t('posts.form.linkedin.document_title_placeholder')
                "
                :disabled="disabled"
            />
        </SettingsRow>
        <MetaSwitchRow
            v-if="platform === Platform.UnipileLinkedIn && media.length === 0"
            :meta="meta"
            meta-key="link_preview"
            :label="$t('posts.form.formats.link_attachment')"
            :test-id="`unipile-link-preview-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
        <template v-if="native">
            <MetaTextRow
                v-if="hasVideo"
                :meta="meta"
                meta-key="video_title"
                :label="$t('posts.form.formats.video_title')"
                :placeholder="$t('posts.form.formats.video_title_placeholder')"
                :test-id="`linkedin-video-title-${accountId}`"
                :maxlength="255"
                :disabled="disabled"
                @update:meta="update"
            />
            <MetaSelectRow
                v-if="platform === Platform.LinkedIn"
                :meta="meta"
                meta-key="visibility"
                :label="$t('posts.form.formats.visibility')"
                :options="visibilityOptions"
                :test-id="`linkedin-visibility-${accountId}`"
                :disabled="disabled"
                @update:meta="update"
            />
            <MetaSwitchRow
                :meta="meta"
                meta-key="disable_reshare"
                :label="$t('posts.form.formats.disable_reshare')"
                :test-id="`linkedin-disable-reshare-${accountId}`"
                :disabled="disabled"
                @update:meta="update"
            />
        </template>
    </SettingsSection>
</template>
