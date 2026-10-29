'use strict';

const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const net = require('node:net');
const http = require('node:http');
const cp = require('node:child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const APPLICATION_FILES = [
  'VERSION', 'accounting.php', 'api.php', 'app.js', 'auth.php',
  'composer.json', 'composer.lock', 'config.php', 'currency.php',
  'ecb_rates.php', 'favicon.svg', 'finance.js', 'index.html',
  'manifest.webmanifest', 'recommendation.php', 'report.php',
  'service-worker.js', 'statement_import.php', 'statement_reconciliation.php',
  'style.css',
];

function findFreePort() {
  return new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => {
      const address = socket.address();
      socket.close(() => resolve(address.port));
    });
    socket.on('error', reject);
  });
}

function waitForServer(url, timeoutMs = 30000) {
  return new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const poll = () => {
      const request = http.get(url, (response) => {
        let body = '';
        response.on('data', (chunk) => { body += chunk.toString(); });
        response.on('end', () => {
          if (response.statusCode === 200 && body.length > 0) {
            resolve();
          } else if (Date.now() - startedAt >= timeoutMs) {
            reject(new Error(`Timed out waiting for ${url}`));
          } else {
            setTimeout(poll, 25);
          }
        });
      });
      request.on('error', () => {
        if (Date.now() - startedAt >= timeoutMs) reject(new Error(`Timed out waiting for ${url}`));
        else setTimeout(poll, 25);
      });
      request.setTimeout(2000, () => request.destroy());
    };
    poll();
  });
}

class FinBrowserWorker {
  static async start(workerIndex) {
    const instance = new FinBrowserWorker(workerIndex);
    await instance.bootstrap();
    return instance;
  }

  constructor(workerIndex) {
    this.workerIndex = workerIndex;
    this.homeDir = '';
    this.codeDir = '';
    this.dbPath = '';
    this.baselinePath = '';
    this.baseURL = '';
    this.server = null;
    this.releaseVersions = null;
  }

  async bootstrap() {
    const nonce = crypto.randomBytes(6).toString('hex');
    this.homeDir = path.join(process.env.TMPDIR || '/tmp', `fin-browser-worker-${this.workerIndex}-${nonce}`);
    this.codeDir = path.join(this.homeDir, 'app');
    const authDir = path.join(this.homeDir, 'auth');
    this.dbPath = path.join(this.homeDir, 'worker.sqlite');
    this.baselinePath = path.join(this.homeDir, 'baseline.sqlite');
    const pinPath = path.join(authDir, 'master-pin.hash');
    const throttlePath = path.join(authDir, 'master-pin-throttle.json');
    const logPath = path.join(this.homeDir, 'server.log');
    const port = await findFreePort();
    this.baseURL = `http://localhost:${port}`;

    fs.mkdirSync(this.codeDir, { recursive: true, mode: 0o700 });
    fs.mkdirSync(authDir, { recursive: true, mode: 0o700 });
    for (const file of APPLICATION_FILES) {
      fs.copyFileSync(path.join(ROOT, file), path.join(this.codeDir, file));
    }
    fs.symlinkSync(path.join(ROOT, 'vendor'), path.join(this.codeDir, 'vendor'));
    fs.symlinkSync(path.join(ROOT, 'icons'), path.join(this.codeDir, 'icons'));

    fs.writeFileSync(path.join(this.codeDir, 'config.local.php'), [
      '<?php',
      'return [',
      "    'auth' => [",
      `        'master_pin_file' => '${pinPath}',`,
      `        'throttle_file' => '${throttlePath}',`,
      "        'file_group' => '',",
      "        'pin_failure_delay_us' => 0,",
      '    ],',
      "    'webauthn_rp_id' => 'localhost',",
      `    'webauthn_origin' => '${this.baseURL}',`,
      '];',
      '',
    ].join('\n'), { mode: 0o600 });

    const env = { ...process.env, FIN_DB_FILE: this.dbPath, FIN_TEST_APP_ROOT: this.codeDir };
    this.runPhp([path.join(this.codeDir, 'api.php'), 'migrate'], env);
    this.runPhp(
      [path.join(this.codeDir, 'api.php'), 'set-master-pin', '--stdin'],
      env,
      '12345678\n12345678\n'
    );
    if (!fs.existsSync(throttlePath)) fs.writeFileSync(throttlePath, '{}', { mode: 0o600 });

    this.runPhp([path.join(ROOT, 'tests', 'fixtures', 'seed-browser.php')], {
      ...env,
      FIN_TEST_APP_ROOT: this.codeDir,
    });

    this.releaseVersions = {
      app_version: fs.readFileSync(path.join(ROOT, 'VERSION'), 'utf8').trim(),
      database_version: this.readDatabaseVersion(env),
    };
    fs.copyFileSync(this.dbPath, this.baselinePath);
    fs.chmodSync(this.baselinePath, 0o600);

    const output = fs.openSync(logPath, 'a');
    this.server = cp.spawn('php', ['-S', `127.0.0.1:${port}`, '-t', this.codeDir], {
      cwd: this.codeDir,
      env,
      stdio: ['ignore', output, output],
    });
    await waitForServer(`${this.baseURL}/api.php?action=version`);
  }

  runPhp(args, env, input = undefined) {
    const result = cp.spawnSync('php', args, {
      cwd: this.codeDir,
      env,
      input,
      encoding: 'utf8',
      timeout: 30000,
      stdio: ['pipe', 'pipe', 'pipe'],
    });
    if (result.status !== 0) {
      throw new Error(`php ${args.join(' ')} failed\n${result.stdout || ''}${result.stderr || ''}`);
    }
    return result.stdout || '';
  }

  readDatabaseVersion(env) {
    return this.runPhp(['-r', [
      '$db = new PDO("sqlite:" . getenv("FIN_DB_FILE"));',
      '$versions = $db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);',
      'usort($versions, "version_compare");',
      'echo (string) end($versions);',
    ].join(' ')], env).trim();
  }

  restoreBaseline() {
    // Playwright has closed the previous test context before this test-scoped
    // fixture runs. Every completed request has released its short-lived PDO
    // connection, so replacing the worker database cannot race an active FIN
    // request.
    for (const suffix of ['-journal', '-shm', '-wal']) {
      fs.rmSync(this.dbPath + suffix, { force: true });
    }
    const replacement = `${this.dbPath}.restore-${crypto.randomBytes(4).toString('hex')}`;
    fs.copyFileSync(this.baselinePath, replacement);
    fs.chmodSync(replacement, 0o600);
    fs.renameSync(replacement, this.dbPath);
  }

  stop() {
    if (this.server && !this.server.killed) {
      try { this.server.kill('SIGKILL'); } catch {}
    }
    this.server = null;
    if (this.homeDir) fs.rmSync(this.homeDir, { recursive: true, force: true });
  }
}

module.exports = { FinBrowserWorker };
