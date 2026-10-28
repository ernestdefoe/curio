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
import Tooltip from 'flarum/common/components/Tooltip';
import Select from 'flarum/common/components/Select';
import username from 'flarum/common/helpers/username';
import humanTime from 'flarum/common/helpers/humanTime';
import textContrastClass from 'flarum/common/helpers/textContrastClass';
import { t, text, label, locale, types, loadTypes, collectionUrl } from '../util';
import { optionLabel } from '../../common/labels';
import { browseSections } from '../../common/ranking';
import addToMyCollection from '../copyItem';
import { collectionCard, cover, progressPill } from './bits';
import ItemModal from './ItemModal';
import OwnersModal from './OwnersModal';
import Stars from './Stars';
import useStyles from '../styles';

/** What each list on the page asks for; each loads the first time its tab is opened, 12 at a time. */
const LISTS = {
  owned: { filter: { status: 'owned' }, sort: '-createdAt' },
  wishlist: { filter: { status: 'wishlist' }, sort: '-createdAt' },
  trade: { filter: { status: 'trade' }, sort: '-createdAt' },
  progress: { filter: { changed: 'progress' }, sort: '-progressChangedAt' },
  ratings: { filter: { changed: 'rating' }, sort: '-ratingChangedAt' },
};
const PAGE = 12;

/** 1,600 → "1.6K" / "1,6 k", in the reader's language. */
const compact = (n) => new Intl.NumberFormat(locale(), { notation: 'compact', maximumFractionDigits: 1 }).format(n);

/**
 * /collections: what this forum collects. Three looks for the types, the
 * admin's choice: Flarum's own tags page tiles (the default), cover tiles, or
 * a row of small tabs. Then what was added lately (owned, wishlists, open to
 * trade), what changed lately (progress, ratings), the featured collections,
 * and the rankings the admin chose: top collections, top collectors, or both.
 */
export default class BrowsePage extends Page {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.bodyClass = 'App--curio';
    this.overview = null;
    this.lists = {};
    this.recentTab = 'owned';
    this.latestTab = 'progress';
    this.collections = null;
    this.featured = null;
    this.top = null;
    this.collectors = null;
    this.typeId = m.route.param('type') || null;
    this.q = '';
    this.found = null;

    app.history.push('curio.browse', text('browse.title'));
    app.setTitle(text('browse.title'));

    loadTypes();
    app.request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/curio/overview` }).then((o) => {
      this.overview = o;
      if (o.latest && !this.typeId) this.list(this.latestTab);
      m.redraw();
    });
    if (!this.typeId) {
      this.list(this.recentTab);
      this.loadFeatured();
    }
    this.loadCollections();
  }

  /** A list's state, loading its first page the first time it is asked for. */
  list(key) {
    if (!this.lists[key]) {
      this.lists[key] = { items: [], more: false, loading: false };
      this.loadMore(key);
    }
    return this.lists[key];
  }

  loadMore(key) {
    const list = this.lists[key];
    list.loading = true;
    app.store
      .find('curio-items', { ...LISTS[key], include: 'collection.user,collection.type', page: { limit: PAGE, offset: list.items.length } })
      .then((items) => {
        list.items = list.items.concat(items);
        list.more = !!items.payload?.links?.next;
      })
      .finally(() => {
        list.loading = false;
        m.redraw();
      });
  }

  /** A row of standard buttons switching between lists. */
  tabs(keys, current, choose, heading) {
    // Phones get one drop-down instead, so the choice stays on the heading's line (CSS picks which shows).
    return [
      <div className="CurioListTabs" role="tablist">
        {keys.map((key) => (
          <Button
            className={'Button Button--small' + (key === current ? ' Button--primary active' : '')}
            role="tab"
            aria-selected={key === current ? 'true' : 'false'}
            onclick={() => choose(key)}
          >
            {t('browse.tab_' + key)}
          </Button>
        ))}
      </div>,
      <Select
        aria-label={heading}
        wrapperAttrs={{ className: 'CurioListTabs-select' }}
        options={Object.fromEntries(keys.map((key) => [key, text('browse.tab_' + key)]))}
        value={current}
        onchange={choose}
      />,
    ];
  }

  /** The chosen type's collections, biggest first. Without a type, the home lists load as they are drawn. */
  loadCollections() {
    this.collections = null;
    if (!this.typeId) return;
    app.store.find('curio-collections', { filter: { type: this.typeId }, sort: '-ownedCount', page: { limit: 24 } }).then((found) => {
      this.collections = found;
      m.redraw();
    });
  }

  /**
   * Featured collections, then the rankings the admin chose, each fetched the
   * first time it is drawn. Featuring adds a list; it never replaces "Top
   * collections" (it used to stand in only while nothing was featured).
   */
  sections() {
    if (!this.featured) this.loadFeatured();
    if (this.featured.loading) return [];

    const sections = browseSections(this.overview.ranking, this.featured.length);
    if (sections.includes('top') && !this.top) {
      this.top = [];
      this.top.loading = true;
      app.store.find('curio-collections', { sort: '-ownedCount', page: { limit: 12 } }).then((found) => {
        this.top = found;
        m.redraw();
      });
    }
    if (sections.includes('collectors') && !this.collectors) {
      this.collectors = { rows: [], more: false, loading: false };
      this.loadCollectors();
    }
    return sections;
  }

  loadFeatured() {
    this.featured = [];
    this.featured.loading = true;
    app.store.find('curio-collections', { filter: { featured: '1' }, sort: '-featuredAt', page: { limit: 12 } }).then((found) => {
      this.featured = found;
      m.redraw();
    });
  }

  /** The next ten collectors: their counts, and just enough of each member for an avatar and a link. */
  loadCollectors() {
    const state = this.collectors;
    state.loading = true;
    app
      .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/curio/collectors`, params: { offset: state.rows.length } })
      .then((found) => {
        app.store.pushPayload({ data: found.users });
        state.rows = state.rows.concat(found.data.map((row) => ({ ...row, user: app.store.getById('users', row.userId) })));
        state.more = found.more;
      })
      .finally(() => {
        state.loading = false;
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
            {this.searching || this.q ? (
              <div className="Form-group CurioSearchField">
                <Icon name="fas fa-magnifying-glass" />
                <input
                  className="FormControl"
                  type="search"
                  value={this.q}
                  aria-label={text('browse.search')}
                  title={text('browse.search')}
                  oncreate={(vnode) => vnode.dom.focus()}
                  onblur={() => (this.searching = false)}
                  onkeydown={(e) => {
                    if (e.key !== 'Escape') return;
                    e.preventDefault();
                    this.searching = false;
                    this.search('');
                  }}
                  oninput={(e) => this.search(e.target.value)}
                />
              </div>
            ) : (
              <Tooltip text={text('browse.search')}>
                <Button
                  className="Button Button--icon"
                  icon="fas fa-magnifying-glass"
                  aria-label={text('browse.search')}
                  onclick={() => {
                    // Drawn now, inside the tap, so iOS lets the field take focus and opens the keyboard.
                    this.searching = true;
                    m.redraw.sync();
                  }}
                />
              </Tooltip>
            )}
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
        {this.found.length ? this.strip(this.found, true) : <Placeholder text={t('browse.no_results')} />}
      </>
    );
  }

  home(chosen) {
    const counts = this.overview?.types || {};

    return (
      <>
        {!this.overview ? <LoadingIndicator display="block" /> : this.typeLayout(counts)}

        {chosen ? null : (
          <>
            <div className="CurioSection-heading">
              <h2>{t('browse.recent')}</h2>
              {this.tabs(['owned', 'wishlist', 'trade'], this.recentTab, (key) => this.list((this.recentTab = key)), text('browse.recent'))}
            </div>
            {this.shown(this.recentTab, (items) => this.strip(items))}
          </>
        )}

        {!chosen && this.overview?.latest ? (
          <>
            <div className="CurioSection-heading">
              <h2>{t('browse.latest')}</h2>
              {this.tabs(['progress', 'ratings'], this.latestTab, (key) => this.list((this.latestTab = key)), text('browse.latest'))}
            </div>
            {this.shown(this.latestTab, (items) => this.changes(items))}
          </>
        ) : null}

        {chosen ? (
          <>
            <div className="CurioSection-heading">
              <h2>{label(chosen.names())}</h2>
            </div>
            {this.cards(this.collections)}
          </>
        ) : this.overview ? (
          this.sections().map((section) => this.section(section))
        ) : null}
      </>
    );
  }

  section(key) {
    const icon = { featured: 'fas fa-award', top: 'fas fa-ranking-star', collectors: 'fas fa-trophy' }[key];
    const ranking = key === 'collectors';
    const mostOwned = ranking && this.rankTab === 'most_owned';
    return (
      <>
        <div className="CurioSection-heading">
          <h2>
            <Icon name={icon} />{' '}
            {t({ featured: 'browse.featured', top: 'browse.largest', collectors: mostOwned ? 'browse.tab_most_owned' : 'browse.collectors' }[key])}
          </h2>
          {ranking
            ? this.tabs(['collectors', 'most_owned'], this.rankTab || 'collectors', (tab) => this.chooseRanking(tab), text('browse.collectors'))
            : null}
        </div>
        {ranking ? (mostOwned ? this.mostOwnedTable() : this.collectorTable()) : this.cards(key === 'featured' ? this.featured : this.top)}
      </>
    );
  }

  /** Top collectors, or the titles the most members have (Wlork, d/40107 #91). */
  chooseRanking(tab) {
    this.rankTab = tab;
    if (tab === 'most_owned' && !this.mostOwned) {
      this.mostOwned = { rows: [], more: false, loading: false };
      this.loadMostOwned();
    }
  }

  /** The next ten titles and how many members have each, then those copies' titles and covers in one request. */
  loadMostOwned() {
    const state = this.mostOwned;
    state.loading = true;
    app
      .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/curio/most-owned`, params: { offset: state.rows.length } })
      .then((found) => {
        state.more = found.more;
        if (!found.data.length) return;
        return app.store
          .find('curio-items', { filter: { ids: found.data.map((row) => row.itemId).join(',') }, include: 'collection.type', page: { limit: 50 } })
          .then(() => {
            state.rows = state.rows.concat(
              found.data.map((row) => ({ ...row, item: app.store.getById('curio-items', row.itemId) })).filter((row) => row.item)
            );
          });
      })
      .finally(() => {
        state.loading = false;
        m.redraw();
      });
  }

  /** Most owned: rank, cover and title (opens "Who has this"), members. */
  mostOwnedTable() {
    const { rows, more, loading } = this.mostOwned;
    if (!rows.length) return loading ? <LoadingIndicator display="block" /> : <Placeholder text={t('browse.none_most_owned')} />;
    const number = new Intl.NumberFormat(locale());
    return (
      <>
        <table className="CurioCollectors CurioMostOwned">
          <thead>
            <tr>
              <th className="CurioCollectors-rank">#</th>
              <th>{t('browse.most_owned_title')}</th>
              <th className="CurioCollectors-n">{t('browse.most_owned_owners')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={row.workId}>
                <td className="CurioCollectors-rank">{i + 1}</td>
                <td>
                  <button
                    type="button"
                    className="CurioCollectors-member CurioMostOwned-title"
                    onclick={() => app.modal.show(OwnersModal, { workId: row.workId, title: row.item.displayTitle() })}
                  >
                    {cover(row.item.coverUrl(), row.item.collection()?.type()?.shape(), false, row.item.displayTitle())}
                    <span>{row.item.displayTitle()}</span>
                  </button>
                </td>
                <td className="CurioCollectors-n">{number.format(row.owners)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {more ? (
          <div className="CurioBrowse-more">
            <Button className="Button" loading={loading} onclick={() => this.loadMostOwned()}>
              {t('browse.more')}
            </Button>
          </div>
        ) : null}
      </>
    );
  }

  /** Collection cards, or a line when there are none. */
  cards(collections) {
    if (!collections || collections.loading) return <LoadingIndicator display="block" />;
    if (!collections.length) return <Placeholder text={t('browse.none')} />;
    return (
      <div className="CurioCards">
        {collections.map((c) => (
          <div className="CurioCards-slot">{collectionCard(c)}</div>
        ))}
      </div>
    );
  }

  /** Top collectors: rank, member (to their collections), collections, items owned, wishlist; ten more at a time. */
  collectorTable() {
    const { rows, more, loading } = this.collectors;
    if (!rows.length) return loading ? <LoadingIndicator display="block" /> : <Placeholder text={t('browse.none_collectors')} />;
    const number = new Intl.NumberFormat(locale());
    return (
      <>
        <table className="CurioCollectors">
          <thead>
            <tr>
              <th className="CurioCollectors-rank">#</th>
              <th>{t('browse.collector')}</th>
              <th className="CurioCollectors-n">{t('browse.collector_collections')}</th>
              <th className="CurioCollectors-n">{t('browse.collector_owned')}</th>
              <th className="CurioCollectors-n">{t('browse.collector_wishlist')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={row.userId}>
                <td className="CurioCollectors-rank">{i + 1}</td>
                <td>
                  <Link className="CurioCollectors-member" href={app.route('user.curio', { username: row.user.slug() })}>
                    <Avatar user={row.user} /> <span className="username">{username(row.user)}</span>
                  </Link>
                </td>
                <td className="CurioCollectors-n">{number.format(row.collections)}</td>
                <td className="CurioCollectors-n">{number.format(row.owned)}</td>
                <td className="CurioCollectors-n">{number.format(row.wishlist)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {more ? (
          <div className="CurioBrowse-more">
            <Button className="Button" loading={loading} onclick={() => this.loadCollectors()}>
              {t('browse.more')}
            </Button>
          </div>
        ) : null}
      </>
    );
  }

  typeLayout(counts) {
    const layout = this.overview.layout;
    return layout === 'covers' ? this.coverTiles(counts) : layout === 'compact' ? this.typeTabs(counts) : this.tagTiles(counts);
  }

  /** A list as it loads: the items, a "More" button while there are more, or a line when there are none. */
  shown(key, render) {
    const list = this.list(key);
    if (!list.items.length) return list.loading ? <LoadingIndicator display="block" /> : <Placeholder text={t('browse.none_' + key)} />;
    return (
      <>
        {render(list.items)}
        {list.more ? (
          <div className="CurioBrowse-more">
            <Button className="Button" loading={list.loading} onclick={() => this.loadMore(key)}>
              {t('browse.more')}
            </Button>
          </div>
        ) : null}
      </>
    );
  }

  /**
   * The types as small tabs, about a button's size: icon, name, a short count
   * ("1.6K items"). Coloured like the type, either filled or on the icon and
   * name only. They wrap on wide screens and scroll sideways on a phone.
   */
  typeTabs(counts) {
    const filled = this.overview.typeColor !== 'text';
    return (
      <nav className={'CurioTypeTabs CurioTypeTabs--' + (filled ? 'filled' : 'text')} aria-label={text('browse.types')}>
        {this.shownTypes(counts).map((type) => {
          const items = counts[type.id()]?.items || 0;
          const active = this.typeId === type.id();
          const color = type.color();
          return (
            <Link
              className={'CurioTypeTab' + (color ? ' colored' + (filled ? ' ' + textContrastClass(color) : '') : '') + (active ? ' active' : '')}
              style={{ '--curio-accent': color || undefined }}
              href={active ? app.route('curio.browse') : app.route('curio.browse', { type: type.id() })}
              aria-current={active ? 'true' : undefined}
            >
              <Icon name={type.icon()} />
              <span className="CurioTypeTab-name">{label(type.names())}</span>
              <span className="CurioTypeTab-count">{t('count.items_short', { count: items, short: compact(items) })}</span>
            </Link>
          );
        })}
      </nav>
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

  /** The type's first choice (a game's platform, a record's format) as the reader reads it. */
  facet(item) {
    const field = (item.collection()?.type()?.fields() || []).find((f) => f.kind === 'select');
    return field && item.facet() ? optionLabel(field, item.facet(), locale()) : null;
  }

  /**
   * Item cards in a row. Search results are grouped (Wlork, d/40107 #91): a
   * title several members have shows once, with how many, and opens "Who has
   * this" instead of one member's copy.
   */
  strip(items, grouped = false) {
    const groups = [];
    const byWork = new Map();
    items.forEach((item) => {
      const work = grouped && item.workId();
      if (work && byWork.has(work)) return byWork.get(work).push(item);
      const group = [item];
      if (work) byWork.set(work, group);
      groups.push(group);
    });

    return (
      <ul className="CurioStrip">
        {groups.map((group) => {
          const item = group[0];
          const members = new Set(group.map((i) => i.collection()?.user()?.id())).size;
          const user = item.collection()?.user();
          const facet = group.length === 1 ? this.facet(item) : null;
          const open =
            members > 1
              ? () => app.modal.show(OwnersModal, { workId: item.workId(), title: item.displayTitle() })
              : () => app.modal.show(ItemModal, { itemId: item.id() });
          return (
            <li>
              <button type="button" onclick={open}>
                {cover(item.coverUrl(), item.collection()?.type()?.shape(), false, item.displayTitle())}
                <span className="CurioItemCard-title">{item.displayTitle()}</span>
                {facet ? <span className="CurioStrip-facet">{facet}</span> : null}
                {members > 1 ? (
                  <span className="CurioStrip-count">
                    <Icon name="fas fa-users" /> {t('browse.owned_by', { count: members })}
                  </span>
                ) : user ? (
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

  /** Latest changes: who marked what as completed (or started it), or rated it. */
  changes(items) {
    const ratings = this.latestTab === 'ratings';
    return (
      <ul className="CurioChanges">
        {items.map((item) => {
          const user = item.collection()?.user();
          const title = <strong>{item.displayTitle()}</strong>;
          const when = ratings ? item.ratingChangedAt() : item.progressChangedAt();
          return (
            <li>
              <button type="button" className="CurioChange" onclick={() => app.modal.show(ItemModal, { itemId: item.id() })}>
                {cover(item.coverUrl(), item.collection()?.type()?.shape(), false)}
                <span className="CurioChange-body">
                  <span className="CurioChange-line">
                    <span>{t(ratings ? 'browse.change_rating' : 'browse.change_progress', { name: <strong>{username(user)}</strong>, title })}</span>
                    {ratings ? <Stars value={item.rating()} small /> : progressPill(item, item.collection()?.type())}
                  </span>
                </span>
                {when ? <span className="CurioChange-time">{humanTime(when)}</span> : null}
              </button>
            </li>
          );
        })}
      </ul>
    );
  }
}
