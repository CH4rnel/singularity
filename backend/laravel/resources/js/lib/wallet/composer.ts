/**
 * The message composer's one piece of behaviour.
 *
 * A chat field that is one line tall wastes the screen the moment someone
 * writes a paragraph, and a field that is five lines tall wastes it the rest of
 * the time. It grows with the draft instead, up to the cap `.cw-chat-form` sets
 * — past that the draft scrolls rather than pushing the transcript off screen.
 *
 * The height has to be cleared before it is read: `scrollHeight` on an element
 * that is already tall enough reports the height it was given, not the height
 * the text needs, so a shrinking draft would never shrink the field back.
 */
export const growComposer = (field: HTMLTextAreaElement | null): void => {
    if (!field) {
        return;
    }

    const cap = parseFloat(getComputedStyle(field).maxHeight);

    field.style.height = 'auto';
    field.style.height = `${Number.isFinite(cap) ? Math.min(field.scrollHeight, cap) : field.scrollHeight}px`;
};
