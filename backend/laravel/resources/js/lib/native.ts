/**
 * Detects the native shells that wrap this site.
 *
 * `frontend/desktop` (Electron) exposes `window.cyberiaNative` and appends
 * `CyberiaDesktop/<version>` to the user agent; `frontend/mobile` (Capacitor)
 * appends `CyberiaMobile/<version>`. Everything else — browsers and the
 * installed PWA — is not a native shell.
 */

export type NativeShell = 'desktop' | 'mobile' | null;

interface DesktopBridge {
    shell?: string;
    version?: string;
    openExternal?: (url: string) => void;
    openProxySettings?: () => void;
}

declare global {
    interface Window {
        cyberiaNative?: DesktopBridge;
        Capacitor?: unknown;
    }
}

let detected: NativeShell = null;

function detect(): NativeShell {
    if (typeof window === 'undefined') {
        return null;
    }

    const userAgent = window.navigator.userAgent;

    if (
        window.cyberiaNative?.shell === 'desktop' ||
        /\bCyberiaDesktop\//.test(userAgent)
    ) {
        return 'desktop';
    }

    if (window.Capacitor !== undefined || /\bCyberiaMobile\//.test(userAgent)) {
        return 'mobile';
    }

    return null;
}

/**
 * Resolves the shell once and mirrors it onto `<html data-native-shell>` so CSS
 * can react to it too.
 */
export function initializeNativeShell(): NativeShell {
    detected = detect();

    if (detected && typeof document !== 'undefined') {
        document.documentElement.dataset.nativeShell = detected;
    }

    return detected;
}

export function nativeShell(): NativeShell {
    return detected;
}

export function isNativeShell(): boolean {
    return detected !== null;
}

/**
 * Whether the shell has network settings of its own to open.
 *
 * Only the desktop app does: a browser tab cannot choose a proxy, and on a
 * phone that choice belongs to the system. Feature-detected rather than
 * inferred from the shell name, because an older desktop build has no such
 * window and would give the user a button that does nothing.
 */
export function canOpenProxySettings(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.cyberiaNative?.openProxySettings === 'function'
    );
}

/** Raises the shell's proxy window. No-op anywhere else. */
export function openProxySettings(): void {
    window.cyberiaNative?.openProxySettings?.();
}
