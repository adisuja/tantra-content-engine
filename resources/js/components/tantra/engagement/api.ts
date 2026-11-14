// tantra: the Engagement tab's calls. Each goes to the Engine (/tantra/engagement/api/*), which relays it to the
// Tantra CRM with the server's key (App\Tantra\Http\Controllers\Web\EngagementController). The key never reaches here.
import { ref } from 'vue';

export interface ApiResult<T> {
    ok: boolean;
    status: number;
    data: T | null;
    message: string;
    code: string | null;
}

/** Set when the CRM cannot be reached or this Engine has no CRM workspace; the page shows that state. */
export const crmProblem = ref<{ code: string; message: string } | null>(null);

const BASE = '/tantra/engagement/api';

const xsrf = (): string => {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
};

export async function call<T>(
    method: 'GET' | 'POST' | 'PATCH',
    path: string,
    opts: {
        query?: Record<string, string | number | undefined | null>;
        body?: Record<string, unknown>;
    } = {},
): Promise<ApiResult<T>> {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(opts.query ?? {})) {
        if (v !== undefined && v !== null && v !== '') {
            qs.set(k, String(v));
        }
    }
    const url = `${BASE}${path}${qs.toString() ? `?${qs}` : ''}`;
    try {
        const r = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf(),
            },
            body:
                method === 'GET' ? undefined : JSON.stringify(opts.body ?? {}),
        });
        const json = (await r.json().catch(() => ({}))) as Record<
            string,
            unknown
        >;
        const errors = json.errors as Record<string, string[]> | undefined;
        const message =
            (errors ? Object.values(errors).flat()[0] : undefined) ??
            (typeof json.message === 'string' ? json.message : '');
        const code = typeof json.code === 'string' ? json.code : null;
        if (
            !r.ok &&
            (code === 'crm_unreachable' || code === 'crm_not_connected')
        ) {
            crmProblem.value = { code, message };
        }

        return {
            ok: r.ok,
            status: r.status,
            data: r.ok ? (json as T) : null,
            message,
            code,
        };
    } catch {
        crmProblem.value = { code: 'crm_unreachable', message: '' };

        return {
            ok: false,
            status: 0,
            data: null,
            message: '',
            code: 'crm_unreachable',
        };
    }
}

export const get = <T>(
    path: string,
    query?: Record<string, string | number | undefined | null>,
) => call<T>('GET', path, { query });
export const post = <T>(path: string, body: Record<string, unknown> = {}) =>
    call<T>('POST', path, { body });
export const patch = <T>(path: string, body: Record<string, unknown> = {}) =>
    call<T>('PATCH', path, { body });
