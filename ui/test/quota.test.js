import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { limitsSummary, quotaState } from '../src/quota.js';

const accept = [
  { type: 'image/jpeg', extensions: ['jpg', 'jpeg'], group: 'image' },
  { type: 'image/png', extensions: ['png'], group: 'image' },
  { type: 'application/pdf', extensions: ['pdf'], group: 'document' },
  { type: 'video/mp4', extensions: ['mp4'], group: 'video' },
];

describe('limitsSummary', () => {
  it('gives one size when there is no size by group', () => {
    assert.deepEqual(limitsSummary({ accept: accept.slice(0, 2), limits: { file_size: 100, file_sizes: null } }), {
      sizes: [{ group: null, max: 100 }],
      types: ['JPG', 'PNG'],
    });
  });

  it('gives a size per accepted group, in the order of accept', () => {
    const info = { accept, limits: { file_size: 500, file_sizes: { image: 100, video: 500 } } };
    assert.deepEqual(limitsSummary(info).sizes, [
      { group: 'image', max: 100 },
      { group: 'document', max: 500 },
      { group: 'video', max: 500 },
    ]);
  });

  it('gives one size when every accepted group has the same', () => {
    const info = { accept: accept.slice(0, 2), limits: { file_size: 500, file_sizes: { image: 100, video: 500 } } };
    assert.deepEqual(limitsSummary(info).sizes, [{ group: null, max: 100 }]);
  });
});

describe('quotaState', () => {
  const info = (usage, limits) => ({ usage, limits: { file_size: 10, total_size: null, files: null, ...limits } });

  it('has no meter without a quota', () => {
    assert.equal(quotaState(info({ size: 5, files: 5 }, {})), null);
  });

  it('takes the larger share of size and files', () => {
    assert.deepEqual(quotaState(info({ size: 25, files: 1 }, { total_size: 100, files: 10 })), { fraction: 0.25, level: 'ok', full: false, reason: null });
    assert.deepEqual(quotaState(info({ size: 10, files: 9 }, { total_size: 100, files: 10 })), { fraction: 0.9, level: 'near', full: false, reason: null });
  });

  it('is full when the files are used up or the size has no room for even a byte', () => {
    assert.deepEqual(quotaState(info({ size: 10, files: 10 }, { total_size: 100, files: 10 })), { fraction: 1, level: 'full', full: true, reason: 'files' });
    assert.deepEqual(quotaState(info({ size: 100, files: 3 }, { total_size: 100 })), { fraction: 1, level: 'full', full: true, reason: 'size' });
  });

  it('never goes over one, even when an owner is over its quota', () => {
    assert.equal(quotaState(info({ size: 300, files: 1 }, { total_size: 100 })).fraction, 1);
  });
});
