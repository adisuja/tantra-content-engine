<script setup lang="ts">
// tantra: replies to a Threads channel's latest posts, answered in place
// (ThreadsRepliesController). Reads threads_read_replies, posts threads_manage_replies.
import { trans } from 'laravel-vue-i18n';
import { onMounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

interface ThreadsReply {
    id: string;
    text: string | null;
    username: string | null;
    permalink: string | null;
    timestamp: string | null;
}

interface ThreadsPost {
    id: string;
    text: string | null;
    permalink: string | null;
    timestamp: string | null;
    replies_failed?: boolean;
    replies: ThreadsReply[];
}

const props = defineProps<{ channelId: string }>();

const posts = ref<ThreadsPost[]>([]);
const loading = ref(true);
const failed = ref(false);
const needsReconnect = ref(false);
const reconnectUrl = ref<string | null>(null);
/** Replies sent from here, shown under the reply they answer. */
const sent = reactive<
    Record<string, { text: string; permalink: string | null }>
>({});
const drafts = reactive<Record<string, string>>({});
const sending = ref<string | null>(null);
const openReply = ref<string | null>(null);

const endpoint = (): string =>
    `/tantra/channels/${encodeURIComponent(props.channelId)}/threads-replies`;

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const when = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : '';

async function load(): Promise<void> {
    loading.value = true;

    try {
        const response = await fetch(endpoint(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        posts.value = data.posts ?? [];
        failed.value = Boolean(data.failed) || !response.ok;
        needsReconnect.value = Boolean(data.needs_reconnect);
        reconnectUrl.value = data.reconnect_url ?? null;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

async function send(replyToId: string): Promise<void> {
    const text = (drafts[replyToId] ?? '').trim();

    if (!text || sending.value) {
        return;
    }

    sending.value = replyToId;

    try {
        const response = await fetch(endpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ reply_to_id: replyToId, text }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            if (data.needs_reconnect) {
                needsReconnect.value = true;
                reconnectUrl.value = data.reconnect_url ?? null;
            }

            toast.error(data.message || trans('tantra.threads_replies.failed'));

            return;
        }

        toast.success(trans('tantra.threads_replies.sent'));
        sent[replyToId] = { text, permalink: data.permalink ?? null };
        drafts[replyToId] = '';
        openReply.value = null;
    } finally {
        sending.value = null;
    }
}

onMounted(load);
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-4 sm:p-5"
        data-testid="threads-replies"
    >
        <h3 class="text-base font-semibold">
            {{ $t('tantra.threads_replies.title') }}
        </h3>
        <p class="mt-1 text-sm text-muted-foreground">
            {{ $t('tantra.threads_replies.help') }}
        </p>

        <p v-if="loading" class="mt-4 text-sm text-muted-foreground">…</p>
        <div
            v-else-if="needsReconnect"
            class="mt-4 flex flex-wrap items-center gap-3"
            data-testid="threads-replies-reconnect"
        >
            <p class="text-sm">
                {{ $t('tantra.threads_replies.needs_reconnect') }}
            </p>
            <Button v-if="reconnectUrl" as="a" :href="reconnectUrl" size="sm">
                {{ $t('tantra.threads_replies.reconnect') }}
            </Button>
        </div>
        <p v-else-if="failed" class="mt-4 text-sm text-destructive">
            {{ $t('tantra.threads_replies.failed') }}
        </p>
        <p v-else-if="!posts.length" class="mt-4 text-sm text-muted-foreground">
            {{ $t('tantra.threads_replies.no_posts') }}
        </p>

        <ul v-else class="mt-4 space-y-4">
            <li
                v-for="post in posts"
                :key="post.id"
                class="border-t border-border pt-4 first:border-t-0 first:pt-0"
                data-testid="threads-replies-post"
            >
                <a
                    :href="post.permalink ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="line-clamp-2 text-sm font-medium hover:underline"
                    >{{ post.text || post.permalink }}</a
                >
                <p class="text-xs text-muted-foreground">
                    {{ when(post.timestamp) }}
                </p>

                <p
                    v-if="post.replies_failed"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ $t('tantra.threads_replies.replies_failed') }}
                </p>
                <p
                    v-else-if="!post.replies.length"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ $t('tantra.threads_replies.empty') }}
                </p>
                <ul v-else class="mt-2 space-y-3 border-l-2 border-border pl-3">
                    <li
                        v-for="reply in post.replies"
                        :key="reply.id"
                        data-testid="threads-replies-reply"
                    >
                        <p class="text-sm">
                            <span class="font-medium"
                                >@{{ reply.username }}</span
                            >
                            {{ reply.text }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ when(reply.timestamp) }}
                            ·
                            <button
                                type="button"
                                class="font-medium text-foreground hover:underline"
                                @click="openReply = reply.id"
                            >
                                {{ $t('tantra.threads_replies.reply') }}
                            </button>
                        </p>
                        <p
                            v-if="sent[reply.id]"
                            class="mt-2 border-l-2 border-primary pl-3 text-sm"
                            data-testid="threads-replies-sent"
                        >
                            <span class="font-medium">{{
                                $t('tantra.threads_replies.you_replied')
                            }}</span>
                            {{ sent[reply.id].text }}
                            <a
                                v-if="sent[reply.id].permalink"
                                :href="sent[reply.id].permalink ?? undefined"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-muted-foreground hover:underline"
                                >↗</a
                            >
                        </p>
                        <form
                            v-if="openReply === reply.id"
                            class="mt-2 flex flex-col gap-2"
                            @submit.prevent="send(reply.id)"
                        >
                            <Textarea
                                v-model="drafts[reply.id]"
                                :placeholder="
                                    $t('tantra.threads_replies.placeholder')
                                "
                                maxlength="500"
                                rows="2"
                            />
                            <Button
                                type="submit"
                                size="sm"
                                class="self-end"
                                :disabled="
                                    sending === reply.id ||
                                    !(drafts[reply.id] ?? '').trim()
                                "
                            >
                                {{ $t('tantra.threads_replies.reply') }}
                            </Button>
                        </form>
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>
