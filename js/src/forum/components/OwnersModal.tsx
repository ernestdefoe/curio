import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Avatar from 'flarum/common/components/Avatar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import username from 'flarum/common/helpers/username';
import { t, locale } from '../util';
import { optionLabel } from '../../common/labels';
import Stars from './Stars';
import useStyles from '../styles';

/**
 * "Who has this": every member with a copy of one looked-up title, in the
 * collections the reader may open. One line each: avatar, name, platform (or
 * a record's format, a book's edition), rating. A line opens that copy.
 * (Wlork, Discuss d/40107 #91.)
 */
export default class OwnersModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.items = null;

    app.store
      .find('curio-items', {
        filter: { work: this.attrs.workId, status: 'owned' },
        include: 'collection.user,collection.type',
        sort: '-rating',
        page: { limit: 50 },
      })
      .then((items) => (this.items = items))
      .catch(() => (this.items = []))
      .finally(() => m.redraw());
  }

  className() {
    return 'CurioOwnersModal';
  }

  title() {
    return t('item.owners_title', { title: this.attrs.title });
  }

  facet(item) {
    const field = (item.collection()?.type()?.fields() || []).find((f) => f.kind === 'select');
    return field && item.facet() ? optionLabel(field, item.facet(), locale()) : null;
  }

  content() {
    if (!this.items)
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );
    if (!this.items.length)
      return (
        <div className="Modal-body">
          <p className="CurioOwners-empty">{t('item.owners_none')}</p>
        </div>
      );

    return (
      <div className="Modal-body">
        <ul className="CurioOwners">
          {this.items.map((item) => {
            const user = item.collection()?.user();
            const facet = this.facet(item);
            return (
              <li>
                <button type="button" className="CurioOwners-row" onclick={() => this.open(item)}>
                  <Avatar user={user} />
                  <span className="CurioOwners-name">{username(user)}</span>
                  {facet ? <span className="CurioOwners-facet">{facet}</span> : null}
                  {item.status() === 'trade' ? <span className="CurioOwners-trade">{t('item.for_trade')}</span> : null}
                  <span className="CurioOwners-rating">
                    {item.rating() !== null && item.rating() !== undefined ? <Stars value={item.rating()} small /> : null}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      </div>
    );
  }

  open(item) {
    import('./ItemModal').then(({ default: ItemModal }) => app.modal.show(ItemModal, { itemId: item.id() }));
  }
}
