<script setup lang="ts">
// tantra: profile rebrands (PRD 25.11). The person, the Account owner or an
// admin asks for one; it is written by our agent; only the person themself
// puts the headline on LinkedIn. The rest comes ready to copy.
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

interface Person {
    id: string;
    name: string;
    is_me: boolean;
}
interface Rebrand {
    id: string;
    user_id: string;
    status: string;
    banner: string | null;
    proposal: {
        headline_options: string[];
        about: string;
        banner?: { brief?: string; text?: string };
        featured?: { title: string; why?: string }[];
        experience?: string;
        notes?: string;
    } | null;
    applied: { headline?: { text: string; confirmed: boolean } } | null;
}

const props = defineProps<{ people: Person[]; canStartForOthers: boolean }>();

const rebrands = ref<Rebrand[]>([]);
const busy = ref(false);
const pick = ref<Record<string, string>>({});

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};
const call = async (method: string, url: string, body?: object) => {
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
        data?: unknown;
        message?: string;
        errors?: Record<string, string[]>;
    };

    return {
        ok: r.ok,
        data: json.data,
        message:
            (json.errors ? Object.values(json.errors).flat()[0] : undefined) ??
            json.message ??
            '',
    };
};
const load = async () => {
    const r = await call('GET', '/tantra/rebrands');
    rebrands.value = (r.data as Rebrand[]) ?? [];
};
onMounted(load);

const latest = (userId: string) =>
    rebrands.value.find(
        (r) => r.user_id === userId && r.status !== 'dismissed',
    );
const start = async (p: Person) => {
    busy.value = true;
    const r = await call('POST', `/tantra/team/${p.id}/rebrand`);
    busy.value = false;
    (r.ok ? toast.success : toast.error)(
        r.message || trans('tantra.rebrand.failed'),
    );
    await load();
};
const apply = async (r: Rebrand) => {
    const headline = pick.value[r.id] ?? r.proposal?.headline_options[0];
    if (!headline) {
        return;
    }
    busy.value = true;
    const res = await call('POST', `/tantra/rebrands/${r.id}/headline`, {
        headline,
    });
    busy.value = false;
    (res.ok ? toast.success : toast.error)(res.message);
    await load();
};
const copy = async (text: string) => {
    await navigator.clipboard.writeText(text);
    toast.success(trans('tantra.rebrand.copied'));
};
</script>

<template>
    <section class="flex flex-col gap-3" data-testid="team-rebrand">
        <h2 class="font-heading text-base font-medium">
            {{ $t('tantra.rebrand.title') }}
        </h2>
        <p class="max-w-2xl text-sm text-muted-foreground">
            {{ $t('tantra.rebrand.intro') }}
        </p>
        <div
            v-for="p in props.people.filter(
                (x) => x.is_me || canStartForOthers,
            )"
            :key="p.id"
            class="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 text-sm"
        >
            <div class="flex items-center gap-2">
                <b>{{ p.name }}</b>
                <Badge v-if="latest(p.id)" variant="outline">{{
                    $t(`tantra.rebrand.states.${latest(p.id)!.status}`)
                }}</Badge>
                <span class="flex-1" />
                <Button
                    v-if="!latest(p.id)"
                    size="sm"
                    variant="outline"
                    :disabled="busy"
                    @click="start(p)"
                    >{{ $t('tantra.rebrand.start') }}</Button
                >
            </div>
            <template v-if="latest(p.id)?.proposal">
                <div class="flex flex-col gap-1">
                    <span class="font-medium">{{
                        $t('tantra.rebrand.headline')
                    }}</span>
                    <label
                        v-for="h in latest(p.id)!.proposal!.headline_options"
                        :key="h"
                        class="flex items-start gap-2"
                    >
                        <input
                            v-model="pick[latest(p.id)!.id]"
                            type="radio"
                            :value="h"
                            :disabled="!p.is_me"
                        />
                        <span>{{ h }}</span>
                    </label>
                    <div class="flex gap-2">
                        <Button
                            v-if="p.is_me"
                            size="sm"
                            :disabled="busy"
                            @click="apply(latest(p.id)!)"
                            >{{ $t('tantra.rebrand.apply_headline') }}</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="
                                copy(
                                    pick[latest(p.id)!.id] ??
                                        latest(p.id)!.proposal!
                                            .headline_options[0],
                                )
                            "
                            >{{ $t('tantra.rebrand.copy') }}</Button
                        >
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <span class="font-medium">{{
                        $t('tantra.rebrand.about')
                    }}</span>
                    <p class="whitespace-pre-wrap text-muted-foreground">
                        {{ latest(p.id)!.proposal!.about }}
                    </p>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="w-fit"
                        @click="copy(latest(p.id)!.proposal!.about)"
                        >{{ $t('tantra.rebrand.copy') }}</Button
                    >
                </div>
                <div
                    v-if="
                        latest(p.id)!.banner ||
                        latest(p.id)!.proposal!.banner?.brief
                    "
                    class="flex flex-col gap-1"
                >
                    <span class="font-medium">{{
                        $t('tantra.rebrand.banner')
                    }}</span>
                    <img
                        v-if="latest(p.id)!.banner"
                        :src="latest(p.id)!.banner!"
                        alt=""
                        class="w-full max-w-xl rounded border border-border"
                    />
                    <p class="text-muted-foreground">
                        {{ latest(p.id)!.proposal!.banner?.brief }}
                    </p>
                </div>
                <div
                    v-if="latest(p.id)!.proposal!.featured?.length"
                    class="flex flex-col gap-1"
                >
                    <span class="font-medium">{{
                        $t('tantra.rebrand.featured')
                    }}</span>
                    <ul class="list-disc pl-5 text-muted-foreground">
                        <li
                            v-for="f in latest(p.id)!.proposal!.featured"
                            :key="f.title"
                        >
                            {{ f.title }}<span v-if="f.why">: {{ f.why }}</span>
                        </li>
                    </ul>
                </div>
                <p
                    v-if="latest(p.id)!.proposal!.notes"
                    class="text-xs text-muted-foreground"
                >
                    {{ latest(p.id)!.proposal!.notes }}
                </p>
            </template>
        </div>
    </section>
</template>
