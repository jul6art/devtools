export interface Order {
  id: number;
  customer: string;
}

const orders: Order[] = [];

export function list(): Order[] {
  return orders;
}

export function find(id: number): Order | undefined {
  return orders.find((order) => order.id === id);
}

export function create(customer: string): Order {
  const order = { id: orders.length + 1, customer };
  orders.push(order);
  return order;
}

export function remove(id: number): void {
  orders.splice(orders.findIndex((order) => order.id === id), 1);
}
