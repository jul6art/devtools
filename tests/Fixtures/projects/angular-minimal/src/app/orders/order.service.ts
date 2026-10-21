import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { environment } from '@env';

export interface Order {
  id: number;
  customer: string;
  status: string;
}

@Injectable({ providedIn: 'root' })
export class OrderService {
  private readonly http = inject(HttpClient);

  list() {
    return this.http.get<Order[]>('/api/orders');
  }

  get(id: number) {
    return this.http.get<Order>(`${environment.apiUrl}/orders/${id}`);
  }

  cancel(id: number) {
    return this.http.post<Order>(`${environment.apiUrl}/orders/${id}/cancel`, {});
  }
}
