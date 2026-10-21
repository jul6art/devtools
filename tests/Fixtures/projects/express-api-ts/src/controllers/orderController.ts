import type { Request, Response } from 'express';
import * as orderService from '../services/orderService.ts';
import bus from '../events/bus.ts';

export function list(req: Request, res: Response): void {
  res.json(orderService.list());
}

export function show(req: Request, res: Response): void {
  res.json(orderService.find(Number(req.params.id)));
}

export function create(req: Request, res: Response): void {
  const order = orderService.create(req.body.customer);
  bus.emit('order.created', order);
  res.status(201).json(order);
}

export function remove(req: Request, res: Response): void {
  orderService.remove(Number(req.params.id));
  res.status(204).end();
}
