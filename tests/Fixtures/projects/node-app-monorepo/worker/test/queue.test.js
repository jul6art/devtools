import test from 'node:test';
import { drain } from '../src/queue.js';

test('an empty queue drains to nothing', async () => {
  if ((await drain()).length !== 0) throw new Error('not empty');
});
