function requireAuth(req, res, next) {
  return req.headers.authorization ? next() : res.status(401).end();
}

function requireAdmin(req, res, next) {
  return req.headers['x-role'] === 'admin' ? next() : res.status(403).end();
}

module.exports = { requireAuth, requireAdmin };
