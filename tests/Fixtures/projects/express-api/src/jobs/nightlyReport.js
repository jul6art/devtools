const cron = require('node-cron');
const orderService = require('../services/orderService');

cron.schedule('0 2 * * *', () => {
  console.log(orderService.list().length, 'orders');
});
