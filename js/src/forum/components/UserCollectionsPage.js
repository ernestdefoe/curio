import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import { t, collectionUrl } from '../util';
import { collectionCard, cover } from './bits';
import GridSort from '../../common/gridSort';
import CollectionEditModal from './CollectionEditModal';
import ItemModal from './ItemModal';
import useStyles from '../styles';
import addToMyCollection from '../copyItem';

/** Profile → Collections: what the member collects, their collections, their wishlist. */
export default class UserCollectionsPage extends UserPage {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.collections = null;
    this.wanted = [];
    this.arranging = false;
    this.sorter = new GridSort(
      () => this.collections,
      () =>
        app.request({
          method: 'POST',
          url: `${app.forum.attribute('apiUrl')}/curio-collections/order`,
          body: { ids: this.collections.map((c) => c.id()) },
        })
    );
    this.loadUser(m.route.param('username'));
  }

  show(user) {
    super.show(user);
    if (this.shownFor === user.id()) return;
    this.shownFor = user.id();

    app.store.find('curio-collections', { filter: { user: user.id() }, sort: 'position', page: { limit: 50 } }).then((collections) => {
      this.collections = collections;
      m.redraw();
    });
    app.store
      .find('curio-items', { filter: { user: user.id(), status: 'wishlist' }, include: 'collection.type', sort: '-createdAt', page: { limit: 24 } })
      .then((items) => {
        this.wanted = items;
        m.redraw();
      });
  }

  content() {
    const own = app.session.user === this.user;
    const list = this.collections || [];
    const sum = (fn) => list.reduce((n, c) => n + (fn(c) || 0), 0);
    const since = list.length ? dayjs(Math.min(...list.map((c) => c.createdAt().getTime()))).format('YYYY') : null;

    return (
      <div className="CurioUserPage">
        {list.length ? (
          <div className="CurioUserStats">
            <div>
              <b>{sum((c) => c.ownedCount())}</b>
              <span>{t('profile.items')}</span>
            </div>
            <div>
              <b>{list.length}</b>
              <span>{t('profile.collections')}</span>
            </div>
            <div>
              <b>{sum((c) => c.wishCount())}</b>
              <span>{t('profile.wanted')}</span>
            </div>
            <div>
              <b>{sum((c) => c.tradeCount())}</b>
              <span>{t('profile.trade')}</span>
            </div>
            <div>
              <b>{since}</b>
              <span>{t('profile.since')}</span>
            </div>
          </div>
        ) : null}

        <div className="CurioSection-heading">
          <h2>{t('profile.collections_heading')}</h2>
          <div className="CurioSection-buttons">
            {own && list.length > 1 ? (
              <Button
                className={'Button' + (this.arranging ? ' Button--primary' : '')}
                icon={this.arranging ? 'fas fa-check' : 'fas fa-up-down-left-right'}
                onclick={() => (this.arranging = !this.arranging)}
              >
                {this.arranging ? t('page.arrange_done') : t('page.arrange')}
              </Button>
            ) : null}
            {own && app.forum.attribute('canCreateCurio') ? (
              <Button
                className="Button"
                icon="fas fa-folder-plus"
                onclick={() => app.modal.show(CollectionEditModal, { onsave: (c) => m.route.set(collectionUrl(c)) })}
              >
                {t('profile.new')}
              </Button>
            ) : null}
            {own && app.forum.attribute('canCreateCurio') && list.length ? (
              <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => addToMyCollection({})}>
                {t('browse.add')}
              </Button>
            ) : null}
          </div>
        </div>

        {this.collections === null ? (
          <LoadingIndicator display="block" />
        ) : list.length ? (
          <div className={'CurioCards' + (this.arranging ? ' is-arranging' : '')} data-sortable={this.arranging ? 'true' : undefined}>
            {list.map((c, i) =>
              this.arranging ? (
                <div key={c.id()} className={'CurioCards-slot' + (this.sorter.dragging === i ? ' is-dragging' : '')} {...this.sorter.attrs(i)}>
                  {collectionCard(c, { onclick: (e) => e.preventDefault() })}
                  <Icon name="fas fa-grip" className="CurioItemCard-grip" />
                </div>
              ) : (
                <div key={c.id()} className="CurioCards-slot">
                  {collectionCard(c)}
                </div>
              )
            )}
          </div>
        ) : (
          <Placeholder text={own ? t('profile.empty_own') : t('profile.empty')} />
        )}

        {this.wanted.length ? (
          <>
            <div className="CurioSection-heading">
              <h2>
                <Icon name="far fa-heart" /> {t('profile.wishlist_heading')}
              </h2>
            </div>
            <ul className="CurioStrip">
              {this.wanted.map((item) => (
                <li>
                  <button type="button" onclick={() => app.modal.show(ItemModal, { itemId: item.id() })}>
                    {cover(item.coverUrl(), item.collection()?.type()?.shape(), true)}
                    <span className="CurioItemCard-title">{item.displayTitle()}</span>
                    {item.year() ? <span className="CurioItemCard-sub">{item.year()}</span> : null}
                  </button>
                </li>
              ))}
            </ul>
          </>
        ) : null}
      </div>
    );
  }
}
