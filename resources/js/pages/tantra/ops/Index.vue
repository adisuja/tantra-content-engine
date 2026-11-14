<script setup lang="ts">
// tantra: Studio ops (PRD 25.4 and 25.5), for TANTRA_ADMIN_EMAILS only. Agent
// jobs with their inputs and logs, the Claude accounts that work them, the
// runners, and how to set up a cloud task or a laptop runner.
import { Head, router } from '@inertiajs/vue3';
import { IconRobot } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import HeaderTitle from '@/components/HeaderTitle.vue';
import { ops } from '@/components/tantra/ops/opsApi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';

interface Job {
    id: string;
    kind: string;
    status: string;
    summary: string | null;
    error: string | null;
    workspace: string | null;
    requested_by: string | null;
    runner: string | null;
    session_url: string | null;
    attempts: number;
    dispatches: number;
    created_at: string | null;
    finished_at: string | null;
    input?: Record<string, unknown> | null;
    result?: Record<string, unknown> | null;
    log?: { at: string; line: string }[];
}
interface Account {
    id: string;
    name: string;
    kind: string;
    config_dir: string | null;
    enabled: boolean;
    has_fire_url: boolean;
    has_secret: boolean;
    last_test_at: string | null;
    last_test_result: string | null;
}
interface Runner {
    id: string;
    name: string;
    kind: string;
    kinds: string[] | null;
    claude_account: string | null;
    last_seen_at: string | null;
    revoked: boolean;
}

const props = defineProps<{
    jobs: Job[];
    accounts: Account[];
    runners: Runner[];
    kinds: string[];
    engineUrl: string;
}>();

type Tab = 'jobs' | 'accounts' | 'runners' | 'setup';
const tab = ref<Tab>('jobs');
const TABS: Tab[] = ['jobs', 'accounts', 'runners', 'setup'];

const status = ref('');
const kind = ref('');
const shown = computed(() =>
    props.jobs.filter(
        (j) =>
            (!status.value || j.status === status.value) &&
            (!kind.value || j.kind === kind.value),
    ),
);
const STATUSES = [
    'awaiting_admin',
    'queued',
    'dispatched',
    'running',
    'done',
    'failed',
    'cancelled',
];
const tone = (
    s: string,
): 'default' | 'secondary' | 'destructive' | 'outline' =>
    s === 'done'
        ? 'default'
        : s === 'failed'
          ? 'destructive'
          : s === 'cancelled'
            ? 'outline'
            : 'secondary';
const when = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '';

const reload = () => router.reload({ only: ['jobs', 'accounts', 'runners'] });

const open = ref<Job | null>(null);
const openJob = async (id: string) => {
    const r = await ops<Job>('GET', `/jobs/${id}`);
    if (r.ok && r.data) {
        open.value = r.data;
    } else {
        toast.error(r.message || trans('tantra.ops.failed'));
    }
};
const act = async (path: string, body?: Record<string, unknown>) => {
    const r = await ops('POST', path, body);
    if (r.ok) {
        toast.success(trans('tantra.ops.done'));
        reload();
        if (open.value) {
            await openJob(open.value.id);
        }
    } else {
        toast.error(r.message || trans('tantra.ops.failed'));
    }
};

// Claude accounts.
const acc = ref({
    name: '',
    kind: 'cloud_task',
    fire_url: '',
    secret: '',
    config_dir: '',
});
const addAccount = async () => {
    const body = Object.fromEntries(
        Object.entries(acc.value).filter(([, v]) => v !== ''),
    );
    const r = await ops('POST', '/accounts', body);
    if (r.ok) {
        acc.value = {
            name: '',
            kind: 'cloud_task',
            fire_url: '',
            secret: '',
            config_dir: '',
        };
        toast.success(trans('tantra.ops.done'));
        reload();
    } else {
        toast.error(r.message || trans('tantra.ops.failed'));
    }
};
const patchAccount = async (a: Account, body: Record<string, unknown>) => {
    const r = await ops('PATCH', `/accounts/${a.id}`, body);
    if (r.ok) {
        reload();
    } else {
        toast.error(r.message || trans('tantra.ops.failed'));
    }
};
const removeAccount = async (a: Account) => {
    if (!window.confirm(trans('tantra.ops.remove_confirm', { name: a.name }))) {
        return;
    }
    const r = await ops('DELETE', `/accounts/${a.id}`);
    if (r.ok) {
        reload();
    }
};

// Runners.
const run = ref({ name: '', kind: 'cloud_task', claude_account_id: '' });
const newToken = ref('');
const addRunner = async () => {
    const r = await ops('POST', '/runners', {
        name: run.value.name,
        kind: run.value.kind,
        claude_account_id: run.value.claude_account_id || null,
    });
    if (r.ok && r.token) {
        newToken.value = r.token;
        run.value = { name: '', kind: 'cloud_task', claude_account_id: '' };
        reload();
    } else {
        toast.error(r.message || trans('tantra.ops.failed'));
    }
};
const revokeRunner = async (x: Runner) => {
    if (!window.confirm(trans('tantra.ops.revoke_confirm', { name: x.name }))) {
        return;
    }
    const r = await ops('DELETE', `/runners/${x.id}`);
    if (r.ok) {
        reload();
    }
};
const copy = async (text: string) => {
    await navigator.clipboard.writeText(text);
    toast.success(trans('tantra.ops.copied'));
};

const setupLocal = computed(
    () =>
        `# once per Claude account on this Mac\nCLAUDE_CONFIG_DIR=~/.claude-<name> claude   # then /login with that account\n\n# then, in the tantra-content folder\nTANTRA_ENGINE_URL=${props.engineUrl} TANTRA_RUNNER_TOKEN=<runner token> \\\n  CLAUDE_CONFIG_DIR=~/.claude-<name> python3 kits/agent/runner.py`,
);
const pretty = (v: unknown): string => JSON.stringify(v ?? {}, null, 2);
</script>

<template>
    <AppLayout full-width>
        <Head :title="$t('tantra.ops.title')" />
        <div
            class="flex min-h-full min-w-0 flex-col gap-6 px-4 pt-6 pb-10 md:px-8"
        >
            <header class="flex flex-col gap-2">
                <HeaderTitle
                    :title="$t('tantra.ops.title')"
                    :icon="IconRobot"
                />
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.ops.intro') }}
                </p>
            </header>

            <nav class="flex flex-wrap gap-2" data-testid="ops-tabs">
                <Button
                    v-for="t in TABS"
                    :key="t"
                    size="sm"
                    :variant="tab === t ? 'default' : 'outline'"
                    @click="tab = t"
                    >{{ $t(`tantra.ops.tabs.${t}`) }}</Button
                >
            </nav>

            <section v-if="tab === 'jobs'" class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        v-model="status"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        :aria-label="$t('tantra.ops.status')"
                    >
                        <option value="">
                            {{ $t('tantra.ops.any_status') }}
                        </option>
                        <option v-for="s in STATUSES" :key="s" :value="s">
                            {{ s }}
                        </option>
                    </select>
                    <select
                        v-model="kind"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        :aria-label="$t('tantra.ops.kind')"
                    >
                        <option value="">
                            {{ $t('tantra.ops.any_kind') }}
                        </option>
                        <option v-for="k in kinds" :key="k" :value="k">
                            {{ k }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            act('/jobs', {
                                kind: 'ping',
                                input: { say: 'pong' },
                            })
                        "
                        >{{ $t('tantra.ops.ping') }}</Button
                    >
                    <Button size="sm" variant="ghost" @click="reload">{{
                        $t('tantra.ops.refresh')
                    }}</Button>
                </div>
                <p
                    v-if="shown.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('tantra.ops.no_jobs') }}
                </p>
                <button
                    v-for="j in shown"
                    :key="j.id"
                    type="button"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 text-left text-sm hover:bg-muted/50"
                    @click="openJob(j.id)"
                >
                    <Badge :variant="tone(j.status)">{{ j.status }}</Badge>
                    <b>{{ j.kind }}</b>
                    <span class="text-muted-foreground">{{ j.workspace }}</span>
                    <span class="text-muted-foreground">{{
                        j.requested_by
                    }}</span>
                    <span class="min-w-0 flex-1 truncate">{{
                        j.summary || j.error || ''
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        when(j.created_at)
                    }}</span>
                </button>

                <div
                    v-if="open"
                    class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 text-sm"
                    data-testid="ops-job-detail"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :variant="tone(open.status)">{{
                            open.status
                        }}</Badge>
                        <b>{{ open.kind }}</b>
                        <span class="text-muted-foreground">{{ open.id }}</span>
                        <a
                            v-if="open.session_url"
                            :href="open.session_url"
                            target="_blank"
                            rel="noopener"
                            class="underline"
                            >{{ $t('tantra.ops.session') }}</a
                        >
                        <span class="flex-1" />
                        <Button
                            v-if="open.status === 'awaiting_admin'"
                            size="sm"
                            @click="act(`/jobs/${open.id}/run`)"
                            >{{ $t('tantra.ops.run') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="act(`/jobs/${open.id}/retry`)"
                            >{{ $t('tantra.ops.retry') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="act(`/jobs/${open.id}/cancel`)"
                            >{{ $t('tantra.ops.cancel') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="open = null"
                            >{{ $t('tantra.ops.close') }}</Button
                        >
                    </div>
                    <p v-if="open.error" class="text-destructive">
                        {{ open.error }}
                    </p>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <div>
                            <h3 class="mb-1 font-medium">
                                {{ $t('tantra.ops.input') }}
                            </h3>
                            <pre
                                class="max-h-80 overflow-auto rounded bg-muted p-2 text-xs"
                                >{{ pretty(open.input) }}</pre
                            >
                        </div>
                        <div>
                            <h3 class="mb-1 font-medium">
                                {{ $t('tantra.ops.result') }}
                            </h3>
                            <pre
                                class="max-h-80 overflow-auto rounded bg-muted p-2 text-xs"
                                >{{ pretty(open.result) }}</pre
                            >
                        </div>
                    </div>
                    <div>
                        <h3 class="mb-1 font-medium">
                            {{ $t('tantra.ops.log') }}
                        </h3>
                        <ol
                            class="max-h-64 overflow-auto rounded bg-muted p-2 font-mono text-xs"
                        >
                            <li v-for="(l, i) in open.log ?? []" :key="i">
                                {{ when(l.at) }} · {{ l.line }}
                            </li>
                        </ol>
                    </div>
                </div>
            </section>

            <section v-if="tab === 'accounts'" class="flex flex-col gap-3">
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.ops.accounts_help') }}
                </p>
                <div
                    v-for="a in accounts"
                    :key="a.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 text-sm"
                >
                    <b>{{ a.name }}</b>
                    <Badge variant="outline">{{
                        $t(`tantra.ops.kinds.${a.kind}`)
                    }}</Badge>
                    <Badge :variant="a.enabled ? 'default' : 'outline'">{{
                        a.enabled ? $t('tantra.ops.on') : $t('tantra.ops.off')
                    }}</Badge>
                    <span v-if="a.config_dir" class="font-mono text-xs">{{
                        a.config_dir
                    }}</span>
                    <span
                        class="min-w-0 flex-1 truncate text-muted-foreground"
                        >{{ a.last_test_result }}</span
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="act(`/accounts/${a.id}/test`)"
                        >{{ $t('tantra.ops.test') }}</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="patchAccount(a, { enabled: !a.enabled })"
                        >{{
                            a.enabled
                                ? $t('tantra.ops.turn_off')
                                : $t('tantra.ops.turn_on')
                        }}</Button
                    >
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="removeAccount(a)"
                        >{{ $t('tantra.ops.remove') }}</Button
                    >
                </div>
                <form
                    class="grid max-w-2xl gap-2 rounded-lg border border-dashed border-border p-3 text-sm"
                    @submit.prevent="addAccount"
                >
                    <b>{{ $t('tantra.ops.add_account') }}</b>
                    <Input
                        v-model="acc.name"
                        :placeholder="$t('tantra.ops.name')"
                        required
                    />
                    <select
                        v-model="acc.kind"
                        class="h-9 rounded-md border border-border bg-background px-2"
                    >
                        <option value="cloud_task">
                            {{ $t('tantra.ops.kinds.cloud_task') }}
                        </option>
                        <option value="local_cli">
                            {{ $t('tantra.ops.kinds.local_cli') }}
                        </option>
                        <option value="api_key">
                            {{ $t('tantra.ops.kinds.api_key') }}
                        </option>
                    </select>
                    <template v-if="acc.kind === 'cloud_task'">
                        <Input
                            v-model="acc.fire_url"
                            :placeholder="$t('tantra.ops.fire_url')"
                        />
                        <Input
                            v-model="acc.secret"
                            type="password"
                            autocomplete="off"
                            :placeholder="$t('tantra.ops.fire_token')"
                        />
                    </template>
                    <Input
                        v-if="acc.kind === 'local_cli'"
                        v-model="acc.config_dir"
                        :placeholder="$t('tantra.ops.config_dir')"
                    />
                    <Input
                        v-if="acc.kind === 'api_key'"
                        v-model="acc.secret"
                        type="password"
                        autocomplete="off"
                        :placeholder="$t('tantra.ops.api_key')"
                    />
                    <Button type="submit" size="sm" class="w-fit">{{
                        $t('tantra.ops.save')
                    }}</Button>
                </form>
            </section>

            <section v-if="tab === 'runners'" class="flex flex-col gap-3">
                <p class="max-w-2xl text-sm text-muted-foreground">
                    {{ $t('tantra.ops.runners_help') }}
                </p>
                <div
                    v-if="newToken"
                    class="flex max-w-2xl flex-col gap-2 rounded-lg border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                >
                    <b>{{ $t('tantra.ops.token_once') }}</b>
                    <code class="break-all">{{ newToken }}</code>
                    <Button size="sm" class="w-fit" @click="copy(newToken)">{{
                        $t('tantra.ops.copy')
                    }}</Button>
                </div>
                <div
                    v-for="x in runners"
                    :key="x.id"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3 text-sm"
                >
                    <b>{{ x.name }}</b>
                    <Badge variant="outline">{{ x.kind }}</Badge>
                    <span class="text-muted-foreground">{{
                        x.claude_account
                    }}</span>
                    <span class="flex-1 text-xs text-muted-foreground">{{
                        x.last_seen_at
                            ? $t('tantra.ops.seen', {
                                  when: when(x.last_seen_at),
                              })
                            : $t('tantra.ops.never_seen')
                    }}</span>
                    <Badge v-if="x.revoked" variant="destructive">{{
                        $t('tantra.ops.revoked')
                    }}</Badge>
                    <Button
                        v-else
                        size="sm"
                        variant="ghost"
                        @click="revokeRunner(x)"
                        >{{ $t('tantra.ops.revoke') }}</Button
                    >
                </div>
                <form
                    class="grid max-w-2xl gap-2 rounded-lg border border-dashed border-border p-3 text-sm"
                    @submit.prevent="addRunner"
                >
                    <b>{{ $t('tantra.ops.add_runner') }}</b>
                    <Input
                        v-model="run.name"
                        :placeholder="$t('tantra.ops.name')"
                        required
                    />
                    <select
                        v-model="run.kind"
                        class="h-9 rounded-md border border-border bg-background px-2"
                    >
                        <option value="cloud_task">
                            {{ $t('tantra.ops.kinds.cloud_task') }}
                        </option>
                        <option value="local">
                            {{ $t('tantra.ops.kinds.local_cli') }}
                        </option>
                    </select>
                    <select
                        v-model="run.claude_account_id"
                        class="h-9 rounded-md border border-border bg-background px-2"
                    >
                        <option value="">
                            {{ $t('tantra.ops.no_account') }}
                        </option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">
                            {{ a.name }}
                        </option>
                    </select>
                    <Button type="submit" size="sm" class="w-fit">{{
                        $t('tantra.ops.create_runner')
                    }}</Button>
                </form>
            </section>

            <section
                v-if="tab === 'setup'"
                class="flex max-w-3xl flex-col gap-4 text-sm"
            >
                <div class="flex flex-col gap-2">
                    <h2 class="font-heading text-base font-medium">
                        {{ $t('tantra.ops.setup_cloud') }}
                    </h2>
                    <ol
                        class="list-decimal space-y-1 pl-5 text-muted-foreground"
                    >
                        <li>{{ $t('tantra.ops.cloud_1') }}</li>
                        <li>{{ $t('tantra.ops.cloud_2') }}</li>
                        <li>
                            {{ $t('tantra.ops.cloud_3', { url: engineUrl }) }}
                        </li>
                        <li>{{ $t('tantra.ops.cloud_4') }}</li>
                    </ol>
                </div>
                <div class="flex flex-col gap-2">
                    <h2 class="font-heading text-base font-medium">
                        {{ $t('tantra.ops.setup_local') }}
                    </h2>
                    <p class="text-muted-foreground">
                        {{ $t('tantra.ops.local_help') }}
                    </p>
                    <Textarea
                        :model-value="setupLocal"
                        readonly
                        rows="7"
                        class="font-mono text-xs"
                    />
                    <Button
                        size="sm"
                        class="w-fit"
                        variant="outline"
                        @click="copy(setupLocal)"
                        >{{ $t('tantra.ops.copy') }}</Button
                    >
                </div>
                <p class="text-muted-foreground">
                    {{ $t('tantra.ops.terms') }}
                </p>
            </section>
        </div>
    </AppLayout>
</template>
