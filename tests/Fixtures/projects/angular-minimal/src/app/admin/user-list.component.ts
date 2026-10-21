import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-user-list',
  imports: [RouterLink],
  template: `<h1>Users</h1><a routerLink="/orders">Orders</a>`,
})
export class UserListComponent {}
