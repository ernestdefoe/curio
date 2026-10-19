import app from 'flarum/forum/app';

/** After a quick add, the new item opens on its collection's page. */
const onAdded = (item) => {
  const collection = item.collection();
  if (collection) m.route.set(app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) + `?item=${item.id()}`);
};

/**
 * "I have this too" / "Add to my wishlist" / "Add to collection": open the
 * add window in one of the member's collections whose type takes this kind of
 * thing, asking which when there are several and offering a new one when none.
 * `prefill` is an item to copy, or `work` a looked-up title. Without
 * `typeIds` it is the quick "Add an item": any of the member's collections,
 * or a new one.
 */
export default function addToMyCollection({ prefill, work, status = 'owned', typeIds = null }) {
  return Promise.all([
    import('./components/ItemEditModal'),
    import('./components/CollectionEditModal'),
    import('./components/ChooseCollectionModal'),
  ]).then(([{ default: ItemEditModal }, { default: CollectionEditModal }, { default: ChooseCollectionModal }]) =>
    app.store.find('curio-collections', { filter: { user: app.session.user.id() }, page: { limit: 50 } }).then((mine) => {
      const fitting = typeIds ? mine.filter((c) => typeIds.includes(c.type()?.id())) : mine;
      const open = (collection) => app.modal.show(ItemEditModal, { collection, prefill, work, status, onsave: typeIds ? undefined : onAdded });
      const create = () => app.modal.show(CollectionEditModal, { typeId: typeIds?.[0], onsave: open });

      if (fitting.length === 1 && typeIds) return open(fitting[0]);
      if (fitting.length) return app.modal.show(ChooseCollectionModal, { collections: fitting, onchoose: open, onnew: typeIds ? null : create });
      create();
    })
  );
}
