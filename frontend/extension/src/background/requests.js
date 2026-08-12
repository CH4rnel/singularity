/**
 * The queue between a page asking and a person answering.
 *
 * A request lives only as long as the service worker that holds it: the port
 * from the content script keeps that worker alive while a page is waiting, and
 * if the browser tears everything down anyway the page gets a rejection rather
 * than a promise that never settles.
 *
 * Approvals are shown in their own window instead of the toolbar popup. A
 * toolbar popup closes the moment the user clicks anything else — including
 * the page that is waiting for them — and a signature prompt that vanishes
 * mid-read teaches people to click before reading.
 */
import { rpcError } from '../shared/protocol.js';

const pending = new Map();

let sequence = 0;
let windowId = null;

const AMBER = '#E8B44A';

/** Badge = how many decisions are waiting. Nothing else earns the space. */
const paintBadge = async () => {
    const count = pending.size;

    await chrome.action.setBadgeBackgroundColor({ color: AMBER });
    await chrome.action.setBadgeText({ text: count > 0 ? String(count) : '' });
};

const openWindow = async () => {
    if (windowId !== null) {
        try {
            await chrome.windows.update(windowId, { focused: true });
            return;
        } catch {
            // The user closed it; fall through and open a new one.
            windowId = null;
        }
    }

    const created = await chrome.windows.create({
        url: chrome.runtime.getURL('popup.html?view=request'),
        type: 'popup',
        // The design's popup is 348 wide; the frame adds its own chrome.
        width: 372,
        height: 620,
        focused: true,
    });

    windowId = created?.id ?? null;
};

const closeWindowIfIdle = async () => {
    if (pending.size > 0 || windowId === null) {
        return;
    }

    const id = windowId;
    windowId = null;

    try {
        await chrome.windows.remove(id);
    } catch {
        // Already gone.
    }
};

/**
 * Ask the human. Resolves with whatever the popup approves, rejects with an
 * EIP-1193 error when they say no — 4001 is what a dapp expects and handles.
 */
export const ask = async ({ type, origin, payload }) => {
    const id = `${Date.now().toString(36)}-${++sequence}`;

    const answer = new Promise((resolve, reject) => {
        pending.set(id, { id, type, origin, payload, createdAt: Date.now(), resolve, reject });
    });

    await paintBadge();
    await openWindow();

    return answer;
};

export const list = () =>
    [...pending.values()].map(({ id, type, origin, payload, createdAt }) => ({
        id,
        type,
        origin,
        payload,
        createdAt,
    }));

export const first = () => pending.values().next().value ?? null;

export const settle = async (id, { approved, value, error }) => {
    const request = pending.get(id);

    if (!request) {
        return false;
    }

    pending.delete(id);

    if (approved) {
        request.resolve(value);
    } else {
        request.reject(error ?? rpcError('userRejected'));
    }

    await paintBadge();
    await closeWindowIfIdle();

    return true;
};

/** Everything a locked or restarted wallet cannot honestly keep waiting on. */
export const rejectAll = async (reason = 'userRejected') => {
    for (const [id] of pending) {
        await settle(id, { approved: false, error: rpcError(reason) });
    }
};

export const rejectOrigin = async (origin, reason = 'unauthorized') => {
    for (const [id, request] of pending) {
        if (request.origin === origin) {
            await settle(id, { approved: false, error: rpcError(reason) });
        }
    }
};

export const count = () => pending.size;

export const refreshBadge = paintBadge;
