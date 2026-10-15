/**
 * Puts a drawn screen into the page.
 *
 * The popup and the onboarding tab draw each screen as one template string,
 * and every value interpolated into one goes through `esc()` first — that, and
 * the extension's `script-src 'self'` policy, is what keeps a token name or a
 * dapp's origin from becoming markup. This only decides *where* the string is
 * parsed: in an inert document that has no scripting and loads nothing, with
 * the resulting nodes then moved into the live one, rather than by assigning
 * `innerHTML` on the live element.
 */
export const renderInto = (element, html) => {
    const parsed = new DOMParser().parseFromString(html, 'text/html');

    element.replaceChildren(...parsed.body.childNodes);
};
