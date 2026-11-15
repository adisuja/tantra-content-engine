<script setup lang="ts">
// tantra: Engagement > Posts. The watched posts and accounts (CRM sources), each with one switch. A post that was
// never on asks first whether people who already engaged are included. Going live is Approve and start, in the
// post's sheet, and only on the owner's click.
import { IconBrandLinkedin, IconBuilding, IconUsers } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';

import { get, post } from '@/components/tantra/engagement/api';
import {
    type Source,
    sourceTitle,
    when,
} from '@/components/tantra/engagement/shared';
import SourceSheet from '@/components/tantra/engagement/SourceSheet.vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';

const props = defineProps<{ canManage: boolean }>();

const sources = ref<Source[] | null>(null);
const open = ref<string | null>(null);
const askEnable = ref(false);

const load = async () => {
    const r = await get<{ sources: Source[] }>('/sources');
    if (r.ok) {
        sources.value = r.data?.sources ?? [];
    } else if (r.code !== 'crm_unreachable' && r.code !== 'crm_not_connected') {
        toast.error(r.message || trans('tantra.engagement.refused'));
        sources.value ??= [];
    }
};
onMounted(load);

const toggle = async (s: Source) => {
    // Never on before: the sheet asks about people who already engaged.
    if (!s.enabled && !s.options?.started_at) {
        askEnable.value = true;
        open.value = s.id;

        return;
    }
    const next = !s.enabled;
    s.enabled = next;
    const r = await post(`/sources/${s.id}/enable`, { enabled: next });
    if (!r.ok) {
        s.enabled = !next;
        toast.error(r.message || trans('tantra.engagement.refused'));

        return;
    }
    toast.success(
        trans(
            next ? 'tantra.engagement.posts.on' : 'tantra.engagement.posts.off',
        ),
    );
};

const openSheet = (id: string) => {
    askEnable.value = false;
    open.value = id;
};
const closeSheet = () => {
    open.value = null;
    askEnable.value = false;
    void load();
};

const pollWords = (e: string | null): string | null =>
    !e
        ? null
        : /NotFoundUrn|decorate the URN/i.test(e)
          ? trans('tantra.engagement.posts.not_found')
          : e;
</script>

<template>
    <div class="mx-auto w-full max-w-4xl px-4 py-6 md:px-6">
        <div v-if="!sources" class="grid place-items-center py-10">
            <Spinner />
        </div>
        <template v-else>
            <div class="mb-3 flex items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    {{
                        sources.length
                            ? $t('tantra.engagement.posts.count', {
                                  on: String(
                                      sources.filter((s) => s.enabled).length,
                                  ),
                                  total: String(sources.length),
                              })
                            : ''
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t('tantra.engagement.posts.add_in_crm') }}
                </p>
            </div>
            <p
                v-if="!sources.length"
                class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
            >
                {{ $t('tantra.engagement.posts.empty') }}
            </p>
            <ul
                v-else
                class="divide-y divide-border rounded-xl border border-border bg-card"
                data-testid="engagement-sources"
            >
                <li
                    v-for="s in sources"
                    :key="s.id"
                    class="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-3 text-left"
                        @click="openSheet(s.id)"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl bg-[#0a66c2]/10 text-[#0a66c2]"
                        >
                            <IconBuilding
                                v-if="s.platform === 'linkedin_page'"
                                class="size-4"
                            />
                            <IconBrandLinkedin v-else class="size-4" />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{
                                sourceTitle(s)
                            }}</span>
                            <span
                                class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                            >
                                <span class="truncate">
                                    <template v-if="s.stats">{{
                                        $t(
                                            'tantra.engagement.posts.prospects',
                                            {
                                                count: String(
                                                    s.stats.prospects ??
                                                        s.stats.people,
                                                ),
                                            },
                                        )
                                    }}</template>
                                    <template v-if="s.stats?.keywords">
                                        ·
                                        {{
                                            $t(
                                                'tantra.engagement.posts.asked',
                                                {
                                                    count: String(
                                                        s.stats.keywords,
                                                    ),
                                                },
                                            )
                                        }}</template
                                    >
                                    <template v-if="s.keyword">
                                        ·
                                        {{
                                            $t(
                                                'tantra.engagement.posts.keyword',
                                                {
                                                    keyword: s.keyword,
                                                },
                                            )
                                        }}</template
                                    >
                                    <template v-if="s.last_polled_at">
                                        ·
                                        {{
                                            $t('tantra.engagement.posts.read', {
                                                when: when(s.last_polled_at),
                                            })
                                        }}</template
                                    >
                                </span>
                                <Badge
                                    v-if="s.stats?.team"
                                    variant="secondary"
                                    class="h-auto px-1.5"
                                    ><IconUsers />{{
                                        $t('tantra.engagement.posts.team', {
                                            count: String(s.stats.team),
                                        })
                                    }}</Badge
                                >
                            </span>
                            <span
                                v-if="s.poll_error"
                                class="block truncate text-xs text-destructive-text"
                                >{{ pollWords(s.poll_error) }}</span
                            >
                        </span>
                    </button>
                    <Switch
                        :model-value="s.enabled"
                        :disabled="!props.canManage"
                        :aria-label="
                            $t(
                                s.enabled
                                    ? 'tantra.engagement.posts.turn_off'
                                    : 'tantra.engagement.posts.turn_on',
                                { title: sourceTitle(s) },
                            )
                        "
                        :data-testid="`engagement-source-switch-${s.id}`"
                        @update:model-value="toggle(s)"
                    />
                </li>
            </ul>
        </template>
        <SourceSheet
            :id="open"
            :ask-enable="askEnable"
            :can-manage="canManage"
            @close="closeSheet"
        />
    </div>
</template>
