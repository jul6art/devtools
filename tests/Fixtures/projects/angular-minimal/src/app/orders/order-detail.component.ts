import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { OrderService } from './order.service';

@Component({
  selector: 'app-order-detail',
  imports: [AsyncPipe],
  template: `<p>{{ (order$ | async)?.customer }}</p>`,
})
export class OrderDetailComponent {
  readonly order$ = inject(OrderService).get(Number(inject(ActivatedRoute).snapshot.paramMap.get('id')));
}
