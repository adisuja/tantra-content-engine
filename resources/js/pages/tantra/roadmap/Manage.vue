<script setup lang="ts">
// tantra: Settings > Roadmap (RoadmapAdminController). The public page is
// read only; the instance owner moves, edits and removes cards here.
import { Head } from '@inertiajs/vue3';
import {
    IconExternalLink,
    IconPencil,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

interface Item {
    id: string;
    title: string;
    description: string | null;
    column: string;
    column_label: string;
    product: string;
    product_label: string;
    category: string | null;
    position: number;
    source: 'team' | 'request';
    created_at: string | null;
    shipped_at: string | null;
    requester: { name: string | null; email: string | null } | null;
}

interface Option {
    key: string;
    label: string;
}

const props = defineProps<{
    items: Item[];
    columns: Option[];
    products: Option[];
    categories: string[];
    publicUrl: string;
}>();

const items = ref<Item[]>([...props.items]);
const busy = ref<string | null>(null);

const grouped = computed(() =>
    props.columns.map((column) => ({
        ...column,
        items: items.value
            .filter((i) => i.column === column.key)
            .sort((a, b) => a.position - b.position),
    })),
);

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};

async function call(
    method: string,
    path: string,
    body?: Record<string, unknown>,
): Promise<{
    ok: boolean;
    data: { message?: string; item?: Item; errors?: Record<string, string[]> };
}> {
    try {
        const res = await fetch(`/settings/workspace/roadmap/${path}`, {
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

        return { ok: res.ok, data: await res.json().catch(() => ({})) };
    } catch {
        return {
            ok: false,
            data: { message: 'The engine could not be reached.' },
        };
    }
}

const failure = (data: {
    message?: string;
    errors?: Record<string, string[]>;
}): string =>
    (data.errors ? Object.values(data.errors).flat()[0] : undefined) ??
    data.message ??
    'Not saved.';

const replace = (item: Item): void => {
    const at = items.value.findIndex((i) => i.id === item.id);
    if (at >= 0) items.value.splice(at, 1, item);
    else items.value.push(item);
};

const move = async (item: Item, column: string): Promise<void> => {
    if (column === item.column) return;
    busy.value = item.id;
    const { ok, data } = await call('POST', `items/${item.id}/move`, {
        column,
    });
    busy.value = null;
    if (!ok || !data.item) {
        toast.error(failure(data));

        return;
    }
    replace(data.item);
    toast.success(data.message ?? 'Moved.');
};

const remove = async (item: Item): Promise<void> => {
    if (
        !window.confirm(
            `Delete "${item.title}"? It leaves the public roadmap at once.`,
        )
    )
        return;
    busy.value = item.id;
    const { ok, data } = await call('DELETE', `items/${item.id}`);
    busy.value = null;
    if (!ok) {
        toast.error(failure(data));

        return;
    }
    items.value = items.value.filter((i) => i.id !== item.id);
    toast.success('Deleted.');
};

// One dialog for adding and editing.
const dialogOpen = ref(false);
const editing = ref<Item | null>(null);
const draft = ref({
    title: '',
    description: '',
    category: '',
    product: 'content-engine',
    column: 'planned',
});
const saving = ref(false);

const openNew = (): void => {
    editing.value = null;
    draft.value = {
        title: '',
        description: '',
        category: '',
        product: 'content-engine',
        column: 'planned',
    };
    dialogOpen.value = true;
};

const openEdit = (item: Item): void => {
    editing.value = item;
    draft.value = {
        title: item.title,
        description: item.description ?? '',
        category: item.category ?? '',
        product: item.product,
        column: item.column,
    };
    dialogOpen.value = true;
};

const save = async (): Promise<void> => {
    saving.value = true;
    const body = {
        title: draft.value.title,
        description: draft.value.description || null,
        category: draft.value.category || null,
        product: draft.value.product,
        column: draft.value.column,
    };
    const { ok, data } = editing.value
        ? await call('PUT', `items/${editing.value.id}`, body)
        : await call('POST', 'items', body);
    saving.value = false;
    if (!ok || !data.item) {
        toast.error(failure(data));

        return;
    }
    replace(data.item);
    dialogOpen.value = false;
    toast.success(editing.value ? 'Saved.' : 'Added.');
};

const meta = (item: Item): string =>
    `${item.product_label}, added ${date(item.created_at)}` +
    (item.requester ? `, from ${item.requester.name || 'someone'}` : '');

const date = (value: string | null): string =>
    value
        ? new Intl.DateTimeFormat('en-GB', {
              day: 'numeric',
              month: 'short',
          }).format(new Date(value))
        : '';
</script>

<template>
    <Head title="Roadmap" />
    <SettingsLayout
        title="Roadmap"
        description="The public roadmap is read only. Move, edit and remove cards here. Requests from the form land in Q: Requests with the email the person gave."
    >
        <div class="flex flex-col gap-8" data-testid="roadmap-manage">
            <div class="flex flex-wrap items-center gap-2">
                <Button size="sm" @click="openNew">
                    <IconPlus class="size-4" /> Add a card
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    as="a"
                    :href="publicUrl"
                    target="_blank"
                    rel="noopener"
                >
                    <IconExternalLink class="size-4" /> Open the public roadmap
                </Button>
            </div>

            <section
                v-for="column in grouped"
                :key="column.key"
                class="flex flex-col gap-2"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    {{ column.label }}
                    <span class="text-xs font-normal text-muted-foreground">{{
                        column.items.length
                    }}</span>
                </h2>
                <p
                    v-if="column.items.length === 0"
                    class="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground"
                >
                    Nothing here.
                </p>
                <ul class="divide-y rounded-xl border bg-card">
                    <li
                        v-for="item in column.items"
                        :key="item.id"
                        class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start"
                        :data-testid="`roadmap-item-${item.id}`"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="font-medium">{{
                                    item.title
                                }}</span>
                                <Badge
                                    v-if="item.category"
                                    variant="secondary"
                                    >{{ item.category }}</Badge
                                >
                                <Badge
                                    v-if="item.source === 'request'"
                                    variant="outline"
                                    >Request</Badge
                                >
                            </div>
                            <p
                                v-if="item.description"
                                class="mt-1 line-clamp-2 text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ item.description }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ meta(item) }}
                                <a
                                    v-if="item.requester?.email"
                                    class="underline"
                                    :href="`mailto:${item.requester.email}`"
                                    >{{ item.requester.email }}</a
                                >
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <select
                                :value="item.column"
                                :disabled="busy === item.id"
                                :aria-label="`Move ${item.title}`"
                                class="h-8 cursor-pointer rounded-md border border-input bg-transparent px-2 text-xs font-medium shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                @change="
                                    move(
                                        item,
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option
                                    v-for="c in columns"
                                    :key="c.key"
                                    :value="c.key"
                                >
                                    {{ c.label }}
                                </option>
                            </select>
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8"
                                :aria-label="`Edit ${item.title}`"
                                @click="openEdit(item)"
                            >
                                <IconPencil class="size-4" />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                class="size-8 text-destructive"
                                :disabled="busy === item.id"
                                :aria-label="`Delete ${item.title}`"
                                @click="remove(item)"
                            >
                                <IconTrash class="size-4" />
                            </Button>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? 'Edit the card' : 'Add a card'
                    }}</DialogTitle>
                    <DialogDescription>
                        Plain text, no en or em dashes. A blank line in the
                        description splits the card line from the detail people
                        see when they open it.
                    </DialogDescription>
                </DialogHeader>
                <form class="flex flex-col gap-4" @submit.prevent="save">
                    <div class="flex flex-col gap-1.5">
                        <Label for="rm-title">Title</Label>
                        <Input
                            id="rm-title"
                            v-model="draft.title"
                            maxlength="120"
                            required
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="rm-description">Description</Label>
                        <Textarea
                            id="rm-description"
                            v-model="draft.description"
                            rows="5"
                            maxlength="2000"
                        />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="flex flex-col gap-1.5">
                            <Label for="rm-category">Category</Label>
                            <Input
                                id="rm-category"
                                v-model="draft.category"
                                list="rm-categories"
                                maxlength="40"
                            />
                            <datalist id="rm-categories">
                                <option
                                    v-for="c in categories"
                                    :key="c"
                                    :value="c"
                                />
                            </datalist>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="rm-product">Product</Label>
                            <NativeSelect
                                id="rm-product"
                                v-model="draft.product"
                                class="w-full"
                            >
                                <NativeSelectOption
                                    v-for="p in products"
                                    :key="p.key"
                                    :value="p.key"
                                    >{{ p.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label for="rm-column">Column</Label>
                            <NativeSelect
                                id="rm-column"
                                v-model="draft.column"
                                class="w-full"
                            >
                                <NativeSelectOption
                                    v-for="c in columns"
                                    :key="c.key"
                                    :value="c.key"
                                    >{{ c.label }}</NativeSelectOption
                                >
                            </NativeSelect>
                        </div>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="saving">{{
                            editing ? 'Save' : 'Add'
                        }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </SettingsLayout>
</template>
