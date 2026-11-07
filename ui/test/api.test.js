import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { TansuClient, TansuError } from '../src/api.js';

function fakeFetch(status, body) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init });
    return { status, text: async () => (body === undefined ? '' : JSON.stringify(body)) };
  };
  return { fetch, calls };
}

describe('TansuClient', () => {
  it('requires an endpoint', () => {
    assert.throws(() => new TansuClient({}), /endpoint/);
  });

  it('builds URLs with query parameters and the owner', () => {
    const client = new TansuClient({ endpoint: '/tansu/', owner: 'c 1', fetch: () => {} });
    assert.equal(client.url('/folder', { path: '/a b' }), '/tansu/folder?path=%2Fa+b&owner=c+1');
    assert.equal(new TansuClient({ endpoint: '/t', fetch: () => {} }).url('/info'), '/t/info');
  });

  it('sends JSON with the CSRF token on changes only', async () => {
    const { fetch, calls } = fakeFetch(201, { name: 'a', path: '/a' });
    const client = new TansuClient({ endpoint: '/t', csrfToken: 'tok', fetch });

    assert.deepEqual(await client.createFolder('/', 'a'), { name: 'a', path: '/a' });
    assert.equal(calls[0].url, '/t/folders');
    assert.equal(calls[0].init.method, 'POST');
    assert.equal(calls[0].init.credentials, 'same-origin');
    assert.equal(calls[0].init.headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(calls[0].init.body), { path: '/', name: 'a' });

    await client.info();
    assert.equal(calls[1].init.headers['X-CSRF-TOKEN'], undefined);
    assert.equal(calls[1].init.body, undefined);
  });

  it('sends DELETE with the path in the query and accepts 204', async () => {
    const { fetch, calls } = fakeFetch(204);
    const client = new TansuClient({ endpoint: '/t', fetch });

    assert.equal(await client.deleteFile('/a.jpg'), null);
    assert.equal(calls[0].url, '/t/files?path=%2Fa.jpg');
    assert.equal(calls[0].init.method, 'DELETE');
  });

  it('turns protocol errors into TansuError', async () => {
    const { fetch } = fakeFetch(409, { error: { code: 'in_use', message: 'The file is in use.', path: '/a.jpg' } });
    const error = await new TansuClient({ endpoint: '/t', fetch }).deleteFile('/a.jpg').catch((e) => e);

    assert.ok(error instanceof TansuError);
    assert.deepEqual([error.code, error.status, error.path, error.message], ['in_use', 409, '/a.jpg', 'The file is in use.']);
  });

  it('turns other error responses into http_<status>', async () => {
    const { fetch } = fakeFetch(419, { message: 'CSRF token mismatch.' });
    const error = await new TansuClient({ endpoint: '/t', fetch }).createFolder('/', 'a').catch((e) => e);

    assert.deepEqual([error.code, error.status, error.message], ['http_419', 419, 'CSRF token mismatch.']);
  });

  it('reports network failures', async () => {
    const fetch = async () => {
      throw new TypeError('Failed to fetch');
    };
    const error = await new TansuClient({ endpoint: '/t', fetch }).info().catch((e) => e);

    assert.equal(error.code, 'network');
  });

  it('uploads with XMLHttpRequest and reports progress', async () => {
    const sent = {};
    const xhr = {
      upload: {},
      headers: {},
      open(method, url) {
        Object.assign(sent, { method, url });
      },
      setRequestHeader(name, value) {
        this.headers[name] = value;
      },
      send(form) {
        sent.form = form;
        this.upload.onprogress({ lengthComputable: true, loaded: 1, total: 4 });
        this.status = 201;
        this.responseText = JSON.stringify({ name: 'a.jpg', path: '/f/a.jpg' });
        this.onload();
      },
    };
    const progress = [];
    const client = new TansuClient({ endpoint: '/t', owner: 'o', csrfToken: 'tok', fetch: () => {}, xhr: () => xhr });

    const file = await client.upload('/f', new File(['x'], 'a.jpg', { type: 'image/jpeg' }), { onProgress: (p) => progress.push(p) });

    assert.deepEqual(file, { name: 'a.jpg', path: '/f/a.jpg' });
    assert.deepEqual([sent.method, sent.url], ['POST', '/t/files?owner=o']);
    assert.equal(xhr.headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(xhr.withCredentials, true);
    assert.equal(sent.form.get('path'), '/f');
    assert.equal(sent.form.get('file').name, 'a.jpg');
    assert.deepEqual(progress, [0.25]);
    assert.equal(sent.form.get('optimize'), null);
  });

  it('sends extra fields with an upload (optimization)', async () => {
    const sent = {};
    const xhr = {
      open() {},
      setRequestHeader() {},
      send(form) {
        sent.form = form;
        this.status = 201;
        this.responseText = '{}';
        this.onload();
      },
    };
    const client = new TansuClient({ endpoint: '/t', fetch: () => {}, xhr: () => xhr });

    await client.upload('/', new File(['x'], 'a.jpg'), { fields: { optimize: 'custom', max_width: 800, quality: undefined } });

    assert.equal(sent.form.get('optimize'), 'custom');
    assert.equal(sent.form.get('max_width'), '800');
    assert.equal(sent.form.has('quality'), false);
  });

  it('rejects uploads with the protocol error', async () => {
    const xhr = {
      open() {},
      setRequestHeader() {},
      send() {
        this.status = 413;
        this.responseText = JSON.stringify({ error: { code: 'file_too_large', message: 'too large' } });
        this.onload();
      },
    };
    const client = new TansuClient({ endpoint: '/t', fetch: () => {}, xhr: () => xhr });
    const error = await client.upload('/', new Blob(['x'])).catch((e) => e);

    assert.equal(error.code, 'file_too_large');
  });
});
