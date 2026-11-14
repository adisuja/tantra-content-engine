<script setup lang="ts">
// tantra: the Channels section of the Connectors tab
// (app/Tantra/Http/Controllers/Web/ChannelAppController.php). One card per
// developer app; saving its keys makes the networks it unlocks connectable.
import { router } from '@inertiajs/vue3';
import { IconCircleCheck, IconCopy, IconExternalLink } from '@tabler/icons-vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import PlatformLogo from '@/components/PlatformLogo.vue';
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
import { Switch } from '@/components/ui/switch';

export interface ChannelAppField {
    key: string;
    label: string;
    secret: boolean;
    switch: boolean;
    source: 'app' | 'server' | null;
    last_four: string | null;
    value: string | null;
    on: boolean | null;
}

export interface ChannelAppEntry {
    key: string;
    label: string;
    portal: string;
    help: string;
    catch: string | null;
    callbacks: string[];
    networks: { value: string; label: string; ready: boolean }[];
    fields: ChannelAppField[];
    saved_in_app: boolean;
    // Testing (only invited testers connect) or live (anyone does).
    mode: 'development' | 'live';
    default_mode: 'development' | 'live';
    testing: {
        owner: string;
        you: string;
        live: string;
        console: string;
        accept: string | null;
    };
}

const MODES = ['development', 'live'] as const;

const props = defineProps<{
    apps: ChannelAppEntry[];
    canManage: boolean;
}>();

const apps = ref<ChannelAppEntry[]>(props.apps);

const isReady = (app: ChannelAppEntry): boolean =>
    app.networks.some((n) => n.ready);

const readyCount = computed(
    () =>
        apps.value.flatMap((a) => a.networks).filter((n) => n.ready).length + 2,
);
const totalCount = computed(
    () => apps.value.flatMap((a) => a.networks).length + 2,
);

// ---- dialog --------------------------------------------------------------

const open = ref(false);
const current = ref<ChannelAppEntry | null>(null);
const form = ref<Record<string, string | boolean>>({});
const busy = ref<'' | 'save' | 'remove'>('');
const error = ref('');

const openApp = (app: ChannelAppEntry): void => {
    current.value = app;
    form.value = {
        ...Object.fromEntries(
            app.fields.map((f) => [
                f.key,
                f.switch ? Boolean(f.on) : f.secret ? '' : (f.value ?? ''),
            ]),
        ),
        mode: app.mode,
    };
    error.value = '';
    open.value = true;
};

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};

const call = async (
    method: 'PUT' | 'DELETE',
    app: string,
    body?: Record<string, unknown>,
): Promise<void> => {
    error.value = '';
    try {
        const res = await fetch(
            `/settings/workspace/connectors/channels/${app}`,
            {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: body ? JSON.stringify(body) : undefined,
            },
        );
        const data = (await res.json().catch(() => ({}))) as {
            message?: string;
            apps?: ChannelAppEntry[];
            errors?: Record<string, string[]>;
        };
        if (!res.ok) {
            error.value =
                Object.values(data.errors ?? {})[0]?.[0] ??
                data.message ??
                'Could not save.';
            return;
        }
        if (data.apps) apps.value = data.apps;
        toast.success(data.message ?? 'Saved.');
        open.value = false;
        // The connect dialog reads readiness from the shared page props.
        router.reload({ only: ['connectablePlatforms'] });
    } catch {
        error.value = 'The engine could not be reached.';
    }
};

const save = async (): Promise<void> => {
    if (!current.value) return;
    busy.value = 'save';
    await call('PUT', current.value.key, form.value);
    busy.value = '';
};

const remove = async (): Promise<void> => {
    if (!current.value) return;
    busy.value = 'remove';
    await call('DELETE', current.value.key);
    busy.value = '';
};

const copy = async (text: string): Promise<void> => {
    await navigator.clipboard.writeText(text);
    toast.success('Copied');
};

// The connect dialog links here with ?channel=<app>: open that card.
onMounted(() => {
    const wanted = new URLSearchParams(window.location.search).get('channel');
    const app = apps.value.find((a) => a.key === wanted);
    if (app) {
        document.getElementById('channels')?.scrollIntoView({ block: 'start' });
        if (props.canManage) openApp(app);
    }
});
</script>

<template>
    <section
        id="channels"
        class="flex flex-col gap-3"
        data-testid="connectors-group-channels"
    >
        <div>
            <h2 class="text-base font-emphasis text-foreground">Channels</h2>
            <p class="text-sm text-muted-foreground">
                The developer app keys behind each network.
                {{ readyCount }} of {{ totalCount }} networks connect today; add
                a network's keys and it connects at once in Add channel, for
                every workspace.
            </p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div
                v-for="app in apps"
                :key="app.key"
                class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                :data-testid="`channel-app-${app.key}`"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-emphasis text-foreground">
                            {{ app.label }}
                        </p>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <span
                                v-for="n in app.networks"
                                :key="n.value"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <PlatformLogo :platform="n.value" size="xs" />
                                {{ n.label }}
                                <IconCircleCheck
                                    v-if="n.ready"
                                    class="size-3.5 text-success"
                                />
                            </span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <Badge
                            v-if="isReady(app)"
                            class="bg-success/15 text-success"
                            :data-testid="`channel-app-status-${app.key}`"
                            ><IconCircleCheck class="size-3.5" /> Ready</Badge
                        >
                        <Badge
                            v-else
                            variant="outline"
                            :data-testid="`channel-app-status-${app.key}`"
                            >Needs keys</Badge
                        >
                        <Badge
                            v-if="app.mode === 'development'"
                            variant="outline"
                            :data-testid="`channel-app-mode-${app.key}`"
                            >{{
                                $t('tantra.connect.settings.modes.development')
                            }}</Badge
                        >
                    </div>
                </div>

                <p v-if="app.catch" class="text-xs text-muted-foreground">
                    {{ app.catch }}
                </p>

                <div class="mt-auto flex items-center justify-between gap-2">
                    <a
                        :href="app.portal"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground underline underline-offset-2"
                        >Developer portal <IconExternalLink class="size-3"
                    /></a>
                    <Button
                        v-if="canManage"
                        size="sm"
                        :variant="isReady(app) ? 'outline' : 'default'"
                        :data-testid="`channel-app-open-${app.key}`"
                        @click="openApp(app)"
                        >{{ isReady(app) ? 'Manage' : 'Add keys' }}</Button
                    >
                </div>
            </div>
        </div>

        <Dialog v-model:open="open">
            <DialogContent
                v-if="current"
                class="max-w-lg"
                data-testid="channel-app-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{{ current.label }}</DialogTitle>
                    <DialogDescription>
                        {{ current.help }}
                        <a
                            :href="current.portal"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 underline underline-offset-2"
                            >Open the portal <IconExternalLink class="size-3.5"
                        /></a>
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="current.callbacks.length"
                    class="flex flex-col gap-1.5"
                >
                    <Label>Redirect URLs to paste in the portal</Label>
                    <button
                        v-for="url in current.callbacks"
                        :key="url"
                        type="button"
                        class="flex items-center justify-between gap-2 rounded-md border bg-muted px-3 py-2 text-left font-mono text-xs"
                        :data-testid="`channel-app-callback`"
                        @click="copy(url)"
                    >
                        <span class="truncate">{{ url }}</span>
                        <IconCopy class="size-3.5 shrink-0" />
                    </button>
                </div>

                <div class="flex flex-col gap-2">
                    <Label>{{ $t('tantra.connect.settings.mode') }}</Label>
                    <div class="flex gap-2" role="radiogroup">
                        <Button
                            v-for="m in MODES"
                            :key="m"
                            type="button"
                            size="sm"
                            role="radio"
                            :aria-checked="form.mode === m"
                            :variant="form.mode === m ? 'default' : 'outline'"
                            :data-testid="`channel-app-mode-${m}`"
                            @click="form.mode = m"
                            >{{
                                $t(`tantra.connect.settings.modes.${m}`)
                            }}</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ $t('tantra.connect.settings.mode_help') }}
                    </p>
                    <div
                        class="flex flex-col gap-2 rounded-md border bg-muted/40 p-3 text-xs text-muted-foreground"
                    >
                        <p>
                            <span class="font-medium text-foreground">{{
                                $t('tantra.connect.settings.testers')
                            }}</span>
                            {{ current.testing.owner }}
                            {{ current.testing.you }}
                        </p>
                        <p>
                            <span class="font-medium text-foreground">{{
                                $t('tantra.connect.settings.go_live')
                            }}</span>
                            {{ current.testing.live }}
                        </p>
                        <a
                            :href="current.testing.console"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 underline underline-offset-2"
                            >{{ $t('tantra.connect.prepare.open_console') }}
                            <IconExternalLink class="size-3"
                        /></a>
                    </div>
                </div>

                <div class="flex flex-col gap-4">
                    <div
                        v-for="f in current.fields"
                        :key="f.key"
                        class="flex flex-col gap-1.5"
                    >
                        <div
                            v-if="f.switch"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Switch
                                :model-value="Boolean(form[f.key])"
                                @update:model-value="
                                    (v: boolean) => (form[f.key] = v)
                                "
                            />
                            {{ f.label }}
                        </div>
                        <template v-else>
                            <Label :for="`ca-${f.key}`">{{ f.label }}</Label>
                            <Input
                                :id="`ca-${f.key}`"
                                v-model="form[f.key] as string"
                                :type="f.secret ? 'password' : 'text'"
                                autocomplete="off"
                                :placeholder="
                                    f.secret && f.last_four
                                        ? `Saved ••••${f.last_four}. Paste to replace.`
                                        : f.source === 'server'
                                          ? 'Set on the server. Paste to replace.'
                                          : ''
                                "
                                :data-testid="`channel-app-field-${f.key}`"
                            />
                        </template>
                    </div>
                </div>

                <p v-if="error" class="text-sm text-destructive" role="alert">
                    {{ error }}
                </p>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button
                        v-if="current.saved_in_app"
                        variant="ghost"
                        :disabled="busy !== ''"
                        data-testid="channel-app-remove"
                        @click="remove"
                        >Remove keys</Button
                    >
                    <span v-else />
                    <Button
                        :disabled="busy !== ''"
                        data-testid="channel-app-save"
                        @click="save"
                        >{{ busy === 'save' ? 'Saving…' : 'Save' }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
