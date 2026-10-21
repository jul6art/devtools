const { Router } = require('express');
const { requireAdmin } = require('../middleware/auth');
const orderController = require('../controllers/orderController');

const router = Router();

router.delete('/orders/:id', requireAdmin, orderController.remove);

module.exports = router;
