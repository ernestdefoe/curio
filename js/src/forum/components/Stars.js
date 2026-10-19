import Component from 'flarum/common/Component';
import { ratingAt, starsLabel } from '../../common/rating';

/**
 * Five stars in half steps (the owner's rating). Read-only, or an input: a
 * transparent range input lies over the stars, so pointer, touch and keyboard
 * all work and screen readers get a real slider. `value` is 1-10 or null;
 * `onchange` gets 1-10, or null when the same rating is picked again (cleared).
 */
export default class Stars extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.hover = null;
  }

  view() {
    const { value, onchange, label, small } = this.attrs;
    const shown = this.hover ?? value ?? 0;

    return (
      <span
        className={'CurioStars' + (small ? ' CurioStars--small' : '') + (onchange ? ' CurioStars--input' : '')}
        style={{ '--curio-fill': `${shown * 10}%` }}
        title={value ? starsLabel(value) + ' / 5' : undefined}
        aria-label={!onchange && value ? starsLabel(value) + ' / 5' : undefined}
        role={onchange ? undefined : 'img'}
      >
        <span className="CurioStars-row" aria-hidden="true">
          ★★★★★
        </span>
        {onchange ? (
          <input
            type="range"
            className="CurioStars-input"
            min="0"
            max="10"
            step="1"
            value={value || 0}
            aria-label={label}
            aria-valuetext={value ? starsLabel(value) + ' / 5' : '0'}
            onpointermove={(e) => (this.hover = ratingAt(e.offsetX, e.currentTarget.clientWidth))}
            onpointerleave={() => (this.hover = null)}
            onclick={(e) => {
              // A click picks the half star under the pointer; the same one again clears it.
              const picked = e.detail ? ratingAt(e.offsetX, e.currentTarget.clientWidth) : Number(e.currentTarget.value);
              e.currentTarget.value = picked;
              onchange(picked === value ? null : picked);
            }}
            onkeyup={(e) => {
              if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].includes(e.key)) return;
              onchange(Number(e.currentTarget.value) || null);
            }}
          />
        ) : null}
      </span>
    );
  }
}
