// Self-contained Node-native replacement for seed-browser.php.
//
// Each call:
//   - allocates a private tmpdir;
//   - copies the writable FIN code into a fresh app/ subdir;
//   - symlinks vendor/ and icons/ from the real source tree (never
//     state, only read-only code);
//   - writes config.local.php pointing the application at a private
//     SQLite, Master PIN hash file, and throttle file;
//   - runs `migrate` through the canonical `php api.php migrate` path
//     so the schema matches the live tree;
//   - runs `set-master-pin --stdin` through the canonical CLI;
//   - starts `php -S` on a random free port;
//   - returns { baseURL, stop, dbPath, homeDir, configLocalPath }.
//
// Nothing is shared. Tests in different Playwright workers each get
// their own isolated server (the whole call is allocated with random
// suffixes and removed on stop()).
'use strict';

const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const net = require('node:net');
const cp = require('node:child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const REQUIRED = [
  'VERSION', 'accounting.php', 'api.php', 'app.js', 'auth.php',
  'composer.json', 'composer.lock', 'config.php', 'currency.php',
  'ecb_rates.php', 'favicon.svg', 'finance.js', 'index.html',
  'manifest.webmanifest', 'recommendation.php', 'report.php',
  'service-worker.js', 'statement_import.php', 'statement_reconciliation.php',
  'style.css',
];

function findFreePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer((sock) => sock.end());
    server.listen(0, '127.0.0.1', () => {
      const addr = server.address();
      server.close(() => resolve(addr.port));
    });
    server.on('error', reject);
  });
}

function waitFor(url, timeoutMs) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const tick = () => {
      const req = require('node:http').get(url, (res) => {
        let body = '';
        res.on('data', (chunk) => { body += chunk.toString(); });
        res.on('end', () => {
          if (res.statusCode === 200 && body.length > 0) resolve(body);
          else if (Date.now() - start > timeoutMs) reject(new Error('timeout waiting for ' + url));
          else setTimeout(tick, 25);
        });
      });
      req.on('error', () => {
        if (Date.now() - start > timeoutMs) reject(new Error('timeout waiting for ' + url));
        else setTimeout(tick, 25);
      });
      req.setTimeout(2000, () => req.destroy());
    };
    tick();
  });
}

async function start(pin = '12345678') {
  const port = await findFreePort();
  const nonce = crypto.randomBytes(6).toString('hex');
  const homeDir = '/tmp/fin-isolated-' + nonce;
  const codeDir = homeDir + '/app';
  const authDir = homeDir + '/auth';
  const dbPath = homeDir + '/fin.sqlite';
  const configLocalPath = codeDir + '/config.local.php';
  const pinPath = authDir + '/master-pin.hash';
  const throttlePath = authDir + '/master-pin-throttle.json';
  const logFile = homeDir + '/server.log';

  fs.mkdirSync(homeDir, { mode: 0o700 });
  fs.mkdirSync(codeDir, { mode: 0o700 });
  fs.mkdirSync(authDir, { mode: 0o700 });
  for (const file of REQUIRED) {
    fs.copyFileSync(path.join(ROOT, file), path.join(codeDir, file));
    fs.chmodSync(path.join(codeDir, file), 0o644);
  }
  fs.symlinkSync(path.join(ROOT, 'vendor'), path.join(codeDir, 'vendor'));
  fs.symlinkSync(path.join(ROOT, 'icons'), path.join(codeDir, 'icons'));

  const wpOrigin = 'http://127.0.0.1:' + port;
  const cfg = [
    '<?php',
    'return [',
    "    'auth' => [",
    "        'master_pin_file' => '" + pinPath + "',",
    "        'throttle_file' => '" + throttlePath + "',",
    "        'file_group' => '',",
    "        'pin_failure_delay_us' => 0,",
    '    ],',
    "    'webauthn_rp_id' => '127.0.0.1',",
    "    'webauthn_origin' => '" + wpOrigin + "',",
    '];',
  ].join('\n');
  fs.writeFileSync(configLocalPath, cfg, { mode: 0o600 });

  const env = { ...process.env, FIN_DB_FILE: dbPath, FIN_TEST_APP_ROOT: codeDir };
  function runPhp(args) {
    const result = cp.spawnSync('php', args, {
      env,
      cwd: codeDir,
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 30000,
      encoding: 'utf-8',
    });
    if (result.status !== 0) {
      throw new Error('Command failed: php ' + args.join(' ') + '\nstdout:\n' + (result.stdout || '') + '\nstderr:\n' + (result.stderr || ''));
    }
    return result.stdout || '';
  }

  function runPhpWithInput(args, input) {
    const result = cp.spawnSync('php', args, {
      env,
      cwd: codeDir,
      input,
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 30000,
      encoding: 'utf-8',
    });
    if (result.status !== 0) {
      throw new Error('Command failed: php ' + args.join(' ') + '\nstdout:\n' + (result.stdout || '') + '\nstderr:\n' + (result.stderr || ''));
    }
    return result.stdout || '';
  }

  // Migrate the schema.
  runPhp([path.join(codeDir, 'api.php'), 'migrate']);

  // Set Master PIN via canonical CLI -- reads two lines from stdin.
  // spawnSync with explicit input + EOF on stdin ensures the CLI's fread
  // loop terminates after both PIN lines are read.
  runPhpWithInput([path.join(codeDir, 'api.php'), 'set-master-pin', '--stdin'], pin + '\n' + pin + '\n');

  // Both files must exist with mode 0600 for the application's auth
  // layer to accept them. The set-master-pin CLI writes the file but
  // does not always enforce the mode, so we re-assert it here.
  for (const path_ of [pinPath, throttlePath]) {
    if (fs.existsSync(path_)) fs.chmodSync(path_, 0o600);
  }

  if (!fs.existsSync(pinPath)) {
    throw new Error('Master PIN file was not created at ' + pinPath);
  }
  // The throttle file may not exist yet if the CLI never touched it,
  // so create it with the required mode.
  if (!fs.existsSync(throttlePath)) {
    fs.writeFileSync(throttlePath, '{}', { mode: 0o600 });
  } else {
    fs.chmodSync(throttlePath, 0o600);
  }

  // Start the PHP built-in server. The server process MUST inherit the
  // test's FIN_DB_FILE so every API request targets the isolated SQLite
  // we just migrated; otherwise it falls back to the project's default
  // data/fin.sqlite which is user's DB territory and a hard test
  // architecture violation.
  const out = fs.openSync(logFile, 'a');
  const err = fs.openSync(logFile, 'a');
  const proc = cp.spawn('php', ['-S', '127.0.0.1:' + port, '-t', codeDir], {
    cwd: codeDir,
    env,
    stdio: ['ignore', out, err],
    detached: false,
  });

  const baseURL = 'http://127.0.0.1:' + port;
  try {
    await waitFor(baseURL + '/api.php?action=version', 30000);
  } catch (err) {
    try { proc.kill('SIGKILL'); } catch {}
    throw new Error('Isolated FIN did not become reachable: ' + err.message);
  }

  const stop = (opts = {}) => {
    try { proc.kill('SIGKILL'); } catch {}
    if (!opts.preserveHome) {
      try { fs.rmSync(homeDir, { recursive: true, force: true }); } catch {}
    } else {
      try { fs.writeFileSync('/tmp/fin-iso-preserve-' + port + '.flag', homeDir); } catch {}
    }
  };

  return { baseURL, stop, dbPath, configLocalPath, homeDir, port };
}

module.exports = { start };
