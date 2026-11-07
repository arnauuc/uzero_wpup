import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { mkdtemp, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { validInput } from './testing.js';
import { pageUrl, verify } from './verify.js';

// A local site: the tests never leave the container.
const pages: Record<string, [number, string]> = {
  '/': [200, '<p>ok</p><img src="/missing.png"><script>console.error("boom")</script>'],
  '/broken': [500, 'fatal'],
  '/checkout': [200, '<script>fetch("/?wc-ajax=checkout", { method: "POST" })</script>'],
};
let server: Server;
let base: string;
const hits: string[] = [];

beforeAll(async () => {
  server = createServer((request, response) => {
    hits.push(request.url ?? '');
    const [status, body] = pages[request.url ?? ''] ?? [404, 'not found'];
    response.writeHead(status, { 'content-type': 'text/html' }).end(body);
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});
afterAll(() => new Promise<void>((resolve) => server.close(() => resolve())));

describe('verify', () => {
  it('records HTTP status, console and resource errors, a screenshot per page, and never lets the checkout through', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'wpup-verify-'));
    try {
      const output = await verify(
        validInput({
          site: { name: 'local', base_url: base },
          urls: [
            { path: '/', profile: 'home' },
            { path: '/broken', profile: 'page' },
            { path: '/checkout', profile: 'checkout' },
          ],
          output_dir: dir,
        }),
      );

      expect(output.verifier_errors).toEqual([]);
      const [home, broken, checkout] = output.results;
      expect(home).toMatchObject({ http_status: 200, screenshot: join('desktop', '0.png'), error: null });
      expect(home?.console_errors.some((message) => message.includes('boom'))).toBe(true);
      expect(home?.resource_errors.some((resource) => resource.status === 404 && resource.url.endsWith('/missing.png'))).toBe(true);
      expect(broken?.http_status).toBe(500);
      expect(checkout?.http_status).toBe(200);
      expect(hits.some((url) => url.includes('wc-ajax=checkout'))).toBe(false);
      expect((await stat(join(dir, 'desktop', '0.png'))).size).toBeGreaterThan(0);
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }, 60_000);
});

describe('pageUrl', () => {
  it('keeps the path of a site in a subdirectory', () => {
    expect(pageUrl('https://x.test/shop/', '/cart')).toBe('https://x.test/shop/cart');
  });
});
