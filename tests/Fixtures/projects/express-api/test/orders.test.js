const { test } = require('node:test');
const assert = require('node:assert');
const app = require('../src/app');

test('lists orders', async () => {
  const server = app.listen(0);
  const response = await fetch(new URL('/orders', `http://127.0.0.1:${server.address().port}`));
  assert.strictEqual(response.status, 200);
  server.close();
});
