import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { ApiClient as Client } from '@jul6art/web-kit';

/** Both clients in one service: one integration, its calls together. */
@Injectable({ providedIn: 'root' })
export class LoanService {
  private readonly http = inject(HttpClient);
  private readonly client = inject(Client);

  statistics() {
    return this.http.get('/api/stats');
  }

  lend(body: unknown) {
    return this.client.create('/api/loans', body);
  }
}
