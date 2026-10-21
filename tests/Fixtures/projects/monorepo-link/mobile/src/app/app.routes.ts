import { Routes } from '@angular/router';
import { LoginComponent } from './auth/login.component';
import { ItemDetailComponent } from './items/item-detail.component';
import { ItemListComponent } from './items/item-list.component';

export const routes: Routes = [
  { path: '', component: ItemListComponent },
  { path: 'items/:id', component: ItemDetailComponent },
  { path: 'login', component: LoginComponent },
];
