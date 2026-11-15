<script setup lang="ts">
// tantra: the Create button on Review inside the Tantra CRM's Content tab,
// where the Engine sidebar and its "New" menu are hidden (PRD 2026-11-15, 3.3).
import { router, usePage } from '@inertiajs/vue3';
import {
    IconBulb,
    IconChevronDown,
    IconFileText,
    IconHandClick,
    IconRss,
    IconTemplate,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { create as createIdea } from '@/routes/app/create/ideas';

const page = usePage();
const tasks = computed(() => {
    const review = page.props.tantraReview as
        | { tasks_open?: number; tasks_due?: number }
        | null
        | undefined;

    return { open: review?.tasks_open ?? 0, due: review?.tasks_due ?? 0 };
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button size="sm" class="gap-1.5" data-testid="embed-create">
                Create
                <IconChevronDown class="size-4" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="w-56"
            data-testid="embed-create-menu"
        >
            <DropdownMenuItem
                data-testid="embed-create-post"
                @select="openPostComposer()"
            >
                <IconFileText class="size-4 text-muted-foreground" />
                Post
            </DropdownMenuItem>
            <DropdownMenuItem
                data-testid="embed-create-idea"
                @select="router.visit(createIdea.url())"
            >
                <IconBulb class="size-4 text-muted-foreground" />
                Idea
            </DropdownMenuItem>
            <DropdownMenuItem
                data-testid="embed-create-template"
                @select="router.visit('/create/templates')"
            >
                <IconTemplate class="size-4 text-muted-foreground" />
                From a template
            </DropdownMenuItem>
            <DropdownMenuItem
                data-testid="embed-create-feed"
                @select="router.visit('/create/feeds')"
            >
                <IconRss class="size-4 text-muted-foreground" />
                From a feed
            </DropdownMenuItem>
            <template v-if="tasks.open > 0">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    data-testid="embed-create-tasks"
                    @select="router.visit('/tantra/tasks')"
                >
                    <IconHandClick class="size-4 text-muted-foreground" />
                    {{
                        tasks.due > 0
                            ? `Post by hand (${tasks.due} due)`
                            : 'Post by hand'
                    }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
