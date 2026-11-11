<script setup lang="ts">
// tantra: posts the owner publishes by hand (PRD 12): X posts and threads, and
// polls on personal LinkedIn profiles.
import { Head, router } from '@inertiajs/vue3';
import { IconHandClick } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import { postJson } from '@/components/tantra/review/reviewApi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';

interface Task {
    id: string;
    kind: 'x_post' | 'x_thread' | 'linkedin_poll';
    status: 'waiting' | 'due' | 'posted' | 'skipped' | 'expired';
    due_at: string | null;
    text: string;
    thread: string[];
    poll: { question?: string; options?: string[] } | null;
    media_url: string | null;
    posted_url: string | null;
    engine_post_id: string | null;
    review_url: string | null;
    workspace: string | null;
    can_mark: boolean;
}

defineProps<{ tasks: Task[] }>();

const urls = ref<Record<string, string>>({});
const busy = ref<string | null>(null);

const copy = async (text: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('tantra.tasks.copied'));
    } catch {
        toast.error(trans('tantra.tasks.copy_failed'));
    }
};

const pollText = (task: Task): string =>
    [task.poll?.question ?? '', ...(task.poll?.options ?? [])]
        .filter(Boolean)
        .join('\n');

const xIntent = (text: string): string =>
    `https://x.com/intent/post?text=${encodeURIComponent(text)}`;

const open = (task: Task): void => {
    const text = task.kind === 'linkedin_poll' ? pollText(task) : task.text;
    void copy(text);
    window.open(
        task.kind === 'linkedin_poll'
            ? 'https://www.linkedin.com/feed/?shareActive=true'
            : xIntent(task.text),
        '_blank',
        'noopener',
    );
};

const act = async (task: Task, action: 'posted' | 'skip'): Promise<void> => {
    busy.value = task.id;
    const result = await postJson(
        `/tantra/tasks/${task.id}/${action}`,
        action === 'posted' ? { url: urls.value[task.id] ?? null } : {},
    );
    busy.value = null;
    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return;
    }
    toast.success(result.message || trans('tantra.review.saved'));
    router.reload({ only: ['tasks'] });
};

const when = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : trans('tantra.tasks.not_timed');
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.tasks.title')" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex flex-col gap-2">
                <HeaderTitle
                    :title="$t('tantra.tasks.title')"
                    :icon="IconHandClick"
                />
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.tasks.intro') }}
                </p>
            </header>

            <p
                v-if="!tasks.length"
                class="rounded-xl border border-dashed border-border p-8 text-center text-muted-foreground"
            >
                {{ $t('tantra.tasks.empty') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-2">
                <article
                    v-for="task in tasks"
                    :key="task.id"
                    class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 text-sm"
                    :data-testid="`tantra-task-${task.engine_post_id}`"
                >
                    <header class="flex flex-wrap items-center gap-2">
                        <b class="font-heading text-base">{{
                            task.engine_post_id
                        }}</b>
                        <Badge variant="outline">{{
                            $t(`tantra.tasks.kinds.${task.kind}`)
                        }}</Badge>
                        <Badge
                            :variant="
                                task.status === 'due'
                                    ? 'default'
                                    : task.status === 'expired'
                                      ? 'destructive'
                                      : 'secondary'
                            "
                            >{{
                                $t(`tantra.tasks.status.${task.status}`)
                            }}</Badge
                        >
                        <span class="text-muted-foreground">{{
                            when(task.due_at)
                        }}</span>
                        <a
                            v-if="task.review_url"
                            :href="task.review_url"
                            class="ml-auto text-xs underline"
                            >{{ $t('tantra.tasks.open_review') }}</a
                        >
                    </header>

                    <template v-if="task.kind === 'linkedin_poll'">
                        <div class="rounded-lg bg-muted/60 p-3">
                            <div class="font-medium">
                                {{ task.poll?.question }}
                            </div>
                            <ol class="mt-1 list-decimal pl-5">
                                <li
                                    v-for="(option, i) in task.poll?.options ??
                                    []"
                                    :key="i"
                                >
                                    {{ option }}
                                </li>
                            </ol>
                        </div>
                        <div
                            v-if="task.text"
                            class="rounded-lg bg-muted/60 p-3 whitespace-pre-line"
                        >
                            {{ task.text }}
                        </div>
                    </template>
                    <template v-else>
                        <div
                            v-for="(block, i) in [task.text, ...task.thread]"
                            :key="i"
                            class="flex flex-col gap-1 rounded-lg bg-muted/60 p-3"
                        >
                            <div
                                v-if="task.thread.length"
                                class="text-xs text-muted-foreground"
                            >
                                {{ i + 1 }}/{{ task.thread.length + 1 }}
                            </div>
                            <div class="whitespace-pre-line">{{ block }}</div>
                            <div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="copy(block)"
                                    >{{ $t('tantra.tasks.copy') }}</Button
                                >
                            </div>
                        </div>
                    </template>

                    <a
                        v-if="task.media_url"
                        :href="task.media_url"
                        target="_blank"
                        rel="noopener"
                        download
                        class="block w-40 overflow-hidden rounded-lg border border-border"
                        :title="$t('tantra.tasks.download_image')"
                    >
                        <img :src="task.media_url" alt="" class="w-full" />
                    </a>

                    <div
                        v-if="
                            task.can_mark &&
                            (task.status === 'due' || task.status === 'waiting')
                        "
                        class="flex flex-col gap-2"
                    >
                        <div class="flex flex-wrap gap-2">
                            <Button @click="open(task)">{{
                                task.kind === 'linkedin_poll'
                                    ? $t('tantra.tasks.open_linkedin')
                                    : $t('tantra.tasks.open_x')
                            }}</Button>
                            <Button
                                variant="ghost"
                                :disabled="busy === task.id"
                                @click="act(task, 'skip')"
                                >{{ $t('tantra.tasks.skip') }}</Button
                            >
                        </div>
                        <div class="flex gap-2">
                            <Input
                                v-model="urls[task.id]"
                                :placeholder="
                                    $t('tantra.tasks.url_placeholder')
                                "
                            />
                            <Button
                                variant="outline"
                                :disabled="busy === task.id"
                                @click="act(task, 'posted')"
                                >{{ $t('tantra.tasks.posted') }}</Button
                            >
                        </div>
                    </div>
                    <a
                        v-else-if="task.posted_url"
                        :href="task.posted_url"
                        target="_blank"
                        rel="noopener"
                        class="text-xs underline"
                        >{{ $t('tantra.tasks.view_post') }}</a
                    >
                </article>
            </div>
        </div>
    </AppLayout>
</template>
