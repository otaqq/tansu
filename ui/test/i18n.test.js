import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { formatDate, formatSize, languageOf, setMessages, translator } from '../src/i18n.js';

describe('i18n', () => {
  it('translates with placeholders and falls back to English, then to the key', () => {
    setMessages('xx', { upload: 'Up {n}' });
    assert.equal(translator('ja')('upload'), 'アップロード');
    assert.equal(translator('xx')('upload', { n: 2 }), 'Up 2');
    assert.equal(translator('xx')('delete'), 'Delete');
    assert.equal(translator('en')('nope'), 'nope');
    assert.equal(translator('en')('confirmDeleteFile', { name: 'a.jpg' }), 'Delete "a.jpg"?');
    assert.equal(translator('en')('confirmDeleteFile'), 'Delete "{name}"?');
  });

  it('picks the language from the closest lang attribute, its base language, or English', () => {
    const element = (lang) => ({ closest: () => (lang ? { getAttribute: () => lang } : null) });
    assert.equal(languageOf(element('ja')), 'ja');
    assert.equal(languageOf(element('ja-JP')), 'ja');
    assert.equal(languageOf(element('de')), 'en');
    assert.equal(languageOf(element(null)), 'en');
  });

  it('formats sizes', () => {
    assert.equal(formatSize(900), '900 B');
    assert.equal(formatSize(1536), '1.5 KB');
    assert.equal(formatSize(2 * 1024 * 1024), '2 MB');
    assert.equal(formatSize(15 * 1024 * 1024), '15 MB');
  });

  it('formats dates and leaves unreadable ones as they are', () => {
    assert.match(formatDate('2026-11-07T10:18:40+09:00', 'en'), /2026/);
    assert.equal(formatDate('nonsense'), 'nonsense');
  });
});
