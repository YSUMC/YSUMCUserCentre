// Run after extracting the pinned Blessing Skin 6.0.2 runtime and installing Janus.
// Keep upstream bundles intact; publish versioned fixes for empty viewer inputs.
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const ts = require(path.join(root, 'janus/node_modules/typescript'));
const appDir = path.join(root, 'public/app');
const version = 'ysu20261021';
const bundles = [
  ['846', 'd55824d'],
  ['997', 'b99f3d5'],
  ['613', '3837fe8'],
];
const replacements = [
  ['cape:e.cape||""', 'cape:e.cape||null'],
  [
    'n.current.loadBackground(A[k])',
    'k<0?n.current.background=document.body.classList.contains("dark-mode")?"#6c757d":null:n.current.loadBackground(A[k])',
  ],
];
function replaceOnce(source, before, after, label) {
  if (source.split(before).length !== 2) {
    throw new Error(`Unexpected upstream content: ${label}. Review the pinned runtime before deploying.`);
  }
  return source.replace(before, after);
}
const outputs = [];
let app = fs.readFileSync(path.join(appDir, 'app.672e119.js'), 'utf8');
for (const [id, hash] of bundles) {
  const filename = `${id}.${hash}.js`;
  let source = fs.readFileSync(path.join(appDir, filename), 'utf8');
  for (const [before, after] of replacements) {
    source = replaceOnce(source, before, after, filename);
  }
  new vm.Script(source, { filename });
  outputs.push([path.join(appDir, `${id}.${version}.js`), source]);
  app = replaceOnce(app, `"${hash}"`, `"${version}"`, `chunk ${id}`);
}
new vm.Script(app, { filename: `app.${version}.js` });
outputs.push([path.join(appDir, `app.${version}.js`), app]);

// The vendored plugin contains TypeScript sources, not browser-ready assets.
for (const name of ['dnd', 'config']) {
  const sourcePath = path.join(root, `plugins/yggdrasil-connect/assets/${name}.ts`);
  const source = fs.readFileSync(sourcePath, 'utf8').replace(/^export\s*\{\s*\}\s*;?\s*$/gm, '');
  const result = ts.transpileModule(source, {
    fileName: `${name}.ts`,
    compilerOptions: { target: ts.ScriptTarget.ES2019, module: ts.ModuleKind.None },
    reportDiagnostics: true,
  });
  if (result.diagnostics?.some(d => d.category === ts.DiagnosticCategory.Error)) {
    throw new Error(`Cannot transpile plugin asset: ${name}`);
  }
  const browserScript = `(() => {\n${result.outputText}\n})();\n`;
  new vm.Script(browserScript, { filename: `${name}.js` });
  outputs.push([path.join(root, `public/plugins/yggdrasil-connect/assets/${name}.js`), browserScript]);
}
const template = path.join(root, 'resources/views/assets/app.twig');
const previous = fs.readFileSync(template, 'utf8');
if (!previous.includes('app.672e119.js') && !previous.includes(`app.${version}.js`)) {
  throw new Error('Unexpected assets.app template; refusing to replace another runtime.');
}
const backup = path.join(root, '.deploy/ui-runtime-backup');
fs.mkdirSync(backup, { recursive: true, mode: 0o700 });
const backupTemplate = path.join(backup, 'assets-app.twig');
if (!fs.existsSync(backupTemplate)) fs.writeFileSync(backupTemplate, previous, { mode: 0o600 });
for (const [file, content] of outputs) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(`${file}.tmp`, content);
  fs.renameSync(`${file}.tmp`, file);
}
fs.writeFileSync(`${template}.tmp`, previous.replace('app.672e119.js', `app.${version}.js`));
fs.renameSync(`${template}.tmp`, template);
console.log(`Published ${outputs.length} runtime assets; upstream originals preserved.`);
