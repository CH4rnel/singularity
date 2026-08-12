/**
 * Detects the native shells that wrap this site.
 *
 * `frontend/desktop` (Electron) exposes `window.cyberiaNative` and appends
 * `CyberiaDesktop/<version>` to the user agent; `frontend/mobile` (Capacitor)
 * appends `CyberiaMobile/<version>`. Telegram opens the same pages in its own
 * web view as a Mini App, which is a shell in every way that matters here: it
 * owns the frame, so the site header and footer would be a second chrome
 * inside someone's chat. Everything else — browsers and the installed PWA — is
 * not a native shell.
 */
import { isTelegramMiniApp } from '@/lib/telegram';
import type { TorrentBridge } from '@/lib/wallet/torrent';

export type NativeShell = 'desktop' | 'mobile' | 'telegram' | null;

interface DesktopBridge {
    shell?: string;
    version?: string;
    openExternal?: (url: string) => void;
    openProxySettings?: () => void;
    /**
     * A real BitTorrent client, which only the desktop shell can host — the
     * DHT is UDP and peers are TCP, neither of which a web view has.
     */
    torrent?: TorrentBridge;
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

    // Read off the launch parameters in the URL rather than off the SDK: the
    // layout is chosen before any script has loaded, and a wallet that flashes
    // a site header inside a chat looks like the wrong page opened.
    return isTelegramMiniApp() ? 'telegram' : null;
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
