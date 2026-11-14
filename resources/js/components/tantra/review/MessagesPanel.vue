<script setup lang="ts">
// tantra: the follow-up messages that send this post's resource (email, WhatsApp,
// SMS, LinkedIn), each with click to copy. The review owner edits them here; the
// saved text is what every follow-up sends (App\Tantra\Review\Messages).
import { IconCheck, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { postJson } from '@/components/tantra/review/reviewApi';
import type { ReviewItemRow } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{ item: ReviewItemRow; editable: boolean }>();
const emit = defineEmits<{ changed: [] }>();

const ORDER = [
    'email_subject',
    'email_body',
    'whatsapp',
    'sms',
    'linkedin_dm',
    'linkedin_connect',
];

const entries = computed(() =>
    ORDER.filter((key) => props.item.messages?.[key]).map((key) => ({
        key,
        ...props.item.messages[key],
    })),
);

const drafts = reactive<Record<string, string>>({});
const saving = ref<string | null>(null);
const copied = ref<string | null>(null);

const reset = (): void => {
    for (const key of Object.keys(drafts)) delete drafts[key];
    for (const e of entries.value) drafts[e.key] = e.text;
};
watch(() => props.item.messages, reset, { immediate: true, deep: true });

const dirty = (key: string): boolean =>
    (drafts[key] ?? '') !== (props.item.messages[key]?.text ?? '');

const copy = async (key: string): Promise<void> => {
    if (
        await copyToClipboard(
            drafts[key] ?? '',
            trans('tantra.review.messages.copied'),
        )
    ) {
        copied.value = key;
        window.setTimeout(() => {
            if (copied.value === key) copied.value = null;
        }, 1500);
    }
};

const save = async (key: string, text: string | null): Promise<void> => {
    saving.value = key;
    const result = await postJson(
        `/tantra/review/items/${encodeURIComponent(props.item.id)}/messages/${key}`,
        { text },
    );
    saving.value = null;
    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return;
    }
    toast.success(result.message || trans('tantra.review.saved'));
    emit('changed');
};
</script>

<template>
    <section
        v-if="entries.length"
        class="flex w-full flex-col gap-3 rounded-2xl border border-border bg-card p-4"
        data-testid="tantra-messages"
    >
        <div class="flex flex-col gap-1">
            <div
                class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ $t('tantra.review.messages.title') }}
            </div>
            <div class="text-sm text-muted-foreground">
                {{
                    editable
                        ? $t('tantra.review.messages.help_edit')
                        : $t('tantra.review.messages.help_view')
                }}
            </div>
        </div>

        <div
            v-for="e in entries"
            :key="e.key"
            class="flex flex-col gap-1.5"
            :data-testid="`tantra-message-${e.key}`"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm font-medium">
                    {{ e.label }}
                    <span
                        v-if="e.edited"
                        class="ml-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >{{ $t('tantra.review.messages.edited') }}</span
                    >
                </div>
                <Button
                    size="sm"
                    variant="ghost"
                    class="h-8"
                    @click="copy(e.key)"
                >
                    <IconCheck v-if="copied === e.key" class="size-4" />
                    <IconCopy v-else class="size-4" />
                    {{
                        copied === e.key
                            ? $t('tantra.review.messages.copied_short')
                            : $t('tantra.review.messages.copy')
                    }}
                </Button>
            </div>
            <Textarea
                v-model="drafts[e.key]"
                :readonly="!editable"
                :rows="
                    e.key === 'email_subject'
                        ? 1
                        : e.key === 'sms' || e.key === 'linkedin_connect'
                          ? 3
                          : 6
                "
                :maxlength="e.max"
                class="text-sm"
            />
            <div class="flex items-center justify-between gap-2">
                <span
                    class="text-xs"
                    :class="
                        (drafts[e.key] ?? '').length > e.max
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    >{{ (drafts[e.key] ?? '').length }} / {{ e.max }}</span
                >
                <div v-if="editable" class="flex gap-2">
                    <Button
                        v-if="e.edited && !dirty(e.key)"
                        size="sm"
                        variant="ghost"
                        :disabled="saving === e.key"
                        @click="save(e.key, null)"
                    >
                        {{ $t('tantra.review.messages.restore') }}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="saving === e.key || !dirty(e.key)"
                        @click="save(e.key, drafts[e.key] ?? '')"
                    >
                        {{ $t('tantra.review.messages.save') }}
                    </Button>
                </div>
            </div>
        </div>
    </section>
</template>
