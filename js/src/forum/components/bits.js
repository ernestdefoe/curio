import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import { t, label, collectionUrl, CONDITIONS } from '../util';

export function typeBadge(type, extra = '') {
  if (!type) return null;
  return (
    <span className={'CurioTypeBadge ' + extra} style={{ '--curio-accent': type.color() }}>
      <Icon name={type.icon()} /> {label(type.names())}
    </span>
  );
}

export function statusPill(status) {
  if (!status || status === 'owned') return null;
  return (
    <span className={'CurioStatus CurioStatus--' + status}>
      <Icon name={status === 'wishlist' ? 'far fa-heart' : 'fas fa-right-left'} /> {t('status.' + status)}
    </span>
  );
}

const PROGRESS_ICONS = {
  pending: 'far fa-circle',
  in_progress: 'fas fa-circle-half-stroke',
  completed: 'fas fa-circle-check',
  abandoned: 'fas fa-circle-xmark',
};

/** Not started, in progress, completed or abandoned, where the item's type tracks it. */
export function progressPill(item, type) {
  const progress = type?.progress() && item.status() !== 'wishlist' ? item.progress() : null;
  if (!progress) return null;
  return (
    <span className={'CurioProgress CurioProgress--' + progress}>
      <Icon name={PROGRESS_ICONS[progress]} /> {t('progress.' + progress)}
    </span>
  );
}

/** "Graded": a professionally graded copy, where the item's type offers it. Its photos show by whom and the score. */
export function gradedBadge(item, type) {
  if (!type?.grading() || !item.graded()) return null;
  return (
    <span className="CurioGraded">
      <Icon name="fas fa-certificate" /> {t('item.graded')}
    </span>
  );
}

/** The image itself, blurred, fills whatever the whole picture leaves of its frame. */
export const backdrop = (url) => (url ? { '--curio-cover': `url("${url.replace(/["\\\n]/g, encodeURIComponent)}")` } : undefined);

export function cover(url, shape, wanted, alt = '') {
  return (
    <div className={'CurioCover CurioCover--' + (shape || 'portrait') + (wanted ? ' CurioCover--wanted' : '')} style={backdrop(url)}>
      {url ? <img src={url} alt={alt} loading="lazy" /> : <Icon name="fas fa-image" className="CurioCover-empty" />}
    </div>
  );
}

export function owner(user, small) {
  if (!user) return null;
  return (
    <Link className={'CurioOwner' + (small ? ' CurioOwner--small' : '')} href={app.route('user.curio', { username: user.slug() })}>
      <Avatar user={user} /> <strong>{username(user)}</strong>
    </Link>
  );
}

/** A collection as a card: its covers fanned out over a blur of the first. */
export function collectionCard(collection, attrs = {}) {
  const type = collection.type();
  const preview = collection.preview() || [];
  const shape = type ? type.shape() : 'portrait';

  return (
    <Link className={'CurioCard CurioCard--' + shape} href={collectionUrl(collection)} style={{ '--curio-accent': type?.color() }} {...attrs}>
      <div className="CurioCard-art">
        {preview[0] ? <div className="CurioCard-blur" style={{ backgroundImage: `url(${preview[0]})` }} /> : null}
        <div className={'CurioCard-fan CurioCard-fan--' + preview.length}>
          {preview.length ? (
            preview.map((url) => <img src={url} alt="" loading="lazy" />)
          ) : (
            <Icon name={type?.icon() || 'fas fa-box-open'} className="CurioCard-empty" />
          )}
        </div>
        {collection.featured() ? (
          <span className="CurioCard-featured">
            <Icon name="fas fa-award" />
          </span>
        ) : null}
      </div>
      <div className="CurioCard-body">
        {typeBadge(type)}
        <h3 className="CurioCard-name">{collection.name()}</h3>
        <div className="CurioCard-stats">
          <span>{t('count.items', { count: collection.ownedCount() })}</span>
          {collection.wishCount() ? <span>{t('count.wanted', { count: collection.wishCount() })}</span> : null}
          {collection.tradeCount() ? <span className="CurioCard-trade">{t('count.trade', { count: collection.tradeCount() })}</span> : null}
          {collection.visibility() !== 'public' ? (
            <span className="CurioCard-private" title={t('visibility.' + collection.visibility())}>
              <Icon name={collection.visibility() === 'private' ? 'fas fa-lock' : 'fas fa-user-group'} />
            </span>
          ) : null}
        </div>
      </div>
    </Link>
  );
}

/** Trading cards' own scale (TCGplayer's), each shown with its short code: "Near Mint (NM)". */
export const CARD_CONDITIONS = ['mint', 'near_mint', 'light_played', 'moderately_played', 'heavily_played', 'damaged'];

/** The conditions an item of this type can be in. */
export const conditionsFor = (type) => (type?.conditionScale() === 'cards' ? CARD_CONDITIONS : CONDITIONS);

/** A condition's name on its type's scale (one kept from another scale still reads right). */
export const conditionText = (type, value) => {
  const cards = type?.conditionScale() === 'cards' ? CARD_CONDITIONS.includes(value) : !CONDITIONS.includes(value);
  return t((cards ? 'card_condition.' : 'condition.') + value);
};

/** The condition as a small coloured word, or null. */
export const conditionPill = (type, value) => (value ? <span className={'CurioCond CurioCond--' + value}>{conditionText(type, value)}</span> : null);
