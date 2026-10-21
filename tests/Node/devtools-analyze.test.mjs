// The Node bridge's own tests (ADR-0031), run by PHPUnit through `node --test` (NodeScriptTest).
//
// Each test builds a project in a temporary directory, with the TypeScript of the angular-minimal fixture
// copied into its node_modules: the script reads with the project's TypeScript, never one of its own.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cpSync, mkdirSync, mkdtempSync, rmSync, writeFileSync, realpathSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const script = path.join(here, '../../resources/node/devtools-analyze.mjs');
const typescript = path.join(here, '../Fixtures/projects/angular-minimal/node_modules/typescript');

function project(files, { withTypeScript = true } = {}) {
    const root = realpathSync(mkdtempSync(path.join(tmpdir(), 'devtools-node-')));

    for (const [file, content] of Object.entries(files)) {
        mkdirSync(path.dirname(path.join(root, file)), { recursive: true });
        writeFileSync(path.join(root, file), content);
    }

    if (withTypeScript) {
        cpSync(typescript, path.join(root, 'node_modules/typescript'), { recursive: true });
    }

    return root;
}

function analyse(root, input) {
    const run = spawnSync(process.execPath, [script], { input: JSON.stringify({ root, ...input }), encoding: 'utf8' });

    return { status: run.status, output: JSON.parse(run.stdout) };
}

test('imports are resolved with the paths and baseUrl of tsconfig.json', (t) => {
    const root = project({
        'package.json': '{}',
        'tsconfig.json': JSON.stringify({ compilerOptions: { baseUrl: '.', paths: { '@app/*': ['src/app/*'] } } }),
        'src/main.ts': "import { A } from '@app/a';\nimport type { B } from './b';\nimport { of } from 'rxjs';\nimport { readFileSync } from 'node:fs';\nexport * from './c';\nconst lazy = () => import('./d');\n",
        'src/app/a.ts': 'export class A {}\n',
        'src/b.ts': 'export interface B {}\n',
        'src/c.ts': 'export const c = 1;\n',
        'src/d.ts': 'export const d = 1;\n',
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    const { status, output } = analyse(root, { files: ['src/main.ts'], queries: ['imports'] });

    assert.equal(status, 0);
    assert.deepEqual(output.files['src/main.ts'].imports, [
        { specifier: '@app/a', kind: 'static', resolved: 'src/app/a.ts', package: null, names: [{ imported: 'A', local: 'A' }] },
        { specifier: 'rxjs', kind: 'static', resolved: null, package: 'rxjs', names: [{ imported: 'of', local: 'of' }] },
        { specifier: 'node:fs', kind: 'static', builtin: true, resolved: null, package: null, names: [{ imported: 'readFileSync', local: 'readFileSync' }] },
        { specifier: './c', kind: 'export', resolved: 'src/c.ts', package: null },
        { specifier: './d', kind: 'dynamic', resolved: 'src/d.ts', package: null },
    ], 'A type-only import is no dependency at run time.');
});

test('decorators and their literal arguments are read, a lazy route keeps its import', (t) => {
    const root = project({
        'package.json': '{}',
        'src/order.component.ts': "import { Component, Injectable } from '@angular/core';\n@Injectable({ providedIn: 'root' })\nexport class OrderService {}\n@Component({ selector: 'app-order', templateUrl: './order.component.html', imports: [OrderService], standalone: true, count: 2 })\nexport class OrderComponent {}\nexport const routes = [{ path: 'admin', loadChildren: () => import('./admin').then((m) => m.ROUTES) }, { path: `x/${1}` }];\n",
        'src/admin.ts': 'export const ROUTES = [];\n',
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    const { output } = analyse(root, { files: ['src/order.component.ts'], queries: ['decorators', 'variables', 'exports'] });
    const facts = output.files['src/order.component.ts'];

    assert.deepEqual(facts.decorators, [
        { name: 'Injectable', target: 'OrderService', member: null, arguments: [{ providedIn: 'root' }] },
        { name: 'Component', target: 'OrderComponent', member: null, arguments: [{ selector: 'app-order', templateUrl: './order.component.html', imports: [{ $identifier: 'OrderService' }], standalone: true, count: 2 }] },
    ]);
    assert.deepEqual(facts.variables[0].value, [
        { path: 'admin', loadChildren: { $function: true, $imports: [{ specifier: './admin', member: 'ROUTES', resolved: 'src/admin.ts' }] } },
        { path: { $template: ['x/', 1] } },
    ]);
    assert.deepEqual(facts.exports, ['OrderService', 'OrderComponent', 'routes']);
});

test('calls and new expressions are reported by name, with their class; CommonJS is read too', (t) => {
    const root = project({
        'package.json': '{}',
        'src/app.js': "const express = require('express');\nconst router = express.Router();\nrouter.get('/orders', list);\nrouter.use(auth);\nnew Worker('emails', handle);\nmodule.exports = { router };\nclass Api { load() { return this.http.get(`${base}/x`); } }\n",
    });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    const { output } = analyse(root, { files: ['src/app.js'], queries: ['imports', 'calls', 'news', 'variables'], calls: ['get'], news: ['Worker'] });
    const facts = output.files['src/app.js'];

    assert.deepEqual(facts.imports, [{ specifier: 'express', kind: 'require', resolved: null, package: 'express', names: [{ imported: 'default', local: 'express' }] }]);
    assert.deepEqual(facts.calls, [
        { callee: 'router.get', arguments: ['/orders', { $identifier: 'list' }], class: null, line: 3 },
        { callee: 'this.http.get', arguments: [{ $template: [{ $identifier: 'base' }, '/x'] }], class: 'Api', line: 7 },
    ], 'Only the calls asked for.');
    assert.deepEqual(facts.news, [{ class: 'Worker', arguments: ['emails', { $identifier: 'handle' }], line: 5 }]);
    assert.deepEqual(facts.variables.at(-1), { name: 'module.exports', type: null, exported: true, value: { router: { $identifier: 'router' } }, line: 6 });
});

test('nothing outside the project is read, whatever a file imports or the list names', (t) => {
    const root = project({
        'package.json': '{}',
        'src/escape.ts': "import secrets from '../../../../../../../../../../etc/hosts';\nimport outside from '../../outside';\n",
    });
    writeFileSync(path.join(root, '../outside.ts'), 'export default 1;\n');
    t.after(() => {
        rmSync(root, { recursive: true, force: true });
        rmSync(path.join(root, '../outside.ts'), { force: true });
    });

    const { status, output } = analyse(root, { files: ['src/escape.ts', '../outside.ts', '/etc/hosts'], queries: ['imports'] });

    assert.equal(status, 0);
    assert.deepEqual(output.files['src/escape.ts'].imports.map((i) => i.resolved), [null, null]);
    assert.deepEqual(Object.keys(output.files), ['src/escape.ts']);
    assert.deepEqual(output.errors.map((e) => e.file), ['../outside.ts', '/etc/hosts']);
});

test('without TypeScript in the project, the script refuses and says what to install', (t) => {
    const root = project({ 'package.json': '{}', 'src/a.js': 'module.exports = 1;\n' }, { withTypeScript: false });
    t.after(() => rmSync(root, { recursive: true, force: true }));

    const { status, output } = analyse(root, { files: ['src/a.js'], queries: ['imports'] });

    assert.equal(status, 2);
    assert.match(output.error, /^TypeScript is not installed in this project .*add it to its devDependencies to read it natively\.$/);
});

test('an input that is not JSON is refused in JSON', () => {
    const run = spawnSync(process.execPath, [script], { input: 'nope', encoding: 'utf8' });

    assert.equal(run.status, 2);
    assert.match(JSON.parse(run.stdout).error, /^The input is not JSON/);
});
