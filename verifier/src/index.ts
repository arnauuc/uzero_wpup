/** Version of the JSON protocol spoken between the orchestrator and the verifier. */
export const VERIFIER_PROTOCOL_VERSION = 1;

/**
 * Parses a protocol version from untrusted input.
 * Returns the version as a positive integer, or null if the value is not valid.
 */
export function parseProtocolVersion(value: unknown): number | null {
  if (typeof value === 'number' && Number.isInteger(value) && value > 0) {
    return value;
  }
  if (typeof value === 'string' && /^[1-9]\d*$/.test(value)) {
    return Number(value);
  }
  return null;
}
