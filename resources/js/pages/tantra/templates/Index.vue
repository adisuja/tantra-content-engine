<script setup lang="ts">
// tantra: content templates (PRD 25.9). One per sample from the taxonomy, plus
// any built from a post someone pasted. "Add from a post" queues the build.
import { Head, router } from '@inertiajs/vue3';
import { IconLink, IconPlus } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';

interface Card {
    id: string;
    key: string;
    title: string;
    platform: string;
    format: string | null;
    family: string | null;
    creator: string | null;
    status: string;
    state: string;
    origin: string;
    preview: string | null;
    source_url: string | null;
}
interface Detail extends Card {
    archetype: string | null;
    description: string | null;
    spec: string | null;
    copy_template: string | null;
    palette: string[];
    note: string | null;
}

const props = defineProps<{
    templates: Card[];
    platforms: Record<string, number>;
    formats: string[];
}>();

const platform = ref('');
const format = ref('');
const search = ref('');
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.templates.filter(
        (t) =>
            (!platform.value || t.platform === platform.value) &&
            (!format.value || t.format === format.value) &&
            (!q ||
                `${t.key} ${t.title} ${t.creator ?? ''} ${t.format ?? ''}`
                    .toLowerCase()
                    .includes(q)),
    );
});

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};
const call = async <T,>(method: string, url: string, body?: object) => {
    const r = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = (await r.json().catch(() => ({}))) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
    };

    return {
        ok: r.ok,
        data: json.data ?? null,
        message:
            (json.errors ? Object.values(json.errors).flat()[0] : undefined) ??
            json.message ??
            '',
    };
};
const open = ref<Detail | null>(null);
const openDetail = async (t: Card) => {
    const r = await call<Detail>('GET', `/tantra/templates/${t.id}`);
    if (r.ok && r.data) {
        open.value = r.data;
    }
};

const adding = ref(false);
const url = ref('');
const note = ref('');
const busy = ref(false);
const add = async () => {
    busy.value = true;
    const r = await call<Card>('POST', '/tantra/templates/intake', {
        url: url.value.trim(),
        note: note.value.trim() || null,
    });
    busy.value = false;
    if (r.ok) {
        toast.success(r.message);
        adding.value = false;
        url.value = '';
        note.value = '';
        router.reload({ only: ['templates', 'platforms', 'formats'] });
    } else {
        toast.error(r.message || trans('tantra.templates.not_added'));
    }
};

const stateTone = (s: string): 'default' | 'secondary' | 'destructive' =>
    s === 'failed' ? 'destructive' : s === 'live' ? 'default' : 'secondary';
</script>

<template>
    <Head :title="$t('tantra.templates.title')" />

    <AppLayout full-width>
        <template #header>
            <CreateHeader />
        </template>

        <template #header-actions>
            <Button
                variant="outline"
                data-testid="content-templates-add"
                @click="adding = true"
            >
                <IconPlus class="size-4" />
                <span>{{ $t('tantra.templates.add') }}</span>
            </Button>
        </template>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <CreateTabs active="content-templates">
                <template #filters>
                    <Input
                        v-model="search"
                        class="h-8 w-44"
                        :placeholder="$t('tantra.templates.search')"
                    />
                    <select
                        v-model="platform"
                        class="h-8 rounded-md border border-border bg-background px-2 text-sm"
                        :aria-label="$t('tantra.templates.platform')"
                    >
                        <option value="">
                            {{ $t('tantra.templates.all_networks') }}
                        </option>
                        <option v-for="(n, p) in platforms" :key="p" :value="p">
                            {{ p }} ({{ n }})
                        </option>
                    </select>
                    <select
                        v-model="format"
                        class="h-8 max-w-44 rounded-md border border-border bg-background px-2 text-sm"
                        :aria-label="$t('tantra.templates.format')"
                    >
                        <option value="">
                            {{ $t('tantra.templates.all_formats') }}
                        </option>
                        <option v-for="f in formats" :key="f" :value="f">
                            {{ f }}
                        </option>
                    </select>
                </template>
            </CreateTabs>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-6 md:px-8">
                <p class="mb-4 max-w-3xl text-sm text-muted-foreground">
                    {{
                        $t('tantra.templates.intro', {
                            count: String(templates.length),
                        })
                    }}
                </p>
                <p
                    v-if="shown.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('tantra.templates.none') }}
                </p>
                <div
                    class="grid grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4"
                    data-testid="content-templates-grid"
                >
                    <button
                        v-for="t in shown"
                        :key="t.id"
                        type="button"
                        class="flex flex-col overflow-hidden rounded-xl border border-border bg-card text-left transition hover:shadow-md"
                        @click="openDetail(t)"
                    >
                        <div class="aspect-[4/5] w-full bg-muted">
                            <img
                                v-if="t.preview"
                                :src="t.preview"
                                :alt="t.title"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <div
                                v-else
                                class="flex h-full items-center justify-center p-4 text-center text-sm text-muted-foreground"
                            >
                                {{
                                    t.state === 'live'
                                        ? t.platform
                                        : $t(
                                              `tantra.templates.states.${t.state}`,
                                          )
                                }}
                            </div>
                        </div>
                        <div class="flex flex-col gap-1 p-3">
                            <div class="flex items-center gap-2 text-xs">
                                <b>{{ t.key }}</b>
                                <Badge variant="outline">{{
                                    t.platform
                                }}</Badge>
                                <Badge
                                    v-if="t.state !== 'live'"
                                    :variant="stateTone(t.state)"
                                    >{{
                                        $t(`tantra.templates.states.${t.state}`)
                                    }}</Badge
                                >
                            </div>
                            <span class="line-clamp-2 text-sm font-medium">{{
                                t.title
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                [t.format, t.creator]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <Dialog :open="open !== null" @update:open="(v) => !v && (open = null)">
            <DialogContent class="max-h-[90vh] max-w-3xl overflow-y-auto">
                <DialogHeader v-if="open">
                    <DialogTitle>{{ open.key }} · {{ open.title }}</DialogTitle>
                    <DialogDescription>{{
                        [open.platform, open.format, open.creator]
                            .filter(Boolean)
                            .join(' · ')
                    }}</DialogDescription>
                </DialogHeader>
                <div v-if="open" class="flex flex-col gap-3 text-sm">
                    <img
                        v-if="open.preview"
                        :src="open.preview"
                        :alt="open.title"
                        class="max-h-96 w-full rounded-lg object-contain"
                    />
                    <p v-if="open.description">{{ open.description }}</p>
                    <div v-if="open.palette.length" class="flex gap-1">
                        <span
                            v-for="c in open.palette"
                            :key="c"
                            class="size-6 rounded border border-border"
                            :style="{ background: c }"
                            :title="c"
                        />
                    </div>
                    <p v-if="open.archetype" class="text-muted-foreground">
                        {{ open.archetype }}
                    </p>
                    <div v-if="open.copy_template">
                        <h3 class="mb-1 font-medium">
                            {{ $t('tantra.templates.copy_template') }}
                        </h3>
                        <pre
                            class="rounded bg-muted p-2 text-xs whitespace-pre-wrap"
                            >{{ open.copy_template }}</pre
                        >
                    </div>
                    <div v-if="open.spec">
                        <h3 class="mb-1 font-medium">
                            {{ $t('tantra.templates.spec') }}
                        </h3>
                        <pre
                            class="max-h-80 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap"
                            >{{ open.spec }}</pre
                        >
                    </div>
                    <a
                        v-if="open.source_url"
                        :href="open.source_url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 underline"
                        ><IconLink class="size-4" />{{
                            $t('tantra.templates.source')
                        }}</a
                    >
                </div>
            </DialogContent>
        </Dialog>

        <Dialog :open="adding" @update:open="(v) => (adding = v)">
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ $t('tantra.templates.add') }}</DialogTitle>
                    <DialogDescription>{{
                        $t('tantra.templates.add_help')
                    }}</DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-3" @submit.prevent="add">
                    <Input
                        v-model="url"
                        type="url"
                        required
                        :placeholder="$t('tantra.templates.url')"
                        data-testid="content-templates-url"
                    />
                    <Textarea
                        v-model="note"
                        rows="3"
                        :placeholder="$t('tantra.templates.note')"
                    />
                    <Button type="submit" :disabled="busy" class="w-fit">{{
                        $t('tantra.templates.build')
                    }}</Button>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
