// Entry point: `node dist/bin.js < input.json` (docs/verificador.md).
import { text } from 'node:stream/consumers';
import { main } from './cli.js';
import { verify } from './verify.js';

process.exitCode = await main(await text(process.stdin), (line) => process.stdout.write(`${line}\n`), verify);
