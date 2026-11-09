import { Component } from '@angular/core';
import { Table } from '@jul6art/web-kit';

/** The kit's table reads its rows itself: its path is the call. */
@Component({
  selector: 'app-loan-list',
  imports: [Table],
  template: `
    <h1>Loans</h1>
    <kit-table
      caption="loan.plural"
      path="/api/loans"
      [columns]="columns"
    />
  `,
})
export class LoanList {
  readonly columns = [];
}
