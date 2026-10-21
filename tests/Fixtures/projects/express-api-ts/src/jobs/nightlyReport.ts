import cron from 'node-cron';
import { list } from '../services/orderService.ts';

cron.schedule('0 2 * * *', () => {
  console.log(list().length, 'orders');
});
