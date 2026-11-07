import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { readFile, mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inflateSync } from 'node:zlib';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { InputSchema, isSafeSelector, type VerifierOutput } from './contract.js';
import { validInput } from './testing.js';
import { verify } from './verify.js';

const FONT_FILE = '/usr/share/fonts/truetype/freefont/FreeSerifBold.ttf';
const IMAGE = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="300" height="200" fill="#c33"/><circle cx="150" cy="100" r="60" fill="#36c"/></svg>';

/**
 * A page that is different every time it is looked at, unless the verifier
 * stabilizes it: motion, a late web font, a lazy image below the fold, a late
 * cookie banner and a blinking caret.
 */
function testPage(options: { banner: boolean }): string {
  return `<!doctype html><meta charset="utf-8"><style>
  @font-face { font-family: Late; src: url('/late.ttf'); }
  body { margin: 0; font: 28px Late, serif; }
  .spin { width: 80px; height: 80px; background: #393; animation: spin 1s linear infinite; }
  .slide { height: 60px; background: #fa0; animation: slide 3s ease-in-out infinite alternate; }
  .fade { height: 60px; background: #09c; opacity: 0; animation: fade 1s forwards; transition: opacity 2s; }
  .space { height: 2400px; background: linear-gradient(#eee, #999); }
  @keyframes spin { to { transform: rotate(360deg); } }
  @keyframes slide { from { margin-left: 0; } to { margin-left: 300px; } }
  @keyframes fade { to { opacity: 1; } }
  #CybotCookiebotDialog { position: fixed; left: 0; right: 0; bottom: 0; height: 150px; background: #000; color: #fff; }
  .promo { height: 40px; background: #f0f; }
  </style>
  <p>Stable text, set in a font that arrives late.</p>
  <input autofocus value="caret">
  <div class="spin"></div><div class="slide"></div><div class="fade"></div><div class="promo"></div>
  <div class="space"></div>
  <img src="/lazy.svg" loading="lazy" width="300" height="200">
  <img data-src="/lazy.svg" width="300" height="200">
  ${options.banner ? '<script>setTimeout(() => document.body.insertAdjacentHTML("beforeend", \'<div id="CybotCookiebotDialog">We use cookies</div>\'), 400)</script>' : ''}`;
}

let server: Server;
let base: string;

beforeAll(async () => {
  // No fallback: if the font is missing the test must fail, not silently stop testing fonts.
  const font = await readFile(FONT_FILE);
  server = createServer((request, response) => {
    const send = (type: string, body: string | Buffer, delay = 0): void => {
      setTimeout(() => response.writeHead(200, { 'content-type': type }).end(body), delay);
    };
    switch (request.url) {
      case '/late.ttf':
        return send('font/ttf', font, 500);
      case '/lazy.svg':
        return send('image/svg+xml', IMAGE, 300);
      case '/banner':
        return send('text/html', testPage({ banner: true }));
      case '/clean':
        return send('text/html', testPage({ banner: false }));
      case '/csp-banner':
      case '/csp-clean':
        // Inline styles are forbidden: only a context that bypasses the CSP can inject the neutral CSS.
        response.writeHead(200, { 'content-type': 'text/html', 'content-security-policy': "default-src 'self'; style-src 'self'" });
        return void response.end(`<!doctype html><link rel="stylesheet" href="/csp.css"><p>csp page</p>${request.url === '/csp-banner' ? '<div id="CybotCookiebotDialog">cookies</div>' : ''}`);
      case '/csp.css':
        return send('text/css', '#CybotCookiebotDialog { position: fixed; left: 0; right: 0; bottom: 0; height: 200px; background: #000; }');
      case '/locked':
        return send('text/html', '<!doctype html><html style="overflow:hidden"><body style="margin:0;overflow:hidden;height:100vh"><div style="height:3000px;background:#69c"></div>');
      case '/infinite':
        return send(
          'text/html',
          `<!doctype html><body style="margin:0"><div style="height:1200px;background:#cde"></div><script>
          addEventListener('scroll', () => { if (scrollY + innerHeight > document.documentElement.scrollHeight - 300) document.body.insertAdjacentHTML('beforeend', '<div style="height:1000px;background:#cde;border-top:4px solid #135"></div>'); });
          </script>`,
        );
      case '/hang':
        return send('text/html', '<!doctype html><p>hang</p><script>setTimeout(() => { const t = Date.now(); while (Date.now() - t < 8000); }, 300)</script>');
      default:
        response.writeHead(404).end();
    }
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});
afterAll(() => new Promise<void>((resolve) => server.close(() => resolve())));

interface Raster {
  width: number;
  height: number;
  /** RGBA, 4 bytes per pixel. */
  data: Uint8Array;
}

/** Minimal PNG decoder for what Chromium writes (8 bit, not interlaced): enough to compare pixels without a dependency. */
function decodePng(file: Buffer): Raster {
  const width = file.readUInt32BE(16);
  const height = file.readUInt32BE(20);
  const channels = ({ 2: 3, 6: 4 } as Record<number, number>)[file[25] ?? 0];
  if (file[24] !== 8 || file[28] !== 0 || channels === undefined) throw new Error('unsupported PNG');
  const parts: Buffer[] = [];
  for (let offset = 8; offset < file.length; ) {
    const length = file.readUInt32BE(offset);
    if (file.toString('latin1', offset + 4, offset + 8) === 'IDAT') parts.push(file.subarray(offset + 8, offset + 8 + length));
    offset += 12 + length;
  }
  const raw = inflateSync(Buffer.concat(parts));
  const stride = width * channels;
  const data = new Uint8Array(width * height * 4).fill(255);
  let previous = new Uint8Array(stride);
  for (let y = 0; y < height; y++) {
    const filter = raw[y * (stride + 1)] ?? 0;
    const line = new Uint8Array(raw.subarray(y * (stride + 1) + 1, (y + 1) * (stride + 1)));
    for (let i = 0; i < stride; i++) {
      const a = i >= channels ? (line[i - channels] ?? 0) : 0;
      const b = previous[i] ?? 0;
      const c = i >= channels ? (previous[i - channels] ?? 0) : 0;
      const p = a + b - c;
      const pa = Math.abs(p - a);
      const pb = Math.abs(p - b);
      const pc = Math.abs(p - c);
      const predictor = [0, a, b, (a + b) >> 1, pa <= pb && pa <= pc ? a : pb <= pc ? b : c][filter] ?? 0;
      line[i] = ((line[i] ?? 0) + predictor) & 255;
    }
    for (let x = 0; x < width; x++) {
      for (let k = 0; k < channels; k++) data[(y * width + x) * 4 + k] = line[x * channels + k] ?? 0;
    }
    previous = line;
  }

  return { width, height, data };
}

/** Share of pixels that differ at all (exact comparison); 1 when the sizes differ. */
function diffRatio(a: Raster, b: Raster): number {
  if (a.width !== b.width || a.height !== b.height) return 1;
  let different = 0;
  for (let i = 0; i < a.data.length; i += 4) {
    if (a.data[i] !== b.data[i] || a.data[i + 1] !== b.data[i + 1] || a.data[i + 2] !== b.data[i + 2]) different++;
  }

  return different / (a.width * a.height);
}

async function capture(paths: string[], extra: Partial<Parameters<typeof validInput>[0]> = {}): Promise<{ rasters: Raster[][]; dir: string; output: VerifierOutput }> {
  const dir = await mkdtemp(join(tmpdir(), 'wpup-capture-'));
  const output = await verify(
    validInput({
      site: { name: 'local', base_url: base },
      urls: paths.map((path) => ({ path, profile: 'page' })),
      viewports: [
        { name: 'desktop', width: 1440 },
        { name: 'mobile', width: 390 },
      ],
      masks: [],
      output_dir: dir,
      timeouts: { navigation_ms: 30000, screenshot_ms: 30000, total_ms: 120000 },
      ...extra,
    }),
  );
  expect(output.verifier_errors).toEqual([]);
  expect(output.results.map((result) => result.error)).toEqual(paths.flatMap(() => [null, null]));
  const rasters: Raster[][] = [];
  for (const viewport of ['desktop', 'mobile']) {
    rasters.push(await Promise.all(paths.map(async (_, index) => decodePng(await readFile(join(dir, viewport, `${index}.png`))))));
  }

  return { rasters, dir, output };
}

describe('stable captures', () => {
  it('two captures of the same unchanged page are identical, in both viewports and full page', async () => {
    const { rasters, dir } = await capture(['/banner', '/banner']);
    try {
      const [desktop, mobile] = rasters;
      expect(desktop?.[0]?.width).toBe(1440);
      expect(mobile?.[0]?.width).toBe(390);
      // Full page: far taller than the 900 px window.
      expect(desktop?.[0]?.height).toBeGreaterThan(2500);
      for (const pair of rasters) expect(diffRatio(pair[0]!, pair[1]!)).toBeLessThan(0.0001);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);

  it('hides the cookie banner: the page with it looks like the page without it', async () => {
    const { rasters, dir } = await capture(['/banner', '/clean']);
    try {
      for (const pair of rasters) expect(diffRatio(pair[0]!, pair[1]!)).toBeLessThan(0.0001);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);

  it('loads the lazy images before the capture (the image is not a blank box)', async () => {
    const { rasters, dir } = await capture(['/clean']);
    try {
      const shot = rasters[0]?.[0];
      const bottom = shot ? shot.height - 20 : 0;
      // The loaded svg has a red area at its left edge, on the last rows of the page.
      const pixel = (x: number, y: number): number[] => Array.from(shot?.data.subarray((y * (shot?.width ?? 0) + x) * 4, (y * (shot?.width ?? 0) + x) * 4 + 3) ?? []);
      expect(pixel(10, bottom)).toEqual([0xcc, 0x33, 0x33]);
      expect(pixel(310, bottom)).toEqual([0xcc, 0x33, 0x33]);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);
});

describe('robust preparation', () => {
  it('captures a page behind a strict Content-Security-Policy (the banner is still hidden)', async () => {
    const { rasters, dir } = await capture(['/csp-banner', '/csp-clean']);
    try {
      for (const pair of rasters) expect(diffRatio(pair[0]!, pair[1]!)).toBeLessThan(0.0001);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);

  it('drops an invalid selector with a warning and still applies the others', async () => {
    const extra = { hide_selectors: ['.promo', 'div:not-a-real-pseudo(1)'] };
    const { rasters, dir, output } = await capture(['/banner', '/clean'], extra);
    try {
      expect(output.results[0]?.warnings.some((warning) => warning.includes('invalid selector ignored'))).toBe(true);
      for (const pair of rasters) expect(diffRatio(pair[0]!, pair[1]!)).toBeLessThan(0.0001);
      // .promo (40 px, magenta) is gone from both: the page is shorter than a plain /clean capture would be.
      const plain = await capture(['/clean']);
      try {
        expect(rasters[0]?.[1]?.height).toBeLessThan(plain.rasters[0]?.[0]?.height ?? 0);
      } finally {
        await rm(plain.dir, { recursive: true, force: true });
      }
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);

  it('lifts a scroll lock so the full page is captured', async () => {
    const { rasters, dir } = await capture(['/locked']);
    try {
      expect(rasters[0]?.[0]?.height).toBeGreaterThan(2500);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);

  it('cuts an infinite scroll at max_height so two captures are comparable', async () => {
    const { rasters, dir, output } = await capture(['/infinite', '/infinite'], { capture: { max_height: 3000 } });
    try {
      for (const pair of rasters) {
        expect(pair[0]?.height).toBe(3000);
        expect(diffRatio(pair[0]!, pair[1]!)).toBeLessThan(0.0001);
      }
      expect(output.results[0]?.warnings.some((warning) => warning.includes('cut at 3000'))).toBe(true);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 120_000);
});

describe('a page that hangs', () => {
  it('is reported with a warning and an error, and the run still finishes with a result', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'wpup-capture-'));
    try {
      const started = Date.now();
      const output = await verify(
        validInput({
          site: { name: 'local', base_url: base },
          urls: [{ path: '/hang', profile: 'page' }],
          viewports: [{ name: 'desktop', width: 1440 }],
          masks: [],
          output_dir: dir,
          capture: { prepare_ms: 2000 },
          timeouts: { navigation_ms: 30000, screenshot_ms: 2000, total_ms: 30000 },
        }),
      );
      expect(Date.now() - started).toBeLessThan(20_000);
      expect(output.results).toHaveLength(1);
      expect(output.results[0]?.warnings.some((warning) => warning.includes('captured as is'))).toBe(true);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 60_000);
});

describe('contract: hide_selectors and capture', () => {
  it('are optional (protocol version 1 is unchanged) and bounded', () => {
    expect(InputSchema.safeParse(validInput()).success).toBe(true);
    expect(InputSchema.safeParse(validInput({ hide_selectors: ['.promo'], capture: { prepare_ms: 5000, max_height: 8000 } })).success).toBe(true);
    expect(InputSchema.safeParse(validInput({ hide_selectors: Array.from({ length: 51 }, () => '.x') })).success).toBe(false);
    expect(InputSchema.safeParse(validInput({ capture: { max_height: 10 } })).success).toBe(false);
  });

  it('rejects selectors that could leave their rule', () => {
    for (const bad of ['a { color: red', '.x /* c */', '[data-x="a]', '.a)', 'a, b }', '@import "x"', '.x(']) expect(isSafeSelector(bad)).toBe(false);
    for (const good of ['.promo > a', '[data-x="a)b"]', 'div:not(.a, .b)', '#id\\(x']) expect(isSafeSelector(good)).toBe(true);
  });
});
