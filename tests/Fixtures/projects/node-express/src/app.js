const express = require('express');
const ordersRouter = require('./routes/orders');

const app = express();
app.use(express.json());
app.use('/orders', ordersRouter);

app.get('/health', (req, res) => res.json({ status: 'ok' }));

module.exports = app;
