// Files for setInputFiles
const PNG_1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

export const png = (name) => ({ name, mimeType: 'image/png', buffer: Buffer.from(PNG_1x1, 'base64') });

export const svg = (name) => ({
  name,
  mimeType: 'image/svg+xml',
  buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><script>alert(1)</script><rect width="2" height="2"/></svg>'),
});

export const text = (name) => ({ name, mimeType: 'text/plain', buffer: Buffer.from('not an image') });
