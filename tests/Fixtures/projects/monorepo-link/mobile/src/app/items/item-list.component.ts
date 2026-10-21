import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ItemService } from './item.service';

@Component({
  selector: 'app-item-list',
  imports: [AsyncPipe, RouterLink],
  template: `
    @for (item of items$ | async; track item.id) {
      <a [routerLink]="['/items', item.id]">{{ item.label }}</a>
    }
  `,
})
export class ItemListComponent {
  readonly items$ = inject(ItemService).list();
}
