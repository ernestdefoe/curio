import app from 'flarum/admin/app';
import { extend as after } from 'flarum/common/extend';
import badges from './badges';
import { fofWidget } from '../common/widget';
import { t } from './t';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe-curio', () => {
  badges();

  // FoF Forum Widgets' layout editor only lists widgets registered on the
  // admin side too. Registered when the editor opens, from the setting as it
  // is now: read only at boot, switching "Offer the widget" on left the
  // editor without it until the whole admin was reloaded.
  const fof = (path) => {
    const mod = globalThis.flarum?.reg?.get?.('fof-forum-widgets-core', path);
    return mod?.default ?? mod;
  };
  const Widgets = fof('common/extend/Widgets');
  const WidgetEditor = fof('admin/components/WidgetEditor');
  if (!Widgets || !WidgetEditor) return;

  after(WidgetEditor.prototype, 'oninit', () => {
    const on = ['1', 'true', true].includes(app.data.settings['ernestdefoe-curio.widget']);
    const id = 'ernestdefoe-curio:curioRecent';
    const has = app.widgets.widgets.some((w) => w.id === id);
    // Switched off later, it stays listed until the admin reloads; the forum shows it only while on.
    if (on && !has) {
      new Widgets()
        .add({ ...fofWidget(null), title: () => t('settings.widget_title'), icon: 'fas fa-boxes-stacked' })
        .extend(app, 'ernestdefoe-curio');
    }
  });
});
