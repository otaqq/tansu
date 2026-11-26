/**
 * <tansu-documents>: edit a fixed set of named text documents (for example a site's pages) — a list on the left, and
 * a detail with a text editor and a host-provided preview on the right.
 *
 * The documents and the preview come from the host, so tansu stays unaware of how a document is rendered:
 *   el.documents = [{ key, name, path }]      the list; `path` is the file saved with PUT /files (created if new)
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
      this.#documents.length
        ? this.#documents.map((doc) => h('button', {
          type: 'button', class: 'doc', 'aria-current': this.#selected === doc.key ? 'true' : 'false',
          onclick: () => this.#select(doc.key),
        }, h('span', { class: 'name' }, doc.name || doc.key)))
        : h('p', { class: 'empty' }, t('documents.empty')));
    if (!this.#selected) {
      replace(this.#els.layout, list, h('div', { class: 'placeholder' }, h('p', { class: 'hint' }, t('documents.pick'))));
      return;
    }
    replace(this.#els.layout, list, this.#editorPane(), this.#previewPane());
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
    this.#els.save = h('button', {
      type: 'button', class: 'primary',
      disabled: !this.#dirty || this.#saving || this.#loading ? true : null,
      onclick: () => this.#save(),
    }, t('save'));
    this.#els.status = h('span', { class: 'status', role: 'status' },
      this.#error ? this.#message(this.#error) : this.#saving ? t('saving') : this.#saved && !this.#dirty ? t('saved') : '');
    const preview = this.#previewProvider
      ? h('button', { type: 'button', disabled: this.#loading ? true : null, onclick: () => this.#renderPreview() }, t('documents.preview'))
      : null;
    const fileInput = h('input', {
      type: 'file', accept: this.#accept(), hidden: true,
      onchange: (event) => this.#loadFile(event.target.files?.[0]),
    });
    const upload = h('button', { type: 'button', disabled: this.#loading ? true : null, onclick: () => fileInput.click() }, t('upload'));
    const download = h('button', { type: 'button', disabled: this.#loading ? true : null, onclick: () => this.#download() }, t('download'));
    return h('div', { class: 'editor-pane' }, editor,
      h('div', { class: 'bar' }, this.#els.save, preview, upload, download, this.#els.status), fileInput);
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
    return h('div', { class: 'preview-pane' }, this.#els.mount);
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
