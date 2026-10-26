import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, text, label, locale } from '../util';
import { fieldValue, linkTo, reviewField, safeUrl } from '../../common/labels';
import { typeBadge, statusPill, progressPill, gradedBadge, conditionPill, owner, backdrop } from './bits';
import ItemEditModal from './ItemEditModal';
import Link from 'flarum/common/components/Link';
import Tooltip from 'flarum/common/components/Tooltip';
import { startShowOff } from '../showOff';
import addToMyCollection from '../copyItem';
import useStyles from '../styles';
import Stars from './Stars';

const LONG = 600;

/** IGDB's genre names in the reader's language, when Curio has a word for them. */
const genre = (name) => {
  const key =
    'ernestdefoe-curio.forum.genre.' +
    name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_|_$/g, '');
  return app.translator.translations[key] ? app.translator.trans(key) : name;
};

/** One item: its photos, details, the owner's notes and what the forum knows about it, in the reader's language. */
export default class ItemModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);
    useStyles();
    this.item = null;
    this.photo = 0;
    this.expanded = false;

    app.store
      .find('curio-items', this.attrs.itemId)
      .then((item) => (this.item = item))
      .catch(() => (this.missing = true))
      .finally(() => m.redraw());
  }

  className() {
    return 'CurioItemModal Modal--large';
  }

  title() {
    return this.item ? this.item.displayTitle() : '';
  }

  content() {
    if (this.missing) return <div className="Modal-body">{t('item.missing')}</div>;
    if (!this.item)
      return (
        <div className="Modal-body">
          <LoadingIndicator display="block" />
        </div>
      );

    const item = this.item;
    const collection = item.collection();
    const type = collection?.type();
    const work = item.work();
    const photos = item.photos() || [];
    const pictures = photos.length ? photos.map((p) => p.url) : work && work.coverUrl() ? [work.coverUrl()] : [];
    const mine = app.session.user && collection && collection.user() === app.session.user;

    return (
      <div className="Modal-body CurioItem">
        <div className={'CurioItem-gallery CurioItem-gallery--' + (type ? type.shape() : 'portrait')}>
          <div className="CurioItem-photo" style={backdrop(pictures[this.photo] || pictures[0])}>
            {pictures.length ? (
              <img src={pictures[this.photo] || pictures[0]} alt={item.displayTitle()} />
            ) : (
              <Icon name={type ? type.icon() : 'fas fa-box-open'} />
            )}
            {pictures.length > 1 ? (
              <span className="CurioItem-photoCount">
                <Icon name="fas fa-camera" /> {this.photo + 1} / {pictures.length}
              </span>
            ) : null}
          </div>
          {pictures.length > 1 ? (
            <div className="CurioItem-thumbs">
              {photos.map((p, i) => (
                <button
                  type="button"
                  className={'CurioItem-thumb' + (i === this.photo ? ' active' : '')}
                  onclick={() => (this.photo = i)}
                  aria-label={text('item.photo_n', { n: i + 1 })}
                >
                  <img src={p.small} alt="" />
                </button>
              ))}
            </div>
          ) : null}
          {!mine && item.canEdit() ? (
            // A moderator's Edit on someone else's item, kept apart from what members do with it.
            <div className="CurioItem-moderate">
              <Tooltip text={text('item.edit_this')}>
                <Button
                  className="Button Button--icon Button--rounded"
                  icon="fas fa-pen"
                  aria-label={text('item.edit_this')}
                  onclick={() => this.edit(item, collection)}
                />
              </Tooltip>
            </div>
          ) : null}
        </div>

        <div className="CurioItem-info">
          <div className="CurioItem-badges">
            {typeBadge(type)}
            {statusPill(item.status())}
            {progressPill(item, type)}
            {gradedBadge(item, type)}
            {item.favourite() ? (
              <span className="CurioFav">
                <Icon name="fas fa-star" /> {t('item.favourite')}
              </span>
            ) : null}
          </div>
          <h2 className="CurioItem-title">
            {item.displayTitle()} {item.year() ? <span className="CurioMuted">({item.year()})</span> : null}
          </h2>
          {item.subtitle() ? <div className="CurioItem-sub">{item.subtitle()}</div> : null}
          {this.ratingLine(item, type)}

          {this.facts(item, type, work)}

          {item.notes() ? (
            <blockquote className="CurioItem-notes">
              {owner(collection?.user(), true)}
              <p>{item.notes()}</p>
            </blockquote>
          ) : null}

          {work ? this.about(work) : null}

          {this.offers(item)}
          {this.actions(item, collection, mine)}
        </div>
      </div>
    );
  }

  /** The stars, and "Read my review" when the type has a review link and it is filled. */
  ratingLine(item, type) {
    const review = reviewField(type ? type.fields() : []);
    const url = review && safeUrl((item.fields() || {})[review.key]);
    if (!item.rating() && !url) return null;

    return (
      <div className="CurioItem-rating">
        {item.rating() ? <Stars value={item.rating()} /> : null}
        {url ? (
          <span className="CurioItem-review">
            {linkTo(url.href, [<Icon name="fas fa-arrow-up-right-from-square" />, ' ', t('item.read_review')])}
          </span>
        ) : null}
      </div>
    );
  }

  facts(item, type, work) {
    const fields = type ? type.fields() || [] : [];
    const values = item.fields() || {};
    const rows = [];
    const known = (work && work.facts()) || {};
    const list = (names) => (names || []).join(', ');

    // What the look-up knows about the title (IGDB for games).
    if (known.developer) rows.push([t('item.developer'), list(known.developer)]);
    if (known.publisher) rows.push([t('item.publisher'), list(known.publisher)]);
    if (known.released) rows.push([t('item.released'), dayjs(known.released).format('LL')]);
    if (known.genres) rows.push([t('item.genres'), list(known.genres.map(genre))]);
    if (known.platforms) rows.push([t('item.platforms'), list(known.platforms)]);

    const review = reviewField(fields);
    fields.forEach((f) => {
      const v = values[f.key];
      if (v === undefined || v === null || v === '') return;
      // Shown beside the rating instead.
      if (f === review && safeUrl(v)) return;
      rows.push([label(f.labels), f.kind === 'bool' ? t(v ? 'item.yes' : 'item.no') : fieldValue(f, v, locale())]);
    });
    if (item.condition()) rows.push([t('item.condition'), conditionPill(type, item.condition())]);
    if (item.acquiredOn()) rows.push([t('item.acquired'), dayjs(item.acquiredOn()).format('LL')]);
    if (item.pricePaid() !== null && item.pricePaid() !== undefined) {
      rows.push([
        <span>
          {t('item.price')} <Icon name="fas fa-lock" className="CurioLock" />
        </span>,
        new Intl.NumberFormat(locale(), { style: 'currency', currency: item.priceCurrency() || 'USD' }).format(item.pricePaid()),
      ]);
    }

    if (!rows.length) return null;

    return (
      <dl className="CurioItem-facts">
        {rows.map(([k, v]) => (
          <div>
            <dt>{k}</dt>
            <dd>{v}</dd>
          </div>
        ))}
      </dl>
    );
  }

  /**
   * What the forum knows about the title, in the reader's language only. When
   * that is missing (or just Wikidata's one-liner), the English text waits
   * behind a toggle.
   */
  about(work) {
    const own = work.description();
    const other = work.fallback();
    const language = other && new Intl.DisplayNames([locale()], { type: 'language' }).of(other.locale);

    return (
      <section className="CurioItem-about">
        <h4>{t('item.about')}</h4>
        {own ? this.paragraph(own) : <p className="CurioMuted">{t('item.no_description')}</p>}
        {own ? (
          <div className="CurioItem-source">
            {this.credit(work.descriptionSource(), work.sourceUrl())}
            {work.languages() > 1 ? (
              <span className="CurioItem-langs">
                <Icon name="fas fa-language" /> {t('item.languages', { count: work.languages() })}
              </span>
            ) : null}
          </div>
        ) : null}
        {other ? (
          <Button
            className="Button Button--link CurioToggle"
            aria-expanded={this.showOther ? 'true' : 'false'}
            onclick={() => (this.showOther = !this.showOther)}
          >
            {t(this.showOther ? 'item.hide_other' : 'item.show_other', { language })}
          </Button>
        ) : null}
        {other && this.showOther ? (
          <div className="CurioItem-other" lang={other.locale}>
            {this.paragraph(other.description)}
            <div className="CurioItem-source">{this.credit(other.source, other.url)}</div>
          </div>
        ) : null}
      </section>
    );
  }

  paragraph(text) {
    const long = text.length > LONG;

    return [
      <p>{long && !this.expanded ? text.slice(0, text.lastIndexOf(' ', LONG)) + ' …' : text}</p>,
      long ? (
        <Button className="Button Button--link CurioToggle" onclick={() => (this.expanded = !this.expanded)}>
          {this.expanded ? t('item.less') : t('item.more')}
        </Button>
      ) : null,
    ];
  }

  credit(source, url) {
    if (source === 'wikipedia')
      return (
        <a href={url} target="_blank" rel="noopener noreferrer">
          <Icon name="fab fa-wikipedia-w" /> {t('item.from_wikipedia')}
        </a>
      );
    if (source === 'deepl') return <span>{t('item.translated_deepl')}</span>;
    if (source === 'wikidata') return <span>{t('item.from_wikidata')}</span>;
    return null;
  }

  /** On a wanted item: who has it open to trade. */
  offers(item) {
    const offers = item.tradeOffers && item.tradeOffers();
    if (!offers || !offers.count) return null;

    return (
      <Link
        className="CurioOffers"
        href={app.route('curio.collection', { id: offers.collectionId }) + `?item=${offers.itemId}`}
        onclick={() => app.modal.close()}
      >
        <Icon name="fas fa-right-left" /> {t('item.offers', { count: offers.count })}
      </Link>
    );
  }

  actions(item, collection, mine) {
    const discussed = item.discussionId() ? (
      <Link className="Button CurioItem-discussed" href={app.route('discussion', { id: item.discussionId() })} onclick={() => app.modal.close()}>
        <Icon name="far fa-comments" className="Button-icon" />
        <span className="Button-label">{t('item.discussed')}</span>
      </Link>
    ) : null;

    if (mine) {
      return (
        <div className="CurioItem-actions">
          <Button className="Button Button--primary" icon="fas fa-pen" onclick={() => this.edit(item, collection)}>
            {t('item.edit')}
          </Button>
          {discussed ||
            (item.status() !== 'wishlist' ? (
              <Button className="Button" icon="fas fa-bullhorn" onclick={() => startShowOff(item)}>
                {t('item.show_off')}
              </Button>
            ) : null)}
        </div>
      );
    }

    const canAdd = app.forum.attribute('canCreateCurio') && app.session.user;

    // Someone else's item: "I have this too" and "Add to my wishlist" stay for
    // everyone; a moderator's Edit sits under the cover.
    return (
      <div className="CurioItem-actions">
        {canAdd ? (
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.copy(item, 'owned')}>
            {t('item.have_too')}
          </Button>
        ) : null}
        {canAdd ? (
          <Button className="Button" icon="far fa-heart" onclick={() => this.copy(item, 'wishlist')}>
            {t('item.want_it')}
          </Button>
        ) : null}
        {discussed}
      </div>
    );
  }

  edit(item, collection) {
    app.modal.show(ItemEditModal, {
      item,
      collection,
      onsave: () => this.attrs.onchange && this.attrs.onchange(),
      ondelete: () => this.attrs.onchange && this.attrs.onchange(),
    });
  }

  /** "I have this too" / "Add to my wishlist": into any of my collections, those of the same type first. */
  copy(item, status) {
    addToMyCollection({ prefill: item, status, typeIds: [item.collection().type().id()] });
  }
}
