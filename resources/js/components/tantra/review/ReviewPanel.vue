<script setup lang="ts">
// tantra: the side panel next to each phone: what the post is built on, the
// numbers to sign off, flags, history, feedback and the review actions.
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import type { ReviewAction } from '@/components/tantra/review/reviewApi';
import { sendReviewAction } from '@/components/tantra/review/reviewApi';
import type { ReviewItemRow } from '@/components/tantra/review/types';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{ item: ReviewItemRow }>();
const emit = defineEmits<{ changed: [] }>();

const busy = ref<ReviewAction | null>(null);
const savedAt = ref<string | null>(null);
const mode = ref<'none' | 'changes' | 'reject'>('none');
const note = ref('');
const comment = ref('');

const status = computed(() => props.item.review_status);
const waiting = computed(
    () => status.value === 'in_review' || status.value === 'changes_requested',
);
const openNumber = computed(() =>
    props.item.numbers_for_signoff.find((n) => !n.signed_at),
);
const canUnapprove = computed(
    () =>
        props.item.can_review &&
        (status.value === 'scheduled' || status.value === 'approved'),
);

const formatDate = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';

const plannedLabel = computed(() => {
    const p = props.item.planned;
    if (!p.date) return '';
    const d = new Date(`${p.date}T12:00:00Z`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

    return `${d}${p.time ? ` ${p.time}` : ''}${p.tz ? ` ${p.tz}` : ''}`;
});

const run = async (
    action: ReviewAction,
    body: Record<string, unknown> = {},
): Promise<boolean> => {
    busy.value = action;
    const result = await sendReviewAction(props.item.id, action, body);
    busy.value = null;

    if (!result.ok) {
        toast.error(result.message || trans('tantra.review.not_saved'));

        return false;
    }

    savedAt.value = new Date().toISOString();
    toast.success(result.message || trans('tantra.review.saved'));
    emit('changed');

    return true;
};

const approve = (): Promise<boolean> => run('approve');
const unapprove = (): Promise<boolean> => run('unapprove');
const signOff = (index: number): Promise<boolean> => run('sign-off', { index });

const submitNote = async (): Promise<void> => {
    const ok =
        mode.value === 'changes'
            ? await run('request-changes', { note: note.value })
            : await run('reject', { note: note.value || null });
    if (ok) {
        mode.value = 'none';
        note.value = '';
    }
};

const submitComment = async (): Promise<void> => {
    if (await run('comments', { body: comment.value })) {
        comment.value = '';
    }
};

defineExpose({ approve, openChanges: () => (mode.value = 'changes') });

const statusVariant = (
    s: string,
): 'default' | 'secondary' | 'destructive' | 'outline' =>
    s === 'scheduled' || s === 'published' || s === 'approved'
        ? 'default'
        : s === 'rejected' || s === 'failed'
          ? 'destructive'
          : s === 'changes_requested'
            ? 'secondary'
            : 'outline';
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-4 rounded-xl border border-border bg-card p-4 text-sm"
        :data-testid="`tantra-panel-${item.engine_post_id}`"
    >
        <header class="flex flex-wrap items-center gap-2">
            <span class="font-heading text-base font-medium">
                {{ item.engine_post_id }}
            </span>
            <Badge :variant="statusVariant(status)">
                {{ $t(`tantra.review.status.${status}`) }}
            </Badge>
            <Badge v-if="item.revision > 1" variant="outline">
                {{ $t('tantra.review.revision', { n: String(item.revision) }) }}
            </Badge>
            <Badge v-if="item.assisted_manual" variant="outline">
                {{ $t('tantra.review.manual') }}
            </Badge>
            <span
                v-if="savedAt"
                class="ml-auto text-xs text-muted-foreground"
                data-testid="tantra-saved"
            >
                {{ $t('tantra.review.saved') }}
            </span>
        </header>

        <p v-if="item.idea" class="text-muted-foreground">{{ item.idea }}</p>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
            <template v-if="plannedLabel">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.planned') }}
                </dt>
                <dd>{{ plannedLabel }}</dd>
            </template>
            <template v-if="item.scheduled_at">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.goes_out') }}
                </dt>
                <dd>
                    {{ formatDate(item.scheduled_at) }}
                    <span
                        v-if="item.slot_fallback"
                        class="text-amber-700 dark:text-amber-400"
                    >
                        · {{ $t('tantra.review.moved') }}
                    </span>
                </dd>
            </template>
            <template v-if="item.pillar">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.pillar') }}
                </dt>
                <dd>{{ item.pillar }}</dd>
            </template>
            <template v-if="item.format">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.format') }}
                </dt>
                <dd>{{ item.format }}</dd>
            </template>
            <template v-if="item.sample.id || item.sample.creator">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.sample') }}
                </dt>
                <dd>
                    <b>{{ item.sample.id }}</b>
                    <template v-if="item.sample.creator">
                        · {{ item.sample.creator }}</template
                    >
                    <template v-if="item.sample.url">
                        ·
                        <a
                            :href="item.sample.url"
                            target="_blank"
                            rel="noopener"
                            class="underline"
                            >{{ $t('tantra.review.view_original') }}</a
                        >
                    </template>
                    <div v-if="item.sample.what" class="text-muted-foreground">
                        {{ item.sample.what }}
                    </div>
                </dd>
            </template>
            <template v-if="item.copy_basis">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.copy_basis') }}
                </dt>
                <dd>{{ item.copy_basis }}</dd>
            </template>
            <template v-if="item.visual_copy.length">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.on_visual') }}
                </dt>
                <dd>
                    <ol class="list-decimal pl-4">
                        <li v-for="(line, i) in item.visual_copy" :key="i">
                            {{ line }}
                        </li>
                    </ol>
                </dd>
            </template>
            <template v-if="item.platform !== 'x'">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.first_comment') }}
                </dt>
                <dd class="whitespace-pre-line">
                    {{
                        item.first_comment ||
                        $t('tantra.review.no_first_comment')
                    }}
                </dd>
            </template>
            <template v-if="item.resource">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.resource') }}
                </dt>
                <dd>
                    <b>{{ item.resource.name }}</b>
                    <template v-if="item.resource.keyword">
                        · {{ item.resource.keyword }}</template
                    >
                    <div
                        v-if="item.resource.what"
                        class="text-muted-foreground"
                    >
                        {{ item.resource.what }}
                    </div>
                </dd>
            </template>
            <template v-if="item.sources.length">
                <dt class="text-muted-foreground">
                    {{ $t('tantra.review.sources') }}
                </dt>
                <dd>
                    <div
                        v-for="(source, i) in item.sources"
                        :key="i"
                        class="break-words"
                    >
                        {{ source }}
                    </div>
                </dd>
            </template>
        </dl>

        <div
            v-if="item.flags.length"
            class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
        >
            <div class="mb-1 font-medium">{{ $t('tantra.review.flags') }}</div>
            <div v-for="(flag, i) in item.flags" :key="i">{{ flag }}</div>
        </div>

        <div v-if="item.numbers_for_signoff.length" class="flex flex-col gap-2">
            <div class="font-medium">{{ $t('tantra.review.numbers') }}</div>
            <div
                v-for="n in item.numbers_for_signoff"
                :key="n.index"
                class="flex items-start gap-2 rounded-lg border border-border p-2"
            >
                <span class="min-w-0 flex-1">
                    {{ n.text }}
                    <span class="block text-xs text-muted-foreground">
                        {{
                            n.signer === 'adi'
                                ? $t('tantra.review.signer_adi')
                                : $t('tantra.review.signer_owner')
                        }}
                    </span>
                </span>
                <Badge v-if="n.signed_at" variant="default">
                    {{ $t('tantra.review.signed') }}
                </Badge>
                <Button
                    v-else-if="n.can_sign"
                    size="sm"
                    variant="outline"
                    :disabled="busy !== null"
                    @click="signOff(n.index)"
                >
                    {{ $t('tantra.review.sign') }}
                </Button>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <template v-if="item.can_review && waiting">
                <Button
                    :disabled="busy !== null || !!openNumber"
                    :title="
                        openNumber
                            ? $t('tantra.review.sign_first', {
                                  text: openNumber.text,
                              })
                            : undefined
                    "
                    data-testid="tantra-approve"
                    @click="approve"
                >
                    {{ $t('tantra.review.approve') }}
                </Button>
                <Button
                    variant="outline"
                    :disabled="busy !== null"
                    @click="mode = mode === 'changes' ? 'none' : 'changes'"
                >
                    {{ $t('tantra.review.request_changes') }}
                </Button>
                <Button
                    variant="ghost"
                    :disabled="busy !== null"
                    @click="mode = mode === 'reject' ? 'none' : 'reject'"
                >
                    {{ $t('tantra.review.reject') }}
                </Button>
            </template>
            <Button
                v-if="canUnapprove"
                variant="outline"
                :disabled="busy !== null"
                @click="unapprove"
            >
                {{ $t('tantra.review.unapprove') }}
            </Button>
            <Button
                v-if="item.post_id && item.can_review && waiting"
                variant="ghost"
                as="a"
                :href="`/posts/${item.post_id}/edit`"
            >
                {{ $t('tantra.review.edit') }}
            </Button>
            <Button
                v-if="item.media.download"
                variant="ghost"
                as="a"
                :href="item.media.download.url"
                target="_blank"
                rel="noopener"
            >
                {{ item.media.download.label }}
            </Button>
            <Button
                v-if="item.post_url"
                variant="ghost"
                as="a"
                :href="item.post_url"
                target="_blank"
                rel="noopener"
            >
                {{ $t('tantra.review.view_live') }}
            </Button>
        </div>
        <p
            v-if="item.can_review && waiting && openNumber"
            class="text-xs text-muted-foreground"
        >
            {{ $t('tantra.review.sign_first', { text: openNumber.text }) }}
        </p>
        <p
            v-if="!item.can_review && waiting"
            class="text-xs text-muted-foreground"
        >
            {{ $t('tantra.review.only_owner') }}
        </p>

        <form
            v-if="mode !== 'none'"
            class="flex flex-col gap-2"
            @submit.prevent="submitNote"
        >
            <Textarea
                v-model="note"
                rows="3"
                :placeholder="
                    mode === 'changes'
                        ? $t('tantra.review.changes_placeholder')
                        : $t('tantra.review.reject_placeholder')
                "
                :required="mode === 'changes'"
            />
            <div class="flex gap-2">
                <Button
                    type="submit"
                    size="sm"
                    :disabled="
                        busy !== null || (mode === 'changes' && !note.trim())
                    "
                >
                    {{
                        mode === 'changes'
                            ? $t('tantra.review.send_changes')
                            : $t('tantra.review.reject')
                    }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="mode = 'none'"
                >
                    {{ $t('tantra.review.cancel') }}
                </Button>
            </div>
        </form>

        <details v-if="item.events.length" class="group">
            <summary class="cursor-pointer font-medium">
                {{ $t('tantra.review.history') }} · {{ item.events.length }}
            </summary>
            <ol class="mt-2 flex flex-col gap-2">
                <li
                    v-for="(event, i) in item.events"
                    :key="i"
                    class="border-l-2 border-border pl-3"
                >
                    <div class="text-xs text-muted-foreground">
                        {{ $t(`tantra.review.events.${event.kind}`) }}
                        <template v-if="event.by"> · {{ event.by }}</template>
                        · {{ formatDate(event.at) }}
                    </div>
                    <div v-if="event.body" class="whitespace-pre-line">
                        {{ event.body }}
                    </div>
                </li>
            </ol>
        </details>

        <form class="flex flex-col gap-2" @submit.prevent="submitComment">
            <Textarea
                v-model="comment"
                rows="2"
                :placeholder="$t('tantra.review.comment_placeholder')"
            />
            <div>
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    :disabled="busy !== null || !comment.trim()"
                >
                    {{ $t('tantra.review.comment') }}
                </Button>
            </div>
        </form>
    </section>
</template>
