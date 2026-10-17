import Extend from 'flarum/common/extenders';
import { WishlistMatchNotification, NewItemsNotification } from './components/notifications';

export default [new Extend.Notification().add('curioWishlistMatch', WishlistMatchNotification).add('curioNewItems', NewItemsNotification)];
