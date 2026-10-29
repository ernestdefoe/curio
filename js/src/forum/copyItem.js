import app from 'flarum/forum/app';

/** After a quick add, the new item opens on its collection's page. */
const onAdded = (item) => {
  const collection = item.collection();
  if (collection) m.route.set(app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) + `?item=${item.id()}`);
};

/** The add windows, fetched together (a hover over Marquee's button fetches them early). */
export const addWindows = () =>
  Promise.all([import('./components/ItemEditModal'), import('./components/CollectionEditModal'), import('./components/ChooseCollectionModal')]);

/**
 * "I have this too" / "Add to my wishlist" / "Add to collection": ask which
 * of the member's collections it goes in (any of them: a PlayStation copy of
 * a game seen in someone's Xbox collection), the ones of the same type
 * first, or a new one of that type, then open the add window there.
 * `prefill` is an item to copy, or `work` a looked-up title (or the promise
 * of one, still loading while the member picks a collection); `typeIds` are
 * the types it came from, first one first (or a promise of them). Without
 * them it is the quick "Add an item". `onsave` gets the new item.
 */
export default function addToMyCollection({ prefill, work, status = 'owned', typeIds = null, onsave }) {
  // The member's collections load alongside the windows, not after them.
  const mine = app.store.find('curio-collections', { filter: { user: app.session.user.id() }, page: { limit: 50 } });

  return Promise.all([addWindows(), Promise.resolve(typeIds)]).then(
    ([[{ default: ItemEditModal }, { default: CollectionEditModal }, { default: ChooseCollectionModal }], ids]) => {
      const open = (collection) =>
        Promise.resolve(work).then(
          (title) => app.modal.show(ItemEditModal, { collection, prefill, work: title, status, onsave: onsave || (ids ? undefined : onAdded) }),
          () => {} // the caller says why
        );
      const create = () => app.modal.show(CollectionEditModal, { typeId: ids?.[0], onsave: open });
      const choose = (collections) => app.modal.show(ChooseCollectionModal, { collections, typeIds: ids || [], onchoose: open, onnew: create });

      // Knowing where it came from, the choice opens at once and fills in as the collections arrive.
      if (ids) return choose(mine.then((list) => list.slice()));
      return mine.then((list) => (list.length ? choose(list.slice()) : create()));
    }
  );
}
