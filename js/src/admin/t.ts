import app from 'flarum/admin/app';

export const t = (key, params) => app.translator.trans(`ernestdefoe-curio.admin.${key}`, params);
