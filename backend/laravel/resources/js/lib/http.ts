/**
 * Minimal fetch wrapper for session-authenticated web routes (unlike the
 * stateless /api/wallet/* calls): sends cookies and the XSRF token Laravel
 * expects on non-GET requests.
 */

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`));

    return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null;
}

export async function apiFetch<T = unknown>(
    url: string,
    options: RequestInit = {},
): Promise<T> {
    const method = (options.method ?? 'GET').toUpperCase();

    const headers: Record<string, string> = {
        Accept: 'application/json',
        ...(options.headers as Record<string, string> | undefined),
    };

    if (method !== 'GET' && method !== 'HEAD') {
        const token = readCookie('XSRF-TOKEN');

        if (token) {
            headers['X-XSRF-TOKEN'] = token;
        }

        if (options.body && !headers['Content-Type']) {
            headers['Content-Type'] = 'application/json';
        }
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        method,
        headers,
    });

    if (!response.ok) {
        throw new Error(`Request failed: ${response.status}`);
    }

    return (await response.json()) as T;
}
