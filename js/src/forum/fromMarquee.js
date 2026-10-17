import app from 'flarum/forum/app';
import { lookup, loadTypes, types } from './util';
import addToMyCollection from './copyItem';

/** A Marquee title → the Curio source that knows it, and the type sources it fits. */
const KINDS = {
  movie: ['marquee-movie', ['movies']],
  tv: ['marquee-tv', ['tv', 'movies']],
  game: ['marquee-game', ['games']],
  music: ['deezer', ['music']],
  book: ['openlibrary', ['books']],
};

/** "Add to collection" on a Marquee card: the title, looked up once, into one of your collections. */
export default function addFromMarquee(media) {
  const [source, sources] = KINDS[media.kind()] || [];
  if (!source) return Promise.resolve();

  return loadTypes()
    .then(() => lookup({ source, id: media.externalId() }))
    .then((found) => app.store.find('curio-works', found.workId))
    .then((work) => {
      const typeIds = types()
        .filter((type) => sources.includes(type.source()))
        .sort((a, b) => sources.indexOf(a.source()) - sources.indexOf(b.source()))
        .map((type) => type.id());
      return addToMyCollection({ work, typeIds: typeIds.length ? typeIds : types().map((type) => type.id()) });
    })
    .catch(() => app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-curio.forum.item_edit.lookup_failed')));
}
