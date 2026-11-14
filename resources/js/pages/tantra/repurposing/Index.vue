<script setup lang="ts">
// tantra: the Repurposing tab (PRD 25.10): which network a post moves to in
// which week, each network's preset workflow, and the Account's own rules on
// top of it.
import { Head } from '@inertiajs/vue3';
import { IconArrowDown, IconArrowUp } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';

interface Step {
    network: string;
    week: number;
    enabled: boolean;
}
interface Preset {
    network: string;
    source: string;
    title: string;
    rules: string;
    version: number;
    custom: string | null;
}

const props = defineProps<{
    cadence: { enabled: boolean; sequence: Step[] };
    presets: Preset[];
    networks: string[];
    custom: Record<string, string>;
    canEdit: boolean;
    canEditPresets: boolean;
}>();

const enabled = ref(props.cadence.enabled);
const steps = ref<Step[]>(
    [...props.cadence.sequence].sort((a, b) => a.week - b.week),
);
const missing = () =>
    props.networks.filter((n) => !steps.value.some((s) => s.network === n));

const move = (i: number, by: -1 | 1) => {
    const j = i + by;
    if (j < 0 || j >= steps.value.length) {
        return;
    }
    const a = steps.value[i];
    const b = steps.value[j];
    [a.week, b.week] = [b.week, a.week];
    steps.value.sort((x, y) => x.week - y.week);
};
const add = (network: string) =>
    steps.value.push({
        network,
        week: Math.max(0, ...steps.value.map((s) => s.week)) + 1,
        enabled: true,
    });

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};
const put = async (url: string, body: object): Promise<boolean> => {
    const r = await fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify(body),
    });
    const json = (await r.json().catch(() => ({}))) as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const msg =
        (json.errors ? Object.values(json.errors).flat()[0] : undefined) ??
        json.message ??
        '';
    if (r.ok) {
        toast.success(msg || trans('tantra.repurposing.saved'));
    } else {
        toast.error(msg || trans('tantra.repurposing.not_saved'));
    }

    return r.ok;
};
const saveCadence = () =>
    put('/tantra/repurposing/cadence', {
        enabled: enabled.value,
        sequence: steps.value,
    });

const rules = ref<Record<string, string>>({ ...props.custom });
const openPreset = ref<string | null>(null);
const saveRules = (network: string) =>
    put(`/tantra/repurposing/rules/${network}`, {
        rules: rules.value[network] ?? '',
    });
</script>

<template>
    <Head :title="$t('tantra.repurposing.title')" />

    <AppLayout full-width>
        <template #header>
            <CreateHeader />
        </template>

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
            <CreateTabs active="repurposing" />

            <div
                class="flex min-h-0 flex-1 flex-col gap-8 overflow-y-auto px-4 py-6 md:px-8"
            >
                <section class="flex max-w-3xl flex-col gap-3">
                    <div class="flex items-center gap-3">
                        <h2 class="font-heading text-base font-medium">
                            {{ $t('tantra.repurposing.cadence') }}
                        </h2>
                        <Switch
                            v-model="enabled"
                            :disabled="!canEdit"
                            :aria-label="$t('tantra.repurposing.cadence_on')"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('tantra.repurposing.cadence_help') }}
                    </p>
                    <ol
                        class="flex flex-col gap-2"
                        data-testid="repurposing-cadence"
                    >
                        <li
                            v-for="(s, i) in steps"
                            :key="s.network"
                            class="flex items-center gap-3 rounded-lg border border-border bg-card p-2 text-sm"
                        >
                            <span class="w-20 text-muted-foreground">{{
                                $t('tantra.repurposing.week', {
                                    n: String(s.week),
                                })
                            }}</span>
                            <b class="flex-1 capitalize">{{ s.network }}</b>
                            <Input
                                v-model.number="s.week"
                                type="number"
                                min="0"
                                max="52"
                                class="h-8 w-20"
                                :disabled="!canEdit"
                                :aria-label="
                                    $t('tantra.repurposing.week_of', {
                                        network: s.network,
                                    })
                                "
                            />
                            <Switch
                                v-model="s.enabled"
                                :disabled="!canEdit"
                                :aria-label="s.network"
                            />
                            <Button
                                size="icon"
                                variant="ghost"
                                :disabled="!canEdit || i === 0"
                                :aria-label="$t('tantra.repurposing.earlier')"
                                @click="move(i, -1)"
                                ><IconArrowUp class="size-4"
                            /></Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :disabled="!canEdit || i === steps.length - 1"
                                :aria-label="$t('tantra.repurposing.later')"
                                @click="move(i, 1)"
                                ><IconArrowDown class="size-4"
                            /></Button>
                        </li>
                    </ol>
                    <div v-if="canEdit" class="flex flex-wrap gap-2">
                        <Button
                            v-for="n in missing()"
                            :key="n"
                            size="sm"
                            variant="outline"
                            class="capitalize"
                            @click="add(n)"
                            >+ {{ n }}</Button
                        >
                        <Button size="sm" @click="saveCadence">{{
                            $t('tantra.repurposing.save')
                        }}</Button>
                    </div>
                </section>

                <section class="flex max-w-3xl flex-col gap-3">
                    <h2 class="font-heading text-base font-medium">
                        {{ $t('tantra.repurposing.presets') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('tantra.repurposing.presets_help') }}
                    </p>
                    <p
                        v-if="presets.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('tantra.repurposing.no_presets') }}
                    </p>
                    <div
                        v-for="p in presets"
                        :key="p.network"
                        class="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 text-sm"
                    >
                        <div class="flex items-center gap-2">
                            <b class="capitalize">{{ p.network }}</b>
                            <span class="text-muted-foreground">{{
                                p.title
                            }}</span>
                            <Badge v-if="rules[p.network]" variant="outline">{{
                                $t('tantra.repurposing.has_custom')
                            }}</Badge>
                            <span class="flex-1" />
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    openPreset =
                                        openPreset === p.network
                                            ? null
                                            : p.network
                                "
                                >{{
                                    openPreset === p.network
                                        ? $t('tantra.repurposing.hide')
                                        : $t('tantra.repurposing.show')
                                }}</Button
                            >
                        </div>
                        <template v-if="openPreset === p.network">
                            <pre
                                class="max-h-80 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap"
                                >{{ p.rules }}</pre
                            >
                            <label class="text-xs font-medium">{{
                                $t('tantra.repurposing.your_rules')
                            }}</label>
                            <Textarea
                                v-model="rules[p.network]"
                                rows="3"
                                :disabled="!canEdit"
                                :placeholder="
                                    $t('tantra.repurposing.rules_placeholder')
                                "
                            />
                            <Button
                                v-if="canEdit"
                                size="sm"
                                class="w-fit"
                                @click="saveRules(p.network)"
                                >{{
                                    $t('tantra.repurposing.save_rules')
                                }}</Button
                            >
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
