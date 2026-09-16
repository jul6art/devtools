import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';

export interface Order {
  id: number;
  customer: string;
}

@Injectable({ providedIn: 'root' })
export class OrderService {
  private readonly http = inject(HttpClient);

  list() {
    return this.http.get<Order[]>('/api/orders');
  }

  get(id: number) {
    return this.http.get<Order>(`/api/orders/${id}`);
  }
}
