import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { EXIT, main } from './cli.js';
import { validInput } from './testing.js';

let dir: string;
let lines: string[];
const write = (line: string): void => {
  lines.push(line);
};

beforeEach(async () => {
  dir = await mkdtemp(join(tmpdir(), 'wpup-cli-'));
  lines = [];
});
afterEach(async () => {
  await rm(dir, { recursive: true, force: true });
});

describe('main', () => {
  it('writes the output document to result.json, readable only by its owner', async () => {
    const code = await main(JSON.stringify(validInput({ output_dir: dir })), write, (input) =>
      Promise.resolve({ version: 1, run_id: input.run_id, results: [], verifier_errors: [] }),
    );
    expect(code).toBe(EXIT.ok);
    expect(lines).toEqual([]);
    expect(JSON.parse(await readFile(join(dir, 'result.json'), 'utf8'))).toEqual({ version: 1, run_id: 7, results: [], verifier_errors: [] });
    expect((await stat(join(dir, 'result.json'))).mode & 0o777).toBe(0o600);
  });

  it('exits 2 with a structured error on invalid input, without running anything', async () => {
    let ran = false;
    const code = await main('{"version":1}', write, () => {
      ran = true;
      return Promise.reject(new Error('unreachable'));
    });
    expect(code).toBe(EXIT.invalidInput);
    expect(ran).toBe(false);
    expect(lines).toHaveLength(1);
    expect(JSON.parse(lines[0] ?? '')).toMatchObject({ version: 1, error: { code: 'invalid_input' } });
  });

  it('exits 1 with one line and no stack trace when the run crashes', async () => {
    const code = await main(JSON.stringify(validInput({ output_dir: dir })), write, () =>
      Promise.reject(new Error('browser crashed\n    at launch (/app/node_modules/playwright/x.js:1:1)')),
    );
    expect(code).toBe(EXIT.internal);
    expect(lines).toEqual([JSON.stringify({ version: 1, error: { code: 'internal', message: 'browser crashed', issues: [] } })]);
  });

  it('refuses an oversized input', async () => {
    expect(await main(' '.repeat(1024 * 1024 + 1), write, () => Promise.reject(new Error('unreachable')))).toBe(EXIT.invalidInput);
  });
});
