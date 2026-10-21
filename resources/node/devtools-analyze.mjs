// DevTools — the Node bridge (ADR-0031). https://github.com/jul6art/devtools
//
// Reads JavaScript and TypeScript sources with the TypeScript compiler OF THE ANALYSED PROJECT — this script
// has no dependency of its own — and prints, per file, the facts DevTools' adapters interpret: imports,
// decorators, calls, `new` expressions, top-level variables, classes, exports. No interpretation here: what
// is an entry point is the PHP adapter's decision.
//
// Input, on stdin: {"root": "/abs/project", "base": "/abs/project/front", "files": ["front/src/a.ts"],
//                   "queries": ["imports", "decorators", "calls", "news", "variables", "classes", "exports"],
//                   "calls": ["get", "post"], "news": ["Worker"]}
// Output, on stdout: {"typescript": "6.0.2", "files": {"front/src/a.ts": {…}}, "errors": [{"file", "message"}]}
//
// ⚠️ Nothing outside `root` is ever read: a file of the list outside it is refused, and the module resolution
// host answers "absent" for any path outside it, which is what an `import '../../../etc/passwd'` meets.

import { builtinModules, createRequire } from 'node:module';
import { readFileSync, existsSync, statSync, realpathSync } from 'node:fs';
import path from 'node:path';

function fail(message) {
    process.stdout.write(JSON.stringify({ error: message }) + '\n');
    process.exit(2);
}

let input;

try {
    input = JSON.parse(readFileSync(0, 'utf8'));
} catch (error) {
    fail('The input is not JSON: ' + error.message);
}

const root = realpathSync(input.root);
const base = realpathSync(input.base ?? input.root);
const queries = new Set(input.queries ?? ['imports']);
const callNames = new Set(input.calls ?? []);
const newNames = new Set(input.news ?? []);

const inside = (file) => {
    const resolved = path.resolve(file);

    return resolved === root || resolved.startsWith(root + path.sep);
};

if (!inside(base)) {
    fail('The stack root is outside the project.');
}

// TypeScript is the project's own: `npm install --save-dev typescript` makes a JavaScript project readable.
let ts;

try {
    const require = createRequire(path.join(base, 'package.json'));
    const resolved = require.resolve('typescript');

    if (!inside(resolved)) {
        throw new Error('it resolves outside the project, to ' + resolved);
    }

    ts = require(resolved);
} catch (error) {
    fail('TypeScript is not installed in this project (' + error.message.split('\n')[0] + '): add it to its devDependencies to read it natively.');
}

const host = {
    fileExists: (file) => inside(file) && existsSync(file) && statSync(file).isFile(),
    readFile: (file) => (inside(file) && existsSync(file) ? readFileSync(file, 'utf8') : undefined),
    directoryExists: (directory) => inside(directory) && existsSync(directory) && statSync(directory).isDirectory(),
    realpath: (file) => (inside(file) && existsSync(file) ? realpathSync(file) : file),
    getCurrentDirectory: () => base,
    useCaseSensitiveFileNames: true,
    readDirectory: (directory, extensions, excludes, includes, depth) => (inside(directory) ? ts.sys.readDirectory(directory, extensions, excludes, includes, depth) : []),
};

// The project's tsconfig.json: `paths` and `baseUrl` decide what `@app/orders` is.
let options = { allowJs: true, checkJs: false, moduleResolution: ts.ModuleResolutionKind.Bundler ?? ts.ModuleResolutionKind.NodeJs, module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.Latest, resolveJsonModule: true };
const tsconfig = path.join(base, 'tsconfig.json');

if (host.fileExists(tsconfig)) {
    const read = ts.readConfigFile(tsconfig, host.readFile);

    if (!read.error) {
        const parsed = ts.parseJsonConfigFileContent(read.config, host, base, undefined, tsconfig);
        options = { ...options, ...parsed.options, allowJs: true, noEmit: true };
    }
}

const relative = (file) => path.relative(root, file).split(path.sep).join('/');

const packageOf = (specifier) => {
    if (specifier.startsWith('node:')) {
        return null;
    }

    const parts = specifier.split('/');

    return specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0];
};

const builtins = new Set(builtinModules);

function resolve(specifier, containingFile) {
    if (specifier.startsWith('node:') || builtins.has(specifier)) {
        return { builtin: true, resolved: null, package: null };
    }

    const result = ts.resolveModuleName(specifier, containingFile, options, host).resolvedModule;

    if (result === undefined) {
        // Not found — or outside the project, which the host hides on purpose.
        return { resolved: null, package: specifier.startsWith('.') || specifier.startsWith('/') ? null : packageOf(specifier) };
    }

    if (result.isExternalLibraryImport || result.resolvedFileName.includes('/node_modules/')) {
        return { resolved: null, package: packageOf(specifier) };
    }

    return { resolved: inside(result.resolvedFileName) ? relative(result.resolvedFileName) : null, package: null };
}

// What an expression literally is: a string, a number, an array, an object — or a marker saying what else.
function literal(node, depth = 0) {
    if (node === undefined || depth > 12) {
        return null;
    }

    while (ts.isParenthesizedExpression(node) || ts.isAsExpression(node) || (ts.isSatisfiesExpression && ts.isSatisfiesExpression(node)) || ts.isNonNullExpression(node) || ts.isTypeAssertionExpression?.(node)) {
        node = node.expression;
    }

    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
        return node.text;
    }

    if (ts.isNumericLiteral(node)) {
        return Number(node.text);
    }

    switch (node.kind) {
        case ts.SyntaxKind.TrueKeyword:
            return true;
        case ts.SyntaxKind.FalseKeyword:
            return false;
        case ts.SyntaxKind.NullKeyword:
            return null;
    }

    if (ts.isIdentifier(node)) {
        return node.text === 'undefined' ? null : { $identifier: node.text };
    }

    if (ts.isTemplateExpression(node)) {
        const parts = [node.head.text];

        for (const span of node.templateSpans) {
            parts.push(literal(span.expression, depth + 1), span.literal.text);
        }

        return { $template: parts.filter((part) => part !== '') };
    }

    if (ts.isArrayLiteralExpression(node)) {
        return node.elements.filter((element) => !ts.isSpreadElement(element) && !ts.isOmittedExpression(element)).map((element) => literal(element, depth + 1));
    }

    if (ts.isObjectLiteralExpression(node)) {
        const object = {};

        for (const property of node.properties) {
            if (ts.isPropertyAssignment(property) && (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name))) {
                object[property.name.text] = literal(property.initializer, depth + 1);
            } else if (ts.isShorthandPropertyAssignment(property)) {
                object[property.name.text] = { $identifier: property.name.text };
            } else if (ts.isMethodDeclaration(property) && ts.isIdentifier(property.name)) {
                object[property.name.text] = { $function: true, $imports: dynamicImports(property) };
            }
        }

        return object;
    }

    if (ts.isArrowFunction(node) || ts.isFunctionExpression(node)) {
        return { $function: true, $imports: dynamicImports(node) };
    }

    if (ts.isNewExpression(node)) {
        return { $new: node.expression.getText(), $arguments: (node.arguments ?? []).map((argument) => literal(argument, depth + 1)) };
    }

    if (ts.isCallExpression(node)) {
        if (node.expression.kind === ts.SyntaxKind.ImportKeyword) {
            return { $import: literal(node.arguments[0], depth + 1) };
        }

        return { $call: node.expression.getText(), $arguments: node.arguments.map((argument) => literal(argument, depth + 1)) };
    }

    return { $expression: node.getText().slice(0, 200) };
}

// `() => import('./admin/admin.routes').then((m) => m.ADMIN_ROUTES)` → [{specifier, member, resolved}].
function dynamicImports(node) {
    const found = [];

    const visit = (child) => {
        if (ts.isCallExpression(child) && child.expression.kind === ts.SyntaxKind.ImportKeyword && child.arguments.length > 0 && ts.isStringLiteralLike(child.arguments[0])) {
            let member = null;
            const parent = child.parent;

            if (parent && ts.isPropertyAccessExpression(parent) && parent.name.text === 'then' && ts.isCallExpression(parent.parent)) {
                const callback = parent.parent.arguments[0];

                if (callback && (ts.isArrowFunction(callback) || ts.isFunctionExpression(callback)) && ts.isPropertyAccessExpression(callback.body)) {
                    member = callback.body.name.text;
                }
            }

            found.push({ specifier: child.arguments[0].text, member, resolved: resolve(child.arguments[0].text, node.getSourceFile().fileName).resolved });
        }

        ts.forEachChild(child, visit);
    };

    visit(node);

    return found;
}

const decoratorsOf = (node) => (ts.getDecorators ? ts.getDecorators(node) : node.decorators) ?? [];

function decorator(node, target, member) {
    const expression = node.expression;
    const name = ts.isCallExpression(expression) ? expression.expression.getText() : expression.getText();
    const args = ts.isCallExpression(expression) ? expression.arguments.map((argument) => literal(argument)) : [];

    return { name, target, member, arguments: args };
}

function enclosingClass(node) {
    for (let current = node.parent; current; current = current.parent) {
        if (ts.isClassDeclaration(current) || ts.isClassExpression(current)) {
            return current.name ? current.name.text : null;
        }
    }

    return null;
}

const lastName = (expression) => (ts.isPropertyAccessExpression(expression) ? expression.name.text : ts.isIdentifier(expression) ? expression.text : null);

function analyse(file) {
    const absolute = path.resolve(root, file);
    const source = ts.createSourceFile(absolute, readFileSync(absolute, 'utf8'), ts.ScriptTarget.Latest, true, /\.[cm]?jsx?$/.test(file) ? ts.ScriptKind.JSX : ts.ScriptKind.TSX);
    const facts = {};
    const imports = [];
    const decorators = [];
    const calls = [];
    const news = [];
    const variables = [];
    const classes = [];
    const exports = [];

    const addImport = (specifier, kind, names = []) => imports.push({ specifier, kind, ...resolve(specifier, absolute), ...(names.length > 0 ? { names } : {}) });

    // `import { routes as appRoutes } from './app.routes'` → [{imported: 'routes', local: 'appRoutes'}].
    const namesOf = (declaration) => {
        const clause = declaration.importClause;
        const names = [];

        if (clause?.name) {
            names.push({ imported: 'default', local: clause.name.text });
        }

        if (clause?.namedBindings && ts.isNamedImports(clause.namedBindings)) {
            for (const element of clause.namedBindings.elements) {
                if (!element.isTypeOnly) {
                    names.push({ imported: (element.propertyName ?? element.name).text, local: element.name.text });
                }
            }
        } else if (clause?.namedBindings && ts.isNamespaceImport(clause.namedBindings)) {
            names.push({ imported: '*', local: clause.namedBindings.name.text });
        }

        return names;
    };
    const exported = (node) => (ts.getModifiers ? ts.getModifiers(node) : node.modifiers)?.some((modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword) ?? false;

    const visit = (node) => {
        if ((ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) && node.moduleSpecifier && ts.isStringLiteralLike(node.moduleSpecifier)) {
            if (!(node.importClause?.isTypeOnly ?? false) && !(node.isTypeOnly ?? false)) {
                addImport(node.moduleSpecifier.text, ts.isImportDeclaration(node) ? 'static' : 'export', ts.isImportDeclaration(node) ? namesOf(node) : []);
            }
        } else if (ts.isCallExpression(node)) {
            if (node.expression.kind === ts.SyntaxKind.ImportKeyword && node.arguments.length > 0 && ts.isStringLiteralLike(node.arguments[0])) {
                addImport(node.arguments[0].text, 'dynamic');
            } else if (ts.isIdentifier(node.expression) && node.expression.text === 'require' && node.arguments.length === 1 && ts.isStringLiteralLike(node.arguments[0])) {
                const binding = ts.isVariableDeclaration(node.parent) ? node.parent.name : null;
                const names = binding && ts.isIdentifier(binding)
                    ? [{ imported: 'default', local: binding.text }]
                    : binding && ts.isObjectBindingPattern(binding)
                        ? binding.elements.filter((element) => ts.isIdentifier(element.name)).map((element) => ({ imported: (element.propertyName ?? element.name).getText(), local: element.name.text }))
                        : [];
                addImport(node.arguments[0].text, 'require', names);
            }

            const name = lastName(node.expression);

            if (name !== null && callNames.has(name)) {
                calls.push({ callee: node.expression.getText(), arguments: node.arguments.map((argument) => literal(argument)), class: enclosingClass(node), line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1 });
            }
        } else if (ts.isNewExpression(node)) {
            const name = lastName(node.expression);

            if (name !== null && newNames.has(name)) {
                news.push({ class: node.expression.getText(), arguments: (node.arguments ?? []).map((argument) => literal(argument)), line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1 });
            }
        } else if ((ts.isClassDeclaration(node) || ts.isClassExpression(node)) && node.name) {
            const className = node.name.text;
            const injects = [];
            const properties = [];

            for (const item of decoratorsOf(node)) {
                decorators.push(decorator(item, className, null));
            }

            for (const member of node.members) {
                for (const item of decoratorsOf(member)) {
                    decorators.push(decorator(item, className, member.name ? member.name.getText() : null));
                }

                if (ts.isConstructorDeclaration(member)) {
                    for (const parameter of member.parameters) {
                        if (parameter.type) {
                            injects.push(parameter.type.getText());
                            properties.push({ name: parameter.name.getText(), type: parameter.type.getText(), value: null });
                        }
                    }
                } else if (ts.isPropertyDeclaration(member) && member.name) {
                    const value = member.initializer ? literal(member.initializer) : null;

                    if (value && value.$call === 'inject' && value.$arguments[0]?.$identifier) {
                        injects.push(value.$arguments[0].$identifier);
                    }

                    properties.push({ name: member.name.getText(), type: member.type ? member.type.getText() : null, value });
                }
            }

            const heritage = (token) => (node.heritageClauses ?? []).filter((clause) => clause.token === token).flatMap((clause) => clause.types.map((type) => type.expression.getText()));
            classes.push({ name: className, extends: heritage(ts.SyntaxKind.ExtendsKeyword)[0] ?? null, implements: heritage(ts.SyntaxKind.ImplementsKeyword), injects, properties, exported: exported(node) });

            if (exported(node)) {
                exports.push(className);
            }
        } else if (ts.isVariableStatement(node) && node.parent === source) {
            for (const declaration of node.declarationList.declarations) {
                if (ts.isIdentifier(declaration.name)) {
                    variables.push({ name: declaration.name.text, type: declaration.type ? declaration.type.getText() : null, exported: exported(node), value: declaration.initializer ? literal(declaration.initializer) : null, line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1 });

                    if (exported(node)) {
                        exports.push(declaration.name.text);
                    }
                }
            }
        } else if (ts.isFunctionDeclaration(node) && node.name && exported(node)) {
            exports.push(node.name.text);
        } else if (ts.isExportAssignment(node)) {
            exports.push('default');
            variables.push({ name: 'default', type: null, exported: true, value: literal(node.expression), line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1 });
        } else if (ts.isExpressionStatement(node) && ts.isBinaryExpression(node.expression) && node.expression.operatorToken.kind === ts.SyntaxKind.EqualsToken && node.expression.left.getText() === 'module.exports') {
            exports.push('default');
            variables.push({ name: 'module.exports', type: null, exported: true, value: literal(node.expression.right), line: source.getLineAndCharacterOfPosition(node.getStart()).line + 1 });
        }

        ts.forEachChild(node, visit);
    };

    visit(source);

    for (const [query, value] of [['imports', imports], ['decorators', decorators], ['calls', calls], ['news', news], ['variables', variables], ['classes', classes], ['exports', exports]]) {
        if (queries.has(query)) {
            facts[query] = value;
        }
    }

    return facts;
}

const files = {};
const errors = [];

for (const file of input.files ?? []) {
    const absolute = path.resolve(root, file);

    if (!inside(absolute) || !host.fileExists(absolute)) {
        errors.push({ file, message: 'not a file of the project' });

        continue;
    }

    try {
        files[relative(absolute)] = analyse(relative(absolute));
    } catch (error) {
        errors.push({ file, message: String(error && error.message ? error.message : error).split('\n')[0] });
    }
}

process.stdout.write(JSON.stringify({ typescript: ts.version, files, errors }) + '\n');
