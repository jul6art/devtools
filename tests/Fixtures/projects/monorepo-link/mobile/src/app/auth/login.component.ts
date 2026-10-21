import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from './auth.service';

@Component({
  selector: 'app-login',
  template: `<button (click)="login()">Log in</button>`,
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  login(): void {
    this.auth.login('demo', 'demo').subscribe(() => this.router.navigate(['/']));
  }
}
