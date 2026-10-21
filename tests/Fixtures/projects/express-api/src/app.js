const express = require('express');
const { requireAuth } = require('./middleware/auth');
const adminRouter = require('./routes/admin');
require('./listeners/notifyWarehouse');

const app = express();
app.use(express.json());
app.use('/orders', require('./routes/orders'));
app.use('/admin', requireAuth, adminRouter);

app.get('/health', (req, res) => res.json({ status: 'ok' }));

module.exports = app;
