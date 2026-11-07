import type { Page } from 'playwright';

/**
 * Banners that cover the page and change between visits (consent managers).
 * Hidden before every capture; the input can add more selectors
 * (`hide_selectors`). Masks are different: they paint a box over a region
 * that stays in the layout, these remove the banner from it.
 */
export const COOKIE_BANNER_SELECTORS: readonly string[] = [
  '#CybotCookiebotDialog',
  '#CybotCookiebotDialogBodyUnderlay',
  '#cookieyes',
  '.cky-consent-container',
  '.cky-overlay',
  '.cmplz-cookiebanner',
  '#cmplz-cookiebanner-container',
  '#cookie-law-info-bar',
  '#cookie-law-info-again',
  '.cli-modal-backdrop',
  '#BorlabsCookieBox',
  '#BorlabsCookieBoxWrap',
  '#onetrust-consent-sdk',
  '#onetrust-banner-sdk',
  '#cookie-notice',
  '#moove_gdpr_cookie_info_bar',
  '.moove-gdpr-cookie-info-bar',
  '#didomi-host',
  '#usercentrics-root',
  '#termly-code-snippet-support',
  '.cc-window',
  '.cc-banner',
  '.iubenda-cs-container',
  '#iubenda-cs-banner',
];

/** Defaults of the optional `capture` block of the input (contract.ts). */
export const CAPTURE_DEFAULTS = {
  /** Budget of the whole preparation of one page. */
  prepareMs: 30_000,
  /** Taller pages (infinite scroll) are cut here, so two captures stay comparable. */
  maxHeight: 20_000,
} as const;

export const CAPTURE_LIMITS = {
  networkIdleMs: 5000,
  fontsMs: 5000,
  imagesMs: 8000,
  /** No new request for this long, after the scroll, counts as quiet. */
  quietWindowMs: 500,
  quietMaxMs: 5000,
  /** Safety net for pages that keep growing. */
  maxScrollSteps: 100,
  /** Time kept after the preparation for the screenshot itself. */
  screenshotReserveMs: 1000,
} as const;

export interface CaptureOptions {
  hideSelectors?: readonly string[] | undefined;
  prepareMs?: number | undefined;
  maxHeight?: number | undefined;
}

export interface Prepared {
  /** Full height of the document, px (0 when it could not be measured). */
  height: number;
  /** Cut the screenshot here (the page is taller than max_height), else null. */
  clipHeight: number | null;
  warnings: string[];
}

/**
 * The single time rule of the verifier: a wait never lasts more than its own
 * limit nor more than what is left until `deadline` (epoch ms); never less than 1 ms.
 */
export function timeLeft(deadline: number, limit: number): number {
  return Math.max(1, Math.min(limit, deadline - Date.now()));
}

class PrepareTimeout extends Error {}

/**
 * page.evaluate can hang (a page that blocks its main thread): the wait is
 * bounded by `ms` and gives up with a PrepareTimeout. The evaluation itself
 * may still be running in the page; the context is closed afterwards.
 */
async function evalBounded<T>(page: Page, script: string, ms: number, what: string): Promise<T> {
  let timer: NodeJS.Timeout | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new PrepareTimeout(`${what} timed out after ${ms} ms`)), ms);
  });
  try {
    return await Promise.race([page.evaluate<T>(script), timeout]);
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Animations and transitions jump to their end state (duration ~0, one
 * iteration) instead of being removed, so content that appears through an
 * animation is visible. Scroll locks (overflow: hidden on html/body, common
 * behind consent overlays) are lifted so lazy loading and the full page work.
 */
export const STABILIZING_CSS = `
*, *::before, *::after {
  animation-duration: 0.001s !important;
  animation-delay: 0s !important;
  animation-iteration-count: 1 !important;
  transition-duration: 0s !important;
  transition-delay: 0s !important;
  scroll-behavior: auto !important;
  caret-color: transparent !important;
}
html, body { overflow: visible !important; }
`;

/**
 * Runs in the page. Every selector is validated on its own (querySelector in
 * try/catch) and gets its own <style>, so an invalid one is dropped with a
 * warning and cannot break the others. The page is not typed (no DOM lib in
 * this project): scripts that run in it are plain strings.
 */
export function injectScript(selectors: readonly string[]): string {
  return `((selectors, css) => {
  const warnings = [];
  const add = (text) => { const style = document.createElement('style'); style.textContent = text; (document.head || document.documentElement).appendChild(style); };
  add(css);
  for (const selector of selectors) {
    try { document.querySelector(selector); } catch { warnings.push('invalid selector ignored: ' + selector.slice(0, 80)); continue; }
    add(selector + ' { display: none !important; visibility: hidden !important; }');
  }
  return warnings;
})(${JSON.stringify(selectors)}, ${JSON.stringify(STABILIZING_CSS)})`;
}

const FORCE_LAZY_IMAGES = `(() => {
  for (const image of document.querySelectorAll('img[loading="lazy"], iframe[loading="lazy"]')) image.setAttribute('loading', 'eager');
  for (const image of document.querySelectorAll('img[data-src], img[data-lazy-src]')) {
    const source = image.getAttribute('data-src') || image.getAttribute('data-lazy-src');
    if (source && (!image.getAttribute('src') || image.getAttribute('src').startsWith('data:'))) image.setAttribute('src', source);
  }
  for (const image of document.querySelectorAll('img[data-srcset], img[data-lazy-srcset]')) {
    image.setAttribute('srcset', image.getAttribute('data-srcset') || image.getAttribute('data-lazy-srcset'));
  }
})()`;

const waitForImages = (ms: number): string => `(() => {
  const pending = [...document.images].filter((image) => !image.complete);
  const loaded = Promise.all(pending.map((image) => new Promise((resolve) => {
    image.addEventListener('load', resolve, { once: true });
    image.addEventListener('error', resolve, { once: true });
  })));
  return Promise.race([loaded, new Promise((resolve) => setTimeout(resolve, ${Math.floor(ms)}))]);
})()`;

const waitForFonts = (ms: number): string => `Promise.race([
  document.fonts.ready,
  new Promise((resolve) => setTimeout(resolve, ${Math.floor(ms)})),
])`;

/**
 * Scrolls down and back so IntersectionObserver-based lazy loaders fire. Stops
 * at the bottom, at maxHeight (an infinite scroll never ends), at maxSteps or
 * at the time limit. Resolves to the document height.
 */
const scrollThrough = (untilEpochMs: number, maxHeight: number, maxSteps: number): string => `(async () => {
  const height = () => Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);
  const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
  for (let i = 0; i < ${maxSteps} && Date.now() < ${untilEpochMs}; i++) {
    if (window.scrollY + window.innerHeight >= Math.min(height(), ${maxHeight})) break;
    window.scrollBy(0, step);
    await new Promise((resolve) => setTimeout(resolve, 60));
  }
  window.scrollTo(0, 0);
  return height();
})()`;

/** In-flight requests since the preparation began, to know when the page is really quiet. */
class Activity {
  private readonly inflight = new Set<unknown>();
  private last = Date.now();

  constructor(page: Page) {
    page.on('request', (request) => {
      this.inflight.add(request);
      this.last = Date.now();
    });
    const done = (request: unknown): void => {
      this.inflight.delete(request);
      this.last = Date.now();
    };
    page.on('requestfinished', done);
    page.on('requestfailed', done);
  }

  /** Waits until nothing is in flight and nothing started for `quietMs`, at most `ms`. */
  async quiet(quietMs: number, ms: number): Promise<void> {
    const until = Date.now() + ms;
    while (Date.now() < until && (this.inflight.size > 0 || Date.now() - this.last < quietMs)) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  }
}

/**
 * Gets the page into the same state every time before it is photographed:
 * fonts loaded, network quiet, no motion, no cookie banner, lazy images
 * loaded. Never throws and never makes the capture impossible: whatever fails
 * (a hang, a navigation in the middle, a selector) becomes a warning and the
 * page is captured as it is. A context destroyed by a navigation gets one more try.
 */
export async function preparePage(page: Page, deadline: number, options: CaptureOptions = {}): Promise<Prepared> {
  const prepared: Prepared = { height: 0, clipHeight: null, warnings: [] };
  const maxHeight = options.maxHeight ?? CAPTURE_DEFAULTS.maxHeight;
  // One budget for all of it, and the screenshot keeps its own reserve.
  const until = Math.min(deadline - CAPTURE_LIMITS.screenshotReserveMs, Date.now() + (options.prepareMs ?? CAPTURE_DEFAULTS.prepareMs));
  const activity = new Activity(page);
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      prepared.height = await prepare(page, until, activity, options.hideSelectors ?? [], maxHeight, prepared.warnings);
      break;
    } catch (error) {
      const message = error instanceof Error ? (error.message.split('\n')[0] ?? 'error') : 'error';
      if (attempt === 1 && /context was destroyed|navigat/i.test(message) && until > Date.now()) {
        await page.waitForLoadState('load', { timeout: timeLeft(until, CAPTURE_LIMITS.networkIdleMs) }).catch(() => undefined);
        continue;
      }
      prepared.warnings.push(`preparation incomplete, captured as is: ${message}`.slice(0, 300));
      break;
    }
  }
  if (prepared.height > maxHeight) {
    prepared.clipHeight = maxHeight;
    prepared.warnings.push(`page is ${prepared.height} px tall: capture cut at ${maxHeight} px`);
  }

  return prepared;
}

async function prepare(
  page: Page,
  until: number,
  activity: Activity,
  hideSelectors: readonly string[],
  maxHeight: number,
  warnings: string[],
): Promise<number> {
  await settleNetwork(page, timeLeft(until, CAPTURE_LIMITS.networkIdleMs));
  const invalid = await evalBounded<string[]>(page, injectScript([...COOKIE_BANNER_SELECTORS, ...hideSelectors]), timeLeft(until, 5000), 'style injection');
  for (const warning of invalid) if (!warnings.includes(warning)) warnings.push(warning);
  await evalBounded(page, FORCE_LAZY_IMAGES, timeLeft(until, 5000), 'lazy images');
  const scrollMs = timeLeft(until, CAPTURE_LIMITS.imagesMs);
  const height = await evalBounded<number>(page, scrollThrough(Date.now() + scrollMs, maxHeight, CAPTURE_LIMITS.maxScrollSteps), scrollMs + 1000, 'scroll');
  await evalBounded(page, FORCE_LAZY_IMAGES, timeLeft(until, 5000), 'lazy images');
  const imagesMs = timeLeft(until, CAPTURE_LIMITS.imagesMs);
  const fontsMs = timeLeft(until, CAPTURE_LIMITS.fontsMs);
  await Promise.all([
    evalBounded(page, waitForImages(imagesMs), imagesMs + 1000, 'images'),
    evalBounded(page, waitForFonts(fontsMs), fontsMs + 1000, 'fonts'),
  ]);
  // What the scroll and the late images started has to finish too.
  await activity.quiet(CAPTURE_LIMITS.quietWindowMs, timeLeft(until, CAPTURE_LIMITS.quietMaxMs));

  return height;
}

async function settleNetwork(page: Page, timeout: number): Promise<void> {
  try {
    await page.waitForLoadState('networkidle', { timeout });
  } catch {
    // Never idle within the cap (long polling, analytics): not a failure of the site.
  }
}
