import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import assert from 'node:assert/strict';

// Pass the verifier source/build of the Node package being compared; no platform or live API is called.
if (!process.argv[2]) throw new Error('Usage: node check-node.mjs <Node package verification entry>');
const {verifyCollect} = await import(pathToFileURL(resolve(process.argv[2])).href);
const vectors = JSON.parse(readFileSync(new URL('./collect-vectors.json', import.meta.url), 'utf8'));
for (const item of vectors.cases) {
  const policy = {enabled: true, environment: item.environment || 'production', endpointUrl: 'https://hideguard.io',
    publicKey: 'fixture', signaturePublicKey: item.publicKey || vectors.publicKey, revision: 'fixture',
    blockEpoch: 'fixture', recheckSeconds: 300, blockSeconds: 900, verificationSeconds: 35};
  let accepted = false;
  try { await verifyCollect(item.payload, policy, item.now * 1000); accepted = true; } catch {}
  assert.equal(accepted, item.accepted, item.name);
}
console.log(`PASS: ${vectors.cases.length} shared Collect signature cases on Node; no live API.`);
