#!/usr/bin/env node
const orderService = require('../src/services/orderService');

console.log(JSON.stringify(orderService.list()));
