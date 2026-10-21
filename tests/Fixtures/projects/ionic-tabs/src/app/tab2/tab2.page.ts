import { Component } from '@angular/core';
import { IonContent, IonHeader, IonTitle, IonToolbar } from '@ionic/angular/standalone';

@Component({
  selector: 'app-tab2',
  imports: [IonHeader, IonToolbar, IonTitle, IonContent],
  template: `<ion-header><ion-toolbar><ion-title>Tab 2</ion-title></ion-toolbar></ion-header><ion-content/>`,
})
export class Tab2Page {}
