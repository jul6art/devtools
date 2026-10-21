import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  login(username: string, password: string) {
    return this.http.post<{ token: string }>(`${environment.apiUrl}/login_check`, { username, password });
  }
}
