import app from 'flarum/forum/app';
import { lookup, loadTypes, types } from './util';
import addToMyCollection, { addWindows } from './copyItem';

/** A Marquee title → the Curio source that knows it, and the type sources it fits. */
const KINDS = {
  movie: ['marquee-movie', ['movies']],
  tv: ['marquee-tv', ['tv', 'movies']],
  game: ['marquee-game', ['games']],
  music: ['deezer', ['music']],
  book: ['openlibrary', ['books']],
};

const failed = () => app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-curio.forum.item_edit.lookup_failed'));

/** A hover over the card's button: the windows and the types arrive before the click. */
export function warm(editing) {
  if (editing) return import('./components/ItemEditModal');
  addWindows();
  loadTypes();
}

/** What the discussion page says about the title (MarqueeCard), kept up to date after an add, edit or delete. */
const card = (discussion) => discussion?.attribute('curioMarquee') || null;
const owning = (item) => (item.status() !== 'wishlist' ? 1 : 0);
function remember(discussion, patch) {
  if (!discussion) return;
  const was = card(discussion) || { owners: 0, owning: 0 };
  const now = { ...was, ...patch };
  now.owners = Math.max(0, (was.owners || 0) + (now.owning || 0) - (was.owning || 0));
  discussion.pushAttributes({ curioMarquee: now });
  m.redraw();
}

/**
 * "Add to collection" on a Marquee card. The collection choice opens at
 * once; the title loads behind it (already stored when the card says so,
 * otherwise looked up once) and is ready by the time one is picked.
 */
export function add(media, discussion) {
  const [source, sources] = KINDS[media.kind()] || [];
  if (!source) return Promise.resolve();
  const known = card(discussion)?.workId;

  const work = (known ? Promise.resolve(known) : lookup({ source, id: media.externalId() }, true).then((found) => found.workId)).then(
    (id) => app.store.getById('curio-works', id) || app.store.find('curio-works', id)
  );
  work.catch(() => {
    app.modal.close();
    failed();
  });

  const typeIds = loadTypes().then(() => {
    const ids = types()
      .filter((type) => sources.includes(type.source()))
      .sort((a, b) => sources.indexOf(a.source()) - sources.indexOf(b.source()))
      .map((type) => type.id());
    return ids.length ? ids : types().map((type) => type.id());
  });

  const onsave = (item) => remember(discussion, { itemId: item.id(), workId: item.work()?.id() || known || null, owning: owning(item) });

  return addToMyCollection({ work, typeIds, onsave }).catch(failed);
}

/** "Edit in collection" once the reader has the title: their copy, in the usual editor. */
export function edit(media, discussion) {
  return Promise.all([import('./components/ItemEditModal'), app.store.find('curio-items', card(discussion).itemId, {}, { errorHandler() {} })])
    .then(([{ default: ItemEditModal }, item]) =>
      app.modal.show(ItemEditModal, {
        item,
        collection: item.collection(),
        onsave: (saved) => remember(discussion, { owning: owning(saved) }),
        ondelete: () => remember(discussion, { itemId: null, owning: 0 }),
      })
    )
    .catch(() => app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-curio.forum.item.missing')));
}

/** "Who has this", the same list as on Curio's own pages. */
export function ownersOf(workId, title) {
  return import('./components/OwnersModal').then(({ default: OwnersModal }) => app.modal.show(OwnersModal, { workId, title }));
}
