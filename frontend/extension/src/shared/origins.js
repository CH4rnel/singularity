/**
 * Which site may see which account.
 *
 * A grant is per origin, never per tab and never per host name: `https://` and
 * `http://` of the same host are two different places, and a page cannot be
 * trusted to say which one it is — the origin always comes from the browser.
 *
 * Pure on purpose. Everything here is a value in, a value out, so the rules
 * that decide what a page is allowed to see can be tested without a browser.
 */

/** `https://swap.cyberia.church` for anything web, `null` for anything else. */
export const normaliseOrigin = (input) => {
    if (typeof input !== 'string' || input === '') {
        return null;
    }

    let url;

    try {
        url = new URL(input);
    } catch {
        return null;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    // A file:// page, a PDF viewer, an extension page: nothing with a real
    // origin to grant to, so nothing to grant.
    return url.origin === 'null' ? null : url.origin;
};

export const hostOf = (origin) => {
    const normalised = normaliseOrigin(origin);

    return normalised ? new URL(normalised).host : '';
};

/** The match pattern that injects the provider into exactly this origin. */
export const matchPattern = (origin) => {
    const normalised = normaliseOrigin(origin);

    return normalised ? `${normalised}/*` : null;
};

/**
 * The accounts a site may see, in the order the wallet lists them.
 *
 * A grant survives longer than the account it names — an account can be
 * removed, or the vault replaced with another phrase — so a stale address is
 * dropped here rather than handed to a page that would then ask to sign with a
 * key nobody holds.
 */
export const accountsFor = (grants, origin, known) => {
    const grant = grants?.[normaliseOrigin(origin) ?? ''];

    if (!grant) {
        return [];
    }

    const owned = new Set(known.map((address) => address.toLowerCase()));

    return grant.accounts.filter((address) => owned.has(address.toLowerCase()));
};

export const isConnected = (grants, origin, known) =>
    accountsFor(grants, origin, known).length > 0;

/** Grants with `origin` allowed to see `accounts`; the old grant is replaced. */
export const grantOrigin = (grants, origin, accounts, now = Date.now()) => {
    const normalised = normaliseOrigin(origin);

    if (!normalised || accounts.length === 0) {
        return grants;
    }

    return {
        ...grants,
        [normalised]: {
            accounts: [...new Set(accounts)],
            grantedAt: grants?.[normalised]?.grantedAt ?? now,
            lastSeen: now,
        },
    };
};

export const revokeOrigin = (grants, origin) => {
    const normalised = normaliseOrigin(origin);
    const next = { ...grants };
    delete next[normalised ?? ''];

    return next;
};

/** Every origin that should have the provider injected into it, sorted. */
export const grantedOrigins = (grants) => Object.keys(grants ?? {}).sort();
