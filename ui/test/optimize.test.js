import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { fileGroup, fileSizeLimit, initialOptimize, labelOf, optimizeFields } from '../src/optimize.js';

const optimize = {
  enabled: true,
  default: 'logo',
  presets: [
    { key: 'logo', label: { en: 'Logo', ja: 'ロゴ' }, max_width: 600, max_height: 600, quality: 80 },
    { key: 'small', label: 'Small file', max_width: null, max_height: null, quality: 60 },
  ],
  custom: {
    max_width: { min: 16, max: 4096, default: 1200 },
    max_height: { min: 16, max: 4096, default: 1000 },
    quality: { min: 40, max: 100, default: 80 },
  },
};

describe('initialOptimize', () => {
  it('starts on the attribute, else the server default, else none', () => {
    assert.equal(initialOptimize(optimize, 'small').choice, 'small');
    assert.equal(initialOptimize(optimize, null).choice, 'logo');
    assert.equal(initialOptimize({ ...optimize, default: null }, null).choice, 'none');
    assert.equal(initialOptimize(optimize, 'custom').choice, 'custom');
    assert.equal(initialOptimize(optimize, 'none').choice, 'none');
  });

  it('ignores choices that are not offered', () => {
    assert.equal(initialOptimize(optimize, 'huge').choice, 'logo');
    assert.equal(initialOptimize({ ...optimize, custom: null }, 'custom').choice, 'logo');
    assert.equal(initialOptimize({ enabled: false }, 'logo'), null);
    assert.equal(initialOptimize(undefined, 'logo'), null);
  });

  it('fills custom input with the server defaults, resizing and compressing on', () => {
    assert.deepEqual(initialOptimize(optimize, null).custom, { resize: true, maxWidth: 1200, maxHeight: 1000, compress: true, quality: 80 });
  });
});

describe('optimizeFields', () => {
  it('sends the choice, and the values only for custom', () => {
    const state = initialOptimize(optimize, null);
    assert.deepEqual(optimizeFields(null), {});
    assert.deepEqual(optimizeFields({ ...state, choice: 'none' }), {});
    assert.deepEqual(optimizeFields(state), { optimize: 'logo' });
    assert.deepEqual(optimizeFields({ ...state, choice: 'custom' }), { optimize: 'custom', max_width: 1200, max_height: 1000, quality: 80 });
    assert.deepEqual(optimizeFields({ ...state, choice: 'custom', custom: { ...state.custom, compress: false } }), { optimize: 'custom', max_width: 1200, max_height: 1000 });
    assert.deepEqual(optimizeFields({ ...state, choice: 'custom', custom: { ...state.custom, resize: false } }), { optimize: 'custom', quality: 80 });
  });

  it('sends nothing when custom has neither resize nor compress', () => {
    const state = initialOptimize(optimize, 'custom');
    assert.deepEqual(optimizeFields({ ...state, custom: { ...state.custom, resize: false, compress: false } }), {});
  });
});

describe('labelOf', () => {
  it('takes a string, or the language, then English, then the first', () => {
    assert.equal(labelOf('Small file', 'ja'), 'Small file');
    assert.equal(labelOf({ en: 'Logo', ja: 'ロゴ' }, 'ja'), 'ロゴ');
    assert.equal(labelOf({ en: 'Logo', ja: 'ロゴ' }, 'de'), 'Logo');
    assert.equal(labelOf({ fr: 'Logo FR' }, 'de'), 'Logo FR');
  });
});

describe('fileGroup and fileSizeLimit', () => {
  const info = {
    accept: [
      { type: 'image/png', extensions: ['png'], group: 'image' },
      { type: 'application/pdf', extensions: ['pdf'], group: 'document' },
      { type: 'video/mp4', extensions: ['mp4'], group: 'video' },
    ],
    limits: { file_size: 500, file_sizes: { image: 100, video: 500 } },
  };

  it('groups by media type', () => {
    assert.equal(fileGroup('image/png'), 'image');
    assert.equal(fileGroup('video/webm'), 'video');
    assert.equal(fileGroup('application/pdf'), 'document');
  });

  it('limits by the group of the accepted type the file matches', () => {
    assert.equal(fileSizeLimit(info, { name: 'a.PNG', type: '' }), 100);
    assert.equal(fileSizeLimit(info, { name: 'clip', type: 'video/mp4' }), 500);
    // A group without its own size, or a file of no accepted type, gets the largest
    assert.equal(fileSizeLimit(info, { name: 'a.pdf', type: 'application/pdf' }), 500);
    assert.equal(fileSizeLimit(info, { name: 'a.txt', type: 'text/plain' }), 500);
    assert.equal(fileSizeLimit({ ...info, limits: { file_size: 300, file_sizes: null } }, { name: 'a.png', type: 'image/png' }), 300);
  });
});
