import type { Page } from 'playwright';

/**
 * Banners that cover the page and change between visits (consent managers).
 * Hidden with CSS before every capture; the input can add more selectors
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

/** Time reserved for each wait; the remaining total time always wins. */
export const CAPTURE_LIMITS = {
  networkIdleMs: 5000,
  fontsMs: 5000,
  imagesMs: 8000,
  scrollMs: 8000,
  /** Safety net for infinite pages. */
  maxScrollSteps: 60,
} as const;

/**
 * Animations and transitions jump to their end state (duration ~0, one
 * iteration) instead of being removed, so content that appears through an
 * animation is visible. The cookie banners go in the same sheet.
 */
export function stabilizingCss(hideSelectors: readonly string[]): string {
  // One rule per selector: a selector the browser cannot parse invalidates only its own rule.
  const hidden = [...COOKIE_BANNER_SELECTORS, ...hideSelectors]
    .map((selector) => `${selector.replace(/[{}<>]/g, '')} { display: none !important; visibility: hidden !important; }`)
    .join('\n');

  return `
*, *::before, *::after {
  animation-duration: 0.001s !important;
  animation-delay: 0s !important;
  animation-iteration-count: 1 !important;
  transition-duration: 0s !important;
  transition-delay: 0s !important;
  scroll-behavior: auto !important;
  caret-color: transparent !important;
}
${hidden}
`;
}

// The page is not typed (no DOM lib in this project): the scripts that run in it are plain strings.
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

const WAIT_FOR_IMAGES = (timeoutMs: number): string => `(() => {
  const pending = [...document.images].filter((image) => !image.complete);
  const loaded = Promise.all(pending.map((image) => new Promise((resolve) => {
    image.addEventListener('load', resolve, { once: true });
    image.addEventListener('error', resolve, { once: true });
  })));
  return Promise.race([loaded, new Promise((resolve) => setTimeout(resolve, ${Math.max(1, Math.floor(timeoutMs))}))]);
})()`;

const WAIT_FOR_FONTS = (timeoutMs: number): string => `Promise.race([
  document.fonts.ready,
  new Promise((resolve) => setTimeout(resolve, ${Math.max(1, Math.floor(timeoutMs))})),
])`;

/** Scrolls down and back so IntersectionObserver-based lazy loaders fire. */
const SCROLL_THROUGH = (deadlineEpochMs: number, maxSteps: number): string => `(async () => {
  const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
  for (let i = 0; i < ${maxSteps} && Date.now() < ${deadlineEpochMs}; i++) {
    const before = window.scrollY;
    window.scrollBy(0, step);
    await new Promise((resolve) => setTimeout(resolve, 60));
    if (window.scrollY === before) break;
  }
  window.scrollTo(0, 0);
})()`;

/**
 * Gets the page into the same state every time before it is photographed:
 * fonts loaded, network quiet, no motion, no cookie banner, lazy images
 * loaded. Every wait is capped and clipped to the time left until `deadline`
 * (epoch ms). A page that never goes quiet (polling, trackers) is captured as
 * it is; the diff decides whether that matters.
 */
export async function preparePage(page: Page, deadline: number, hideSelectors: readonly string[] = []): Promise<void> {
  const left = (limit: number): number => Math.max(1, Math.min(limit, deadline - Date.now() - 500));

  await settleNetwork(page, left(CAPTURE_LIMITS.networkIdleMs));
  await page.addStyleTag({ content: stabilizingCss(hideSelectors) });
  await page.evaluate(FORCE_LAZY_IMAGES);
  await page.evaluate(SCROLL_THROUGH(Math.min(deadline, Date.now() + CAPTURE_LIMITS.scrollMs), CAPTURE_LIMITS.maxScrollSteps));
  await page.evaluate(FORCE_LAZY_IMAGES);
  await page.evaluate(WAIT_FOR_IMAGES(left(CAPTURE_LIMITS.imagesMs)));
  await page.evaluate(WAIT_FOR_FONTS(left(CAPTURE_LIMITS.fontsMs)));
  await settleNetwork(page, left(CAPTURE_LIMITS.networkIdleMs));
  await page.evaluate('window.scrollTo(0, 0)');
}

async function settleNetwork(page: Page, timeout: number): Promise<void> {
  try {
    await page.waitForLoadState('networkidle', { timeout });
  } catch {
    // Never idle within the cap (long polling, analytics): not a failure of the site.
  }
}
