import type { NextFunction, Request, Response } from 'express';

export function requireAuth(req: Request, res: Response, next: NextFunction): void {
  if (req.headers.authorization) {
    next();
  } else {
    res.status(401).end();
  }
}

export function requireAdmin(req: Request, res: Response, next: NextFunction): void {
  if (req.headers['x-role'] === 'admin') {
    next();
  } else {
    res.status(403).end();
  }
}
