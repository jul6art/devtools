const request = require('supertest');
const app = require('../src/app');

test('lists orders', async () => {
  const response = await request(app).get('/orders');
  expect(response.status).toBe(200);
});
