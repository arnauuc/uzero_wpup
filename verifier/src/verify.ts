import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium, type Browser } from 'playwright';
import { LIMITS, PROTOCOL_VERSION, truncate, type PageResult, type VerifierInput, type VerifierOutput } from './contract.js';

/** Height of the window; screenshots are full page whatever it is. */
const VIEWPORT_HEIGHT = 900;

/**
 * Visits every URL in every viewport and records what the page did. Skeleton
 * (UZEROWPUP-64): stable screenshots, the diff and the checkout flow come later.
 */
export async function verify(input: VerifierInput): Promise<VerifierOutput> {
  const deadline = Date.now() + input.timeouts.total_ms;
  const results: PageResult[] = [];
  const verifierErrors: string[] = [];
  const browser = await chromium.launch({ headless: true });
  try {
    for (const viewport of input.viewports) {
      await mkdir(join(input.output_dir, viewport.name), { recursive: true, mode: 0o700 });
      for (const [index, url] of input.urls.entries()) {
        if (Date.now() >= deadline) {
          verifierErrors.push(`total timeout reached: ${url.path} (${viewport.name}) not visited`);
          continue;
        }
        results.push(await visit(browser, input, url, viewport, index));
      }
    }
  } finally {
    await browser.close();
  }

  return { version: PROTOCOL_VERSION, run_id: input.run_id, results, verifier_errors: verifierErrors.slice(0, LIMITS.issues) };
}

async function visit(
  browser: Browser,
  input: VerifierInput,
  url: VerifierInput['urls'][number],
  viewport: VerifierInput['viewports'][number],
  index: number,
): Promise<PageResult> {
  const result: PageResult = {
    path: url.path,
    profile: url.profile,
    viewport: viewport.name,
    http_status: null,
    screenshot: null,
    console_errors: [],
    resource_errors: [],
    timings: { navigation_ms: 0 },
    error: null,
  };
  // A fresh context per page: no cookies, storage or service workers carried over.
  const context = await browser.newContext({ viewport: { width: viewport.width, height: VIEWPORT_HEIGHT }, serviceWorkers: 'block' });
  try {
    // AGENTS.md rule 5: the order is never placed, whatever the page tries.
    await context.route(
      (target) => target.searchParams.get('wc-ajax') === 'checkout',
      (route) => route.abort('blockedbyclient'),
    );
    const page = await context.newPage();
    const consoleError = (text: string): void => {
      if (result.console_errors.length < LIMITS.consoleErrorsPerPage) result.console_errors.push(truncate(text));
    };
    page.on('console', (message) => {
      if (message.type() === 'error') consoleError(message.text());
    });
    page.on('pageerror', (error) => consoleError(error.message));
    page.on('response', (response) => {
      if (response.status() >= 400 && result.resource_errors.length < LIMITS.resourceErrorsPerPage) {
        result.resource_errors.push({ url: truncate(response.url()), status: response.status(), failure: null });
      }
    });
    page.on('requestfailed', (request) => {
      if (result.resource_errors.length < LIMITS.resourceErrorsPerPage) {
        result.resource_errors.push({ url: truncate(request.url()), status: null, failure: truncate(request.failure()?.errorText ?? 'failed') });
      }
    });

    const started = Date.now();
    const response = await page.goto(pageUrl(input.site.base_url, url.path), { waitUntil: 'load', timeout: input.timeouts.navigation_ms });
    result.timings.navigation_ms = Date.now() - started;
    result.http_status = response?.status() ?? null;

    const screenshot = join(viewport.name, `${index}.png`);
    await page.screenshot({ path: join(input.output_dir, screenshot), fullPage: true, mask: input.masks.map((selector) => page.locator(selector)) });
    result.screenshot = screenshot;
  } catch (error) {
    result.error = truncate(error instanceof Error ? (error.message.split('\n')[0] ?? 'error') : 'unknown error');
  } finally {
    await context.close();
  }

  return result;
}

/** base_url keeps its own path (a site in a subdirectory); the input schema forbids "//" paths. */
export function pageUrl(baseUrl: string, path: string): string {
  return baseUrl.replace(/\/+$/, '') + path;
}
