import { test } from 'node:test';
import assert from 'node:assert';
import app from '../src/app.ts';

test('lists orders', async () => {
  const server = app.listen(0);
  const address = server.address();
  const response = await fetch(new URL('/orders', `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`));
  assert.strictEqual(response.status, 200);
  server.close();
});
