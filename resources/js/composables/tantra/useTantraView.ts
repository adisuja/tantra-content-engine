import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { embedded } from '@/lib/tantra/embed';

/** Shared by App\Tantra\TantraServiceProvider (TantraView) for a session the Tantra embed started. */
export type TantraView = {
    active: boolean;
    timezone: string | null;
};

/**
 * tantra: AF-10. True inside Tantra: the page is framed by Tantra, or this
 * session was started by the Tantra embed. Then the engine product's own parts
 * are hidden: its name and logo, the "Connect Claude or ChatGPT" prompt,
 * Members, Workspaces, "Invite your team" and "Delete workspace".
 */
export const useTantraView = () => {
    const page = usePage();
    const view = computed<TantraView | null>(
        () => (page.props.tantraView as TantraView | undefined) ?? null,
    );

    return {
        inTantra: computed(
            () => embedded.value || view.value?.active === true,
        ),
        /** Tantra's workspace time zone, when the embed session has one. */
        tantraTimezone: computed(() => view.value?.timezone ?? null),
    };
};

/** The settings items the Tantra view never lists (engine Members and Workspaces). */
export const HIDDEN_IN_TANTRA = ['members', 'workspaces'];
