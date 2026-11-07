// Files for setInputFiles
const PNG_1x1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

export const png = (name) => ({ name, mimeType: 'image/png', buffer: Buffer.from(PNG_1x1, 'base64') });

export const svg = (name) => ({
  name,
  mimeType: 'image/svg+xml',
  buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><script>alert(1)</script><rect width="2" height="2"/></svg>'),
});

export const text = (name) => ({ name, mimeType: 'text/plain', buffer: Buffer.from('not an image') });

// A solid PNG of any size, built with zlib (no image library needed)
import { deflateSync } from 'node:zlib';

const crcTable = Array.from({ length: 256 }, (_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});
const crc32 = (buffer) => {
  let c = 0xffffffff;
  for (const byte of buffer) c = crcTable[(c ^ byte) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
};
const chunk = (type, data) => {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
};

export const bigPng = (name, width, height) => {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header.set([8, 2, 0, 0, 0], 8); // 8-bit RGB
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(width * 3, 0x40)]);
  const pixels = deflateSync(Buffer.concat(Array.from({ length: height }, () => row)));
  const buffer = Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', header), chunk('IDAT', pixels), chunk('IEND', Buffer.alloc(0)),
  ]);
  return { name, mimeType: 'image/png', buffer };
};

// A 4 × 4 GIF with two frames
export const animatedGif = (name) => {
  const frame = [0x21, 0xf9, 0x04, 0x00, 0x0a, 0x00, 0x00, 0x00, 0x2c, 0, 0, 0, 0, 1, 0, 1, 0, 0, 0x02, 0x02, 0x44, 0x01, 0x00];
  const bytes = [...Buffer.from('GIF89a', 'latin1'), 4, 0, 4, 0, 0x80, 0, 0, 0xff, 0xff, 0xff, 0, 0, 0, ...frame, ...frame, 0x3b];
  return { name, mimeType: 'image/gif', buffer: Buffer.from(bytes) };
};

export const pdf = (name) => ({ name, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\ntrailer << >>\n%%EOF\n') });

export const mp4 = (name) => {
  const ftyp = Buffer.concat([Buffer.from('mp42', 'latin1'), Buffer.alloc(4), Buffer.from('mp42isom', 'latin1')]);
  const size = Buffer.alloc(4);
  size.writeUInt32BE(8 + ftyp.length);
  return { name, mimeType: 'video/mp4', buffer: Buffer.concat([size, Buffer.from('ftyp', 'latin1'), ftyp, Buffer.from([0, 0, 0, 8]), Buffer.from('mdat', 'latin1')]) };
};
