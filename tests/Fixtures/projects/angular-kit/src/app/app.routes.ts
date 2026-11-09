import { Routes } from '@angular/router';
import { LoanDetail } from './loans/loan-detail';

export const routes: Routes = [
  { path: 'loans', loadComponent: () => import('./loans/loan-list').then((m) => m.LoanList) },
  { path: 'loans/:id', component: LoanDetail },
];
