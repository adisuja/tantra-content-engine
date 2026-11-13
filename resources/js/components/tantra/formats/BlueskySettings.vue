<script setup lang="ts">
// tantra: Bluesky post settings: language, content warning, who can reply, a quoted post.
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import MetaSelectRow from '@/components/tantra/formats/MetaSelectRow.vue';
import MetaTextRow from '@/components/tantra/formats/MetaTextRow.vue';
import {
    REPLY_CONTROL_OPTIONS,
    type MetaOption,
} from '@/components/tantra/formats/types';
import { Checkbox } from '@/components/ui/checkbox';

const props = withDefaults(
    defineProps<{
        meta: Record<string, any>;
        accountId: string;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();
const update = (meta: Record<string, any>): void => emit('update:meta', meta);

const LABELS: MetaOption[] = [
    { value: 'sexual', labelKey: 'posts.form.formats.label_sexual' },
    { value: 'nudity', labelKey: 'posts.form.formats.label_nudity' },
    { value: 'porn', labelKey: 'posts.form.formats.label_porn' },
    {
        value: 'graphic-media',
        labelKey: 'posts.form.formats.label_graphic_media',
    },
];

const labels = computed((): string[] =>
    Array.isArray(props.meta?.self_labels) ? props.meta.self_labels : [],
);

const toggleLabel = (value: string, on: boolean): void => {
    const next = on
        ? [...new Set([...labels.value, value])]
        : labels.value.filter((label) => label !== value);
    update({ ...props.meta, self_labels: next.length ? next : null });
};
</script>

<template>
    <SettingsSection>
        <MetaTextRow
            :meta="meta"
            meta-key="language"
            :label="$t('posts.form.formats.language')"
            :placeholder="$t('posts.form.formats.language_placeholder')"
            :test-id="`bluesky-language-${accountId}`"
            :maxlength="12"
            :disabled="disabled"
            @update:meta="update"
        />
        <SettingsRow :label="$t('posts.form.formats.self_labels')">
            <div class="flex min-h-8 flex-wrap items-center gap-x-5 gap-y-2">
                <label
                    v-for="option in LABELS"
                    :key="option.value"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="labels.includes(option.value)"
                        :data-testid="`bluesky-label-${option.value}-${accountId}`"
                        :disabled="disabled"
                        @update:model-value="
                            toggleLabel(option.value, $event === true)
                        "
                    />
                    <span>{{ $t(option.labelKey as string) }}</span>
                </label>
            </div>
        </SettingsRow>
        <MetaSelectRow
            :meta="meta"
            meta-key="reply_control"
            :label="$t('posts.form.formats.reply_control')"
            :options="REPLY_CONTROL_OPTIONS"
            :test-id="`bluesky-reply-control-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaTextRow
            :meta="meta"
            meta-key="quote_url"
            :label="$t('posts.form.formats.quote_url')"
            :placeholder="$t('posts.form.formats.quote_url_placeholder')"
            :test-id="`bluesky-quote-url-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>
</template>
