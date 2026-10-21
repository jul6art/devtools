import { Router } from 'express';
import { requireAdmin } from '../middleware/auth.ts';
import { remove } from '../controllers/orderController.ts';

const router = Router();

router.delete('/orders/:id', requireAdmin, remove);

export default router;
