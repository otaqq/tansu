/**
 * <tansu-documents>: edit a fixed set of named text documents (for example a site's pages) — a list on the left, and
 * a detail with a text editor and a host-provided preview on the right.
 *
 * The documents and the preview come from the host, so tansu stays unaware of how a document is rendered:
 *   el.documents = [{ key, name, path, subtitle? }]   the list; `path` is the file saved with PUT /files (created if
 *       new); `subtitle` is an optional small second line (for example the file name)
 *   el.previewProvider = ({ item, content, mount, client }) => {}
 *       renders the preview into `mount` (an element): set an iframe's src to the host's page, POST the unsaved
 *       `content` to the host's render endpoint, render on the client — anything. Omit it for no preview.
 *
 * Attributes: endpoint (required), owner, csrf-token, lang — passed to the API client, as on <tansu-explorer>.
 * Events (bubble, composed):
 *   tansu-change  detail: { action: 'document-saved', path, item }   a document was saved
 *   tansu-error   detail: { error }                                  loading or saving failed
 */
import { TansuClient, TansuError } from './api.js';
import { adoptStyles, h, replace } from './dom.js';
import { iconButton } from './icons.js';
import { languageOf, translator } from './i18n.js';
import { documentsStyles } from './styles.js';

export class TansuDocuments extends HTMLElement {
  static observedAttributes = ['endpoint', 'owner', 'csrf-token', 'lang'];

  #client = null;
  #documents = [];
  #previewProvider = null;
  #selected = null;
  #content = '';
  #loading = false;
  #saving = false;
  #dirty = false;
  #saved = false;
  #error = null;
  #els = {};
  #connected = false;
  #view = 'split';
  #splitRatio = 0.5;

  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    adoptStyles(this.shadowRoot, documentsStyles);
    this.#els.layout = h('div', { class: 'layout' });
    this.shadowRoot.append(this.#els.layout);
  }

  /** The catalog: an array of { key, name, path }. */
  get documents() {
    return this.#documents;
  }

  set documents(list) {
    this.#documents = Array.isArray(list) ? list.filter((d) => d && d.key && d.path) : [];
    if (this.#selected && !this.#documents.some((d) => d.key === this.#selected)) this.#selected = null;
    if (this.#connected) this.#render();
  }

  /** The preview provider: ({ item, content, mount, client }) => void. */
  get previewProvider() {
    return this.#previewProvider;
  }

  set previewProvider(fn) {
    this.#previewProvider = typeof fn === 'function' ? fn : null;
    if (this.#connected && this.#selected) this.#renderPreview();
  }

  /** The API client, for hosts that want to call the API themselves. */
  get client() {
    return this.#client;
  }

  connectedCallback() {
    this.#connected = true;
    this.#setup();
    this.#render();
  }

  attributeChangedCallback() {
    if (!this.#connected) return;
    this.#setup();
    this.#render();
  }

  get #t() {
    return translator(this.getAttribute('lang') || languageOf(this));
  }

  #setup() {
    const endpoint = this.getAttribute('endpoint');
    const csrfToken = this.getAttribute('csrf-token') || document.querySelector('meta[name="csrf-token"]')?.content || '';
    this.#client = endpoint ? new TansuClient({ endpoint, owner: this.getAttribute('owner') || '', csrfToken }) : null;
  }

  #doc() {
    return this.#documents.find((d) => d.key === this.#selected) || null;
  }

  async #select(key) {
    if (this.#selected === key) return;
    this.#selected = key;
    this.#content = '';
    this.#loading = true;
    this.#dirty = false;
    this.#saved = false;
    this.#error = null;
    this.#render();
    const doc = this.#doc();
    if (!doc || !this.#client) return;
    try {
      this.#content = await this.#client.text(this.#client.url('/raw', { path: doc.path }));
    } catch (error) {
      // A document whose file does not exist yet starts empty; other errors are shown
      this.#content = '';
      if (!(error instanceof TansuError) || error.status !== 404) this.#error = error;
    }
    if (this.#selected !== key) return;
    this.#loading = false;
    this.#render();
  }

  #render() {
    const t = this.#t;
    const list = h('nav', { class: 'list', 'aria-label': t('documents.title') },
      h('h2', {}, t('documents.title')),
      this.#selected && this.#previewProvider ? this.#viewToggle() : null,
      this.#documents.length
        ? this.#documents.map((doc) => h('button', {
          type: 'button', class: 'doc', 'aria-current': this.#selected === doc.key ? 'true' : 'false',
          onclick: () => this.#select(doc.key),
        }, h('span', { class: 'name' }, doc.name || doc.key), doc.subtitle ? h('span', { class: 'badge' }, doc.subtitle) : null))
        : h('p', { class: 'empty' }, t('documents.empty')));
    if (!this.#selected) {
      this.#els.layout.dataset.view = 'split';
      replace(this.#els.layout, list, h('div', { class: 'placeholder' }, h('p', { class: 'hint' }, t('documents.pick'))));
      return;
    }
    this.#applyLayout();
    replace(this.#els.layout, list, this.#editorPane(), this.#resizer(), this.#previewPane());
    this.#renderPreview();
  }

  #editorPane() {
    const t = this.#t;
    const editor = h('textarea', {
      class: 'editor', part: 'editor', spellcheck: 'false', wrap: 'off', 'aria-label': this.#doc()?.name || '',
      disabled: this.#loading ? true : null,
      oninput: (event) => {
        this.#content = event.target.value;
        this.#dirty = true;
        this.#saved = false;
        this.#error = null;
        if (this.#els.save) this.#els.save.disabled = false;
        if (this.#els.status) this.#els.status.textContent = '';
      },
    }, this.#loading ? '' : this.#content);
    this.#els.editor = editor;
    this.#els.save = iconButton('check', t('save'), {
      class: 'primary',
      disabled: !this.#dirty || this.#saving || this.#loading ? true : null,
      onclick: () => this.#save(),
    });
    this.#els.status = h('span', { class: 'status', role: 'status' },
      this.#error ? this.#message(this.#error) : this.#saving ? t('saving') : this.#saved && !this.#dirty ? t('saved') : '');
    const preview = this.#previewProvider
      ? iconButton('refresh', t('documents.refresh'), { disabled: this.#loading ? true : null, onclick: () => this.#renderPreview() })
      : null;
    const fileInput = h('input', {
      type: 'file', accept: this.#accept(), hidden: true,
      onchange: (event) => this.#loadFile(event.target.files?.[0]),
    });
    const upload = iconButton('upload', t('upload'), { disabled: this.#loading ? true : null, onclick: () => fileInput.click() });
    const download = iconButton('download', t('download'), { disabled: this.#loading ? true : null, onclick: () => this.#download() });
    const pane = h('div', { class: 'editor-pane' }, editor,
      h('div', { class: 'bar' }, this.#els.save, preview, upload, download, this.#els.status), fileInput);
    this.#els.editorPane = pane;
    return pane;
  }

  /** The accept attribute for the upload input: the document's own extension. */
  #accept() {
    const path = this.#doc()?.path || '';
    const dot = path.lastIndexOf('.');
    return dot >= 0 ? path.slice(dot) : '';
  }

  /** Reads a local file into the editor (the person then saves it). */
  async #loadFile(file) {
    if (!file) return;
    this.#content = await file.text();
    this.#dirty = true;
    this.#saved = false;
    this.#error = null;
    this.#render();
  }

  /** Downloads the current contents as the document's file name. */
  #download() {
    const name = (this.#doc()?.path || 'document').split('/').pop();
    const url = URL.createObjectURL(new Blob([this.#content], { type: 'text/plain;charset=utf-8' }));
    const link = h('a', { href: url, download: name });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  #previewPane() {
    this.#els.mount = h('div', { class: 'preview-mount', part: 'preview' });
    const pane = h('div', { class: 'preview-pane' }, this.#els.mount);
    this.#els.previewPane = pane;
    return pane;
  }

  #viewToggle() {
    const t = this.#t;
    const button = (mode, icon, key) => iconButton(icon, t(key), {
      class: 'view', 'aria-pressed': this.#view === mode ? 'true' : 'false',
      onclick: () => this.#setView(mode),
    });
    this.#els.views = {
      split: button('split', 'columns', 'documents.view_split'),
      editor: button('editor', 'pencil', 'documents.view_editor'),
      preview: button('preview', 'eye', 'documents.view_preview'),
    };
    return h('div', { class: 'view-toggle', role: 'group', 'aria-label': t('documents.view') },
      this.#els.views.split, this.#els.views.editor, this.#els.views.preview);
  }

  /** Switches the editor / preview layout: split (both), editor only, or preview only. */
  #setView(mode) {
    this.#view = mode;
    this.#els.layout.dataset.view = mode;
    for (const [m, button] of Object.entries(this.#els.views || {})) {
      button.setAttribute('aria-pressed', m === mode ? 'true' : 'false');
    }
    if (mode !== 'editor') this.#renderPreview();
  }

  #resizer() {
    const el = h('div', {
      class: 'resizer', part: 'resizer', role: 'separator', 'aria-orientation': 'vertical',
      'aria-label': this.#t('resize'), onpointerdown: (event) => this.#startResize(event),
    });
    this.#els.resizer = el;
    return el;
  }

  /** Drags the split divider so the editor and the preview grow and shrink against each other. */
  #startResize(event) {
    if (this.#view !== 'split') return;
    event.preventDefault();
    const layout = this.#els.layout;
    const resizer = this.#els.resizer;
    const left = this.#els.editorPane?.getBoundingClientRect().left ?? 0;
    const width = (this.#els.previewPane?.getBoundingClientRect().right ?? 0) - left;
    const onMove = (move) => {
      if (width <= 0) return;
      const ratio = Math.min(0.85, Math.max(0.15, (move.clientX - left) / width));
      this.#splitRatio = ratio;
      layout.style.setProperty('--editor-col', `${ratio}fr`);
      layout.style.setProperty('--preview-col', `${1 - ratio}fr`);
    };
    const onUp = () => {
      resizer.removeEventListener('pointermove', onMove);
      resizer.removeEventListener('pointerup', onUp);
      try { resizer.releasePointerCapture(event.pointerId); } catch { /* was not captured */ }
    };
    try { resizer.setPointerCapture(event.pointerId); } catch { /* capture unsupported */ }
    resizer.addEventListener('pointermove', onMove);
    resizer.addEventListener('pointerup', onUp);
  }

  /** Applies the current view and split ratio to the layout element. */
  #applyLayout() {
    const layout = this.#els.layout;
    layout.dataset.view = this.#view;
    layout.style.setProperty('--editor-col', `${this.#splitRatio}fr`);
    layout.style.setProperty('--preview-col', `${1 - this.#splitRatio}fr`);
  }

  #renderPreview() {
    const mount = this.#els.mount;
    const doc = this.#doc();
    if (!mount || !doc || !this.#previewProvider) return;
    replace(mount);
    try {
      this.#previewProvider({ item: doc, content: this.#content, mount, client: this.#client });
    } catch (error) {
      replace(mount, h('p', { class: 'hint' }, this.#message(error)));
    }
  }

  async #save() {
    const doc = this.#doc();
    if (!doc || !this.#client || this.#saving || this.#loading) return;
    this.#saving = true;
    this.#error = null;
    this.#render();
    try {
      const saved = await this.#client.updateFile(doc.path, this.#content);
      if (this.#selected !== doc.key) return;
      this.#saving = false;
      this.#dirty = false;
      this.#saved = true;
      this.dispatchEvent(new CustomEvent('tansu-change', { detail: { action: 'document-saved', path: doc.path, item: saved }, bubbles: true, composed: true }));
    } catch (error) {
      if (this.#selected !== doc.key) return;
      this.#saving = false;
      this.#error = error;
      this.dispatchEvent(new CustomEvent('tansu-error', { detail: { error }, bubbles: true, composed: true }));
    }
    this.#render();
  }

  #message(error) {
    const t = this.#t;
    if (!(error instanceof TansuError)) return t('error.other', { message: String(error?.message || error) });
    const key = `error.${error.code}`;
    const text = t(key, { message: error.message, max: '', types: '' });
    return text === key ? t('error.other', { message: error.message }) : text;
  }
}
