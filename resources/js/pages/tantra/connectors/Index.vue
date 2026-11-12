<script setup lang="ts">
// tantra: the Connectors tab (app/Tantra/Http/Controllers/Web/ConnectorController.php),
// modelled on the LLM Providers page of the main Tantra app.
import { Head, router } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCircleCheck,
    IconExternalLink,
    IconEye,
    IconEyeOff,
    IconPlugConnected,
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

type Status = 'connected' | 'failing' | 'untested' | 'paused';

interface Connector {
    secret_last_four: string | null;
    base_url: string | null;
    selected_model: string | null;
    effective_model: string | null;
    is_default: boolean;
    is_active: boolean;
    status: Status;
    last_verified_at: string | null;
    last_failed_at: string | null;
    failure_reason: string | null;
    failure_message: string | null;
}

interface Provider {
    key: string;
    label: string;
    group: 'free' | 'subscription' | 'paid' | 'tantra';
    kind: 'api_key' | 'subscription' | 'data';
    is_ai: boolean;
    default_base_url: string | null;
    base_url_required: boolean;
    default_model: string | null;
    has_model_list: boolean;
    key_url: string | null;
    key_label: string;
    key_placeholder: string;
    free_note: string | null;
    supports_images: boolean;
    setup: string[];
    connector: Connector | null;
}

const props = defineProps<{
    providers: Provider[];
    canManage: boolean;
    serverFallback: boolean;
}>();

const GROUPS: { key: Provider['group']; title: string; description: string }[] =
    [
        {
            key: 'free',
            title: 'Free AI providers',
            description:
                'Free tiers you can run every AI feature on. Add more than one: when the default runs out, the engine moves to the next.',
        },
        {
            key: 'subscription',
            title: 'AI subscriptions',
            description:
                'Use your own Claude or ChatGPT plan through the official Claude Code and Codex command line tools.',
        },
        {
            key: 'paid',
            title: 'Paid API keys',
            description:
                'Pay-as-you-go keys and any other OpenAI-compatible endpoint.',
        },
        {
            key: 'tantra',
            title: 'Tantra',
            description: 'Your Tantra account, for campaigns and contacts.',
        },
    ];

const REASON_LABEL: Record<string, string> = {
    no_credits: 'Out of credits',
    rate_limited: 'Rate limited',
    bad_key: 'Key rejected',
    bad_model: 'Model not available',
    unreachable: 'Unreachable',
    overloaded: 'Overloaded',
    signed_out: 'Signed out',
    other: 'Error',
};

const grouped = computed(() =>
    GROUPS.map((g) => ({
        ...g,
        providers: props.providers.filter((p) => p.group === g.key),
    })),
);

const defaultProvider = computed(() =>
    props.providers.find((p) => p.connector?.is_default),
);

const failing = computed(() =>
    props.providers.filter((p) => p.connector?.status === 'failing'),
);

// ---- dialog --------------------------------------------------------------

const open = ref(false);
const current = ref<Provider | null>(null);
const secret = ref('');
const showSecret = ref(false);
const baseUrl = ref('');
const model = ref('');
const models = ref<{ id: string; free: boolean }[]>([]);
const modelFilter = ref('');
const busy = ref<'' | 'save' | 'test' | 'models' | 'remove'>('');
const testResult = ref<{
    ok: boolean;
    message: string;
    detail?: string;
} | null>(null);
const confirmRemove = ref(false);

const filteredModels = computed(() => {
    const q = modelFilter.value.trim().toLowerCase();

    return models.value
        .filter((m) => !q || m.id.toLowerCase().includes(q))
        .slice(0, 60);
});

const openProvider = (p: Provider): void => {
    current.value = p;
    secret.value = '';
    showSecret.value = false;
    baseUrl.value =
        p.connector?.base_url ??
        (p.base_url_required ? (p.default_base_url ?? '') : '');
    model.value = p.connector?.selected_model ?? '';
    models.value = [];
    modelFilter.value = '';
    testResult.value = null;
    confirmRemove.value = false;
    open.value = true;
};

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};

async function call(
    method: string,
    path: string,
    body?: Record<string, unknown>,
): Promise<{ ok: boolean; data: Record<string, unknown> }> {
    try {
        const res = await fetch(`/settings/workspace/connectors/${path}`, {
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
        const data = (await res.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;

        return { ok: res.ok, data };
    } catch {
        return {
            ok: false,
            data: { message: 'The engine could not be reached.' },
        };
    }
}

const payload = (): Record<string, unknown> => ({
    secret: secret.value.trim() || null,
    base_url: baseUrl.value.trim() || null,
    selected_model: model.value.trim() || null,
});

async function testConnection(): Promise<void> {
    if (!current.value) return;
    busy.value = 'test';
    testResult.value = null;
    const { data } = await call('POST', `${current.value.key}/test`, payload());
    busy.value = '';
    const count = data.models_available as number | null;
    testResult.value = {
        ok: Boolean(data.ok),
        message:
            String(data.message ?? 'Test failed.') +
            (data.ok && count ? ` ${count} models available.` : '') +
            (data.latency_ms ? ` (${data.latency_ms} ms)` : ''),
    };
    const failure = data.failure as { detail?: string } | null;
    if (failure?.detail) testResult.value.detail = failure.detail;
    if (data.connector) router.reload({ only: ['providers'] });
}

async function loadModels(): Promise<void> {
    if (!current.value) return;
    busy.value = 'models';
    const { data } = await call(
        'POST',
        `${current.value.key}/models`,
        payload(),
    );
    busy.value = '';
    models.value = (data.models as { id: string; free: boolean }[]) ?? [];
    if (models.value.length === 0) {
        toast.error('No model list came back. Type the model name instead.');
    }
}

async function save(): Promise<void> {
    if (!current.value) return;
    busy.value = 'save';
    const { ok, data } = await call('PUT', current.value.key, payload());
    busy.value = '';
    if (!ok) {
        toast.error(String(data.message ?? 'Could not save.'));

        return;
    }
    toast.success(`${current.value.label} saved.`);
    open.value = false;
    router.reload({ only: ['providers', 'tantraAlerts'] });
}

async function remove(): Promise<void> {
    if (!current.value) return;
    busy.value = 'remove';
    const { ok, data } = await call('DELETE', current.value.key);
    busy.value = '';
    if (!ok) {
        toast.error(String(data.message ?? 'Could not remove.'));

        return;
    }
    toast.success(`${current.value.label} removed.`);
    open.value = false;
    router.reload({ only: ['providers', 'tantraAlerts'] });
}

async function makeDefault(p: Provider): Promise<void> {
    const { ok, data } = await call('POST', `${p.key}/default`);
    if (ok) toast.success(String(data.message));
    else toast.error(String(data.message ?? 'Could not change the default.'));
    router.reload({ only: ['providers', 'tantraAlerts'] });
}

async function retest(p: Provider): Promise<void> {
    const { data } = await call('POST', `${p.key}/test`, {});
    if (data.ok) toast.success(String(data.message));
    else toast.error(String(data.message ?? 'Test failed.'));
    router.reload({ only: ['providers', 'tantraAlerts'] });
}

const when = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
</script>

<template>
    <Head title="Connectors" />
    <SettingsLayout
        title="Connectors"
        description="Connect the AI that writes ideas, captions, alt text and team threads, and your Tantra account. Keys are encrypted and shared by every workspace in this account."
    >
        <div class="flex flex-col gap-8" data-testid="connectors-page">
            <!-- Summary of what runs the AI right now -->
            <div
                class="flex items-start gap-3 rounded-lg border p-4"
                :class="
                    defaultProvider
                        ? 'border-border bg-card'
                        : 'border-warning/40 bg-warning/5'
                "
                data-testid="connectors-summary"
            >
                <IconPlugConnected
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                />
                <div class="text-sm">
                    <p
                        v-if="defaultProvider"
                        class="font-emphasis text-foreground"
                    >
                        AI runs on {{ defaultProvider.label
                        }}<span
                            v-if="defaultProvider.connector?.effective_model"
                        >
                            ({{
                                defaultProvider.connector.effective_model
                            }})</span
                        >.
                    </p>
                    <p
                        v-else-if="serverFallback"
                        class="font-emphasis text-foreground"
                    >
                        AI runs on the server's built-in provider. Add your own
                        below to choose.
                    </p>
                    <p v-else class="font-emphasis text-foreground">
                        No AI provider is connected, so Generate ideas and the
                        AI Assistant will not work.
                    </p>
                    <p class="text-muted-foreground">
                        If the default fails, the engine tries your other
                        connected AI providers in turn and tells you which one
                        failed and why.
                    </p>
                </div>
            </div>

            <div
                v-for="f in failing"
                :key="`fail-${f.key}`"
                role="alert"
                class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                :data-testid="`connector-alert-${f.key}`"
            >
                <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    <span class="font-emphasis"
                        >{{ f.label }}:
                        {{
                            REASON_LABEL[
                                f.connector?.failure_reason ?? 'other'
                            ]
                        }}.</span
                    >
                    {{ f.connector?.failure_message }}
                    <span class="text-destructive/80"
                        >Since
                        {{ when(f.connector?.last_failed_at ?? null) }}.</span
                    >
                </p>
            </div>

            <section
                v-for="g in grouped"
                :key="g.key"
                class="flex flex-col gap-3"
                :data-testid="`connectors-group-${g.key}`"
            >
                <div>
                    <h2 class="text-base font-emphasis text-foreground">
                        {{ g.title }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ g.description }}
                    </p>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    <div
                        v-for="p in g.providers"
                        :key="p.key"
                        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
                        :data-testid="`connector-card-${p.key}`"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-emphasis text-foreground">
                                    {{ p.label }}
                                </p>
                                <p
                                    v-if="p.free_note"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ p.free_note }}
                                </p>
                            </div>
                            <Badge
                                v-if="!p.connector"
                                variant="outline"
                                :data-testid="`connector-status-${p.key}`"
                                >Not connected</Badge
                            >
                            <Badge
                                v-else-if="p.connector.status === 'connected'"
                                class="bg-success/15 text-success"
                                :data-testid="`connector-status-${p.key}`"
                                ><IconCircleCheck class="size-3.5" />
                                Connected</Badge
                            >
                            <Badge
                                v-else-if="p.connector.status === 'failing'"
                                variant="destructive"
                                :data-testid="`connector-status-${p.key}`"
                                >{{
                                    REASON_LABEL[
                                        p.connector.failure_reason ?? 'other'
                                    ]
                                }}</Badge
                            >
                            <Badge
                                v-else-if="p.connector.status === 'paused'"
                                variant="outline"
                                :data-testid="`connector-status-${p.key}`"
                                >Paused</Badge
                            >
                            <Badge
                                v-else
                                variant="outline"
                                :data-testid="`connector-status-${p.key}`"
                                >Not tested</Badge
                            >
                        </div>

                        <div
                            v-if="p.connector"
                            class="text-xs text-muted-foreground"
                        >
                            <span v-if="p.connector.secret_last_four"
                                >Key ••••{{
                                    p.connector.secret_last_four
                                }}</span
                            >
                            <span v-if="p.connector.effective_model && p.is_ai">
                                · {{ p.connector.effective_model }}</span
                            >
                            <span v-if="p.connector.last_verified_at">
                                · checked
                                {{ when(p.connector.last_verified_at) }}</span
                            >
                        </div>

                        <div
                            class="mt-auto flex flex-wrap items-center justify-between gap-2"
                        >
                            <div
                                v-if="p.is_ai && p.connector"
                                class="flex items-center gap-2 text-sm"
                                :data-testid="`connector-default-${p.key}`"
                            >
                                <Switch
                                    :model-value="p.connector.is_default"
                                    :disabled="
                                        !canManage || p.connector.is_default
                                    "
                                    @update:model-value="
                                        (v: boolean) => v && makeDefault(p)
                                    "
                                />
                                Default
                            </div>
                            <span v-else />
                            <div class="flex gap-2">
                                <Button
                                    v-if="p.connector && canManage"
                                    size="sm"
                                    variant="ghost"
                                    :data-testid="`connector-retest-${p.key}`"
                                    @click="retest(p)"
                                    >Test</Button
                                >
                                <Button
                                    v-if="canManage"
                                    size="sm"
                                    :variant="
                                        p.connector ? 'outline' : 'default'
                                    "
                                    :data-testid="`connector-open-${p.key}`"
                                    @click="openProvider(p)"
                                    >{{
                                        p.connector
                                            ? 'Manage'
                                            : p.kind === 'subscription'
                                              ? 'Connect'
                                              : 'Set up'
                                    }}</Button
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <p v-if="!canManage" class="text-sm text-muted-foreground">
                Only the account owner can change connectors.
            </p>
        </div>

        <Dialog v-model:open="open">
            <DialogContent
                v-if="current"
                class="max-w-lg"
                data-testid="connector-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{{ current.label }}</DialogTitle>
                    <DialogDescription>
                        {{
                            current.free_note ??
                            (current.is_ai
                                ? 'Runs the AI features when it is the default.'
                                : 'Used to read your Tantra data.')
                        }}
                        <a
                            v-if="current.key_url"
                            :href="current.key_url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 underline underline-offset-2"
                            >Get a key <IconExternalLink class="size-3.5"
                        /></a>
                    </DialogDescription>
                </DialogHeader>

                <ol
                    v-if="current.setup.length"
                    class="list-decimal space-y-1 ps-5 text-sm text-muted-foreground"
                    data-testid="connector-setup"
                >
                    <li v-for="(step, i) in current.setup" :key="i">
                        {{ step }}
                    </li>
                </ol>

                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <Label for="connector-secret">
                            {{
                                current.connector?.secret_last_four
                                    ? 'Replace the saved key (optional)'
                                    : current.key_label
                            }}
                        </Label>
                        <Textarea
                            v-if="current.key === 'codex_cli'"
                            id="connector-secret"
                            v-model="secret"
                            rows="4"
                            :placeholder="current.key_placeholder"
                            class="font-mono text-xs"
                            data-testid="connector-secret"
                        />
                        <div v-else class="flex gap-2">
                            <Input
                                id="connector-secret"
                                v-model="secret"
                                :type="showSecret ? 'text' : 'password'"
                                autocomplete="off"
                                :placeholder="
                                    current.connector?.secret_last_four
                                        ? `••••${current.connector.secret_last_four}`
                                        : current.key_placeholder
                                "
                                data-testid="connector-secret"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="
                                    showSecret ? 'Hide key' : 'Show key'
                                "
                                @click="showSecret = !showSecret"
                            >
                                <IconEyeOff v-if="showSecret" class="size-4" />
                                <IconEye v-else class="size-4" />
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="
                            current.base_url_required ||
                            current.key === 'omniroute'
                        "
                        class="flex flex-col gap-1.5"
                    >
                        <Label for="connector-base-url">Endpoint</Label>
                        <Input
                            id="connector-base-url"
                            v-model="baseUrl"
                            :placeholder="
                                current.default_base_url ?? 'https://.../v1'
                            "
                            data-testid="connector-base-url"
                        />
                    </div>

                    <div v-if="current.is_ai" class="flex flex-col gap-1.5">
                        <Label for="connector-model">Model</Label>
                        <div class="flex gap-2">
                            <Input
                                id="connector-model"
                                v-model="model"
                                :placeholder="
                                    current.default_model ?? 'model name'
                                "
                                data-testid="connector-model"
                            />
                            <Button
                                v-if="current.has_model_list"
                                type="button"
                                variant="outline"
                                :disabled="busy !== ''"
                                data-testid="connector-load-models"
                                @click="loadModels"
                                >{{
                                    busy === 'models'
                                        ? 'Loading…'
                                        : 'List models'
                                }}</Button
                            >
                        </div>
                        <div v-if="models.length" class="flex flex-col gap-1.5">
                            <Input
                                v-model="modelFilter"
                                placeholder="Search models"
                                class="h-8 text-xs"
                                data-testid="connector-model-search"
                            />
                            <div
                                class="max-h-44 overflow-y-auto rounded-md border text-xs"
                                data-testid="connector-model-list"
                            >
                                <button
                                    v-for="m in filteredModels"
                                    :key="m.id"
                                    type="button"
                                    class="flex w-full items-center justify-between px-2 py-1.5 text-start hover:bg-muted"
                                    :class="
                                        m.id === model
                                            ? 'bg-muted font-emphasis'
                                            : ''
                                    "
                                    @click="model = m.id"
                                >
                                    <span class="truncate">{{ m.id }}</span>
                                    <Badge
                                        v-if="m.free"
                                        variant="outline"
                                        class="text-[10px]"
                                        >free</Badge
                                    >
                                </button>
                            </div>
                        </div>
                        <p
                            v-if="!current.supports_images"
                            class="text-xs text-muted-foreground"
                        >
                            Image alt text needs a provider that reads images
                            (Gemini, OpenAI or the Anthropic API).
                        </p>
                    </div>

                    <div
                        v-if="testResult"
                        role="status"
                        class="flex items-start gap-2 rounded-md border px-3 py-2 text-sm"
                        :class="
                            testResult.ok
                                ? 'border-success/30 bg-success/5 text-success'
                                : 'border-destructive/30 bg-destructive/5 text-destructive'
                        "
                        data-testid="connector-test-result"
                    >
                        <IconCircleCheck
                            v-if="testResult.ok"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <IconAlertTriangle
                            v-else
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <span>
                            {{ testResult.message }}
                            <span
                                v-if="testResult.detail"
                                class="mt-1 block text-xs opacity-80"
                                data-testid="connector-test-detail"
                                >{{ testResult.detail }}</span
                            >
                        </span>
                    </div>
                </div>

                <DialogFooter class="flex-wrap gap-2 sm:justify-between">
                    <div>
                        <template v-if="current.connector">
                            <Button
                                v-if="!confirmRemove"
                                variant="ghost"
                                class="text-destructive"
                                data-testid="connector-remove"
                                @click="confirmRemove = true"
                                >Remove</Button
                            >
                            <Button
                                v-else
                                variant="destructive"
                                :disabled="busy !== ''"
                                data-testid="connector-remove-confirm"
                                @click="remove"
                            >
                                {{
                                    busy === 'remove'
                                        ? 'Removing…'
                                        : 'Yes, remove it'
                                }}
                            </Button>
                        </template>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            :disabled="busy !== ''"
                            data-testid="connector-test"
                            @click="testConnection"
                        >
                            {{
                                busy === 'test' ? 'Testing…' : 'Test connection'
                            }}
                        </Button>
                        <Button
                            :disabled="busy !== ''"
                            data-testid="connector-save"
                            @click="save"
                        >
                            {{ busy === 'save' ? 'Saving…' : 'Save' }}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </SettingsLayout>
</template>
