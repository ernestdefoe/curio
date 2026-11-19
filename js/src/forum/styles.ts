import app from 'flarum/forum/app';
import css from '../../generated/pagesCss';

/** Curio's page styles, added once, the first time one of its pages or windows is shown. */
export default function useStyles() {
  if (document.getElementById('curio-styles')) return;
  // How a cover of another shape than its frame is shown: blur (default), plain or crop.
  document.documentElement.dataset.curioFit = app.forum.attribute('curioCoverFit') || 'blur';
  const style = document.createElement('style');
  style.id = 'curio-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
