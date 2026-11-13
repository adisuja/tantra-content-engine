<script setup lang="ts">
// tantra: the Tantra part of an idea card (PRD 25.8): network, kind and week
// badges, a small phone preview, and either Send to review or, while the
// network has no channel, "Connect <Network> to publish".
import { Link } from '@inertiajs/vue3';
import {
    IconArrowsMaximize,
    IconPlugConnected,
    IconSend,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import IdeaPhone from '@/components/tantra/ideas/IdeaPhone.vue';
import {
    ideaRow,
    networkOf,
    sendIdeaToReview,
    useTantraIdeas,
} from '@/components/tantra/ideas/tantraIdeas';
import type { TantraIdeaCard } from '@/components/tantra/ideas/types';
import PhonePreview from '@/components/tantra/review/PhonePreview.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';

const props = defineProps<{
    ideaId: string;
    title: string | null;
    idea: TantraIdeaCard;
    /** The drag preview: no actions. */
    still?: boolean;
}>();

const context = useTantraIdeas();
const board = computed(() => context?.board.value ?? null);
const network = computed(() => networkOf(board.value, props.idea.platform));
const row = computed(() =>
    ideaRow(props.ideaId, props.title, props.idea, network.value),
);
const viewer = computed(() => board.value?.viewer ?? {});

const needsChannel = computed(
    () => !network.value.connected && !network.value.by_hand,
);
const canSend = computed(
    () =>
        props.idea.state === 'idea' &&
        !needsChannel.value &&
        (board.value?.can_send ?? false),
);

const kindLabel = computed(() =>
    trans(`tantra.ideas.kinds.${props.idea.kind}`),
);

const weekLabel = computed((): string | null => {
    if (props.idea.cadence_week !== null) {
        return trans('tantra.ideas.week', {
            n: String(props.idea.cadence_week),
        });
    }
    if (props.idea.target_date) {
        const date = new Date(`${props.idea.target_date}T12:00:00`);

        return trans('tantra.ideas.week_of', {
            date: date.toLocaleDateString(undefined, {
                day: 'numeric',
                month: 'short',
            }),
        });
    }

    return null;
});

const { open: openConnectDialog } = useConnectChannelDialog();

const sending = ref(false);
const send = async (): Promise<void> => {
    if (!canSend.value || sending.value) {
        return;
    }
    sending.value = true;
    const result = await sendIdeaToReview(props.ideaId);
    sending.value = false;

    if (result.ok) {
        toast.success(result.message || trans('tantra.ideas.sent'));
        context?.reload();
    } else {
        toast.error(result.message || trans('tantra.ideas.send_failed'));
    }
};

const previewOpen = ref(false);
</script>

<template>
    <div
        class="-mx-4 -mt-4 flex flex-col gap-2 border-b bg-muted/40 px-3 pt-3 pb-2"
        :data-testid="`tantra-idea-${ideaId}`"
    >
        <div class="flex flex-wrap items-center gap-1">
            <Badge variant="outline">{{ network.label }}</Badge>
            <Badge
                :variant="idea.kind === 'new' ? 'secondary' : 'info'"
                :data-testid="`tantra-idea-kind-${ideaId}`"
            >
                {{ kindLabel }}
            </Badge>
            <Badge v-if="weekLabel" variant="secondary">{{ weekLabel }}</Badge>
            <Badge v-if="idea.state === 'in_review'" variant="success">
                {{ $t('tantra.ideas.in_review') }}
            </Badge>
            <Badge v-else-if="idea.state === 'dropped'" variant="warning">
                {{ $t('tantra.ideas.dropped') }}
            </Badge>
            <Badge v-if="network.by_hand" variant="outline">
                {{ $t('tantra.ideas.by_hand') }}
            </Badge>
        </div>

        <IdeaPhone :item="row" :viewer="viewer" />

        <div
            v-if="!still"
            class="flex flex-wrap items-center gap-1"
            @click.stop
        >
            <Button
                variant="ghost"
                size="sm"
                :data-testid="`tantra-idea-preview-${ideaId}`"
                @click="previewOpen = true"
            >
                <IconArrowsMaximize class="size-3.5" />
                {{ $t('tantra.ideas.preview') }}
            </Button>

            <template v-if="idea.state === 'idea'">
                <Button
                    v-if="needsChannel"
                    variant="outline"
                    size="sm"
                    :data-testid="`tantra-idea-connect-${ideaId}`"
                    @click="openConnectDialog()"
                >
                    <IconPlugConnected class="size-3.5" />
                    {{ $t('tantra.ideas.connect', { network: network.label }) }}
                </Button>
                <Button
                    size="sm"
                    :disabled="!canSend || sending"
                    :title="
                        needsChannel
                            ? $t('tantra.ideas.connect', {
                                  network: network.label,
                              })
                            : board?.can_send
                              ? undefined
                              : $t('tantra.ideas.no_profile')
                    "
                    :data-testid="`tantra-idea-send-${ideaId}`"
                    @click="send"
                >
                    <IconSend class="size-3.5" />
                    {{
                        sending
                            ? $t('tantra.ideas.sending')
                            : $t('tantra.ideas.send_to_review')
                    }}
                </Button>
            </template>
            <Button
                v-else-if="idea.state === 'in_review'"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    href="/tantra/review"
                    :data-testid="`tantra-idea-open-review-${ideaId}`"
                >
                    {{ $t('tantra.ideas.open_review') }}
                </Link>
            </Button>
        </div>

        <Dialog v-model:open="previewOpen">
            <DialogContent class="max-w-xl" @click.stop>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('tantra.ideas.preview_title', {
                                network: network.label,
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ title || idea.external_key }}
                    </DialogDescription>
                </DialogHeader>
                <PhonePreview
                    v-if="previewOpen"
                    :item="row"
                    :viewer="viewer"
                    :max-scale="0.8"
                    :fit-height="640"
                />
            </DialogContent>
        </Dialog>
    </div>
</template>
