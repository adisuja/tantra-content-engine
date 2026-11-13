// tantra: JSON calls for Studio ops (app/Tantra/Http/routes-web.php, tantra/ops/*).

export interface OpsResult<T = unknown> {
    ok: boolean;
    status: number;
    message: string;
    data: T | null;
    token?: string;
}

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

export async function ops<T = unknown>(
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
    path: string,
    body?: Record<string, unknown>,
): Promise<OpsResult<T>> {
    try {
        const response = await fetch(`/tantra/ops${path}`, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const json = (await response.json().catch(() => ({}))) as {
            data?: T;
            message?: string;
            token?: string;
            errors?: Record<string, string[]>;
        };
        const firstError = json.errors
            ? Object.values(json.errors).flat()[0]
            : undefined;

        return {
            ok: response.ok,
            status: response.status,
            message: firstError ?? json.message ?? '',
            data: json.data ?? null,
            token: json.token,
        };
    } catch {
        return { ok: false, status: 0, message: '', data: null };
    }
}
