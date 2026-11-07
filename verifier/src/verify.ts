import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium, type Browser, type Request } from 'playwright';
import { preparePage } from './capture.js';
import { LIMITS, PROTOCOL_VERSION, truncate, type PageResult, type VerifierInput, type VerifierOutput } from './contract.js';

/** Height of the window; screenshots are full page whatever it is. */
const VIEWPORT_HEIGHT = 900;

/** Below this much time left a page is not started: it could not finish. */
const MIN_PAGE_MS = 1000;

/**
 * Visits every URL in every viewport and records what the page did. Every
 * navigation and screenshot is bounded by the time left until total_ms, so
 * the verifier stops by itself, before the orchestrator's hard timeout, and
 * still reports what it did (partial: true). Every page is prepared before its
 * screenshot (capture.ts, UZEROWPUP-65); the diff and the checkout flow come later.
 */
export async function verify(input: VerifierInput): Promise<VerifierOutput> {
  const deadline = Date.now() + input.timeouts.total_ms;
  const results: PageResult[] = [];
  const verifierErrors: string[] = [];
  let partial = false;
  const browser = await chromium.launch({ headless: true });
  // The orchestrator asks to stop with SIGTERM: close the browser, never leave it behind.
  const onTerm = (): void => {
    void browser.close().finally(() => process.exit(143));
  };
  process.once('SIGTERM', onTerm);
  try {
    for (const viewport of input.viewports) {
      await mkdir(join(input.output_dir, viewport.name), { recursive: true, mode: 0o700 });
      for (const [index, url] of input.urls.entries()) {
        if (deadline - Date.now() < MIN_PAGE_MS) {
          partial = true;
          verifierErrors.push(`total time (${input.timeouts.total_ms} ms) ran out: ${url.path} (${viewport.name}) not visited`);
          continue;
        }
        results.push(await visit(browser, input, url, viewport, index, deadline));
      }
    }
  } finally {
    process.removeListener('SIGTERM', onTerm);
    await browser.close();
  }

  return { version: PROTOCOL_VERSION, run_id: input.run_id, results, verifier_errors: verifierErrors.slice(0, LIMITS.issues), partial };
}

async function visit(
  browser: Browser,
  input: VerifierInput,
  url: VerifierInput['urls'][number],
  viewport: VerifierInput['viewports'][number],
  index: number,
  deadline: number,
): Promise<PageResult> {
  const result: PageResult = {
    path: url.path,
    profile: url.profile,
    viewport: viewport.name,
    http_status: null,
    screenshot: null,
    console_errors: [],
    resource_errors: [],
    blocked_requests: [],
    timings: { navigation_ms: 0 },
    error: null,
  };
  const left = (limit: number): number => Math.max(1, Math.min(limit, deadline - Date.now()));
  // A fresh context per page: no cookies, storage or service workers carried over.
  const context = await browser.newContext({ viewport: { width: viewport.width, height: VIEWPORT_HEIGHT }, serviceWorkers: 'block' });
  const blocked = new Set<Request>();
  try {
    // AGENTS.md rule 5: the order is never placed, whatever the page tries.
    await context.route(
      (target) => target.searchParams.get('wc-ajax') === 'checkout',
      (route) => {
        blocked.add(route.request());
        if (result.blocked_requests.length < LIMITS.blockedRequestsPerPage) result.blocked_requests.push(truncate(route.request().url()));
        return route.abort('blockedbyclient');
      },
    );
    const page = await context.newPage();
    // The page's own document is http_status, not a resource error.
    const isPage = (request: Request): boolean => request.isNavigationRequest() && request.frame() === page.mainFrame();
    const consoleError = (text: string): void => {
      if (result.console_errors.length < LIMITS.consoleErrorsPerPage) result.console_errors.push(truncate(text));
    };
    const resourceError = (request: Request, status: number | null, failure: string | null): void => {
      if (!isPage(request) && !blocked.has(request) && result.resource_errors.length < LIMITS.resourceErrorsPerPage) {
        result.resource_errors.push({ url: truncate(request.url()), status, failure: failure === null ? null : truncate(failure) });
      }
    };
    page.on('console', (message) => {
      if (message.type() === 'error') consoleError(message.text());
    });
    page.on('pageerror', (error) => consoleError(error.message));
    page.on('response', (response) => {
      if (response.status() >= 400) resourceError(response.request(), response.status(), null);
    });
    page.on('requestfailed', (request) => resourceError(request, null, request.failure()?.errorText ?? 'failed'));

    const started = Date.now();
    const response = await page.goto(pageUrl(input.site.base_url, url.path), { waitUntil: 'load', timeout: left(input.timeouts.navigation_ms) });
    result.timings.navigation_ms = Date.now() - started;
    result.http_status = response?.status() ?? null;

    await preparePage(page, deadline, input.hide_selectors);
    const screenshot = join(viewport.name, `${index}.png`);
    await page.screenshot({
      path: join(input.output_dir, screenshot),
      fullPage: true,
      animations: 'disabled',
      caret: 'hide',
      timeout: left(input.timeouts.screenshot_ms),
      mask: input.masks.map((selector) => page.locator(selector)),
    });
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
