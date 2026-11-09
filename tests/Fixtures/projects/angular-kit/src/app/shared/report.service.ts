import { Injectable, inject } from '@angular/core';
import { ApiClient } from './api-client';

@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly api = inject(ApiClient);

  monthly() {
    return this.api.get('/api/reports');
  }
}
