import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { pick } from '../common/labels';

export const t = (key, params) => app.translator.trans(`ernestdefoe-curio.forum.${key}`, params);
export const text = (key, params) => extractText(t(key, params));

export const locale = () => app.translator.getLocale() || 'en';
export const label = (labels) => pick(labels, locale());

export const STATUSES = ['owned', 'wishlist', 'trade'];
export const CONDITIONS = ['sealed', 'mint', 'very_good', 'good', 'fair', 'poor'];

export const collectionUrl = (collection) => app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` });

/** Every type, loaded once per page view and kept in the store. */
let typesLoading = null;
export function loadTypes() {
  if (!typesLoading) {
    typesLoading = app.store.find('curio-types').then((types) => {
      m.redraw();
      return types;
    });
    typesLoading.catch(() => (typesLoading = null));
  }
  return typesLoading;
}
export const types = () => app.store.all('curio-types').sort((a, b) => a.position() - b.position());

/** A JSON POST to Curio's look-up endpoint. */
export function lookup(body) {
  return app.request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/curio/lookup`, body });
}

/**
 * Close a Curio window, then carry on (navigate, open the next window).
 * Core's animated hide() waits for a transitionend; when what follows
 * navigates or opens another window, that element is gone before the
 * transition ends, core's ModalManager stays "closing" for the rest of the
 * visit and ignores every later hide(), so the next Save spun forever.
 * Closing the modal state directly never waits on an animation.
 */
export function closeThen(next, ...args) {
  app.modal.close();
  next && next(...args);
}
