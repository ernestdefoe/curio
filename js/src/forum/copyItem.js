import app from 'flarum/forum/app';

/**
 * "I have this too" / "Add to my wishlist" / "Add to collection": open the
 * add window in one of the member's collections whose type takes this kind of
 * thing, asking which when there are several and offering a new one when none.
 * `prefill` is an item to copy, or `work` a looked-up title.
 */
export default function addToMyCollection({ prefill, work, status = 'owned', typeIds }) {
  return Promise.all([
    import('./components/ItemEditModal'),
    import('./components/CollectionEditModal'),
    import('./components/ChooseCollectionModal'),
  ]).then(([{ default: ItemEditModal }, { default: CollectionEditModal }, { default: ChooseCollectionModal }]) =>
    app.store.find('curio-collections', { filter: { user: app.session.user.id() }, page: { limit: 50 } }).then((mine) => {
      const fitting = mine.filter((c) => typeIds.includes(c.type()?.id()));
      const open = (collection) => app.modal.show(ItemEditModal, { collection, prefill, work, status });

      if (fitting.length === 1) return open(fitting[0]);
      if (fitting.length > 1) return app.modal.show(ChooseCollectionModal, { collections: fitting, onchoose: open });
      app.modal.show(CollectionEditModal, { typeId: typeIds[0], onsave: open });
    })
  );
}
