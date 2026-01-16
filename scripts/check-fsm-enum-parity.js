#!/usr/bin/env node

/**
 * FSM ENUM PARITY CHECK
 *
 * This script compares the PHP PluginState enum with the TypeScript PluginState enum.
 *
 * It enforces that:
 * - All PHP enum string values exist in the TS enum
 * - TS enum string values that do not exist in PHP are treated as errors,
 *   except for an explicit allowlist of frontend-only transient states.
 *
 * The goal is to prevent backend/frontend FSM divergence without modifying
 * the FSM implementations themselves.
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');

const phpEnumPath = path.join(root, 'src/Enums/PluginState.php');
const tsEnumPath = path.join(root, 'src/ts/types/fsm.ts');

// Known frontend-only TS states that do not exist in PHP but are allowed.
// "installing" is documented in src/ts/types/fsm.ts as a frontend-only state.
const ALLOWED_TS_ONLY_VALUES = new Set(['installing']);

function readFile(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function extractPhpEnumValues(source) {
  const regex = /case\s+([A-Z0-9_]+)\s*=\s*'([^']+)'/g;
  const values = [];
  let match;

  while ((match = regex.exec(source)) !== null) {
    values.push({ name: match[1], value: match[2] });
  }

  if (values.length === 0) {
    throw new Error('No PluginState cases found in src/Enums/PluginState.php');
  }

  return values;
}

function extractTsEnumValues(source) {
  const enumMatch = source.match(/export\s+enum\s+PluginState\s*{([\s\S]*?)}/);

  if (!enumMatch) {
    throw new Error('Could not find "export enum PluginState" block in src/ts/types/fsm.ts');
  }

  const body = enumMatch[1];
  const regex = /([A-Z0-9_]+)\s*=\s*'([^']+)'/g;
  const values = [];
  let match;

  while ((match = regex.exec(body)) !== null) {
    values.push({ name: match[1], value: match[2] });
  }

  if (values.length === 0) {
    throw new Error('No PluginState members found in src/ts/types/fsm.ts');
  }

  return values;
}

function main() {
  const phpSource = readFile(phpEnumPath);
  const tsSource = readFile(tsEnumPath);

  const phpStates = extractPhpEnumValues(phpSource);
  const tsStates = extractTsEnumValues(tsSource);

  const phpValues = new Set(phpStates.map((s) => s.value));
  const tsValues = new Set(tsStates.map((s) => s.value));

  const missingInTs = [];
  phpValues.forEach((value) => {
    if (!tsValues.has(value)) {
      missingInTs.push(value);
    }
  });

  const extraInTs = [];
  tsValues.forEach((value) => {
    if (!phpValues.has(value) && !ALLOWED_TS_ONLY_VALUES.has(value)) {
      extraInTs.push(value);
    }
  });

  if (missingInTs.length === 0 && extraInTs.length === 0) {
    console.log('FSM enum parity OK: PHP and TypeScript PluginState values are synchronized.');
    process.exit(0);
  }

  console.error('============================================================');
  console.error(' FSM ENUM PARITY CHECK FAILED (PHP <-> TypeScript)');
  console.error('============================================================');

  if (missingInTs.length > 0) {
    console.error('PHP states missing in TypeScript (by string value):');
    missingInTs.forEach((value) => {
      console.error(`  - ${value}`);
    });
  }

  if (extraInTs.length > 0) {
    console.error('TypeScript states not present in PHP (and not explicitly allowed):');
    extraInTs.forEach((value) => {
      console.error(`  - ${value}`);
    });
  }

  console.error('');
  console.error('Developer help:');
  console.error('- Doc: PROJECT/1-INBOX/FSM-STATE-UPDATE-WORKFLOW.md');
  console.error('- Fix: Update BOTH src/Enums/PluginState.php and src/ts/types/fsm.ts so string values match.');
  console.error('- Then re-run: npm run check:fsm-enum-parity');
  console.error('');
  console.error('This error is developer-facing and will block CI until the enums are synchronized.');

  process.exit(1);
}

main();

