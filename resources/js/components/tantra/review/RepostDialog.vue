<script setup lang="ts">
// tantra: Repost (PRD 25.10). Every channel this person can post to, grouped
// by workspace, plus networks nobody has connected yet: pick any, add a note,
// and the new versions land in Review (or in Ideas until a network is connected).
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';

interface Channel {
    id: string;
    name: string;
    network: string;
    workspace: string | null;
    avatar: string | null;
    connected: boolean;
}

const props = defineProps<{ reviewItemId: string; open: boolean }>();
const emit = defineEmits<{ 'update:open': [boolean] }>();

const channels = ref<Channel[]>([]);
const unconnected = ref<string[]>([]);
const picked = ref<string[]>([]);
const networks = ref<string[]>([]);
const note = ref('');
const busy = ref(false);

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};
const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrf(),
});

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            return;
        }
        picked.value = [];
        networks.value = [];
        note.value = '';
        const r = await fetch('/tantra/repost/targets', {
            credentials: 'same-origin',
            headers: headers(),
        });
        const json = (await r.json().catch(() => ({}))) as {
            data?: { channels: Channel[]; unconnected: string[] };
        };
        channels.value = json.data?.channels ?? [];
        unconnected.value = json.data?.unconnected ?? [];
    },
);

const groups = computed(() => {
    const out: Record<string, Channel[]> = {};
    for (const c of channels.value) {
        (out[c.workspace ?? ''] ??= []).push(c);
    }

    return out;
});

const send = async () => {
    busy.value = true;
    const r = await fetch('/tantra/repost', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers(),
        body: JSON.stringify({
            review_item_id: props.reviewItemId,
            targets: [
                ...picked.value.map((id) => ({ channel_id: id })),
                ...networks.value.map((network) => ({ network })),
            ],
            note: note.value.trim() || null,
        }),
    });
    busy.value = false;
    const json = (await r.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const msg =
        (json.errors ? Object.values(json.errors).flat()[0] : undefined) ??
        json.message ??
        '';
    if (r.ok) {
        toast.success(msg);
        emit('update:open', false);
    } else {
        toast.error(msg || trans('tantra.repost.failed'));
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ $t('tantra.repost.title') }}</DialogTitle>
                <DialogDescription>{{
                    $t('tantra.repost.help')
                }}</DialogDescription>
            </DialogHeader>
            <div
                class="flex flex-col gap-4 text-sm"
                data-testid="repost-dialog"
            >
                <div
                    v-for="(list, workspace) in groups"
                    :key="workspace"
                    class="flex flex-col gap-1"
                >
                    <b>{{ workspace }}</b>
                    <label
                        v-for="c in list"
                        :key="c.id"
                        class="flex items-center gap-2"
                    >
                        <input v-model="picked" type="checkbox" :value="c.id" />
                        <img
                            v-if="c.avatar"
                            :src="c.avatar"
                            alt=""
                            class="size-5 rounded-full"
                        />
                        <span>{{ c.name }}</span>
                        <span class="text-muted-foreground capitalize">{{
                            c.network
                        }}</span>
                        <span v-if="!c.connected" class="text-destructive">{{
                            $t('tantra.repost.reconnect')
                        }}</span>
                    </label>
                </div>
                <div v-if="unconnected.length" class="flex flex-col gap-1">
                    <b>{{ $t('tantra.repost.not_connected') }}</b>
                    <label
                        v-for="n in unconnected"
                        :key="n"
                        class="flex items-center gap-2 capitalize"
                    >
                        <input v-model="networks" type="checkbox" :value="n" />
                        {{ n }}
                    </label>
                </div>
                <Textarea
                    v-model="note"
                    rows="2"
                    :placeholder="$t('tantra.repost.note')"
                />
                <Button
                    :disabled="busy || picked.length + networks.length === 0"
                    @click="send"
                    >{{
                        $t('tantra.repost.send', {
                            count: String(picked.length + networks.length),
                        })
                    }}</Button
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
