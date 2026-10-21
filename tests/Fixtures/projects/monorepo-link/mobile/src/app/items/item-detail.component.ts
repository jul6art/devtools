import { Component, inject } from '@angular/core';
import { AsyncPipe } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { Item, ItemService } from './item.service';

@Component({
  selector: 'app-item-detail',
  imports: [AsyncPipe],
  template: `
    @if (item$ | async; as item) {
      <p>{{ item.label }}</p>
      <button (click)="save(item)">Save</button>
      <button (click)="remove(item.id)">Delete</button>
    }
  `,
})
export class ItemDetailComponent {
  private readonly items = inject(ItemService);
  private readonly router = inject(Router);
  readonly item$ = this.items.get(Number(inject(ActivatedRoute).snapshot.paramMap.get('id')));

  save(item: Item): void {
    this.items.save(item).subscribe();
  }

  remove(id: number): void {
    this.items.remove(id).subscribe(() => this.router.navigate(['/']));
  }
}
