import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import listItems from 'flarum/common/helpers/listItems';
import { t, label, locale, collectionUrl } from '../util';
import { fieldValue } from '../../common/labels';
import { typeBadge, backdrop } from './bits';
import ItemModal from './ItemModal';
import addToMyCollection from '../copyItem';
import useStyles from '../styles';

/** The hero of a "Show it off" discussion: the member's item, cover, details and what the forum knows about it. */
export default class ItemHero extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
  }

  view() {
    const { discussion, item } = this.attrs;
    const collection = item.collection();
    const type = collection?.type();
    const work = item.work();
    const owner = collection?.user();
    const cover = (item.photos() || [])[0]?.url || work?.coverUrl() || item.coverUrl();
    const badges = discussion.badges().toArray();
    const fields = type ? type.fields() || [] : [];
    const values = item.fields() || {};
    const about = work && work.description();
    const mine = app.session.user && owner === app.session.user;
    const canAdd = !mine && app.session.user && app.forum.attribute('canCreateCurio');
    const facts = [];

    if (item.condition()) facts.push([t('item.condition'), t('condition.' + item.condition())]);
    if (item.acquiredOn()) facts.push([t('item.acquired'), dayjs(item.acquiredOn()).format('LL')]);
    if (item.ownersCount()) facts.push([t('hero.here'), t('hero.members', { count: item.ownersCount() })]);

    return (
      <header className="Hero DiscussionHero CurioItemHero" style={{ '--curio-accent': type?.color() }}>
        {cover ? <div className="CurioItemHero-backdrop" style={{ backgroundImage: `url(${cover})` }} /> : null}
        <div className="container CurioItemHero-inner">
          <button
            type="button"
            className={'CurioItemHero-cover CurioItemHero-cover--' + (type?.shape() || 'portrait')}
            onclick={() => app.modal.show(ItemModal, { itemId: item.id() })}
            aria-label={t('hero.open_item')}
            style={backdrop(cover)}
          >
            {cover ? <img src={cover} alt={item.displayTitle()} /> : <Icon name={type?.icon() || 'fas fa-box-open'} />}
            {(item.photos() || []).length > 1 ? (
              <span className="CurioItem-photoCount">
                <Icon name="fas fa-camera" /> {item.photos().length}
              </span>
            ) : null}
          </button>
          <div className="CurioItemHero-body">
            {badges.length ? <ul className="DiscussionHero-badges badges">{listItems(badges)}</ul> : null}
            <div className="CurioItemHero-kicker">
              {typeBadge(type, 'CurioTypeBadge--solid')}
              {collection ? <Link href={collectionUrl(collection)}>{t('hero.from', { name: collection.name() })}</Link> : null}
            </div>
            <h1 className="DiscussionHero-title">{discussion.title()}</h1>
            <div className="CurioItemHero-item">{[item.displayTitle(), item.year(), item.subtitle()].filter(Boolean).join(' · ')}</div>
            <div className="CurioItemHero-chips">
              {fields.map((f) => {
                const v = values[f.key];
                if (v === undefined || v === null || v === '' || v === false) return null;
                return (
                  <span className="CurioChip">
                    {f.kind === 'bool' ? (
                      <>
                        <Icon name="fas fa-check" /> {label(f.labels)}
                      </>
                    ) : (
                      fieldValue(f, v, locale())
                    )}
                  </span>
                );
              })}
            </div>
            {facts.length ? (
              <div className="CurioItemHero-stats">
                {facts.map(([k, v]) => (
                  <div>
                    <span>{k}</span>
                    <b>{v}</b>
                  </div>
                ))}
              </div>
            ) : null}
            {about ? <p className="CurioItemHero-about">{about.length > 340 ? about.slice(0, about.lastIndexOf(' ', 330)) + ' …' : about}</p> : null}
            <div className="CurioItemHero-actions">
              {collection ? (
                <Link className="Button Button--primary" href={collectionUrl(collection) + '?item=' + item.id()}>
                  <Icon name="fas fa-boxes-stacked" className="Button-icon" />
                  <span className="Button-label">
                    {mine ? t('hero.in_my_collection') : t('hero.view_in', { name: owner ? owner.displayName() : '' })}
                  </span>
                </Link>
              ) : null}
              {canAdd ? (
                <Button
                  className="Button CurioHero-ghost"
                  icon="fas fa-plus"
                  onclick={() => addToMyCollection({ prefill: item, typeIds: [type.id()] })}
                >
                  {t('item.have_too')}
                </Button>
              ) : null}
              {canAdd ? (
                <Button
                  className="Button CurioHero-ghost"
                  icon="far fa-heart"
                  onclick={() => addToMyCollection({ prefill: item, status: 'wishlist', typeIds: [type.id()] })}
                >
                  {t('item.want_it')}
                </Button>
              ) : null}
              {about && work.descriptionSource() === 'wikipedia' ? (
                <a className="CurioItemHero-credit" href={work.sourceUrl()} target="_blank" rel="noopener noreferrer">
                  <Icon name="fab fa-wikipedia-w" /> {t('item.from_wikipedia')}
                </a>
              ) : null}
            </div>
          </div>
        </div>
      </header>
    );
  }
}
