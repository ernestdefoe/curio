/**
 * Drag-to-reorder for a grid, with pointer events so a mouse, a pen and a
 * finger all work. Only active while the owner is arranging (so on a phone a
 * swipe still scrolls otherwise). The array is reordered in place as the card
 * moves; onEnd runs once when it is dropped.
 */
export default class GridSort {
  constructor(getItems, onEnd) {
    this.getItems = getItems;
    this.onEnd = onEnd;
    this.dragging = null;
  }

  attrs(index) {
    return {
      'data-sort-index': index,
      tabindex: 0,
      'aria-roledescription': 'sortable',
      onpointerdown: (e) => this.start(e, index),
      // Keyboards move a card one place left or right.
      onkeydown: (e) => {
        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[e.key];
        const items = this.getItems();
        const to = index + (step || 0);
        if (!step || to < 0 || to >= items.length) return;
        e.preventDefault();
        const [item] = items.splice(index, 1);
        items.splice(to, 0, item);
        this.onEnd();
        setTimeout(() => e.target.closest('[data-sortable]')?.querySelector(`[data-sort-index="${to}"]`)?.focus(), 0);
      },
    };
  }

  start(e, index) {
    if (e.button !== undefined && e.button !== 0) return;
    const grid = e.currentTarget.closest('[data-sortable]');
    if (!grid) return;
    e.preventDefault();

    this.dragging = index;
    const pointer = e.pointerId;

    // A card is the grid's child that is (or holds) the handle: the admin's
    // type list puts the handle on a grip inside each row.
    const cards = () => Array.from(grid.children).filter((el) => el.matches('[data-sort-index]') || el.querySelector('[data-sort-index]'));

    const move = (ev) => {
      if (ev.pointerId !== pointer) return;
      let best = this.dragging;
      let bestDistance = Infinity;
      cards().forEach((card, i) => {
        const r = card.getBoundingClientRect();
        const d = Math.hypot(ev.clientX - (r.left + r.width / 2), ev.clientY - (r.top + r.height / 2));
        if (d < bestDistance) {
          bestDistance = d;
          best = i;
        }
      });
      if (best !== this.dragging) {
        const items = this.getItems();
        const [item] = items.splice(this.dragging, 1);
        items.splice(best, 0, item);
        this.dragging = best;
        m.redraw();
      }
    };

    const end = (ev) => {
      if (ev.pointerId !== pointer) return;
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', end);
      window.removeEventListener('pointercancel', end);
      this.dragging = null;
      this.onEnd();
      m.redraw();
    };

    // Listened for on the window, not the handle: moving a card up means the
    // keyed redraw moves the dragged element itself in the DOM, which drops
    // its pointer capture, so a listener on it heard nothing after the first
    // step (a drag downwards moves the other cards and kept working).
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    window.addEventListener('pointercancel', end);
    m.redraw();
  }
}
