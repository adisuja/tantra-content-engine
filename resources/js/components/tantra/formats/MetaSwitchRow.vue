<script setup lang="ts">
// tantra: one on/off post setting stored as a boolean meta key.
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import { Switch } from '@/components/ui/switch';

const props = withDefaults(
    defineProps<{
        meta: Record<string, any>;
        metaKey: string;
        label: string;
        testId: string;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const checked = computed({
    get: (): boolean => props.meta?.[props.metaKey] === true,
    set: (value: boolean) =>
        emit('update:meta', { ...props.meta, [props.metaKey]: value }),
});
</script>

<template>
    <SettingsRow :label="label">
        <div class="flex min-h-8 items-center">
            <Switch
                v-model="checked"
                :data-testid="testId"
                :disabled="disabled"
                :aria-label="label"
            />
        </div>
    </SettingsRow>
</template>
