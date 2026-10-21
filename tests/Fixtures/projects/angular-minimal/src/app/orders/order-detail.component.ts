import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { OrderStatusBadgeComponent } from '@app/shared/order-status-badge.component';
import { OrderService } from './order.service';

@Component({
  selector: 'app-order-detail',
  imports: [AsyncPipe, OrderStatusBadgeComponent],
  template: `
    @if (order$ | async; as order) {
      <p>{{ order.customer }}</p>
      <app-order-status-badge [status]="order.status"/>
      <button (click)="cancel(order.id)">Cancel</button>
    }
  `,
})
export class OrderDetailComponent {
  private readonly orders = inject(OrderService);
  private readonly router = inject(Router);
  readonly order$ = this.orders.get(Number(inject(ActivatedRoute).snapshot.paramMap.get('id')));

  cancel(id: number): void {
    this.orders.cancel(id).subscribe(() => this.router.navigate(['/orders']));
  }
}
