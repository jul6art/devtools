import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { OrderService } from './order.service';

@Component({
  selector: 'app-order-list',
  imports: [AsyncPipe, RouterLink],
  template: `
    @for (order of orders$ | async; track order.id) {
      <a [routerLink]="['/orders', order.id]">{{ order.customer }}</a>
    }
  `,
})
export class OrderListComponent {
  readonly orders$ = inject(OrderService).list();
}
