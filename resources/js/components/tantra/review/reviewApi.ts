// tantra: calls the studio's JSON review endpoints (app/Tantra/Http/routes-web.php).

export type ReviewAction =
    | 'approve'
    | 'unapprove'
    | 'request-changes'
    | 'reject'
    | 'comments'
    | 'sign-off';

export interface ReviewActionResult {
    ok: boolean;
    message: string;
}

const xsrfToken = (): string => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

export const reviewItemUrl = (itemId: string, action: ReviewAction): string =>
    `/tantra/review/items/${encodeURIComponent(itemId)}/${action}`;

/** Posts one action. Resolves with ok=false and the server's reason when it refuses. */
export async function sendReviewAction(
    itemId: string,
    action: ReviewAction,
    body: Record<string, unknown> = {},
): Promise<ReviewActionResult> {
    try {
        const response = await fetch(reviewItemUrl(itemId, action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(body),
        });
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (response.ok) {
            return { ok: true, message: data.message ?? '' };
        }

        const firstError = data.errors
            ? Object.values(data.errors).flat()[0]
            : undefined;

        return { ok: false, message: firstError ?? data.message ?? '' };
    } catch {
        return { ok: false, message: '' };
    }
}
