import { rename, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { failure, parseInput, truncate, type VerifierInput, type VerifierOutput } from './contract.js';

/** Exit codes, part of the protocol (docs/verificador.md). */
export const EXIT = { ok: 0, internal: 1, invalidInput: 2 } as const;

export const RESULT_FILE = 'result.json';

/** Upper bound for the input document read from stdin. */
export const MAX_INPUT_BYTES = 1024 * 1024;

/**
 * Reads the input, validates it and writes the output document to
 * <output_dir>/result.json (0600, renamed into place so it is never seen half
 * written). Errors go to stdout as one JSON line; never a stack trace.
 */
export async function main(
  raw: string,
  write: (line: string) => void,
  run: (input: VerifierInput) => Promise<VerifierOutput>,
): Promise<number> {
  if (Buffer.byteLength(raw) > MAX_INPUT_BYTES) {
    write(JSON.stringify(failure('invalid_input', `input is larger than ${MAX_INPUT_BYTES} bytes`)));
    return EXIT.invalidInput;
  }
  const parsed = parseInput(raw);
  if (!parsed.ok) {
    write(JSON.stringify(parsed.failure));
    return EXIT.invalidInput;
  }

  try {
    const output = await run(parsed.input);
    const target = join(parsed.input.output_dir, RESULT_FILE);
    await writeFile(`${target}.tmp`, JSON.stringify(output), { mode: 0o600 });
    await rename(`${target}.tmp`, target);
  } catch (error) {
    const message = error instanceof Error ? (error.message.split('\n')[0] ?? '') : '';
    write(JSON.stringify(failure('internal', truncate(message || 'unknown error'))));
    return EXIT.internal;
  }

  return EXIT.ok;
}
