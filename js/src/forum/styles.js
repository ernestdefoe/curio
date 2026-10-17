import css from '../../generated/pagesCss';

/** Curio's page styles, added once, the first time one of its pages or windows is shown. */
export default function useStyles() {
  if (document.getElementById('curio-styles')) return;
  const style = document.createElement('style');
  style.id = 'curio-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
