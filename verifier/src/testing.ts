import type { VerifierInput } from './contract.js';

/** A valid version 1 input, for the tests. */
export function validInput(overrides: Partial<VerifierInput> = {}): VerifierInput {
  return {
    version: 1,
    run_id: 7,
    site: { name: 'botiga', base_url: 'https://botiga.test' },
    urls: [{ path: '/', profile: 'home' }],
    viewports: [{ name: 'desktop', width: 1440 }],
    masks: ['.slider'],
    thresholds: { max_diff_ratio: 0.01 },
    output_dir: '/tmp/wpup-verifier',
    timeouts: { navigation_ms: 30000, total_ms: 600000 },
    ...overrides,
  };
}
