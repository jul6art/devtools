import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { OrderStatusBadgeComponent } from '@app/shared/order-status-badge.component';
import { OrderService } from './order.service';

@Component({
  selector: 'app-order-list',
  imports: [AsyncPipe, RouterLink, OrderStatusBadgeComponent],
  template: `
    @for (order of orders$ | async; track order.id) {
      <a [routerLink]="['/orders', order.id]">{{ order.customer }}</a>
      <app-order-status-badge [status]="order.status"/>
    }
  `,
})
export class OrderListComponent {
  readonly orders$ = inject(OrderService).list();
}
