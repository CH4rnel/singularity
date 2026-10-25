import { store } from '@/actions/App/Http/Controllers/Api/LaunchpadController';

export const launchpadWebsiteError = (value: string): string | null => {
    const website = value.trim();

    if (!website) {
        return null;
    }

    try {
        if (/^https?:\/\//i.test(website) && new URL(website).hostname) {
            return null;
        }
    } catch {
        // Invalid URLs use the same guidance as local file paths.
    }

    return 'Use an http:// or https:// website URL. To publish a local HTML file, leave Website empty and select it under HTML page.';
};

export const uploadLaunchpadMetadata = async (
    form: FormData,
): Promise<void> => {
    const response = await fetch(store.url(), {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: form,
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        const first = Object.values(body?.errors ?? {})[0]?.[0];

        throw new Error(
            first ??
                body?.message ??
                `Metadata upload failed (HTTP ${response.status}).`,
        );
    }
};
