<script setup lang="ts">
// tantra: one entry of a post's team thread in the review panel (PRD 10.5 step 2):
// keep or remove it, edit its text. Removing a comment removes its replies.
import type { TeamComment } from '@/components/tantra/review/types';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

defineProps<{
    entry: TeamComment;
    isReply?: boolean;
    isAuthor?: boolean;
    editable: boolean;
    editing: boolean;
    draft: string;
    busy: boolean;
}>();

defineEmits<{
    edit: [];
    cancel: [];
    save: [];
    toggle: [];
    'update:draft': [value: string];
}>();
</script>

<template>
    <div
        class="rounded-lg p-2"
        :class="[
            isReply ? 'border border-border' : 'bg-muted/60',
            entry.excluded ? 'opacity-50' : '',
        ]"
    >
        <div class="flex items-center gap-2 text-xs text-muted-foreground">
            <input
                v-if="editable"
                type="checkbox"
                :checked="!entry.excluded"
                :disabled="busy"
                :aria-label="$t('tantra.review.keep_comment')"
                @change="$emit('toggle')"
            />
            <b class="text-foreground">{{ entry.name || entry.by }}</b>
            <span
                v-if="isAuthor"
                class="rounded bg-foreground px-1 text-[10px] text-background"
                >{{ $t('tantra.review.author') }}</span
            >
            <span v-if="isReply">· {{ $t('tantra.review.reply') }}</span>
            <span v-else-if="entry.tone">· {{ entry.tone }}</span>
            <span v-if="entry.edited">· {{ $t('tantra.review.edited') }}</span>
            <button
                v-if="editable && !editing && !entry.excluded"
                type="button"
                class="ml-auto underline"
                @click="$emit('edit')"
            >
                {{ $t('tantra.review.edit') }}
            </button>
        </div>
        <form
            v-if="editing"
            class="mt-1 flex flex-col gap-2"
            @submit.prevent="$emit('save')"
        >
            <Textarea
                :model-value="draft"
                rows="3"
                @update:model-value="$emit('update:draft', String($event))"
            />
            <div class="flex gap-2">
                <Button size="sm" type="submit" :disabled="busy">{{
                    $t('tantra.team.save')
                }}</Button>
                <Button
                    size="sm"
                    type="button"
                    variant="ghost"
                    @click="$emit('cancel')"
                    >{{ $t('tantra.review.cancel') }}</Button
                >
            </div>
        </form>
        <div v-else class="whitespace-pre-line">{{ entry.text }}</div>
    </div>
</template>
