<script setup lang="ts">
// tantra: one free-text post setting stored as a string meta key (blank clears it).
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import { Input } from '@/components/ui/input';

const props = withDefaults(
    defineProps<{
        meta: Record<string, any>;
        metaKey: string;
        label: string;
        testId: string;
        placeholder?: string;
        maxlength?: number;
        disabled?: boolean;
    }>(),
    { placeholder: '', maxlength: undefined, disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const text = computed({
    get: (): string => (props.meta?.[props.metaKey] as string | undefined) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            [props.metaKey]: value.trim() ? value : null,
        }),
});
</script>

<template>
    <SettingsRow :label="label" :label-for="testId">
        <Input
            :id="testId"
            v-model="text"
            type="text"
            :data-testid="testId"
            :placeholder="placeholder"
            :maxlength="maxlength"
            :disabled="disabled"
        />
    </SettingsRow>
</template>
