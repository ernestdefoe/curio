import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Icon from 'flarum/common/components/Icon';
import Avatar from 'flarum/common/components/Avatar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import textContrastClass from 'flarum/common/helpers/textContrastClass';
import { t, text, label, types, loadTypes, collectionUrl } from '../util';
import addToMyCollection from '../copyItem';
import { collectionCard, cover } from './bits';
import ItemModal from './ItemModal';
import useStyles from '../styles';

/**
 * /collections: what this forum collects. Two looks, the admin's choice: the
 * types as Flarum's own tags page tiles (the default), or as cover tiles.
 */
export default class BrowsePage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.bodyClass = 'App--curio';
    this.overview = null;
    this.recent = null;
    this.collections = null;
    this.typeId = m.route.param('type') || null;
    this.q = '';
    this.found = null;

    app.history.push('curio.browse', text('browse.title'));
    app.setTitle(text('browse.title'));

    loadTypes();
    app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/curio/overview` }).then((o) => {
      this.overview = o;
      m.redraw();
    });
    app.store
      .find('curio-items', { filter: { status: 'owned' }, include: 'collection.user,collection.type', sort: '-createdAt', page: { limit: 12 } })
      .then((items) => {
        this.recent = items;
        m.redraw();
      });
    this.loadCollections();
  }

  loadCollections() {
    this.collections = null;
    this.standIn = false;
    const query = this.typeId
      ? { filter: { type: this.typeId }, sort: '-ownedCount', page: { limit: 24 } }
      : { filter: { featured: '1' }, sort: '-featuredAt', page: { limit: 12 } };

    app.store.find('curio-collections', query).then((found) => {
      // No collection featured yet: the biggest public ones stand in.
      if (!found.length && !this.typeId) {
        return app.store.find('curio-collections', { sort: '-ownedCount', page: { limit: 12 } }).then((biggest) => {
          // Headed as what they are, never as "Featured".
          this.standIn = true;
          this.collections = biggest;
          m.redraw();
        });
      }
      this.collections = found;
      m.redraw();
    });
  }

  search(q) {
    this.q = q;
    clearTimeout(this.debounce);
    if (!q.trim()) return (this.found = null);
    this.debounce = setTimeout(() => {
      app.store.find('curio-items', { filter: { q: q.trim() }, include: 'collection.user,collection.type', page: { limit: 24 } }).then((items) => {
        if (this.q === q) this.found = items;
        m.redraw();
      });
    }, 300);
  }

  view() {
    const chosen = this.typeId && app.store.getById('curio-types', this.typeId);

    return (
      <PageStructure className="CurioBrowse" sidebar={() => <IndexSidebar />}>
        <div className="CurioBrowse-head">
          <div>
            <h2>{t('browse.title')}</h2>
            {this.overview ? <p>{t('browse.lead', { items: this.overview.items, members: this.overview.members })}</p> : null}
          </div>
          <div className="CurioBrowse-tools">
            <div className="Form-group CurioSearchField">
              <Icon name="fas fa-magnifying-glass" />
              <input
                className="FormControl"
                type="search"
                value={this.q}
                placeholder={text('browse.search')}
                aria-label={text('browse.search')}
                oninput={(e) => this.search(e.target.value)}
              />
            </div>
            {app.session.user && app.forum.attribute('canCreateCurio') ? (
              <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => addToMyCollection({})}>
                {t('browse.add')}
              </Button>
            ) : null}
          </div>
        </div>

        {this.found ? this.results() : this.home(chosen)}
      </PageStructure>
    );
  }

  results() {
    return (
      <>
        <div className="CurioSection-heading">
          <h2>{t('browse.results', { q: this.q })}</h2>
        </div>
        {this.found.length ? this.strip(this.found) : <Placeholder text={t('browse.no_results')} />}
      </>
    );
  }

  home(chosen) {
    const counts = this.overview?.types || {};

    return (
      <>
        {!this.overview ? <LoadingIndicator display="block" /> : this.overview.layout === 'covers' ? this.coverTiles(counts) : this.tagTiles(counts)}

        {!chosen && this.recent && this.recent.length ? (
          <>
            <div className="CurioSection-heading">
              <h2>{t('browse.recent')}</h2>
            </div>
            {this.strip(this.recent)}
          </>
        ) : null}

        <div className="CurioSection-heading">
          <h2>
            {chosen ? (
              label(chosen.names())
            ) : (
              <>
                <Icon name={this.standIn ? 'fas fa-ranking-star' : 'fas fa-award'} /> {t(this.standIn ? 'browse.largest' : 'browse.featured')}
              </>
            )}
          </h2>
        </div>
        {this.collections === null ? (
          <LoadingIndicator display="block" />
        ) : this.collections.length ? (
          <div className="CurioCards">
            {this.collections.map((c) => (
              <div className="CurioCards-slot">{collectionCard(c)}</div>
            ))}
          </div>
        ) : (
          <Placeholder text={t('browse.none')} />
        )}
      </>
    );
  }

  /** The types as Flarum's tags page draws tags: coloured tiles, the latest collection along the foot. */
  tagTiles(counts) {
    return (
      <ul className="TagTiles CurioTagTiles">
        {this.shownTypes(counts).map((type) => {
          const s = counts[type.id()] || { items: 0, collections: 0 };
          const active = this.typeId === type.id();
          const latest = s.latest;
          return (
            // A type with no colour is drawn like an uncoloured tag.
            <li
              className={'TagTile' + (type.color() ? ' colored ' + textContrastClass(type.color()) : '') + (active ? ' active' : '')}
              style={{ '--tag-bg': type.color() || undefined }}
            >
              <Link
                className="TagTile-info"
                href={active ? app.route('curio.browse') : app.route('curio.browse', { type: type.id() })}
                aria-current={active ? 'true' : undefined}
              >
                <div className="TagTile-heading">
                  <Icon name={type.icon()} />
                  <h3 className="TagTile-name">{label(type.names())}</h3>
                </div>
                <p className="TagTile-description">
                  {t('count.items', { count: s.items })} · {t('count.collections', { count: s.collections || 0 })}
                </p>
              </Link>
              {latest ? (
                <Link className="TagTile-lastPostedDiscussion" href={collectionUrl({ id: () => latest.id, slug: () => latest.slug })}>
                  <span className="TagTile-lastPostedDiscussion-title">{latest.name}</span>
                  {latest.updatedAt ? humanTime(new Date(latest.updatedAt)) : null}
                </Link>
              ) : (
                <span className="TagTile-lastPostedDiscussion" />
              )}
            </li>
          );
        })}
      </ul>
    );
  }

  /** The types as cover tiles: a strip of their latest covers over the name. */
  coverTiles(counts) {
    return (
      <div className="CurioTypes" role="list">
        {this.shownTypes(counts).map((type) => {
          const s = counts[type.id()] || { items: 0, covers: [] };
          const active = this.typeId === type.id();
          return (
            <button
              type="button"
              role="listitem"
              className={'CurioTypeTile' + (active ? ' active' : '')}
              style={{ '--curio-accent': type.color() }}
              aria-pressed={active ? 'true' : 'false'}
              onclick={() => {
                this.typeId = active ? null : type.id();
                this.loadCollections();
              }}
            >
              <span className="CurioTypeTile-covers">
                {s.covers.length ? (
                  s.covers.map((url) => <img src={url} alt="" loading="lazy" />)
                ) : (
                  <Icon name={type.icon()} className="CurioTypeTile-empty" />
                )}
              </span>
              <span className="CurioTypeTile-body">
                <Icon name={type.icon()} className="CurioTypeTile-icon" />
                <strong>{label(type.names())}</strong>
                <span>{t('count.items', { count: s.items })}</span>
              </span>
            </button>
          );
        })}
      </div>
    );
  }

  /**
   * Every switched-on type, like the Tags page shows every tag: an empty tile
   * invites a first collection. With "Hide types with nothing in them" on, only
   * those holding something (and the chosen one), or all while nothing is.
   */
  shownTypes(counts) {
    const all = types().filter((type) => type.enabled() !== false);
    if (!this.overview.hideEmpty) return all;
    const used = all.filter((type) => (counts[type.id()]?.items || 0) > 0 || type.id() === this.typeId);
    return used.length ? used : all;
  }

  strip(items) {
    return (
      <ul className="CurioStrip">
        {items.map((item) => {
          const user = item.collection()?.user();
          return (
            <li>
              <button type="button" onclick={() => app.modal.show(ItemModal, { itemId: item.id() })}>
                {cover(item.coverUrl(), item.collection()?.type()?.shape(), false, item.displayTitle())}
                <span className="CurioItemCard-title">{item.displayTitle()}</span>
                {user ? (
                  <span className="CurioStrip-owner">
                    <Avatar user={user} /> {username(user)}
                  </span>
                ) : null}
              </button>
            </li>
          );
        })}
      </ul>
    );
  }
}
