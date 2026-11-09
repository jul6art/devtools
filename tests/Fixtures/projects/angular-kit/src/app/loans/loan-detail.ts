import { Component, inject, input } from '@angular/core';
import { ApiClient } from '@jul6art/web-kit';

interface Loan {
  item: string;
}

@Component({
  selector: 'app-loan-detail',
  template: `<p>{{ id() }}</p>`,
})
export class LoanDetail {
  readonly id = input.required<string>();
  private readonly api = inject(ApiClient);

  async load(): Promise<void> {
    const loan = await this.api.item<Loan>(`/api/loans/${encodeURIComponent(this.id())}`);
    // An IRI read in the answer: no call the analysis can name.
    await this.api.item(loan.item);
  }

  async giveBack(): Promise<void> {
    await this.api.post(`/api/loans/${encodeURIComponent(this.id())}/return`);
  }
}
