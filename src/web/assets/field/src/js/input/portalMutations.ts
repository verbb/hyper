/**
 * Portal MutationObserver filter: layout tabs only toggle chrome attrs (`.hidden`, aria).
 * Those must not project to the hidden store. Real edits change values / DOM structure.
 */
export function isPortalContentMutation(mutation: MutationRecord, portal: HTMLElement): boolean {
    const target = mutation.target instanceof Element ? mutation.target : mutation.target.parentElement;
    // Vizy publishes real edits through its canonical input's input/change events.
    // Its asynchronous editor DOM and field-layout hydration are not author edits.
    const vizy = target?.closest('vizy-editor');
    if (vizy && portal.contains(vizy)) return false;

    if (mutation.type === 'characterData' || mutation.type === 'childList') {
        return true;
    }

    if (mutation.type !== 'attributes' || !mutation.attributeName) {
        return false;
    }

    const attr = mutation.attributeName;

    if (
        attr === 'tabindex'
        || attr === 'class'
        || attr === 'style'
        || attr === 'hidden'
        || attr === 'aria-selected'
        || attr === 'aria-hidden'
        || attr === 'aria-pressed'
        || attr === 'aria-busy'
    ) {
        return false;
    }

    // A nested field finishes enabling its Add/Paste actions after the parent
    // starts observing. Button availability does not change submitted content.
    if (attr === 'disabled' && mutation.target instanceof Element
        && mutation.target.matches('button, pk-dropdown-item')) {
        return false;
    }

    return true;
}

