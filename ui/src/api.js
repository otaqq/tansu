/**
 * Client for the tansu HTTP API (protocol/README.md).
 */

/** An error answered by the back end, or a failure to reach it. */
export class TansuError extends Error {
  /**
   * @param {string} code protocol error code, or "network" / "http_<status>" for errors outside the protocol
   * @param {string} message
   * @param {{status?: number, path?: string}} [extra]
   */
  constructor(code, message, { status = 0, path } = {}) {
    super(message);
    this.name = 'TansuError';
    this.code = code;
    this.status = status;
    this.path = path;
  }
}

export class TansuClient {
  /**
   * @param {object} options
   * @param {string} options.endpoint base URL of the API
   * @param {string} [options.owner] sent as the `owner` query parameter
   * @param {string} [options.csrfToken] sent as X-CSRF-TOKEN on requests that change something
   * @param {typeof fetch} [options.fetch]
   * @param {() => XMLHttpRequest} [options.xhr] used for uploads, which report progress
   */
  constructor({ endpoint, owner, csrfToken, fetch: fetchImpl, xhr } = {}) {
    if (!endpoint) throw new Error('tansu: endpoint is required');
    this.endpoint = endpoint.replace(/\/+$/, '');
    this.owner = owner || '';
    this.csrfToken = csrfToken || '';
    this.fetch = fetchImpl || globalThis.fetch.bind(globalThis);
    this.xhr = xhr || (() => new XMLHttpRequest());
  }

  /** URL of an endpoint with query parameters (and the owner). */
  url(path, query = {}) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null) params.set(key, value);
    }
    if (this.owner) params.set('owner', this.owner);
    const search = params.toString();
    return `${this.endpoint}${path}${search ? `?${search}` : ''}`;
  }

  info() {
    return this.request('GET', '/info');
  }

  tree() {
    return this.request('GET', '/tree');
  }

  folder(path) {
    return this.request('GET', '/folder', { query: { path } });
  }

  createFolder(parent, name) {
    return this.request('POST', '/folders', { body: { path: parent, name } });
  }

  renameFolder(path, name) {
    return this.request('PATCH', '/folders', { body: { path, name } });
  }

  deleteFolder(path) {
    return this.request('DELETE', '/folders', { query: { path } });
  }

  renameFile(path, name) {
    return this.request('PATCH', '/files', { body: { path, name } });
  }

  deleteFile(path) {
    return this.request('DELETE', '/files', { query: { path } });
  }

  /**
   * Uploads one file into a folder.
   *
   * @param {string} folder
   * @param {Blob & {name?: string}} file
   * @param {{onProgress?: (fraction: number) => void, signal?: AbortSignal}} [options]
   * @returns {Promise<object>} the stored file
   */
  upload(folder, file, { onProgress, signal } = {}) {
    return new Promise((resolve, reject) => {
      const xhr = this.xhr();
      xhr.open('POST', this.url('/files'));
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      if (this.csrfToken) xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
      if (onProgress && xhr.upload) {
        xhr.upload.onprogress = (event) => {
          if (event.lengthComputable) onProgress(event.loaded / event.total);
        };
      }
      xhr.onload = () => {
        const result = parse(xhr.status, xhr.responseText);
        if (result instanceof TansuError) reject(result);
        else resolve(result);
      };
      xhr.onerror = () => reject(new TansuError('network', 'The server could not be reached.'));
      xhr.onabort = () => reject(new TansuError('aborted', 'The upload was cancelled.'));
      signal?.addEventListener('abort', () => xhr.abort());
      const form = new FormData();
      form.append('path', folder);
      form.append('file', file, file.name || 'file');
      xhr.send(form);
    });
  }

  async request(method, path, { query, body } = {}) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (method !== 'GET' && this.csrfToken) headers['X-CSRF-TOKEN'] = this.csrfToken;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    let response;
    try {
      response = await this.fetch(this.url(path, query), {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
      });
    } catch {
      throw new TansuError('network', 'The server could not be reached.');
    }
    const result = parse(response.status, await response.text());
    if (result instanceof TansuError) throw result;
    return result;
  }
}

/** Turns a response into its JSON value, or a TansuError for error statuses. */
function parse(status, text) {
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = null;
  }
  if (status >= 200 && status < 300) return json;
  const error = json && typeof json.error === 'object' ? json.error : null;
  if (error && typeof error.code === 'string') {
    return new TansuError(error.code, error.message || error.code, { status, path: error.path });
  }
  return new TansuError(`http_${status}`, (json && json.message) || `HTTP ${status}`, { status });
}
