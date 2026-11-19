import app from 'flarum/admin/app';
import { extend as after } from 'flarum/common/extend';
import Select from 'flarum/common/components/Select';
import { pick } from '../common/labels';

const t = (key) => app.translator.trans(`ernestdefoe-curio.admin.badges.${key}`);
const METRICS = { curio_items: 'items', curio_collections: 'collections' };

const usesCurio = (builder) => Array.isArray(builder?.config?.conditions) && builder.config.conditions.some((c) => c?.metric in METRICS);

/**
 * FoF Badges lists its trigger metrics in its own code, so Curio's (registered
 * on the server) are added to its trigger builder here, with a type picker
 * while a condition uses one of them. Each addition only while the method it
 * extends is there: if FoF Badges changes its builder, they do nothing.
 */
export default function badges() {
  const mod = globalThis.flarum?.reg?.get?.('fof-badges', 'admin/components/TriggerBuilder');
  const proto = (mod?.default ?? mod)?.prototype;
  if (!proto) return;

  if (typeof proto.getAvailableMetrics === 'function') {
    after(proto, 'getAvailableMetrics', function (metrics) {
      if (Array.isArray(metrics)) Object.entries(METRICS).forEach(([value, key]) => metrics.push({ value, label: t(key) }));
    });
  }

  if (typeof proto.view !== 'function' || typeof proto.emitChange !== 'function') return;

  after(proto, 'oninit', function () {
    // The types the admin pages already load for Curio's own editor; fetched once if not yet.
    if (!app.store.all('curio-types').length) app.store.find('curio-types').then(() => m.redraw());
  });

  after(proto, 'view', function (vdom) {
    if (!usesCurio(this) || !Array.isArray(vdom?.children)) return;

    // Keys like "t3": a plain "3" sorts ahead of "" in an object, which would put "Any type" last.
    const options = { '': t('any_type') };
    app.store.all('curio-types').forEach((type) => (options['t' + type.id()] = pick(type.names(), app.translator.getLocale()) || type.slug()));

    vdom.children.push(
      // FoF's tag filter's own wrapper, so it is laid out like it.
      <div className="TriggerBuilder-tagFilter TriggerBuilder-curioType">
        <div className="Form-group">
          <label>{t('type')}</label>
          <Select
            value={this.config.curio_type_id ? 't' + this.config.curio_type_id : ''}
            options={options}
            onchange={(val) => {
              this.config.curio_type_id = val ? parseInt(val.slice(1), 10) : undefined;
              this.emitChange();
            }}
          />
          <p className="helpText">{t('type_help')}</p>
        </div>
      </div>
    );
  });
}
