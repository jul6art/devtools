import bus from '../events/bus.ts';

bus.on('order.created', (order: { id: number }) => {
  console.log('warehouse notified of order', order.id);
});
