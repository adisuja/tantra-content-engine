<script setup lang="ts">
// tantra: every post of the period across the channels shown (PRD 26), external
// posts included, sorted on the server by any column and paged ten at a time.
import { router } from '@inertiajs/vue3';
import {
    IconArrowDown,
    IconArrowUp,
    IconChevronLeft,
    IconChevronRight,
    IconExternalLink,
} from '@tabler/icons-vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { formatNumber } from '@/lib/utils';
import type {
    InsightsPost,
    PostSort,
    TantraInsights,
} from '@/types/tantra-insights';

const props = defineProps<{
    posts: TantraInsights['posts'];
    url: string;
    query: Record<string, string | string[]>;
}>();

const columns: PostSort[] = [
    'published_at',
    'engagement',
    'impressions',
    'reactions',
    'comments',
    'shares',
    'engagement_rate',
];

const visit = (
    sort: PostSort,
    direction: 'asc' | 'desc',
    page: number,
): void => {
    router.get(
        props.url,
        {
            ...props.query,
            posts_sort: sort,
            posts_dir: direction,
            ...(page > 1 ? { posts_page: String(page) } : {}),
        },
        {
            only: ['tantraInsights'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const sortBy = (column: PostSort): void => {
    const direction =
        props.posts.sort === column && props.posts.direction === 'desc'
            ? 'asc'
            : 'desc';

    visit(column, direction, 1);
};

const goTo = (page: number): void =>
    visit(props.posts.sort, props.posts.direction, page);

const ariaSort = (column: PostSort): 'ascending' | 'descending' | 'none' =>
    props.posts.sort !== column
        ? 'none'
        : props.posts.direction === 'asc'
          ? 'ascending'
          : 'descending';

const value = (post: InsightsPost, column: PostSort): string => {
    if (column === 'published_at') {
        return date.formatDayMonthYear(post.published_at);
    }

    const number = post[column];

    if (number === null || number === undefined) {
        return '';
    }

    return column === 'engagement_rate' ? `${number}%` : formatNumber(number);
};
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-xl border border-border bg-card"
        data-testid="tantra-insights-posts"
    >
        <div class="flex flex-col gap-1 px-4 pt-4 pb-4 sm:px-5">
            <h2
                class="font-heading text-base leading-6 font-medium text-foreground"
            >
                {{ $t('tantra.insights_all.posts_title') }}
            </h2>
            <p class="text-xs text-muted-foreground">
                {{ $t('tantra.insights_all.posts_about') }}
            </p>
        </div>
        <p
            v-if="posts.data.length === 0"
            class="px-4 pb-5 text-sm text-muted-foreground sm:px-5"
            data-testid="tantra-insights-posts-empty"
        >
            {{ $t('tantra.insights_all.no_posts') }}
        </p>
        <template v-else>
            <div class="min-w-0 overflow-x-auto border-t border-border">
                <table class="w-full min-w-[880px] text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr class="border-b border-border">
                            <th
                                scope="col"
                                class="px-4 py-2 font-medium sm:px-5"
                            >
                                {{ $t('tantra.insights_all.post') }}
                            </th>
                            <th
                                v-for="column in columns"
                                :key="column"
                                scope="col"
                                class="px-3 py-2 font-medium whitespace-nowrap"
                                :class="
                                    column === 'published_at'
                                        ? ''
                                        : 'text-right'
                                "
                                :aria-sort="ariaSort(column)"
                                :data-testid="`tantra-insights-posts-column-${column}`"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                                    :data-testid="`tantra-insights-posts-sort-${column}`"
                                    @click="sortBy(column)"
                                >
                                    {{
                                        $t(
                                            `tantra.insights_all.sorts.${column}`,
                                        )
                                    }}
                                    <IconArrowDown
                                        v-if="
                                            posts.sort === column &&
                                            posts.direction === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <IconArrowUp
                                        v-else-if="posts.sort === column"
                                        class="size-3"
                                    />
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="post in posts.data"
                            :key="post.id"
                            class="border-b border-border last:border-b-0"
                            data-testid="tantra-insights-post"
                        >
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex min-w-0 items-start gap-3">
                                    <ChannelAvatar
                                        :platform="post.platform"
                                        :src="post.avatar_url"
                                        :name="post.name ?? ''"
                                        ring="card"
                                        :size="28"
                                        :status="post.status"
                                        :account-id="post.social_account_key"
                                    />
                                    <div
                                        class="flex max-w-96 min-w-0 flex-col gap-0.5"
                                    >
                                        <span
                                            class="truncate text-xs text-muted-foreground"
                                            >{{ post.name
                                            }}<template v-if="post.person">
                                                · {{ post.person }}</template
                                            ></span
                                        >
                                        <span
                                            class="line-clamp-2 text-sm text-foreground"
                                            >{{
                                                post.excerpt ||
                                                $t(
                                                    'tantra.insights_all.no_text',
                                                )
                                            }}</span
                                        >
                                        <a
                                            v-if="post.permalink"
                                            :href="post.permalink"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                        >
                                            {{
                                                $t('tantra.insights_all.open', {
                                                    network: getPlatformLabel(
                                                        post.platform,
                                                    ),
                                                })
                                            }}
                                            <IconExternalLink class="size-3" />
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td
                                v-for="column in columns"
                                :key="column"
                                class="px-3 py-3 whitespace-nowrap tabular-nums"
                                :class="
                                    column === 'published_at'
                                        ? 'text-muted-foreground'
                                        : 'text-right text-foreground'
                                "
                            >
                                {{ value(post, column) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="posts.last_page > 1"
                class="flex items-center justify-end gap-2 border-t border-border px-4 py-2 text-xs text-muted-foreground sm:px-5"
                data-testid="tantra-insights-posts-pages"
            >
                <span>{{
                    $t('tantra.insights_all.page', {
                        page: String(posts.current_page),
                        pages: String(posts.last_page),
                    })
                }}</span>
                <button
                    type="button"
                    class="inline-flex size-7 items-center justify-center rounded-md hover:bg-accent disabled:opacity-40"
                    :disabled="posts.current_page <= 1"
                    :aria-label="$t('tantra.insights_all.previous')"
                    data-testid="tantra-insights-posts-previous"
                    @click="goTo(posts.current_page - 1)"
                >
                    <IconChevronLeft class="size-4" />
                </button>
                <button
                    type="button"
                    class="inline-flex size-7 items-center justify-center rounded-md hover:bg-accent disabled:opacity-40"
                    :disabled="posts.current_page >= posts.last_page"
                    :aria-label="$t('tantra.insights_all.next')"
                    data-testid="tantra-insights-posts-next"
                    @click="goTo(posts.current_page + 1)"
                >
                    <IconChevronRight class="size-4" />
                </button>
            </div>
        </template>
    </section>
</template>
