import express from 'express';
import { requireAuth } from '../middleware/auth.ts';
import * as orderController from '../controllers/orderController.ts';

const router = express.Router();

router.get('/', orderController.list);
router.get('/:id', orderController.show);
router.post('/', requireAuth, orderController.create);

export default router;
