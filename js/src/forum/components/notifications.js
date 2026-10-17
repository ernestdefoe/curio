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

/** A collection you follow has new things. */
export class NewItemsNotification extends Notification {
  icon() {
    return 'fas fa-boxes-stacked';
  }

  href() {
    const collection = this.attrs.notification.subject();
    return collection ? app.route('curio.collection', { id: `${collection.id()}-${collection.slug()}` }) : '#';
  }

  content() {
    const collection = this.attrs.notification.subject();
    return t('notifications.new_items', { user: this.attrs.notification.fromUser(), name: collection ? collection.name() : '' });
  }

  excerpt() {
    return null;
  }
}
