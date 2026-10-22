import { describe, expect, it } from 'vitest';
import { VERIFIER_PROTOCOL_VERSION, parseProtocolVersion } from './index.js';

describe('parseProtocolVersion', () => {
  it('accepts positive integers and numeric strings', () => {
    expect(parseProtocolVersion(1)).toBe(1);
    expect(parseProtocolVersion('2')).toBe(2);
  });

  it('rejects invalid values', () => {
    expect(parseProtocolVersion(0)).toBeNull();
    expect(parseProtocolVersion(1.5)).toBeNull();
    expect(parseProtocolVersion('v1')).toBeNull();
    expect(parseProtocolVersion(undefined)).toBeNull();
  });

  it('exposes the current protocol version', () => {
    expect(VERIFIER_PROTOCOL_VERSION).toBe(1);
  });
});
