import { Routes } from '@angular/router';
import { authGuard } from '@app/auth/auth.guard';
import { HomeComponent } from './home/home.component';
import { OrderDetailComponent } from './orders/order-detail.component';
import { OrderListComponent } from './orders/order-list.component';

// Chosen at build time: the router cannot be read for it.
const reports = './reports/reports.component';

export const routes: Routes = [
  { path: '', component: HomeComponent },
  {
    path: 'orders',
    canActivate: [authGuard],
    children: [
      { path: '', component: OrderListComponent },
      { path: ':id', component: OrderDetailComponent },
    ],
  },
  { path: 'admin', canMatch: [authGuard], loadChildren: () => import('./admin/admin.routes').then((m) => m.ADMIN_ROUTES) },
  { path: 'reports', loadComponent: () => import(reports).then((m) => m.ReportsComponent) },
];
