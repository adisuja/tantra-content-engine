<script setup lang="ts">
// tantra: a poll stored under one meta key as {question?, options, <durationKey>?, multiple?}.
// Optional polls (Threads, Mastodon, Telegram, Discord) start behind an "Add a poll" button;
// a poll content type (LinkedIn) is always shown.
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import type { PollDurationOption } from '@/components/tantra/formats/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

const props = withDefaults(
    defineProps<{
        meta: Record<string, any>;
        metaKey: string;
        testId: string;
        minOptions: number;
        maxOptions: number;
        optionMaxLength: number;
        withQuestion?: boolean;
        questionMaxLength?: number;
        durationKey?: string | null;
        durations?: PollDurationOption[];
        defaultDuration?: string | number | null;
        withMultiple?: boolean;
        optional?: boolean;
        disabled?: boolean;
    }>(),
    {
        withQuestion: false,
        questionMaxLength: 300,
        durationKey: null,
        durations: () => [],
        defaultDuration: null,
        withMultiple: false,
        optional: true,
        disabled: false,
    },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const poll = computed(
    (): Record<string, any> | null => props.meta?.[props.metaKey] ?? null,
);

const open = computed(() => !props.optional || poll.value !== null);

const options = computed((): string[] => {
    const stored = Array.isArray(poll.value?.options)
        ? (poll.value?.options as string[])
        : [];

    return stored.length >= props.minOptions
        ? stored
        : [...stored, ...Array(props.minOptions - stored.length).fill('')];
});

const write = (patch: Record<string, any> | null): void => {
    emit('update:meta', {
        ...props.meta,
        [props.metaKey]: patch === null ? null : { ...poll.value, ...patch },
    });
};

const start = (): void =>
    write({
        options: Array(props.minOptions).fill(''),
        ...(props.durationKey && props.defaultDuration !== null
            ? { [props.durationKey]: props.defaultDuration }
            : {}),
    });

const setOption = (index: number, value: string): void => {
    const next = [...options.value];
    next[index] = value;
    write({ options: next });
};

const addOption = (): void => write({ options: [...options.value, ''] });

const removeOption = (index: number): void =>
    write({ options: options.value.filter((_, i) => i !== index) });

const question = computed({
    get: (): string => poll.value?.question ?? '',
    set: (value: string) => write({ question: value }),
});

const duration = computed({
    get: (): string =>
        String(
            (props.durationKey ? poll.value?.[props.durationKey] : null) ??
                props.defaultDuration ??
                '',
        ),
    set: (value: string) => {
        if (!props.durationKey) {
            return;
        }

        const option = props.durations.find((o) => String(o.value) === value);
        write({ [props.durationKey]: option?.value ?? value });
    },
});

const currentDuration = computed(() =>
    props.durations.find((o) => String(o.value) === duration.value),
);

const multiple = computed({
    get: (): boolean => poll.value?.multiple === true,
    set: (value: boolean) => write({ multiple: value }),
});
</script>

<template>
    <SettingsRow v-if="!open" :label="$t('posts.form.formats.poll')">
        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                :data-testid="`${testId}-add`"
                @click="start"
            >
                <IconPlus class="size-4" />
                {{ $t('posts.form.formats.poll_add') }}
            </Button>
        </div>
    </SettingsRow>
    <template v-else>
        <SettingsRow
            v-if="withQuestion"
            :label="$t('posts.form.formats.poll_question')"
            :label-for="`${testId}-question`"
        >
            <Input
                :id="`${testId}-question`"
                v-model="question"
                :data-testid="`${testId}-question`"
                :maxlength="questionMaxLength"
                :placeholder="
                    $t('posts.form.formats.poll_question_placeholder')
                "
                :disabled="disabled"
            />
        </SettingsRow>
        <SettingsRow :label="$t('posts.form.formats.poll')" align-top>
            <div class="space-y-2">
                <div
                    v-for="(option, index) in options"
                    :key="index"
                    class="flex items-center gap-2"
                >
                    <Input
                        :model-value="option"
                        :data-testid="`${testId}-option-${index}`"
                        :aria-label="
                            $t('posts.form.formats.poll_choice', {
                                number: String(index + 1),
                            })
                        "
                        :placeholder="
                            $t('posts.form.formats.poll_choice', {
                                number: String(index + 1),
                            })
                        "
                        :maxlength="optionMaxLength"
                        :disabled="disabled"
                        @update:model-value="setOption(index, String($event))"
                    />
                    <Button
                        v-if="options.length > minOptions"
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="disabled"
                        :aria-label="
                            $t('posts.form.formats.poll_remove_choice', {
                                number: String(index + 1),
                            })
                        "
                        @click="removeOption(index)"
                    >
                        <IconX class="size-4" />
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="options.length < maxOptions"
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="disabled"
                        :data-testid="`${testId}-add-choice`"
                        @click="addOption"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('posts.form.formats.poll_add_choice') }}
                    </Button>
                    <Button
                        v-if="optional"
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="disabled"
                        :data-testid="`${testId}-remove`"
                        @click="write(null)"
                    >
                        {{ $t('posts.form.formats.poll_remove') }}
                    </Button>
                </div>
            </div>
        </SettingsRow>
        <SettingsRow
            v-if="durationKey && durations.length"
            :label="$t('posts.form.formats.poll_length')"
            :label-for="`${testId}-duration`"
        >
            <Select v-model="duration" :disabled="disabled">
                <SelectTrigger
                    :id="`${testId}-duration`"
                    class="w-full"
                    :data-testid="`${testId}-duration`"
                >
                    <SelectValue>{{
                        currentDuration
                            ? $t(
                                  currentDuration.labelKey,
                                  currentDuration.params ?? {},
                              )
                            : ''
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in durations"
                        :key="String(option.value)"
                        :value="String(option.value)"
                        >{{
                            $t(option.labelKey, option.params ?? {})
                        }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </SettingsRow>
        <SettingsRow
            v-if="withMultiple"
            :label="$t('posts.form.formats.poll_multiple')"
        >
            <div class="flex min-h-8 items-center">
                <Switch
                    v-model="multiple"
                    :data-testid="`${testId}-multiple`"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.formats.poll_multiple')"
                />
            </div>
        </SettingsRow>
    </template>
</template>
