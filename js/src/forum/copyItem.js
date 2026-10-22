import app from 'flarum/forum/app';

/** After a quick add, the new item opens on its collection's page. */
const onAdded = (item) => {
  const collection = item.collection();
  if (collection) m.route.set(app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) + `?item=${item.id()}`);
};

/**
 * "I have this too" / "Add to my wishlist" / "Add to collection": ask which
 * of the member's collections it goes in (any of them: a PlayStation copy of
 * a game seen in someone's Xbox collection), the ones of the same type
 * first, or a new one of that type, then open the add window there.
 * `prefill` is an item to copy, or `work` a looked-up title; `typeIds` are
 * the types it came from, first one first. Without them it is the quick
 * "Add an item".
 */
export default function addToMyCollection({ prefill, work, status = 'owned', typeIds = null }) {
  return Promise.all([
    import('./components/ItemEditModal'),
    import('./components/CollectionEditModal'),
    import('./components/ChooseCollectionModal'),
  ]).then(([{ default: ItemEditModal }, { default: CollectionEditModal }, { default: ChooseCollectionModal }]) =>
    app.store.find('curio-collections', { filter: { user: app.session.user.id() }, page: { limit: 50 } }).then((mine) => {
      const open = (collection) => app.modal.show(ItemEditModal, { collection, prefill, work, status, onsave: typeIds ? undefined : onAdded });
      const create = () => app.modal.show(CollectionEditModal, { typeId: typeIds?.[0], onsave: open });

      if (!typeIds && !mine.length) return create();
      app.modal.show(ChooseCollectionModal, { collections: mine.slice(), typeIds: typeIds || [], onchoose: open, onnew: create });
    })
  );
}
