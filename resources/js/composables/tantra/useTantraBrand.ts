import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Shared by App\Tantra\TantraServiceProvider when Tantra branding is on. */
export type TantraBrand = {
    branding: boolean;
    name: string;
    sourceUrl: string;
};

export const useTantraBrand = () => {
    const page = usePage();

    const brand = computed<TantraBrand | null>(
        () => (page.props.tantra as TantraBrand | undefined) ?? null,
    );

    return {
        brand,
        isTantraBranded: computed(() => brand.value?.branding === true),
    };
};
