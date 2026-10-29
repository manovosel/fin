'use strict';

const { request, expect } = require('@playwright/test');

const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

async function createAuthenticatedStorageState(baseURL) {
  const api = await request.newContext({ baseURL });
  try {
    const response = await api.post('/api.php', {
      headers: { 'content-type': 'application/json' },
      data: { action: 'masterPinLogin', pin: '12345678' },
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    return await api.storageState();
  } finally {
    await api.dispose();
  }
}

async function openAuthenticatedApp(page) {
  const stateLoaded = page.waitForResponse((response) => response.url().includes('/api.php?action=state&'));
  await page.goto('/');
  await stateLoaded;
  await expect(page.locator('#appView')).toBeVisible();
}

function waitForApiAction(page, action) {
  return page.waitForResponse((response) => {
    if (!response.url().includes('/api.php') || response.request().method() !== 'POST') return false;
    try {
      return response.request().postDataJSON()?.action === action;
    } catch {
      return false;
    }
  });
}

async function signInWithMasterPin(page, url = '/') {
  if (url !== null) await page.goto(url);
  await expect(page.locator('#pinLoginForm')).toBeVisible();
  await expect(page.locator('#loginUsername')).toHaveCount(0);
  await page.locator('#masterPin').fill('12345678');
  const stateLoaded = page.waitForResponse((response) => response.url().includes('/api.php?action=state&'));
  await page.locator('#pinLoginForm').getByRole('button', { name: 'Sign in' }).click();
  await stateLoaded;
  await expect(page.locator('#appView')).toBeVisible();
}

module.exports = {
  EMPTY_STORAGE_STATE,
  createAuthenticatedStorageState,
  openAuthenticatedApp,
  signInWithMasterPin,
  waitForApiAction,
};
