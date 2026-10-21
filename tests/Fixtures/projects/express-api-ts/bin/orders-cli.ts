#!/usr/bin/env node
import { list } from '../src/services/orderService.ts';

console.log(JSON.stringify(list()));
