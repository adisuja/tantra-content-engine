<script setup lang="ts">
// tantra: one post setting picked from a list, stored as a string meta key.
// The first option is the default; picking it clears the key.
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import type { MetaOption } from '@/components/tantra/formats/types';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const props = withDefaults(
    defineProps<{
        meta: Record<string, any>;
        metaKey: string;
        label: string;
        testId: string;
        options: MetaOption[];
        disabled?: boolean;
        clearsOnDefault?: boolean;
    }>(),
    { disabled: false, clearsOnDefault: true },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const value = computed({
    get: (): string =>
        (props.meta?.[props.metaKey] as string | undefined) ??
        props.options[0]?.value ??
        '',
    set: (next: string) =>
        emit('update:meta', {
            ...props.meta,
            [props.metaKey]:
                props.clearsOnDefault && next === props.options[0]?.value
                    ? null
                    : next,
        }),
});

const current = computed(() =>
    props.options.find((option) => option.value === value.value),
);
</script>

<template>
    <SettingsRow :label="label" :label-for="testId">
        <Select v-model="value" :disabled="disabled">
            <SelectTrigger :id="testId" class="w-full" :data-testid="testId">
                <SelectValue>{{
                    current?.labelKey
                        ? $t(current.labelKey, current.params ?? {})
                        : (current?.label ?? '')
                }}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                    :data-testid="`${testId}-${option.value}`"
                    >{{
                        option.labelKey
                            ? $t(option.labelKey, option.params ?? {})
                            : option.label
                    }}</SelectItem
                >
            </SelectContent>
        </Select>
    </SettingsRow>
</template>
