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
