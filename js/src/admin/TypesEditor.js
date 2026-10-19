import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import GridSort from '../common/gridSort';
import { pick } from '../common/labels';

const t = (key, params) => app.translator.trans(`ernestdefoe-curio.admin.${key}`, params);
const text = (key, params) => extractText(t(key, params));

const SHAPES = ['portrait', 'square', 'landscape'];
const SOURCES = ['none', 'books', 'music', 'movies', 'tv', 'games'];
const KINDS = ['select', 'text', 'number', 'date', 'bool'];
const clone = (v) => JSON.parse(JSON.stringify(v));

/**
 * The collection types: drag to order, then for the open one its name, icon,
 * colour, cover shape, look-up source and details. Every label is edited in
 * one installed language at a time; an empty one falls back to English.
 */
export default class TypesEditor {
  oninit() {
    this.types = null;
    this.open = null;
    this.draft = null;
    this.locales = Object.keys(app.data.locales || { en: 'English' });
    this.lang = this.locales.includes('en') ? 'en' : this.locales[0];
    this.sorter = new GridSort(
      () => this.types,
      () => this.saveOrder()
    );

    app.store.find('curio-types').then((types) => {
      this.types = types.slice().sort((a, b) => a.position() - b.position());
      if (this.types[0]) this.edit(this.types[0]);
      m.redraw();
    });
  }

  edit(type) {
    this.open = type;
    this.error = null;
    this.draft = type
      ? {
          names: clone(type.names() || {}),
          icon: type.icon(),
          color: type.color(),
          shape: type.shape(),
          source: type.source(),
          fields: clone(type.fields() || []),
        }
      : { names: {}, icon: 'fas fa-box', color: '#5b3fd1', shape: 'portrait', source: 'none', fields: [] };
  }

  view() {
    if (!this.types) return <LoadingIndicator display="block" />;

    return (
      <div className="CurioAdmin">
        <h3>{t('types.heading')}</h3>
        <p className="helpText">{t('types.help')}</p>
        <div className="CurioAdmin-layout">
          <ul className="CurioAdmin-types" data-sortable="true">
            {this.types.map((type, i) => (
              <li
                key={type.id()}
                className={(type === this.open ? 'active ' : '') + (this.sorter.dragging === i ? 'is-dragging' : '')}
                onclick={() => this.edit(type)}
              >
                <span className="CurioAdmin-grip" {...this.sorter.attrs(i)} onclick={(e) => e.stopPropagation()} aria-label={text('types.drag')}>
                  <Icon name="fas fa-grip-vertical" />
                </span>
                <span className="CurioAdmin-icon" style={{ background: type.color() }}>
                  <Icon name={type.icon()} />
                </span>
                <span className="CurioAdmin-name">{pick(type.names(), this.lang)}</span>
                <span className="CurioAdmin-meta">{t('shape.' + type.shape())}</span>
              </li>
            ))}
            <li className={'CurioAdmin-add' + (this.open === null && this.draft ? ' active' : '')} onclick={() => this.edit(null)}>
              <Icon name="fas fa-plus" /> {t('types.add')}
            </li>
          </ul>
          {this.draft ? this.editor() : null}
        </div>
      </div>
    );
  }

  editor() {
    const d = this.draft;
    const igdb = d.source === 'games' && !!app.data.settings['ernestdefoe-curio.igdb_client_id'];
    const marqueeNeeded = ['movies', 'tv', 'games'].includes(d.source) && !igdb && !('ernestdefoe-marquee' in (app.data.extensions || {}));

    return (
      <div className="CurioAdmin-editor">
        {this.locales.length > 1 ? (
          <div className="CurioAdmin-langs">
            <span>{t('types.labels_in')}</span>
            <div className="ButtonGroup">
              {this.locales.map((code) => (
                <Button className={'Button' + (code === this.lang ? ' Button--primary' : '')} onclick={() => (this.lang = code)}>
                  {app.data.locales[code]}
                </Button>
              ))}
            </div>
          </div>
        ) : null}

        <div className="CurioAdmin-grid">
          <div className="Form-group">
            <label>{t('types.name')}</label>
            <input
              className="FormControl"
              value={d.names[this.lang] || ''}
              placeholder={this.lang !== 'en' ? d.names.en : ''}
              oninput={(e) => (d.names[this.lang] = e.target.value)}
            />
          </div>
          <div className="Form-group">
            <label>{t('types.icon')}</label>
            <div className="CurioAdmin-iconField">
              <span className="CurioAdmin-icon" style={{ background: d.color }}>
                <Icon name={d.icon} />
              </span>
              <input className="FormControl" value={d.icon} oninput={(e) => (d.icon = e.target.value)} placeholder="fas fa-gamepad" />
            </div>
          </div>
          <div className="Form-group">
            <label>{t('types.color')}</label>
            <input className="FormControl CurioAdmin-color" type="color" value={d.color} oninput={(e) => (d.color = e.target.value)} />
          </div>
          <div className="Form-group">
            <label>{t('types.shape')}</label>
            <Select value={d.shape} onchange={(v) => (d.shape = v)} options={Object.fromEntries(SHAPES.map((s) => [s, text('shape.' + s)]))} />
          </div>
          <div className="Form-group">
            <label>{t('types.source')}</label>
            <Select value={d.source} onchange={(v) => (d.source = v)} options={Object.fromEntries(SOURCES.map((s) => [s, text('source.' + s)]))} />
            {marqueeNeeded ? <small className="CurioAdmin-warn">{t('types.needs_marquee')}</small> : null}
          </div>
        </div>

        <h4>{t('types.fields')}</h4>
        <p className="helpText">{t('types.fields_help')}</p>
        <table className="CurioAdmin-fields">
          <thead>
            <tr>
              <th>{t('types.field_label', { language: app.data.locales[this.lang] })}</th>
              <th>{t('types.field_kind')}</th>
              <th>{t('types.field_choices')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {d.fields.map((f, i) => (
              <tr>
                <td>
                  <input
                    className="FormControl"
                    value={f.labels[this.lang] || ''}
                    placeholder={this.lang !== 'en' ? f.labels.en : ''}
                    oninput={(e) => (f.labels[this.lang] = e.target.value)}
                  />
                </td>
                <td>
                  <Select value={f.kind} onchange={(v) => (f.kind = v)} options={Object.fromEntries(KINDS.map((k) => [k, text('kind.' + k)]))} />
                </td>
                <td>{f.kind === 'select' ? this.options(f) : <span className="CurioMuted">—</span>}</td>
                <td>
                  <Button
                    className="Button Button--icon Button--link"
                    icon="fas fa-trash"
                    aria-label={text('types.remove_field')}
                    onclick={() => d.fields.splice(i, 1)}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {d.fields.length < 12 ? (
          <Button className="Button" icon="fas fa-plus" onclick={() => d.fields.push({ key: '', kind: 'text', labels: {} })}>
            {t('types.add_field')}
          </Button>
        ) : null}

        <div className="CurioAdmin-note">
          <Icon name="fas fa-language" /> {t('types.translation_note')}
        </div>
        {this.error ? <div className="Alert Alert--error">{this.error}</div> : null}
        <div className="CurioAdmin-actions">
          <Button
            className="Button Button--primary"
            loading={this.saving}
            disabled={!Object.values(d.names).some((n) => n && n.trim())}
            onclick={() => this.save()}
          >
            {this.open ? t('types.save') : t('types.create')}
          </Button>
          {this.open ? (
            <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove()}>
              {t('types.delete')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  options(field) {
    field.options = field.options || [];
    return (
      <div className="CurioAdmin-options">
        {field.options.map((o, i) => (
          <span className="CurioAdmin-option">
            <input
              value={o.labels[this.lang] || ''}
              placeholder={o.labels.en || ''}
              size={Math.max(6, (o.labels[this.lang] || o.labels.en || '').length)}
              oninput={(e) => (o.labels[this.lang] = e.target.value)}
            />
            <button type="button" aria-label={text('types.remove_choice')} onclick={() => field.options.splice(i, 1)}>
              <Icon name="fas fa-xmark" />
            </button>
          </span>
        ))}
        <input
          className="CurioAdmin-newOption"
          placeholder={text('types.add_choice')}
          onkeydown={(e) => {
            if (e.key !== 'Enter' || !e.target.value.trim()) return;
            e.preventDefault();
            field.options.push({ key: '', labels: { [this.lang]: e.target.value.trim() } });
            e.target.value = '';
          }}
        />
      </div>
    );
  }

  save() {
    const d = this.draft;
    const attrs = { ...d, names: Object.fromEntries(Object.entries(d.names).filter(([, v]) => v && v.trim())) };
    if (!this.open)
      attrs.slug =
        (attrs.names.en || Object.values(attrs.names)[0] || 'type')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-|-$/g, '')
          .slice(0, 40) || 'type';

    this.saving = true;
    this.error = null;
    (this.open || app.store.createRecord('curio-types'))
      .save(attrs)
      .then((type) => {
        if (!this.open) this.types.push(type);
        this.edit(type);
      })
      .catch((e) => (this.error = e.response?.errors?.[0]?.detail || text('types.save_failed')))
      .finally(() => {
        this.saving = false;
        m.redraw();
      });
  }

  remove() {
    const type = this.open;
    if (!confirm(text('types.delete_confirm', { name: pick(type.names(), this.lang) }))) return;

    type
      .delete()
      .then(() => {
        this.types = this.types.filter((x) => x !== type);
        this.edit(this.types[0] || null);
      })
      .catch((e) => (this.error = e.response?.errors?.[0]?.detail || text('types.in_use')))
      .finally(() => m.redraw());
  }

  saveOrder() {
    this.types.forEach((type, position) => {
      if (type.position() !== position) type.save({ position });
    });
  }
}
