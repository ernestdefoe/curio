import Model from 'flarum/common/Model';

export class CurioType extends Model {}
Object.assign(CurioType.prototype, {
  slug: Model.attribute('slug'),
  names: Model.attribute('names'),
  icon: Model.attribute('icon'),
  color: Model.attribute('color'),
  shape: Model.attribute('shape'),
  source: Model.attribute('source'),
  fields: Model.attribute('fields'),
  position: Model.attribute('position'),
  searchable: Model.attribute('searchable'),
  enabled: Model.attribute('enabled'),
  progress: Model.attribute('progress'),
});

export class CurioCollection extends Model {}
Object.assign(CurioCollection.prototype, {
  name: Model.attribute('name'),
  slug: Model.attribute('slug'),
  description: Model.attribute('description'),
  visibility: Model.attribute('visibility'),
  wishlistPublic: Model.attribute('wishlistPublic'),
  featured: Model.attribute('featured'),
  position: Model.attribute('position'),
  ownedCount: Model.attribute('ownedCount'),
  wishCount: Model.attribute('wishCount'),
  tradeCount: Model.attribute('tradeCount'),
  preview: Model.attribute('preview'),
  facets: Model.attribute('facets'),
  createdAt: Model.attribute('createdAt', Model.transformDate),
  updatedAt: Model.attribute('updatedAt', Model.transformDate),
  canEdit: Model.attribute('canEdit'),
  canDelete: Model.attribute('canDelete'),
  canFeature: Model.attribute('canFeature'),
  canImport: Model.attribute('canImport'),
  canExport: Model.attribute('canExport'),
  followed: Model.attribute('followed'),
  followerCount: Model.attribute('followerCount'),
  user: Model.hasOne('user'),
  type: Model.hasOne('type'),
});

const hasWork = Model.hasOne('work');

export class CurioItem extends Model {}
Object.assign(CurioItem.prototype, {
  title: Model.attribute('title'),
  displayTitle: Model.attribute('displayTitle'),
  subtitle: Model.attribute('subtitle'),
  year: Model.attribute('year'),
  status: Model.attribute('status'),
  condition: Model.attribute('condition'),
  acquiredOn: Model.attribute('acquiredOn'),
  fields: Model.attribute('fields'),
  facet: Model.attribute('facet'),
  notes: Model.attribute('notes'),
  favourite: Model.attribute('favourite'),
  rating: Model.attribute('rating'),
  progress: Model.attribute('progress'),
  barcode: Model.attribute('barcode'),
  pricePaid: Model.attribute('pricePaid'),
  priceCurrency: Model.attribute('priceCurrency'),
  position: Model.attribute('position'),
  photos: Model.attribute('photos'),
  coverUrl: Model.attribute('coverUrl'),
  createdAt: Model.attribute('createdAt', Model.transformDate),
  progressChangedAt: Model.attribute('progressChangedAt', Model.transformDate),
  ratingChangedAt: Model.attribute('ratingChangedAt', Model.transformDate),
  canEdit: Model.attribute('canEdit'),
  discussionId: Model.attribute('discussionId'),
  collectionId: Model.attribute('collectionId'),
  tradeOffers: Model.attribute('tradeOffers'),
  ownersCount: Model.attribute('ownersCount'),
  collection: Model.hasOne('collection'),
  // An item entered by hand has no looked-up title: null, not core's `false`, so `item.work()?.facts()` is safe.
  work() {
    return hasWork.call(this) || null;
  },
  user: Model.hasOne('user'),
});

export class CurioWork extends Model {}
Object.assign(CurioWork.prototype, {
  title: Model.attribute('title'),
  originalTitle: Model.attribute('originalTitle'),
  creator: Model.attribute('creator'),
  year: Model.attribute('year'),
  coverUrl: Model.attribute('coverUrl'),
  description: Model.attribute('description'),
  descriptionSource: Model.attribute('descriptionSource'),
  descriptionLocale: Model.attribute('descriptionLocale'),
  fallback: Model.attribute('fallback'),
  sourceUrl: Model.attribute('sourceUrl'),
  languages: Model.attribute('languages'),
  source: Model.attribute('source'),
  facts: Model.attribute('facts'),
});

export function registerModels(app) {
  // The item a "Show it off" discussion is about (on the discussion page only).
  app.store.models.discussions.prototype.curioItem = Model.hasOne('curioItem');

  app.store.models['curio-types'] = CurioType;
  app.store.models['curio-collections'] = CurioCollection;
  app.store.models['curio-items'] = CurioItem;
  app.store.models['curio-works'] = CurioWork;
}
