import { z } from 'zod';

/**
 * Version 1 of the JSON protocol between the orchestrator and the verifier
 * (docs/verificador.md). The input is untrusted until it parses; any change
 * that is not backwards compatible bumps PROTOCOL_VERSION.
 */
export const PROTOCOL_VERSION = 1;

/** Caps that keep the output document small whatever the site does. */
export const LIMITS = {
  urls: 50,
  viewports: 4,
  masks: 50,
  hideSelectors: 50,
  consoleErrorsPerPage: 50,
  resourceErrorsPerPage: 100,
  blockedRequestsPerPage: 20,
  messageChars: 500,
  issues: 20,
} as const;

/**
 * A CSS selector that goes into a style rule: no braces, comments or at-rules
 * (they could close the rule), balanced quotes, brackets and parentheses.
 * The verifier also validates each one in the page and drops the invalid ones.
 */
export function isSafeSelector(value: string): boolean {
  if (/[{}@;]|\/\*|\*\//.test(value) || [...value].some((char) => char.charCodeAt(0) < 32)) return false;
  const closing: Record<string, string> = { '(': ')', '[': ']' };
  const stack: string[] = [];
  let quote: string | null = null;
  for (let i = 0; i < value.length; i++) {
    const char = value.charAt(i);
    if (char === '\\') {
      i++;
    } else if (quote !== null) {
      if (char === quote) quote = null;
    } else if (char === '"' || char === "'") {
      quote = char;
    } else if (char in closing) {
      stack.push(closing[char] ?? '');
    } else if (char === ')' || char === ']') {
      if (stack.pop() !== char) return false;
    }
  }

  return quote === null && stack.length === 0;
}

const selector = z.string().min(1).max(500).refine(isSafeSelector, 'unsafe CSS selector');

const httpUrl = z.url({ protocol: /^https?$/ });

export const InputSchema = z.strictObject({
  version: z.literal(PROTOCOL_VERSION),
  run_id: z.int().positive(),
  site: z.strictObject({
    name: z.string().min(1).max(200),
    base_url: httpUrl,
  }),
  urls: z
    .array(
      z.strictObject({
        // Relative to base_url: the verifier only ever visits the site's own public pages.
        path: z.string().regex(/^\/(?!\/)[^\s\\]*$/).max(2000),
        profile: z.string().regex(/^[a-z][a-z0-9_]*$/).max(50),
      }),
    )
    .min(1)
    .max(LIMITS.urls),
  viewports: z
    .array(
      z.strictObject({
        name: z.string().regex(/^[a-z][a-z0-9_-]*$/).max(30),
        width: z.int().min(320).max(3840),
      }),
    )
    .min(1)
    .max(LIMITS.viewports),
  masks: z.array(z.string().min(1).max(500)).max(LIMITS.masks),
  // Optional (additive, protocol stays at 1): CSS selectors of extra elements to hide, on top of the known cookie banners.
  hide_selectors: z.array(selector).max(LIMITS.hideSelectors).optional(),
  // Optional too: budget of the preparation of each page and the height where taller pages are cut.
  capture: z
    .strictObject({
      prepare_ms: z.int().min(1000).max(120_000).optional(),
      max_height: z.int().min(1000).max(100_000).optional(),
    })
    .optional(),
  thresholds: z.strictObject({ max_diff_ratio: z.number().min(0).max(1) }),
  output_dir: z.string().regex(/^\//, 'must be an absolute path').max(1000),
  timeouts: z.strictObject({
    navigation_ms: z.int().min(1000).max(300_000),
    screenshot_ms: z.int().min(1000).max(300_000),
    total_ms: z.int().min(1000).max(3_600_000),
  }),
});

export type VerifierInput = z.infer<typeof InputSchema>;

export interface ResourceError {
  url: string;
  status: number | null;
  failure: string | null;
}

export interface PageResult {
  path: string;
  profile: string;
  viewport: string;
  http_status: number | null;
  /** Relative to output_dir; null when the page could not be captured. */
  screenshot: string | null;
  console_errors: string[];
  /** Resources other than the page itself that answered 4xx/5xx or did not load. */
  resource_errors: ResourceError[];
  /** Requests the verifier blocked on purpose (the checkout order): not errors. */
  blocked_requests: string[];
  timings: { navigation_ms: number };
  /** Preparation problems of the verifier (a hang, an invalid selector, a page cut at max_height): the page was captured anyway. */
  warnings: string[];
  error: string | null;
}

export interface VerifierOutput {
  version: typeof PROTOCOL_VERSION;
  run_id: number;
  results: PageResult[];
  /** Problems of the verifier itself (not of the site): pages skipped, browser crashes… */
  verifier_errors: string[];
  /** True when the time ran out (or the run was stopped) before every url × viewport was visited. */
  partial: boolean;
}

/** Written to stdout (one line) when the verifier cannot produce an output document. */
export interface VerifierFailure {
  version: typeof PROTOCOL_VERSION;
  error: { code: 'invalid_input' | 'internal'; message: string; issues: string[] };
}

export function truncate(text: string, max: number = LIMITS.messageChars): string {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

/**
 * Parses untrusted input. Issues name the field and the rule, never the value,
 * so a malformed document does not end up copied into the orchestrator's logs.
 */
export function parseInput(raw: string): { ok: true; input: VerifierInput } | { ok: false; failure: VerifierFailure } {
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return { ok: false, failure: failure('invalid_input', 'input is not valid JSON') };
  }
  const parsed = InputSchema.safeParse(data);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .slice(0, LIMITS.issues)
      .map((issue) => truncate(`${issue.path.join('.') || '(root)'}: ${issue.code}`, 200));

    return { ok: false, failure: failure('invalid_input', 'input does not match protocol version 1', issues) };
  }

  return { ok: true, input: parsed.data };
}

export function failure(code: VerifierFailure['error']['code'], message: string, issues: string[] = []): VerifierFailure {
  return { version: PROTOCOL_VERSION, error: { code, message: truncate(message), issues } };
}
