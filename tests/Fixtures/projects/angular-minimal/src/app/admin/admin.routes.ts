import { Routes } from '@angular/router';
import { UserListComponent } from './user-list.component';

export const ADMIN_ROUTES: Routes = [
  { path: 'users', component: UserListComponent },
];
