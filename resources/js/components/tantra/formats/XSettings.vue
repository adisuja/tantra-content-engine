<script setup lang="ts">
// tantra: X post settings: who can reply, a quoted post, paid partnership.
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import MetaSelectRow from '@/components/tantra/formats/MetaSelectRow.vue';
import MetaSwitchRow from '@/components/tantra/formats/MetaSwitchRow.vue';
import MetaTextRow from '@/components/tantra/formats/MetaTextRow.vue';
import type { MetaOption } from '@/components/tantra/formats/types';

withDefaults(
    defineProps<{
        meta: Record<string, any>;
        accountId: string;
        isPoll?: boolean;
        disabled?: boolean;
    }>(),
    { isPoll: false, disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();
const update = (meta: Record<string, any>): void => emit('update:meta', meta);

const replyOptions: MetaOption[] = [
    { value: 'everyone', labelKey: 'posts.form.formats.reply_everyone' },
    { value: 'following', labelKey: 'posts.form.formats.reply_following' },
    {
        value: 'mentionedUsers',
        labelKey: 'posts.form.formats.reply_mentioned_users',
    },
    { value: 'subscribers', labelKey: 'posts.form.formats.reply_subscribers' },
    { value: 'verified', labelKey: 'posts.form.formats.reply_verified' },
];
</script>

<template>
    <SettingsSection>
        <MetaSelectRow
            :meta="meta"
            meta-key="reply_settings"
            :label="$t('posts.form.formats.reply_control')"
            :options="replyOptions"
            :test-id="`x-reply-settings-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaTextRow
            v-if="!isPoll"
            :meta="meta"
            meta-key="quote_url"
            :label="$t('posts.form.formats.quote_url')"
            :placeholder="$t('posts.form.formats.quote_url_placeholder')"
            :test-id="`x-quote-url-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
        <MetaSwitchRow
            :meta="meta"
            meta-key="paid_partnership"
            :label="$t('posts.form.formats.paid_partnership')"
            :test-id="`x-paid-partnership-${accountId}`"
            :disabled="disabled"
            @update:meta="update"
        />
    </SettingsSection>
</template>
