const orders = [];

function list() {
  return orders;
}

function find(id) {
  return orders.find((order) => order.id === id);
}

function create(customer) {
  const order = { id: orders.length + 1, customer };
  orders.push(order);
  return order;
}

function remove(id) {
  orders.splice(orders.findIndex((order) => order.id === id), 1);
}

module.exports = { list, find, create, remove };
