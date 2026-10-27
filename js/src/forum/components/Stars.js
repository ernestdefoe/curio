import Component from 'flarum/common/Component';
import { ratingAt, starsLabel } from '../../common/rating';

/** The half star under a pointer, from where it is on the row (offsetX is unreliable for touch). */
const at = (e) => {
  const r = e.currentTarget.getBoundingClientRect();
  return ratingAt(e.clientX - r.left, r.width);
};

/**
 * Five stars in half steps (the owner's rating). Read-only, or an input. As
 * an input, the row of stars takes every tap, slide and click itself; a
 * transparent range input underneath gives the keyboard and screen readers a
 * real slider. `value` is 0-10 or null; `onchange` gets 0-10, or null when
 * the same rating is tapped again (cleared).
 *
 * 🚨 Until 1.14.1 the range input took the pointer too, and no rating could
 * be given on a phone: Chrome and Brave on Android only drag a range input by
 * its (here invisible) thumb, and WebKit, which every iPhone browser runs on,
 * delivered a tap on it to no handler at all.
 */
export default class Stars extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.hover = null;
    this.drag = null;
  }

  view() {
    const { value, onchange, label, small } = this.attrs;
    const shown = this.hover ?? value ?? 0;
    const input = !!onchange;

    return (
      <span
        className={'CurioStars' + (small ? ' CurioStars--small' : '') + (input ? ' CurioStars--input' : '')}
        style={{ '--curio-fill': `${shown * 10}%` }}
        title={value != null ? starsLabel(value) + ' / 5' : undefined}
        aria-label={!input && value != null ? starsLabel(value) + ' / 5' : undefined}
        role={input ? undefined : 'img'}
        {...(input ? this.pointer(value, onchange) : {})}
      >
        <span className="CurioStars-row" aria-hidden="true">
          ★★★★★
        </span>
        {input ? (
          <input
            type="range"
            className="CurioStars-input"
            min="0"
            max="10"
            step="1"
            value={value || 0}
            aria-label={label}
            aria-valuetext={value != null ? starsLabel(value) + ' / 5' : undefined}
            onkeyup={(e) => {
              if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(e.key)) return;
              onchange(Number(e.currentTarget.value));
            }}
          />
        ) : null}
      </span>
    );
  }

  /**
   * Taps, slides and clicks, on the row of stars itself. The slider underneath
   * takes no pointer at all (CSS): on a phone a range input only follows its
   * own thumb, and in WebKit (every iPhone browser, Chrome and Brave included)
   * a tap on an invisible one reaches no handler at all.
   */
  pointer(value, onchange) {
    return {
      onpointerdown: (e) => {
        if (e.button) return;
        e.preventDefault();
        e.currentTarget.querySelector('input')?.focus({ preventScroll: true });
        try {
          e.currentTarget.setPointerCapture(e.pointerId);
        } catch (err) {
          // A pointer the browser no longer tracks; the press still works without capture.
        }
        this.drag = { at: at(e), moved: false };
        this.handled = false;
        this.hover = this.drag.at;
      },
      onpointermove: (e) => {
        this.hover = at(e);
        if (this.drag && this.hover !== this.drag.at) this.drag.moved = true;
      },
      onpointerup: (e) => {
        if (!this.drag) return;
        const picked = at(e);
        const tapped = !this.drag.moved;
        this.drag = null;
        this.hover = null;
        this.handled = true;
        // Tapping the rating it already has clears it; a slide that ends there keeps it.
        onchange(tapped && picked === value ? null : picked);
      },
      onpointercancel: () => {
        this.drag = null;
        this.hover = null;
      },
      onpointerleave: () => {
        if (!this.drag) this.hover = null;
      },
      onclick: (e) => {
        // The click after a press the pointer handlers already took is ignored; a browser that
        // sends a tap only as a click still rates with it.
        if (this.handled) return (this.handled = false);
        const picked = at(e);
        onchange(picked === value ? null : picked);
      },
    };
  }
}
