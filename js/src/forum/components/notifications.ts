import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from '../util';

/** Something on your wishlist is open to trade. */
export class WishlistMatchNotification extends Notification {
  icon() {
    return 'fas fa-right-left';
  }

  href() {
    const item = this.attrs.notification.subject();
    return item ? app.route('curio.collection', { id: item.collectionId() }) + `?item=${item.id()}` : '#';
  }

  content() {
    const item = this.attrs.notification.subject();
    return t('notifications.match', { user: this.attrs.notification.fromUser(), title: item ? item.displayTitle() : '' });
  }

  excerpt() {
    return null;
  }
}

/**
 * Something happened in a collection you follow (at most one of each kind a
 * day): new things, wishlist additions, progress, ratings.
 */
export class NewItemsNotification extends Notification {
  static key = 'new_items';
  static iconName = 'fas fa-boxes-stacked';

  icon() {
    return this.constructor.iconName;
  }

  href() {
    const collection = this.attrs.notification.subject();
    return collection ? app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) : '#';
  }

  content() {
    const collection = this.attrs.notification.subject();
    return t('notifications.' + this.constructor.key, { user: this.attrs.notification.fromUser(), name: collection ? collection.name() : '' });
  }

  excerpt() {
    return null;
  }
}

export class WishlistAddedNotification extends NewItemsNotification {
  static key = 'wishlist_added';
  static iconName = 'far fa-heart';
}

export class ProgressChangedNotification extends NewItemsNotification {
  static key = 'progress_changed';
  static iconName = 'fas fa-flag-checkered';
}

export class RatingChangedNotification extends NewItemsNotification {
  static key = 'rating_changed';
  static iconName = 'fas fa-star-half-stroke';
}

/** For the notification settings grid and the extender: [type, component]. */
export const FOLLOWED = [
  ['curioNewItems', NewItemsNotification],
  ['curioWishlistAdded', WishlistAddedNotification],
  ['curioProgressChanged', ProgressChangedNotification],
  ['curioRatingChanged', RatingChangedNotification],
];
