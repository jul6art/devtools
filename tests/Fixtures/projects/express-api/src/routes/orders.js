const express = require('express');
const { requireAuth } = require('../middleware/auth');
const orderController = require('../controllers/orderController');

const router = express.Router();

router.get('/', orderController.list);
router.get('/:id', orderController.show);
router.post('/', requireAuth, orderController.create);

module.exports = router;
