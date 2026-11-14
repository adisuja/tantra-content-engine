<script setup lang="ts">
// tantra: the step before a network's sign in while its developer app is in
// testing or has no keys yet (app/Tantra/Channels/PreConnect.php). Every
// connect entry point lands here through PreConnectGate, so the connect
// dialog, Reconnect, onboarding links and emails all show the same step.
import { Link } from '@inertiajs/vue3';
import {
    IconExternalLink,
    IconFlask,
    IconKey,
    IconSend,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConnectLayout from '@/layouts/ConnectLayout.vue';

interface PreConnectStep {
    state: 'testing' | 'setup';
    platform: string;
    network: string;
    app: string;
    app_label: string;
    provider: string;
    owner_steps: string;
    your_steps: string;
    accept_url: string | null;
    console_url: string;
    catch: string | null;
    continue_url: string | null;
    back_url: string;
    request_url: string;
    can_manage: boolean;
    settings_url: string;
    owner_name: string;
}

const props = defineProps<{
    step: PreConnectStep;
}>();

const isTesting = computed(() => props.step.state === 'testing');

// Keys only: trans() in setup can run before the locale loads (.ai/rules/js.md).
const titleKey = computed(() =>
    isTesting.value
        ? 'tantra.connect.prepare.title_testing'
        : 'tantra.connect.prepare.title_setup',
);

const handle = ref('');
const sending = ref(false);
const sent = ref('');
const failed = ref(false);

const xsrf = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const requestAccess = async (): Promise<void> => {
    sending.value = true;
    failed.value = false;
    try {
        const res = await fetch(props.step.request_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf(),
            },
            body: JSON.stringify({ handle: handle.value }),
        });
        const data = (await res.json().catch(() => ({}))) as {
            message?: string;
        };
        if (!res.ok) {
            failed.value = true;
            return;
        }
        sent.value = data.message ?? '';
    } catch {
        failed.value = true;
    } finally {
        sending.value = false;
    }
};
</script>

<template>
    <ConnectLayout
        :title="$t(titleKey, { network: step.network })"
        :platform="step.platform"
        :close-url="step.back_url"
    >
        <div
            class="flex flex-col items-center gap-4 text-center"
            :data-testid="`preconnect-${step.state}`"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-muted"
                aria-hidden="true"
            >
                <IconFlask
                    v-if="isTesting"
                    class="size-6 text-muted-foreground"
                />
                <IconKey v-else class="size-6 text-muted-foreground" />
            </span>
            <div class="flex flex-col gap-2">
                <h1 class="text-xl leading-tight font-medium text-foreground">
                    {{ $t(titleKey, { network: step.network }) }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        isTesting
                            ? $t('tantra.connect.prepare.intro_testing', {
                                  network: step.network,
                              })
                            : $t('tantra.connect.prepare.intro_setup', {
                                  network: step.network,
                                  app: step.app_label,
                              })
                    }}
                </p>
            </div>
        </div>

        <section
            v-if="isTesting"
            class="flex flex-col gap-4 rounded-xl border bg-card p-5 text-sm"
            data-testid="preconnect-steps"
        >
            <div class="flex flex-col gap-1">
                <p class="font-medium text-foreground">
                    {{ $t('tantra.connect.prepare.owner_steps') }}
                </p>
                <p class="text-muted-foreground">{{ step.owner_steps }}</p>
            </div>
            <div class="flex flex-col gap-1 border-t pt-4">
                <p class="font-medium text-foreground">
                    {{ $t('tantra.connect.prepare.your_steps') }}
                </p>
                <p class="text-muted-foreground">{{ step.your_steps }}</p>
                <a
                    v-if="step.accept_url"
                    :href="step.accept_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-1 inline-flex items-center gap-1 font-medium text-foreground underline underline-offset-2"
                    data-testid="preconnect-accept"
                    >{{ $t('tantra.connect.prepare.accept') }}
                    <IconExternalLink class="size-3.5"
                /></a>
            </div>
            <p
                v-if="step.catch"
                class="border-t pt-4 text-xs text-muted-foreground"
            >
                {{ step.catch }}
            </p>
        </section>

        <section
            v-if="step.can_manage"
            class="flex flex-col gap-3 rounded-xl border border-dashed p-5 text-sm"
            data-testid="preconnect-manage"
        >
            <p class="text-muted-foreground">
                {{
                    $t('tantra.connect.prepare.you_manage', {
                        app: step.app_label,
                        provider: step.provider,
                    })
                }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" size="sm">
                    <a
                        :href="step.console_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        >{{ $t('tantra.connect.prepare.open_console') }}
                        <IconExternalLink class="size-3.5"
                    /></a>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <a
                        :href="step.settings_url"
                        data-testid="preconnect-settings"
                        >{{
                            isTesting
                                ? $t('tantra.connect.prepare.open_settings')
                                : $t('tantra.connect.prepare.add_keys')
                        }}</a
                    >
                </Button>
            </div>
        </section>

        <section
            v-else
            class="flex flex-col gap-3 rounded-xl border border-dashed p-5"
            data-testid="preconnect-request"
        >
            <template v-if="!sent">
                <div v-if="isTesting" class="flex flex-col gap-1.5">
                    <Label for="preconnect-handle">{{
                        $t('tantra.connect.prepare.handle', {
                            network: step.network,
                        })
                    }}</Label>
                    <Input
                        id="preconnect-handle"
                        v-model="handle"
                        autocomplete="off"
                        maxlength="100"
                        data-testid="preconnect-handle"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        $t('tantra.connect.prepare.request_hint', {
                            owner: step.owner_name,
                        })
                    }}
                </p>
                <div>
                    <Button
                        variant="outline"
                        :disabled="sending"
                        :loading="sending"
                        data-testid="preconnect-request-access"
                        @click="requestAccess"
                    >
                        <IconSend class="size-4" />
                        {{ $t('tantra.connect.prepare.request') }}
                    </Button>
                </div>
                <p v-if="failed" class="text-sm text-destructive" role="alert">
                    {{ $t('tantra.connect.prepare.request_failed') }}
                </p>
            </template>
            <p
                v-else
                class="text-sm text-foreground"
                role="status"
                data-testid="preconnect-requested"
            >
                {{ sent }}
            </p>
        </section>

        <template #footer>
            <Button as-child variant="outline" size="lg">
                <Link :href="step.back_url" data-testid="preconnect-back">
                    {{ $t('tantra.connect.prepare.back') }}
                </Link>
            </Button>
            <div v-if="step.continue_url" class="flex items-center gap-3">
                <span class="hidden text-sm text-muted-foreground sm:inline">
                    {{ $t('tantra.connect.prepare.already_done') }}
                </span>
                <Button as-child size="lg">
                    <a
                        :href="step.continue_url"
                        data-testid="preconnect-continue"
                        >{{ $t('tantra.connect.prepare.continue') }}</a
                    >
                </Button>
            </div>
        </template>
    </ConnectLayout>
</template>
