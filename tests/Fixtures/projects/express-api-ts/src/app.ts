import express from 'express';
import { requireAuth } from './middleware/auth.ts';
import adminRouter from './routes/admin.ts';
import ordersRouter from './routes/orders.ts';
import './listeners/notifyWarehouse.ts';

const app = express();
app.use(express.json());
app.use('/orders', ordersRouter);
app.use('/admin', requireAuth, adminRouter);

app.get('/health', (req, res) => res.json({ status: 'ok' }));

export default app;
