import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import ColorPreviewInput from 'flarum/common/components/ColorPreviewInput';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import GridSort from '../common/gridSort';
import { pick } from '../common/labels';
import { nextName, freeSlug } from './naming';

const t = (key, params) => app.translator.trans(`ernestdefoe-curio.admin.${key}`, params);
const text = (key, params) => extractText(t(key, params));

const SHAPES = ['portrait', 'box', 'square', 'landscape'];
const SOURCES = ['none', 'books', 'music', 'movies', 'tv', 'games', 'pokemon', 'magic', 'yugioh', 'cards'];
const KINDS = ['select', 'text', 'number', 'date', 'bool', 'link'];
const clone = (v) => JSON.parse(JSON.stringify(v));
// What a type export carries: everything an admin sets, labels in every language.
const EXPORTED = ['slug', 'names', 'icon', 'color', 'shape', 'source', 'fields', 'enabled', 'progress', 'conditionScale', 'grading'];
const SCALES = ['general', 'cards'];

/** Saves text as a file in the browser. */
function download(name, data) {
  const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: name });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

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
    // Choices typed but not yet added, per detail; Save adds them too.
    this.pending = new Map();
    this.draft = type
      ? {
          names: clone(type.names() || {}),
          icon: type.icon(),
          color: type.color() || '',
          shape: type.shape(),
          source: type.source(),
          fields: clone(type.fields() || []),
          enabled: type.enabled() !== false,
          progress: !!type.progress(),
          conditionScale: type.conditionScale() || 'general',
          grading: !!type.grading(),
        }
      : {
          names: {},
          icon: 'fas fa-box',
          color: '',
          shape: 'portrait',
          source: 'none',
          fields: [],
          enabled: true,
          progress: false,
          conditionScale: 'general',
          grading: false,
        };
  }

  /**
   * A new type starting as a copy of the open one: icon, colour, shape,
   * look-ups and every detail with its labels in each language. Each name
   * gets the next free number ("Games 2", then "Games 3") so the copy can be
   * told apart until it is renamed.
   */
  duplicate() {
    const copy = clone(this.draft);
    Object.keys(copy.names).forEach(
      (lang) =>
        (copy.names[lang] = nextName(
          copy.names[lang],
          this.types.map((type) => (type.names() || {})[lang])
        ))
    );
    this.open = null;
    this.error = null;
    this.pending = new Map();
    this.draft = copy;
  }

  view() {
    if (!this.types) return <LoadingIndicator display="block" />;

    return (
      <div className="CurioAdmin">
        <div className="CurioAdmin-head">
          <h3>{t('types.heading')}</h3>
          <div className="CurioAdmin-transfer">
            <Button className="Button" icon="fas fa-file-export" onclick={() => this.exportTypes(this.types, 'curio-types.json')}>
              {t('types.export_all')}
            </Button>
            <label className="Button" title={text('types.import_help')}>
              <Icon name="fas fa-file-import" className="Button-icon" />
              <span className="Button-label">{t('types.import')}</span>
              <input type="file" accept=".json,application/json" hidden onchange={(e) => this.importTypes(e.target)} />
            </label>
          </div>
        </div>
        <p className="helpText">{t('types.help')}</p>
        {this.notice ? <div className={'Alert Alert--' + this.notice.type}>{this.notice.text}</div> : null}
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
                <span className="CurioAdmin-icon" style={{ background: type.color() || undefined }}>
                  <Icon name={type.icon()} />
                </span>
                <span className="CurioAdmin-name">{pick(type.names(), this.lang)}</span>
                {type.enabled() === false ? (
                  <span className="CurioAdmin-meta CurioAdmin-off">{t('types.off')}</span>
                ) : (
                  <span className="CurioAdmin-meta">{t('types.on')}</span>
                )}
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
              <span className="CurioAdmin-icon" style={{ background: d.color || undefined }}>
                <Icon name={d.icon} />
              </span>
              <input className="FormControl" value={d.icon} oninput={(e) => (d.icon = e.target.value)} placeholder="fas fa-gamepad" />
            </div>
          </div>
          <div className="Form-group">
            <label>{t('types.color')}</label>
            <ColorPreviewInput
              className="FormControl"
              placeholder="#aaaaaa"
              value={d.color}
              oninput={(e) => (d.color = e.target.value)}
              onchange={(e) => (d.color = e.target.value)}
            />
            <small className="helpText">{t('types.color_help')}</small>
          </div>
          <div className="Form-group">
            <label>{t('types.shape')}</label>
            <Select value={d.shape} onchange={(v) => (d.shape = v)} options={Object.fromEntries(SHAPES.map((s) => [s, text('shape.' + s)]))} />
          </div>
          <div className="Form-group">
            <label>{t('types.source')}</label>
            <Select value={d.source} onchange={(v) => (d.source = v)} options={Object.fromEntries(SOURCES.map((s) => [s, text('source.' + s)]))} />
            {marqueeNeeded ? <small className="CurioAdmin-warn">{t('types.needs_marquee')}</small> : null}
            {['pokemon', 'magic', 'yugioh', 'cards'].includes(d.source) ? <small className="helpText">{t('types.cards_help')}</small> : null}
          </div>
          <div className="Form-group">
            <label>{t('types.conditions')}</label>
            <Select
              value={d.conditionScale}
              onchange={(v) => (d.conditionScale = v)}
              options={Object.fromEntries(SCALES.map((s) => [s, text('types.conditions_' + s)]))}
            />
            <small className="helpText">{t('types.conditions_help')}</small>
          </div>
        </div>

        <div className="CurioAdmin-switches">
          <Switch state={d.enabled} onchange={(v) => (d.enabled = v)}>
            {t('types.enabled')}
          </Switch>
          <small className="helpText">{t('types.enabled_help')}</small>
          <Switch state={d.progress} onchange={(v) => (d.progress = v)}>
            {t('types.progress')}
          </Switch>
          <small className="helpText">{t('types.progress_help')}</small>
          <Switch state={d.grading} onchange={(v) => (d.grading = v)}>
            {t('types.grading')}
          </Switch>
          <small className="helpText">{t('types.grading_help')}</small>
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
                <td data-label={text('types.field_label', { language: app.data.locales[this.lang] })}>
                  <input
                    className="FormControl"
                    value={f.labels[this.lang] || ''}
                    placeholder={this.lang !== 'en' ? f.labels.en : ''}
                    oninput={(e) => (f.labels[this.lang] = e.target.value)}
                  />
                </td>
                <td data-label={text('types.field_kind')}>
                  <Select value={f.kind} onchange={(v) => (f.kind = v)} options={Object.fromEntries(KINDS.map((k) => [k, text('kind.' + k)]))} />
                </td>
                <td>{f.kind === 'select' ? this.options(f) : f.kind === 'link' ? this.reviewSwitch(f) : <span className="CurioMuted">—</span>}</td>
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
            <Button className="Button" icon="fas fa-copy" onclick={() => this.duplicate()}>
              {t('types.duplicate')}
            </Button>
          ) : null}
          {this.open ? (
            <Button className="Button" icon="fas fa-file-export" onclick={() => this.exportTypes([this.open], `curio-type-${this.open.slug()}.json`)}>
              {t('types.export')}
            </Button>
          ) : null}
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
              size={Math.max(6, (o.labels[this.lang] || o.labels.en || '').length + 2)}
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
          value={this.pending.get(field) || ''}
          oninput={(e) => this.pending.set(field, e.target.value)}
          onblur={(e) => this.addChoice(field, e.target)}
          onkeydown={(e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            this.addChoice(field, e.target);
          }}
        />
      </div>
    );
  }

  /**
   * A link detail can be the member's review: the item window then shows
   * "Read my review" beside the rating. One per type.
   */
  reviewSwitch(field) {
    return (
      <label className="CurioAdmin-review">
        <input
          type="checkbox"
          checked={!!field.review}
          onchange={(e) => {
            this.draft.fields.forEach((other) => delete other.review);
            if (e.target.checked) field.review = true;
          }}
        />
        {t('types.review_link')}
      </label>
    );
  }

  /** Adds the choice typed for a detail (Enter, leaving the box, or Save). */
  addChoice(field, input) {
    const label = (input ? input.value : this.pending.get(field) || '').trim();
    this.pending.delete(field);
    // Cleared now, not at the next redraw, so fast typing never joins two choices.
    if (input) input.value = '';
    if (label) (field.options ||= []).push({ key: '', labels: { [this.lang]: label } });
  }

  save() {
    const d = this.draft;
    d.fields.forEach((field) => field.kind === 'select' && this.addChoice(field));
    const attrs = { ...d, color: d.color.trim() || null, names: Object.fromEntries(Object.entries(d.names).filter(([, v]) => v && v.trim())) };
    if (!this.open) {
      const base =
        (attrs.names.en || Object.values(attrs.names)[0] || 'type')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-|-$/g, '')
          .slice(0, 40) || 'type';
      attrs.slug = freeSlug(
        base,
        this.types.map((type) => type.slug())
      );
    }

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

  exportTypes(list, name) {
    download(name, {
      curio: 'types',
      version: 1,
      types: list.map((type) => Object.fromEntries(EXPORTED.map((key) => [key, type.attribute(key)]))),
    });
  }

  /**
   * Adds every type in a Curio types file as a new type. A slug already in
   * use gets a number, so importing never overwrites a type; the server
   * cleans each one as if typed in the editor.
   */
  async importTypes(input) {
    const file = input.files[0];
    input.value = '';
    if (!file) return;
    this.notice = null;

    let list;
    try {
      const data = JSON.parse(file.size < 1024 * 1024 ? await file.text() : '');
      list = data && data.curio === 'types' && Array.isArray(data.types) ? data.types.filter((x) => x && typeof x === 'object').slice(0, 50) : null;
    } catch (e) {
      list = null;
    }
    if (!list || !list.length) {
      this.notice = { type: 'error', text: text('types.import_failed') };
      return m.redraw();
    }

    let count = 0;
    for (const raw of list) {
      const attrs = Object.fromEntries(EXPORTED.filter((key) => key in raw).map((key) => [key, raw[key]]));
      attrs.slug = freeSlug(
        String(attrs.slug || 'type').slice(0, 44),
        this.types.map((type) => type.slug())
      );
      try {
        this.types.push(await app.store.createRecord('curio-types').save(attrs));
        count++;
      } catch (e) {
        this.notice = { type: 'error', text: e.response?.errors?.[0]?.detail || text('types.save_failed') };
      }
    }
    if (count) this.notice = { type: 'success', text: text('types.import_done', { count }) };
    m.redraw();
  }

  saveOrder() {
    this.types.forEach((type, position) => {
      if (type.position() !== position) type.save({ position });
    });
  }
}
