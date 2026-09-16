const express = require('express');
const orderService = require('../services/orderService');

const router = express.Router();

router.get('/', (req, res) => {
  res.json(orderService.list());
});

router.post('/', (req, res) => {
  const order = orderService.create(req.body.customer, req.body.product);
  res.status(201).json(order);
});

module.exports = router;
