import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../environments/environment';

export interface Item {
  id: number;
  label: string;
}

@Injectable({ providedIn: 'root' })
export class ItemService {
  private readonly http = inject(HttpClient);

  list() {
    return this.http.get<Item[]>(`${environment.apiUrl}/items`);
  }

  get(id: number) {
    return this.http.get<Item>(`${environment.apiUrl}/items/${id}`);
  }

  save(item: Item) {
    return this.http.put<Item>(`${environment.apiUrl}/items/${item.id}`, item);
  }

  remove(id: number) {
    return this.http.delete<void>(`${environment.apiUrl}/items/${id}`);
  }
}
