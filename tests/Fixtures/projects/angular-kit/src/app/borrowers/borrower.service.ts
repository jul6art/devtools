import { Injectable } from '@angular/core';
import { ApiClient } from '@jul6art/web-kit';

@Injectable({ providedIn: 'root' })
export class BorrowerService {
  constructor(private readonly client: ApiClient) {}

  rename(id: number, name: string) {
    return this.client.update(`/api/borrowers/${id}`, { name });
  }
}
