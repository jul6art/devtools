import { Component, input } from '@angular/core';

@Component({
  selector: 'app-order-status-badge',
  template: `<span class="badge">{{ status() }}</span>`,
  styleUrl: './order-status-badge.component.css',
})
export class OrderStatusBadgeComponent {
  readonly status = input.required<string>();
}
