const bus = require('../events/bus');

bus.on('order.created', (order) => {
  console.log('warehouse notified of order', order.id);
});
