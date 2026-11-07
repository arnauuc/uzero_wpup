import { describe, expect, it } from 'vitest';
import { parseInput } from './contract.js';
import { validInput } from './testing.js';

describe('parseInput', () => {
  it('accepts a valid version 1 document', () => {
    const parsed = parseInput(JSON.stringify(validInput()));
    expect(parsed.ok).toBe(true);
  });

  it.each([
    ['another version', { version: 2 }],
    ['an unknown field', { extra: true }],
    ['a non-HTTP base URL', { site: { name: 'x', base_url: 'file:///etc/passwd' } }],
    ['a protocol-relative path', { urls: [{ path: '//evil.test/', profile: 'home' }] }],
    ['a relative output dir', { output_dir: 'out' }],
    ['no viewports', { viewports: [] }],
  ])('rejects %s', (_, overrides) => {
    const parsed = parseInput(JSON.stringify({ ...validInput(), ...overrides }));
    expect(parsed.ok).toBe(false);
  });

  it('rejects invalid JSON with a structured error', () => {
    const parsed = parseInput('{not json');
    expect(parsed).toEqual({ ok: false, failure: { version: 1, error: { code: 'invalid_input', message: 'input is not valid JSON', issues: [] } } });
  });

  it('names the field but never echoes its value', () => {
    const parsed = parseInput(JSON.stringify({ ...validInput(), site: { name: 'x', base_url: 'ftp://user:s3cret-value@host' } }));
    expect(parsed.ok).toBe(false);
    const text = JSON.stringify(parsed);
    expect(text).toContain('site.base_url');
    expect(text).not.toContain('s3cret-value');
  });
});
