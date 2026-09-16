const orders = [];

function list() {
  return orders;
}

function create(customer, product) {
  const order = { id: orders.length + 1, customer, product };
  orders.push(order);
  return order;
}

module.exports = { list, create };
