const orderService = require('../services/orderService');
const bus = require('../events/bus');

function list(req, res) {
  res.json(orderService.list());
}

function show(req, res) {
  res.json(orderService.find(Number(req.params.id)));
}

function create(req, res) {
  const order = orderService.create(req.body.customer);
  bus.emit('order.created', order);
  res.status(201).json(order);
}

function remove(req, res) {
  orderService.remove(Number(req.params.id));
  res.status(204).end();
}

module.exports = { list, show, create, remove };
