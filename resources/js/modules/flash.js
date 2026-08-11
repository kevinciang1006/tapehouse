// The signature element's motion, and only motion: a 12%-opacity up/down
// background wash on a row's numeric line that decays to transparent over
// --flash-ms. Nothing here moves, scales or slides anything — CSS in
// _tape.scss owns the decay curve, this module only toggles the class at
// the right moments.
//
// Callers pass the .tape-row__numeric element, not the outer .tape-row —
// that is the element _tape.scss's .is-flashing-up/.is-flashing-down rules
// target.

const pending = new WeakMap();

export function apply(el, direction) {
    const cls = direction === 'down' ? 'is-flashing-down' : 'is-flashing-up';

    // Keyed by element, so a symbol ticking twice inside the decay window
    // restarts its own flash instead of stacking timers that would each strip
    // the class and cut the decay short.
    const existing = pending.get(el);

    if (existing) {
        clearTimeout(existing);
    }

    el.classList.remove('is-flashing-up', 'is-flashing-down');
    // Force a reflow so re-adding the class restarts the transition rather
    // than being coalesced away by the browser.
    void el.offsetWidth;
    el.classList.add(cls);

    // Queried at call time, not module load, so an OS-level change mid-session
    // is respected rather than baked in at import time.
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reducedMotion) {
        // _tape.scss swaps the decaying wash for a static 1px border under
        // this media query — there is no transition to let play out, so the
        // class has to be held on a timer instead of stripped next frame, or
        // the substitute paints and vanishes within a single frame and the
        // one operator this fallback exists for sees nothing at all.
        pending.set(
            el,
            setTimeout(() => {
                el.classList.remove(cls);
                pending.delete(el);
            }, 600),
        );

        return;
    }

    // Two rAFs, not one: the class must be present for a full painted frame
    // (with transition: none) before it is removed, or the browser can
    // coalesce the add+remove into a single style recalc and never paint the
    // instant wash at all.
    requestAnimationFrame(() => {
        requestAnimationFrame(() => el.classList.remove(cls));
    });

    pending.set(el, setTimeout(() => pending.delete(el), 600));
}
