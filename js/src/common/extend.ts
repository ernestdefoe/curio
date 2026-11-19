import Extend from 'flarum/common/extenders';
import Discussion from 'flarum/common/models/Discussion';
import { CurioType, CurioCollection, CurioItem, CurioWork } from './models';

export default [
  new Extend.Store()
    .add('curio-types', CurioType)
    .add('curio-collections', CurioCollection)
    .add('curio-items', CurioItem)
    .add('curio-works', CurioWork),

  // The item a "Show it off" discussion is about (on the discussion page only).
  new Extend.Model(Discussion).hasOne('curioItem'),
];
