'use strict';

const { test: base, expect } = require('@playwright/test');
const { FinBrowserWorker } = require('./FinBrowserWorker.cjs');
const { EMPTY_STORAGE_STATE, createAuthenticatedStorageState } = require('./auth.cjs');

const test = base.extend({
  finWorker: [async ({}, use, workerInfo) => {
    const worker = await FinBrowserWorker.start(workerInfo.workerIndex);
    try {
      await use(worker);
    } finally {
      worker.stop();
    }
  }, { scope: 'worker' }],

  baseURL: async ({ finWorker, pristineDatabase }, use) => {
    await use(finWorker.baseURL);
  },

  releaseVersions: [async ({ finWorker }, use) => {
    await use(finWorker.releaseVersions);
  }, { scope: 'worker' }],

  authenticatedStorageState: [async ({ finWorker }, use) => {
    await use(await createAuthenticatedStorageState(finWorker.baseURL));
  }, { scope: 'worker' }],

  storageState: async ({ authenticatedStorageState }, use) => {
    await use(authenticatedStorageState);
  },

  pristineDatabase: [async ({ finWorker }, use) => {
    finWorker.restoreBaseline();
    await use();
  }, { auto: true }],
});

const unauthenticatedTest = test.extend({
  storageState: async ({}, use) => {
    await use(EMPTY_STORAGE_STATE);
  },
});

module.exports = { test, unauthenticatedTest, expect };
