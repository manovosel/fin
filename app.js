const state = {
  user: null,
  csrfToken: null,
  deviceCredentials: [],
  year: new Date().getFullYear(),
  years: [],
  months: [],
  entries: [],
  transactions: [],
  movements: [],
  envelopeBalanceObservations: [],
  accounts: [],
  envelopes: [],
  currentBalanceEnvelopeId: null,
  reportMonths: [],
  recentExpensesMonthId: null,
  recentExpensesLimit: 24,
  appVersion: '',
  databaseVersion: '',
  currency: 'DKK',
  helperCurrency: 'EUR',
  groupPrefs: {},
  activeScreen: 'today',
  selectedPlanYear: new Date().getFullYear(),
  selectedPlanMonth: new Date().getMonth() + 1,
  fundingRecommendationCache: {},
  activeRecommendationEnvelopeId: null,
  currentAccountId: null,
  statementPreview: null,
  statementFileBase64: '',
  reconciliation: null,
  reconciliationSaveBusy: false,
  monthFormBaseline: null,
  addingMonth: false,
};

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const envelopeRoleLabels = { daily: 'Daily spend', reserved: 'Reserved', savings: 'Savings' };
const allocationStatusLabels = { planned: 'Planned', paid: 'Funded', skipped: 'Skipped' };
const allocationStatusLetters = { planned: 'P', paid: 'F', skipped: 'S' };
const nextAllocationStatus = { planned: 'paid', paid: 'skipped', skipped: 'planned' };
const compareDanishLabels = (left, right) => String(left || '').localeCompare(String(right || ''), 'da', { sensitivity: 'base' });
const $ = (id) => document.getElementById(id);

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('./service-worker.js').catch((error) => console.warn('Service worker registration failed', error)));
}

init().catch((error) => showLoginError(error));

async function init() {
  bindEvents();
  const me = await apiGet('me');
  state.user = me.principal;
  state.csrfToken = me.csrf_token || null;
  state.appVersion = me.app_version || '';
  state.databaseVersion = me.database_version || '';
  loadGroupPrefs();
  if (me.authenticated) {
    showApp();
    await loadState();
  } else {
    await beginAuthentication(me);
  }
}

function bindEvents() {
  initializeDateInputs();
  $('pinLoginForm').addEventListener('submit', loginWithMasterPin);
  $('retryDeviceLoginBtn').addEventListener('click', startDeviceAuthentication);
  $('retryFailedDeviceLoginBtn').addEventListener('click', startDeviceAuthentication);
  $('useMasterPinBtn').addEventListener('click', showMasterPinLogin);
  $('useMasterPinAfterFailureBtn').addEventListener('click', showMasterPinLogin);
  $('logoutBtn').addEventListener('click', logout);
  $('securityBtn').addEventListener('click', openSecurityDialog);
  $('closeSecurityDialogBtn').addEventListener('click', () => $('securityDialog').close());
  $('monthSelect').addEventListener('change', () => {
    state.selectedPlanMonth = Number($('monthSelect').value);
    renderToday();
    renderMonths();
  });
  $('yearSelect').addEventListener('change', () => {
    state.selectedPlanYear = Number($('yearSelect').value);
    state.selectedPlanMonth = null;
    loadState(state.selectedPlanYear);
  });
  $('homeBtn').addEventListener('click', () => {
    if (state.activeScreen === 'today') {
      setScreen('plan');
    } else {
      goToCurrentMonth();
      setScreen('today');
    }
  });
  $('addExpenseBtn').addEventListener('click', () => openExpenseDialog());
  $('addEnvelopeBtn').addEventListener('click', () => openEnvelopeDialog());
  $('addMonthBtn').addEventListener('click', startAddMonth);
  $('cancelAddMonthBtn').addEventListener('click', cancelAddMonth);
  $('copyMonthBtn').addEventListener('click', openCopyDialog);
  $('reportBtn').addEventListener('click', openReportDialog);
  if ($('addEntryBtn')) $('addEntryBtn').addEventListener('click', () => openEntryDialog());
  $('closeExpenseDialogBtn').addEventListener('click', () => $('expenseDialog').close());
  $('closeEnvelopeDialogBtn').addEventListener('click', () => $('envelopeDialog').close());
  $('closeBalanceDialogBtn').addEventListener('click', () => $('balanceDialog').close());
  $('closeCopyDialogBtn').addEventListener('click', () => $('copyDialog').close());
  $('closeReportDialogBtn').addEventListener('click', () => $('reportDialog').close());
  $('closeFundingReviewDialogBtn').addEventListener('click', () => $('fundingReviewDialog').close());
  $('closeAccountDialogBtn').addEventListener('click', closeAccountDialog);
  $('closeStatementImportDialogBtn').addEventListener('click', () => $('statementImportDialog').close());
  $('statementImportBtn').addEventListener('click', openStatementImportDialog);
  $('statementImportForm').addEventListener('submit', previewStatementImport);
  $('importStatementBtn').addEventListener('click', importStatementEvidence);
  $('statementFile').addEventListener('change', resetStatementPreview);
  $('statementAccountChoice').addEventListener('change', resetStatementPreview);
  $('reconcileStatementBtn').addEventListener('click', openReconciliationDialog);
  $('closeReconciliationDialogBtn').addEventListener('click', () => { state.reconciliation = null; $('reconciliationDialog').close(); });
  $('addAccountBtn').addEventListener('click', () => openAccountDialog());
  $('accountForm').addEventListener('submit', saveAccount);
  $('accountCurrency').addEventListener('change', () => {
    $('accountInitialAmountLabel').firstChild.textContent = `Balance ${currencyCode($('accountCurrency').value)}`;
  });
  $('archiveAccountBtn').addEventListener('click', toggleCurrentAccountActive);
  $('accountReconcileForm').addEventListener('submit', reconcileCurrentAccount);
  $('fundingReviewBtn').addEventListener('click', openFundingReview);
  $('closeEntryDialogBtn').addEventListener('click', () => $('entryDialog').close());
  $('expenseForm').addEventListener('submit', saveExpense);
  $('expenseEnvelope').addEventListener('change', () => {
    updateExpenseCurrency(true);
    updateExpensePaymentAccount();
  });
  $('deleteExpenseBtn').addEventListener('click', deleteCurrentExpense);
  $('editExpenseAllocationBtn').addEventListener('click', editAutomaticExpenseAllocation);
  $('envelopeForm').addEventListener('submit', saveEnvelope);
  $('envelopeRole').addEventListener('change', updateEnvelopeGoalVisibility);
  $('deleteEnvelopeBtn').addEventListener('click', deleteCurrentEnvelope);
  $('balanceToolsBtn').addEventListener('click', () => openBalanceDialog(Number($('envelopeId').value || 0)));
  $('envelopeBalanceObservationForm').addEventListener('submit', recordCurrentEnvelopeBalance);
  $('transferBalanceForm').addEventListener('submit', transferFromCurrentEnvelope);
  $('monthForm').addEventListener('submit', saveMonth);
  $('monthForm').addEventListener('invalid', (event) => {
    event.preventDefault();
    showMonthFormMessage(event.target.validationMessage || 'Check the highlighted month value.');
  }, true);
  $('monthForm').addEventListener('input', () => {
    showMonthFormMessage('');
    updateMonthFormDirtyState();
  });
  $('copyForm').addEventListener('submit', copyMonth);
  $('copyFromMonth').addEventListener('change', () => {
    setCopyTargetFromSource();
    updateCopyExistingWarning();
  });
  $('copyToYear').addEventListener('input', updateCopyExistingWarning);
  $('copyToMonth').addEventListener('input', updateCopyExistingWarning);
  $('downloadPdfBtn').addEventListener('click', () => downloadReport('pdf'));
  $('downloadXlsxBtn').addEventListener('click', () => downloadReport('xlsx'));
  $('entryForm').addEventListener('submit', saveEntry);
  $('entryType').addEventListener('change', updateEntryEnvelopeVisibility);
  $('entryBs').addEventListener('change', updateBsDateLabel);
  $('deleteEntryBtn').addEventListener('click', deleteCurrentEntry);
  $('entryEnvelope').addEventListener('input', handleEntryEnvelopeInput);
  $('entryEnvelope').addEventListener('change', handleEntryEnvelopeInput);
  document.addEventListener('toggle', (event) => {
    const node = event.target;
    if (!node || node.tagName !== 'DETAILS') return;
    const key = node.dataset?.groupKey;
    if (!key) return;
    setGroupPref(key, Boolean(node.open));
  }, true);
  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-plan-group-toggle]');
    if (!toggle) return;
    const key = toggle.dataset.planGroupToggle;
    setGroupPref(key, !groupIsOpen(key, true));
    renderMonths();
  });
}

async function loginWithMasterPin(event) {
  event.preventDefault();
  setAuthMessage('');
  const submitButton = event.submitter;
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.classList.add('is-loading');
    submitButton.setAttribute('aria-busy', 'true');
  }
  try {
    const data = await apiPost({ action: 'masterPinLogin', pin: $('masterPin').value });
    $('masterPin').value = '';
    state.user = data.principal || { name: 'owner' };
    state.csrfToken = data.csrf_token || null;
    await enrollCurrentDevice();
    await finishAuthentication(data);
  } catch (error) {
    $('masterPin').value = '';
    setAuthMessage(error.message || 'Authentication failed');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.classList.remove('is-loading');
      submitButton.removeAttribute('aria-busy');
    }
  }
}

async function logout() {
  await apiPost({ action: 'logout' });
  state.user = null;
  state.csrfToken = null;
  state.groupPrefs = {};
  const me = await apiGet('me');
  await beginAuthentication(me);
}

const authStateIds = ['deviceAuthProgress', 'deviceAuthCancelled', 'deviceAuthFailed', 'pinLoginForm', 'authConfigurationRequired'];

function showAuthState(id) {
  showAuth();
  authStateIds.forEach((stateId) => $(stateId).classList.toggle('hidden', stateId !== id));
}

function setAuthMessage(message) {
  $('authMessage').textContent = message || '';
  $('authMessage').classList.toggle('hidden', !message);
}

function showMasterPinLogin() {
  setAuthMessage('');
  showAuthState('pinLoginForm');
  setTimeout(() => $('masterPin').focus(), 0);
}

function webauthnSupported() {
  return Boolean(window.PublicKeyCredential && navigator.credentials?.get && navigator.credentials?.create);
}

async function platformAuthenticatorAvailable() {
  if (!webauthnSupported()) return false;
  if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function') return true;
  try { return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch { return false; }
}

async function beginAuthentication(me) {
  state.user = null;
  state.csrfToken = null;
  setAuthMessage('');
  const credentialId = localStorage.getItem('fin.deviceCredentialId');
  if (credentialId && await platformAuthenticatorAvailable()) {
    await startDeviceAuthentication();
    return;
  }
  if (!me.master_pin_configured) {
    showAuthState('authConfigurationRequired');
    return;
  }
  showMasterPinLogin();
}

function decodeBase64Url(value) {
  const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4);
  const binary = atob(padded);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

function encodeBase64Url(value) {
  const bytes = new Uint8Array(value);
  let binary = '';
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function decodeCredentialOptions(options, create = false) {
  const publicKey = { ...options.publicKey, challenge: decodeBase64Url(options.publicKey.challenge) };
  if (create) {
    publicKey.user = { ...publicKey.user, id: decodeBase64Url(publicKey.user.id) };
    publicKey.excludeCredentials = (publicKey.excludeCredentials || []).map((item) => ({ ...item, id: decodeBase64Url(item.id) }));
  } else {
    publicKey.allowCredentials = (publicKey.allowCredentials || []).map((item) => ({ ...item, id: decodeBase64Url(item.id) }));
  }
  return publicKey;
}

function assertionPayload(credential) {
  return {
    rawId: encodeBase64Url(credential.rawId),
    clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
    authenticatorData: encodeBase64Url(credential.response.authenticatorData),
    signature: encodeBase64Url(credential.response.signature),
    userHandle: credential.response.userHandle ? encodeBase64Url(credential.response.userHandle) : null,
  };
}

function registrationPayload(credential, label) {
  return {
    rawId: encodeBase64Url(credential.rawId),
    clientDataJSON: encodeBase64Url(credential.response.clientDataJSON),
    attestationObject: encodeBase64Url(credential.response.attestationObject),
    transports: typeof credential.response.getTransports === 'function' ? credential.response.getTransports() : [],
    label,
  };
}

async function finishAuthentication(data) {
  state.user = data.principal || { name: 'owner' };
  state.csrfToken = data.csrf_token || null;
  loadGroupPrefs();
  showApp();
  await loadState();
}

async function startDeviceAuthentication() {
  const credentialId = localStorage.getItem('fin.deviceCredentialId');
  if (!credentialId || !(await platformAuthenticatorAvailable())) {
    showMasterPinLogin();
    return;
  }
  setAuthMessage('');
  $('deviceAuthProgress').querySelector('p').textContent = 'Checking device login…';
  showAuthState('deviceAuthProgress');
  try {
    const request = await apiPost({ action: 'webauthnAssertionOptions', credential_id: credentialId });
    if (!request.available || !request.options) {
      localStorage.removeItem('fin.deviceCredentialId');
      showMasterPinLogin();
      return;
    }
    const credential = await navigator.credentials.get({ publicKey: decodeCredentialOptions(request.options) });
    if (!credential) throw new Error('Device authentication failed');
    const data = await apiPost({ action: 'webauthnAssertionVerify', credential: assertionPayload(credential) });
    await finishAuthentication(data);
  } catch (error) {
    showAuthState(error?.name === 'NotAllowedError' ? 'deviceAuthCancelled' : 'deviceAuthFailed');
  }
}

async function enrollCurrentDevice() {
  if (!(await platformAuthenticatorAvailable())) return;
  showAuthState('deviceAuthProgress');
  $('deviceAuthProgress').querySelector('p').textContent = 'Setting up device login…';
  try {
    const response = await apiPost({ action: 'webauthnRegistrationOptions' });
    const credential = await navigator.credentials.create({ publicKey: decodeCredentialOptions(response.options, true) });
    if (!credential) return;
    await apiPost({ action: 'webauthnRegistrationVerify', credential: registrationPayload(credential, '') });
    localStorage.setItem('fin.deviceCredentialId', encodeBase64Url(credential.rawId));
  } catch {
    localStorage.removeItem('fin.deviceCredentialId');
  }
}

async function openSecurityDialog() {
  $('securityDialog').showModal();
  $('securityMessage').textContent = '';
  $('securityMessage').classList.add('hidden');
  const data = await apiGet('deviceCredentials');
  state.deviceCredentials = data.credentials || [];
  renderDeviceCredentials();
}

function renderDeviceCredentials() {
  const list = $('deviceCredentialList');
  if (!state.deviceCredentials.length) {
    list.innerHTML = '<p class="muted">No devices enabled.</p>';
    return;
  }
  list.innerHTML = state.deviceCredentials.map((credential) => `
    <div class="device-credential-row" data-device-id="${credential.id}">
      <div><strong>${escapeHtml(credential.label)}</strong><small>Enabled${credential.last_used_at ? ` · Last used ${escapeHtml(formatDate(String(credential.last_used_at).slice(0, 10)))}` : ''}</small></div>
      <div class="device-credential-actions">
        <button type="button" class="ghost small" data-device-rename="${credential.id}"><span class="button-spinner" aria-hidden="true"></span>Rename</button>
        <button type="button" class="danger small" data-device-revoke="${credential.id}"><span class="button-spinner" aria-hidden="true"></span>Remove</button>
      </div>
    </div>`).join('');
  list.querySelectorAll('[data-device-rename]').forEach((button) => button.addEventListener('click', () => renameDeviceCredential(Number(button.dataset.deviceRename), button)));
  list.querySelectorAll('[data-device-revoke]').forEach((button) => button.addEventListener('click', () => revokeDeviceCredential(Number(button.dataset.deviceRevoke), button)));
}

function showSecurityMessage(message) {
  $('securityMessage').textContent = message || '';
  $('securityMessage').classList.toggle('hidden', !message);
}

async function renameDeviceCredential(id, button) {
  const credential = state.deviceCredentials.find((item) => Number(item.id) === id);
  if (!credential) return;
  const label = window.prompt('Device name', credential.label);
  if (label === null) return;
  const data = await runSaveAction({
    button,
    request: () => apiPost({ action: 'renameWebauthnCredential', id, label }),
    successMessage: 'Device login renamed.',
    failureMessage: 'Device login could not be updated.',
  });
  if (!data) return;
  state.deviceCredentials = data.credentials || [];
  renderDeviceCredentials();
}

async function revokeDeviceCredential(id, button) {
  if (!window.confirm('Remove this device login?')) return;
  const data = await runSaveAction({
    button,
    request: () => apiPost({ action: 'revokeWebauthnCredential', id }),
    successMessage: null,
    failureMessage: 'Device login could not be removed.',
  });
  if (!data) return;
  state.deviceCredentials = data.credentials || [];
  renderDeviceCredentials();
  showSecurityMessage('Device login removed.');
}

async function cycleAllocationStatus(monthId, envelopeId, button) {
  const entry = budgetEntryForEnvelope(monthId, envelopeId);
  const current = entry?.status || 'planned';
  const next = nextAllocationStatus[current] || 'planned';
  const data = await runSaveAction({
    button,
    request: () => apiPost({ action: 'setAllocationStatus', month_id: monthId, envelope_id: envelopeId, status: next, year: state.year }),
    successMessage: `Plan ${allocationStatusLabels[next].toLowerCase()}`,
    failureMessage: 'Failed to update Funding status.',
  });
  if (!data) return;
  applyState(data.state);
}

function groupPrefsStorageKey() {
  const user = state.user?.name || 'owner';
  return `fin.groupPrefs.${user}`;
}

function loadGroupPrefs() {
  try {
    const raw = localStorage.getItem(groupPrefsStorageKey());
    state.groupPrefs = raw ? JSON.parse(raw) : {};
  } catch {
    state.groupPrefs = {};
  }
}

function saveGroupPrefs() {
  try {
    localStorage.setItem(groupPrefsStorageKey(), JSON.stringify(state.groupPrefs || {}));
  } catch {
    // ignore storage errors
  }
}

function groupIsOpen(key, fallbackOpen = true) {
  if (!key) return fallbackOpen;
  if (Object.prototype.hasOwnProperty.call(state.groupPrefs, key)) return Boolean(state.groupPrefs[key]);
  return fallbackOpen;
}

function setGroupPref(key, open) {
  if (!key) return;
  state.groupPrefs[key] = Boolean(open);
  saveGroupPrefs();
}

async function loadState(year = state.year) {
  const data = await apiGet(`state&year=${encodeURIComponent(year)}`);
  applyState(data);
}

function applyState(data) {
  state.year = Number(data.year);
  state.currency = data.currency || state.currency;
  state.helperCurrency = data.helper_currency || state.helperCurrency;
  state.currentExchangeRate = data.current_exchange_rate === undefined ? null : (data.current_exchange_rate === null ? null : Number(data.current_exchange_rate));
  state.years = data.years || [];
  state.months = (data.months || []).map(normalizeMonth);
  state.entries = (data.entries || []).map(normalizeEntry);
  state.transactions = (data.transactions || []).map(normalizeTransaction);
  state.movements = (data.movements || []).map(normalizeMovement);
  state.envelopeBalanceObservations = (data.envelope_balance_observations || []).map(normalizeEnvelopeBalanceObservation);
  state.accounts = (data.accounts || []).map(normalizeAccount);
  state.envelopes = (data.envelopes || []).map(normalizeEnvelope);
  state.reportMonths = (data.report_months || []).map((month) => ({ id: Number(month.id), year: Number(month.year), month: Number(month.month) }));
  state.appVersion = data.app_version || state.appVersion;
  state.databaseVersion = data.database_version || state.databaseVersion;
  state.fundingRecommendationCache = {};
  ensureSelectedPlanMonth();
  renderAll();
}

function setScreen(screen) {
  state.activeScreen = screen;
  $('todayView').classList.toggle('hidden', screen !== 'today');
  $('planView').classList.toggle('hidden', screen !== 'plan');
  $('availableDetails').classList.toggle('hidden', screen !== 'today');
  $('topSelectors').classList.toggle('hidden', screen === 'today');
  $('securityBtn').classList.toggle('hidden', screen !== 'plan');
  const target = screen === 'today' ? 'Month' : 'Today';
  $('homeBtn').setAttribute('aria-label', target);
  $('homeBtn').title = target;
  $('homeBtn').querySelector('.view-icon-month').classList.toggle('hidden', screen !== 'today');
  $('homeBtn').querySelector('.view-icon-home').classList.toggle('hidden', screen === 'today');
}

function renderAll() {
  renderVersionInfo();
  renderCurrencyControls();
  renderYearSelect();
  renderMonthSelect();
  renderEntryMonthOptions();
  renderEntryEnvelopeOptions();
  renderCopyOptions();
  renderReportOptions();
  renderToday();
  renderMonths();
  setScreen(state.activeScreen);
}

function renderVersionInfo() {
  const app = state.appVersion ? `v${state.appVersion}` : 'development';
  const database = state.databaseVersion ? `DB v${state.databaseVersion}` : 'DB unknown';
  $('versionInfo').textContent = `${app} · ${database}`;
}

function currencyCode(preference) {
  return preference === 'HELPER_CURRENCY' ? state.helperCurrency : state.currency;
}

function renderCurrencyControls() {
  for (const id of ['accountCurrency', 'envelopeCurrency']) {
    const select = $(id);
    if (!select) continue;
    select.querySelector('option[value="CURRENCY"]').textContent = state.currency;
    select.querySelector('option[value="HELPER_CURRENCY"]').textContent = state.helperCurrency;
  }
  $('exchangeRateLabel').firstChild.textContent = `${state.helperCurrency} → ${state.currency} exchange rate`;
  $('salaryLabel').firstChild.textContent = `Salary ${state.currency}`;
  $('envelopeGoalLabel').firstChild.textContent = `Savings goal ${state.currency}`;
  $('envelopeManualBaselineLabel').firstChild.textContent = `Historical baseline ${state.currency}/month`;
  $('envelopeBalanceObservationAmountLabel').firstChild.textContent = `Absolute Balance ${state.currency}`;
  $('transferAmountLabel').firstChild.textContent = `Amount ${state.currency}`;
}

function renderYearSelect() {
  const years = new Set(state.years.length ? state.years : [state.year]);
  years.add(state.selectedPlanYear || state.year);
  $('yearSelect').innerHTML = [...years].sort((a, b) => a - b).map((year) => `<option value="${year}" ${year === state.selectedPlanYear ? 'selected' : ''}>${year}</option>`).join('');
}

function renderMonthSelect() {
  $('monthSelect').innerHTML = state.months.map((month) => `<option value="${month.month}" ${month.month === state.selectedPlanMonth ? 'selected' : ''}>${monthNames[month.month - 1]}</option>`).join('');
}

function renderEntryMonthOptions() {
  $('entryMonth').innerHTML = state.months.map((month) => `<option value="${month.id}">${month.year} ${monthNames[month.month - 1]}</option>`).join('');
}

function renderEntryEnvelopeOptions(monthId = null, currentEntryId = null) {
  const selectedMonthId = Number(monthId || selectedPlanMonth()?.id || activeMonth()?.id || 0);
  const usedEnvelopeIds = new Set(state.entries
    .filter((entry) => entry.month_id === selectedMonthId && entry.type !== 'income' && entry.id !== Number(currentEntryId || 0))
    .map((entry) => Number(entry.envelope_id || 0)));
  const roles = ['daily', 'reserved', 'savings'];
  $('entryEnvelope').innerHTML = '<option value="">Select envelope</option>' + roles.map((role) => {
    const envelopes = state.envelopes
      .filter((envelope) => envelopeRole(envelope) === role && !usedEnvelopeIds.has(envelope.id))
      .sort((a, b) => a.name.localeCompare(b.name, 'da', { sensitivity: 'base' }));
    return envelopes.length
      ? `<optgroup label="${escapeHtml(envelopeRoleLabels[role])}">${envelopes.map((envelope) => `<option value="${envelope.id}">${escapeHtml(envelope.name)}</option>`).join('')}</optgroup>`
      : '';
  }).join('');
}

function accountOptionLabel(account) {
  return `${account.name}${account.active ? '' : ' · Archived'}`;
}

function realAccountOptions(emptyLabel, selectedId = null, includeAccountNumber = false) {
  const selectable = state.accounts.filter((account) => account.active || account.id === Number(selectedId || 0));
  return `<option value="">${escapeHtml(emptyLabel)}</option>` + selectable.map((account) => `<option value="${account.id}">${escapeHtml(accountOptionLabel(account))}${includeAccountNumber && account.account_number ? ` · ${escapeHtml(account.account_number)}` : ''}</option>`).join('');
}

function renderExpensePaymentAccountOptions(authoritativeId = undefined) {
  $('expensePaymentAccount').innerHTML = realAccountOptions('Unknown / not recorded', authoritativeId);
  if (authoritativeId !== undefined) {
    $('expensePaymentAccount').value = authoritativeId === null ? '' : String(authoritativeId);
    return;
  }
  updateExpensePaymentAccount();
}

function updateExpensePaymentAccount() {
  const envelope = state.envelopes.find((item) => item.id === Number($('expenseEnvelope').value));
  const defaultAccount = state.accounts.find((account) => account.id === Number(envelope?.default_account_id || 0) && account.active);
  $('expensePaymentAccount').value = defaultAccount ? String(defaultAccount.id) : '';
}

function renderEnvelopeDefaultAccountOptions(selectedId = null) {
  $('envelopeDefaultAccount').innerHTML = realAccountOptions('No default payment account');
  $('envelopeDefaultAccount').value = selectedId ? String(selectedId) : '';
}

function renderCopyOptions() {
  $('copyFromMonth').innerHTML = state.months.map((month) => `<option value="${month.year}-${month.month}">${month.year} ${monthNames[month.month - 1]}</option>`).join('');
  const source = selectedPlanMonth() || activeMonth() || state.months[state.months.length - 1];
  if (source) $('copyFromMonth').value = `${source.year}-${source.month}`;
  setCopyTargetFromSource();
}

function renderReportOptions() {
  const months = state.reportMonths.length ? state.reportMonths : state.months;
  const options = months.map((month) => {
    const value = monthKey(month.year, month.month);
    return `<option value="${value}">${month.year} ${monthNames[month.month - 1]}</option>`;
  }).join('');
  $('reportFromMonth').innerHTML = options;
  $('reportToMonth').innerHTML = options;
  const selected = selectedPlanMonth() || activeMonth() || months[months.length - 1];
  if (selected) {
    const key = monthKey(selected.year, selected.month);
    $('reportFromMonth').value = key;
    $('reportToMonth').value = key;
  }
}

function renderToday() {
  $('recentExpensesDetails').open = groupIsOpen('recent-expenses', true);
  const today = new Date();
  const month = state.months.find((item) => item.year === today.getFullYear() && item.month === today.getMonth() + 1);
  if (!month) {
    $('recentExpenses').innerHTML = '';
    $('availableTotal').textContent = money(0);
    $('safeSpendLabel').textContent = '';
    $('safeEnvelopeRows').innerHTML = '';
    setTodayStatusTone('on-track');
    return;
  }
  const envelopes = spendingEnvelopes(month);
  renderSafeEnvelopeRows(envelopes, month);
  renderExpenseOptions();
  renderRecentExpenses(month);
}

function renderSafeEnvelopeRows(envelopes, month) {
  const rows = envelopes
    .sort((a, b) => a.label.localeCompare(b.label, 'da', { sensitivity: 'base' }));
  const visibleRows = rows.filter((env) => Number(env.remaining || 0) !== 0);
  const grouped = {
    daily: visibleRows.filter((env) => envelopeRole(env.envelope) === 'daily'),
    reserved: visibleRows.filter((env) => envelopeRole(env.envelope) === 'reserved'),
    savings: visibleRows.filter((env) => envelopeRole(env.envelope) === 'savings'),
  };
  const todayGoals = rows.filter((env) => envelopeRole(env.envelope) === 'savings' && Number(env.envelope.savings_goal || 0) > 0 && goalVisibilityForEnvelope(env.envelope) === 'today');
  const todayGoalIds = new Set(todayGoals.map((env) => env.envelope.id));
  const shownOnToday = visibleRows.filter((env) => envelopeRole(env.envelope) !== 'daily' && envelopeVisibility(env.envelope) === 'today' && !todayGoalIds.has(env.envelope.id));
  renderSafeSpendHero(rows.filter((env) => envelopeRole(env.envelope) === 'daily'), month);

  const daily = renderTodayEnvelopeGroup('daily', grouped.daily, month) || '<div class="muted">No daily spend envelopes yet.</div>';
  const pinned = shownOnToday.length
    ? `<section class="today-envelope-group today-pinned-group"><div class="today-envelope-group-body">${shownOnToday.map((env) => renderTodayEnvelopeRow(env, month)).join('')}</div></section>`
    : '';
  const goals = renderTodayGoals(todayGoals);
  $('safeEnvelopeRows').innerHTML = daily + pinned + goals;
}

function renderSafeSpendHero(dailyRows, month) {
  const dailyTotal = dailyRows.reduce((sum, env) => sum + Number(env.remaining || 0), 0);
  const safe = Finance.safeToSpend(dailyTotal, month.year, month.month);
  const status = monthStatusFor(month);
  $('availableTotal').textContent = money(safe.amount);
  $('safeSpendLabel').textContent = safe.label;
  setTodayStatusTone(status.period === 'current' ? status.state : 'on-track');
}

function setTodayStatusTone(status) {
  const card = $('availableDetails');
  card.classList.remove('status-on-track', 'status-getting-tight', 'status-needs-attention');
  card.classList.add(`status-${['getting-tight', 'needs-attention'].includes(status) ? status : 'on-track'}`);
}

function renderTodayEnvelopeGroup(role, rows, month) {
  if (!rows.length) return '';
  const title = envelopeRoleLabels[role] || role;
  const body = `<div class="today-envelope-group-body">${rows.map((env) => renderTodayEnvelopeRow(env, month)).join('')}</div>`;
  return `<section class="today-envelope-group role-${role}"><h3>${escapeHtml(title)}</h3>${body}</section>`;
}

function renderTodayEnvelopeRow(env, month, showContext = true) {
  const total = Number(env.budget || 0);
  const remaining = Number(env.remaining || 0);
  const role = envelopeRole(env.envelope);
  const fill = total > 0 ? Math.max(0, Math.min(100, (remaining / total) * 100)) : 0;
  if (role === 'savings') {
    return renderSavingsEnvelopeRow(env, remaining, showContext);
  }
  const amountText = money(remaining);
  return `<div class="safe-envelope-row role-${role} ${remaining < 0 ? 'negative' : ''}" style="--fill:${fill.toFixed(1)}%"><span class="safe-envelope-name">${escapeHtml(env.label)}</span><span class="safe-envelope-amount">${amountText}</span></div>`;
}

function renderSavingsEnvelopeRow(env, amount, showContext = true) {
  const goal = Number(env.envelope.savings_goal || 0);
  if (goal > 0 && goalVisibilityForEnvelope(env.envelope) === 'today') return renderGoalProgress(env.envelope, env.label);
  return `<div class="safe-envelope-row role-savings"><span class="safe-envelope-name">${escapeHtml(env.label)}</span><span class="safe-envelope-amount">${money(amount)}</span></div>`;
}

function renderTodayGoals(rows) {
  if (!rows.length) return '';
  return `<section class="today-envelope-group today-goals-group"><div class="today-envelope-group-body">${rows.map((env) => renderGoalProgress(env.envelope, env.label)).join('')}</div></section>`;
}

function renderGoalProgress(envelope, label = envelope.name, extraClass = '') {
  const progress = Finance.goalProgress(envelope.balance, envelope.savings_goal);
  const status = `${progress.displayPercent}%`;
  return `<div class="savings-progress-row ${extraClass}${progress.reached ? ' goal-reached' : ''}" style="--fill:${progress.fill.toFixed(1)}%"><div class="savings-progress-head"><span class="safe-envelope-name">${escapeHtml(label)}</span><span class="goal-progress-label">${status}</span></div><div class="savings-progress-track" role="progressbar" aria-label="${escapeHtml(label)} goal progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.round(progress.fill)}" aria-valuetext="${status}"><span></span></div></div>`;
}

function renderExpenseOptions() {
  const byUsage = (a, b) => Number(b.usage_count || 0) - Number(a.usage_count || 0)
    || a.name.localeCompare(b.name, 'da', { sensitivity: 'base' });
  const renderGroup = (label, envelopes) => envelopes.length
    ? `<optgroup label="${escapeHtml(label)}">${envelopes.sort(byUsage).map((envelope) => `<option value="${envelope.id}">${escapeHtml(envelope.name)}</option>`).join('')}</optgroup>`
    : '';
  const visible = state.envelopes.filter((envelope) => envelopeVisibility(envelope) !== 'hidden');
  const hidden = state.envelopes.filter((envelope) => envelopeVisibility(envelope) === 'hidden');
  $('expenseEnvelope').innerHTML = renderGroup('Visible envelopes', visible) + renderGroup('Out of sight envelopes', hidden);
}

function renderRecentExpenses(month) {
  if (state.recentExpensesMonthId !== month.id) {
    state.recentExpensesMonthId = month.id;
    state.recentExpensesLimit = 24;
  }
  const allRows = transactionsForMonth(month.id).filter((tx) => tx.kind === 'expense');
  const rows = allRows.slice(0, state.recentExpensesLimit);
  if (!rows.length) {
    $('recentExpenses').innerHTML = '<p class="muted">No expenses registered yet.</p>';
    return;
  }
  const remaining = allRows.length - rows.length;
  const controls = remaining > 0 || state.recentExpensesLimit > 24
    ? `<div class="recent-expenses-controls">${remaining > 0 ? '<button type="button" class="ghost" onclick="showMoreRecentExpenses()">Show more</button>' : ''}${state.recentExpensesLimit > 24 ? '<button type="button" class="ghost" onclick="showLessRecentExpenses()">Show less</button>' : ''}</div>`
    : '';
  $('recentExpenses').innerHTML = `
    <div class="table-wrap"><table class="recent-expenses-table">
      <thead><tr><th class="recent-date">Date</th><th class="recent-note">Note</th><th class="recent-envelope">Envelope</th><th class="num recent-amount">Amount</th><th class="recent-actions"></th></tr></thead>
      <tbody>${rows.map((tx) => {
        const action = tx.is_automatic ? 'View Direct debit expense' : 'Edit expense';
        const note = tx.note ? escapeHtml(tx.note) : '<span class="muted">—</span>';
        const helperAmount = helperAmountForDate(tx.amount, tx.spent_date);
        const helperDisplay = helperAmount === null ? '<span>Conversion unavailable</span>' : `<span>${helperMoney(helperAmount)}</span>`;
        return `<tr class="transaction-expense"><td class="recent-date" title="${formatRecentDate(tx.spent_date)}">${formatRecentShortDate(tx.spent_date)}</td><td class="recent-note" title="${escapeHtml(tx.note || '')}">${note}</td><td class="recent-envelope" title="${escapeHtml(tx.envelope)}">${escapeHtml(tx.envelope)}</td><td class="num recent-amount">${helperDisplay}<span>${money(tx.amount)}</span></td><td class="num recent-actions"><button class="ghost small" aria-label="${action}" title="${action}" onclick="openExpenseDialog(${tx.id})"><svg class="recent-edit-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg></button></td></tr>`;
      }).join('')}</tbody>
    </table></div>${controls}`;
}

function showMoreRecentExpenses() {
  const month = activeMonth();
  if (!month) return;
  state.recentExpensesLimit += 16;
  renderRecentExpenses(month);
}

function showLessRecentExpenses() {
  const month = activeMonth();
  if (!month) return;
  state.recentExpensesLimit = 24;
  renderRecentExpenses(month);
}

function renderMonths() {
  const month = selectedPlanMonth();
  for (const key of ['month', 'accounts', 'envelopes', 'plan-summary', 'funding']) {
    const details = $(`${key === 'plan-summary' ? 'planSummary' : key.replace(/^./, (letter) => letter.toUpperCase())}Details`);
    if (details) details.open = groupIsOpen(key, false);
  }
  renderMonthSection(month);
  renderAccountsList();
  renderEnvelopeManagementList();
  renderPlanSummary(month);
  if (!month) {
    $('months').innerHTML = '<p class="month-status-empty">Select or add a Month.</p>';
    return;
  }
  const entries = entriesForMonth(month.id);
  const sections = monthSections(month, entries);
  $('months').innerHTML = `${renderUncategorizedWarning(entries)}${renderFundingPlanSection(month, sections.envelopePlan)}`;
}

function accountPositionText(account) {
  if (!account?.balance_known || account.balance === null) return 'Unknown';
  if (account.display_balance === null || account.display_balance === undefined) {
    return `${moneyForCurrency(account.balance, state.currency)} · conversion unavailable`;
  }
  return moneyForCurrency(account.display_balance, account.display_currency || currencyCode(account.currency));
}

function renderAccountsList() {
  const active = state.accounts.filter((account) => account.active);
  const archived = state.accounts.filter((account) => !account.active);
  const rows = (accounts) => accounts.map((account) => `<button type="button" class="managed-account-row${account.active ? '' : ' archived'}" onclick="openAccountDialog(${account.id})"><span><strong>${escapeHtml(account.name)}</strong><small>${escapeHtml(account.account_number)} · ${escapeHtml(currencyCode(account.currency))}</small></span><span>${escapeHtml(accountPositionText(account))}</span></button>`).join('');
  const activeHtml = active.length ? rows(active) : '<p class="account-empty">No Accounts yet.</p>';
  const archivedHtml = archived.length ? `<details class="archived-accounts"><summary>Archived Accounts · ${archived.length}</summary><div>${rows(archived)}</div></details>` : '';
  $('accountsList').innerHTML = `<section><h3>Current</h3><div class="managed-account-list">${activeHtml}</div></section>${archivedHtml}`;
}

function renderEnvelopeManagementList() {
  const list = $('envelopesManagementList');
  if (!state.envelopes.length) {
    list.innerHTML = '<p class="account-empty">No Envelopes yet.</p>';
    return;
  }
  list.innerHTML = [...state.envelopes].sort((left, right) => compareDanishLabels(left.name, right.name)).map((envelope) => {
    const account = state.accounts.find((item) => item.id === Number(envelope.default_account_id || 0));
    const helperCurrencyCode = envelope.currency === 'HELPER_CURRENCY' ? currencyCode(envelope.currency) : '';
    const meta = [envelopeRoleLabels[envelopeRole(envelope)], account?.name || 'No Account', helperCurrencyCode].filter(Boolean).join(' · ');
    return `<button type="button" class="managed-account-row" onclick="openEnvelopeDialog(${envelope.id})"><span><strong>${escapeHtml(envelope.name)}</strong><small>${escapeHtml(meta)}</small></span><span>${money(envelope.balance)}</span></button>`;
  }).join('');
}

function resetStatementPreview() {
  state.statementPreview = null;
  state.statementFileBase64 = '';
  $('statementPreview').classList.add('hidden');
  $('statementImportMessage').classList.add('hidden');
  $('statementImportMessage').textContent = '';
}

function renderStatementAccountOptions() {
  const activeAccounts = state.accounts.filter((account) => account.active);
  $('statementAccountChoice').innerHTML = '<option value="">—</option>' + activeAccounts
    .map((account) => `<option value="${account.id}">${escapeHtml(account.name)} · ${escapeHtml(account.account_number)} · ${escapeHtml(currencyCode(account.currency))}</option>`)
    .join('');
  $('statementAccountChoice').value = '';
}

function showStatementImportMessage(message = '', success = false) {
  const element = $('statementImportMessage');
  element.textContent = message;
  element.classList.toggle('hidden', !message);
  element.classList.toggle('bad', !success);
  element.classList.toggle('good', success);
}

function openStatementImportDialog() {
  $('statementImportForm').reset();
  renderStatementAccountOptions();
  const hasActiveAccounts = state.accounts.some((account) => account.active);
  $('previewStatementBtn').disabled = !hasActiveAccounts;
  resetStatementPreview();
  if (!hasActiveAccounts) showStatementImportMessage('Add an active Account before importing statement evidence.');
  $('statementImportDialog').showModal();
}

async function statementFilePayload() {
  const file = $('statementFile').files?.[0];
  if (!file) throw new Error('Select a statement file.');
  if (file.size > 5_000_000) throw new Error('Statement file must be smaller than 5 MB.');
  const bytes = new Uint8Array(await file.arrayBuffer());
  let binary = '';
  for (let offset = 0; offset < bytes.length; offset += 8192) binary += String.fromCharCode(...bytes.subarray(offset, offset + 8192));
  state.statementFileBase64 = btoa(binary);
  return {
    filename: file.name,
    file_base64: state.statementFileBase64,
  };
}

function renderStatementPreview(preview) {
  if (!preview?.account?.name) throw new Error('Statement preview response was incomplete.');
  state.statementPreview = preview;
  $('statementPreviewAccount').textContent = preview.account.name;
  $('statementPreviewFileAccountNumber').textContent = preview.file_account_number || '—';
  $('statementPreviewPeriod').textContent = preview.statement_from && preview.statement_to
    ? `${formatDate(preview.statement_from)} – ${formatDate(preview.statement_to)}`
    : 'Unknown';
  $('statementPreviewRows').textContent = String(preview.rows_found);
  $('statementPreviewNew').textContent = String(preview.new_rows);
  $('statementPreviewKnown').textContent = String(preview.known_rows);
  $('statementPreviewProblemCount').textContent = String(preview.parse_problem_count);
  $('statementPreviewProblems').innerHTML = preview.parse_problems?.length
    ? `<ul class="statement-problems">${preview.parse_problems.map((problem) => `<li>Row ${problem.row}: ${escapeHtml(problem.problem)}</li>`).join('')}</ul>`
    : '';
  $('importStatementBtn').disabled = preview.new_rows === 0;
  $('statementPreview').classList.remove('hidden');
}

async function previewStatementImport(event) {
  event.preventDefault();
  showStatementImportMessage('');
  const accountId = Number($('statementAccountChoice').value || 0);
  if (!accountId) return showStatementImportMessage('Select an Account.');
  const data = await runSaveAction({
    button: event.submitter,
    request: async () => {
      const payload = await statementFilePayload();
      const result = await apiPost({ action: 'previewStatementImport', account_id: accountId, ...payload });
      const preview = result?.preview;
      if (!preview || typeof preview !== 'object') throw new Error('Statement preview response was incomplete.');
      return preview;
    },
    successMessage: null,
    failureMessage: 'Could not preview statement.',
  });
  if (!data) {
    resetStatementPreview();
    $('previewStatementBtn').disabled = !state.accounts.some((account) => account.active);
    return;
  }
  renderStatementPreview(data);
}

async function importStatementEvidence(event) {
  showStatementImportMessage('');
  const accountId = Number($('statementAccountChoice').value || 0);
  if (!accountId) return showStatementImportMessage('Select an Account.');
  if (!state.statementPreview || !state.statementFileBase64) return showStatementImportMessage('Preview the statement before importing evidence.');
  if (Number(state.statementPreview.account?.id || 0) !== accountId) return showStatementImportMessage('Preview the statement for the selected Account before importing evidence.');
  const file = $('statementFile').files?.[0];
  const data = await runSaveAction({
    button: event.currentTarget,
    request: () => apiPost({
      action: 'importStatementEvidence',
      account_id: accountId,
      filename: file.name,
      file_base64: state.statementFileBase64,
    }),
    successMessage: null,
    failureMessage: 'Could not import statement evidence.',
  });
  if (!data) return;
  renderStatementPreview(data.preview);
  showStatementImportMessage(data.duplicate_file
    ? 'This exact statement file was already imported.'
    : `Evidence imported: ${data.preview.new_rows} new row${data.preview.new_rows === 1 ? '' : 's'}.`, true);
}

function showAccountFormMessage(message = '') {
  $('accountFormMessage').textContent = message;
  $('accountFormMessage').classList.toggle('hidden', !message);
}

// --- Statement reconciliation -----------------------------------------------

function reconciliationAccountSequence(startAccountId = null) {
  const active = state.accounts.filter((item) => item.active);
  if (startAccountId === null) return active;
  const startIndex = active.findIndex((item) => Number(item.id) === Number(startAccountId));
  return startIndex >= 0 ? active.slice(startIndex) : active;
}

async function loadNextReconciliationBatch(startAccountId = null, preservedPending = {}, preservedErrors = {}) {
  const candidates = reconciliationAccountSequence(startAccountId);
  if (!candidates.length) throw new Error('No active accounts');
  for (const candidate of candidates) {
    let data;
    try {
      data = await apiPost({ action: 'loadStatementReconciliation', account_id: candidate.id });
      if (!data?.account || !Array.isArray(data?.state?.unresolved)) throw new Error('Reconciliation response was incomplete.');
    } catch (error) {
      throw new Error(`Could not load statement exceptions for ${candidate.name}: ${error.message || 'Request failed'}`);
    }
    if (data.state.unresolved.length > 0) {
      const pending = Object.fromEntries((data.preselections || []).map((selection) => [String(selection.statement_row_id), selection]));
      const sameAccount = Number(candidate.id) === Number(startAccountId || 0);
      if (sameAccount) Object.assign(pending, preservedPending);
      const unresolvedCount = Number(data.state.unresolved_count);
      state.reconciliation = {
        account_id: data.account.id,
        account_name: data.account.name,
        unresolved: data.state.unresolved,
        unresolved_count: Number.isInteger(unresolvedCount) && unresolvedCount >= 0 ? unresolvedCount : data.state.unresolved.length,
        reconciled: data.state.reconciled || [],
        pending,
        errors: sameAccount ? { ...preservedErrors } : {},
      };
      renderReconciliationState();
      return true;
    }
  }
  const emptyAccount = candidates[0];
  state.reconciliation = { account_id: emptyAccount.id, account_name: emptyAccount.name, unresolved: [], unresolved_count: 0, reconciled: [], pending: {}, errors: {} };
  renderReconciliationState();
  return false;
}

async function openReconciliationDialog() {
  if (!state.accounts.length) return toast('Add an Account first');
  if (!state.accounts.some((item) => item.active)) return toast('No active accounts');
  state.reconciliation = null;
  $('reconciliationDialogSubtitle').textContent = '';
  $('reconciliationDialog').showModal();
  $('reconciliationEmpty').classList.add('hidden');
  $('reconciliationUnresolvedSection').classList.remove('hidden');
  $('reconciliationUnresolved').innerHTML = '<p class="muted">Loading…</p>';
  showReconciliationMessage('');
  $('saveReconciliationExceptionsBtn').disabled = true;
  try {
    await loadNextReconciliationBatch();
  } catch (error) {
    $('reconciliationUnresolvedSection').classList.add('hidden');
    $('reconciliationEmpty').classList.add('hidden');
    showReconciliationMessage(error.message || 'Could not load statement exceptions.');
  }
}

function renderReconciliationState() {
  const r = state.reconciliation;
  if (!r) return;
  const unresolved = r.unresolved || [];
  const reportedCount = Number(r.unresolved_count);
  const unresolvedCount = Number.isInteger(reportedCount) && reportedCount >= 0 ? reportedCount : unresolved.length;
  $('reconciliationDialogSubtitle').textContent = `${r.account_name} — ${unresolvedCount} unresolved.`;
  $('reconciliationMessage').classList.add('hidden');
  $('reconciliationMessage').textContent = '';
  const unresolvedIds = new Set(unresolved.map((row) => String(row.id)));
  r.pending = Object.fromEntries(Object.entries(r.pending || {}).filter(([rowId]) => unresolvedIds.has(rowId)));
  r.errors = Object.fromEntries(Object.entries(r.errors || {}).filter(([rowId]) => unresolvedIds.has(rowId)));
  $('reconciliationEmpty').classList.toggle('hidden', unresolved.length > 0);
  $('reconciliationUnresolvedSection').classList.toggle('hidden', unresolved.length === 0);
  $('reconciliationUnresolved').innerHTML = renderReconciliationExceptionTable(unresolved) || '<p class="muted">No statement exceptions.</p>';
  updateReconciliationSaveState();
}

function renderReconciliationCandidate(candidate, rowId, checked) {
  if (candidate.kind === 'account_event') {
    const id = Number(candidate.account_event_id);
    const value = `account_event:${id}`;
    const dateLabel = formatDate(candidate.event_date);
    const amount = moneyForCurrency(Math.abs(Number(candidate.amount)), state.currency);
    const note = candidate.note ? ` · ${escapeHtml(candidate.note)}` : '';
    if (candidate.account_event_type === 'income') {
      return `<label class="reconciliation-candidate"><input type="radio" name="recon-candidate-${rowId}" value="${value}"${checked ? ' checked' : ''}><span><strong>${dateLabel}</strong> · ${amount} · Income${note}</span></label>`;
    }
    const accounts = `${escapeHtml(candidate.source_account_name || '')} → ${escapeHtml(candidate.destination_account_name || '')}`;
    return `<label class="reconciliation-candidate"><input type="radio" name="recon-candidate-${rowId}" value="${value}"${checked ? ' checked' : ''}><span><strong>${dateLabel}</strong> · ${amount} · Transfer · ${accounts}${note}</span></label>`;
  }
  const id = Number(candidate.transaction_id);
  const value = `transaction:${id}`;
  const dateLabel = formatDate(candidate.spent_date);
  const amount = moneyForCurrency(Math.abs(Number(candidate.amount)), state.currency);
  const exceptionalType = candidate.transaction_kind === 'refund' ? ' · Refund' : '';
  const envelope = escapeHtml(candidate.envelope || 'No envelope');
  const note = candidate.note ? ` · ${escapeHtml(candidate.note)}` : '';
  return `<label class="reconciliation-candidate"><input type="radio" name="recon-candidate-${rowId}" value="${value}"${checked ? ' checked' : ''}><span><strong>${dateLabel}</strong> · ${amount}${exceptionalType} · ${envelope}${note}</span></label>`;
}

function renderReconciliationExceptionTable(rows) {
  if (!rows.length) return '';
  const alphabeticallyByName = (left, right) => String(left.name || '').localeCompare(String(right.name || ''), 'da', { sensitivity: 'base' });
  const sourceAccountId = Number(state.reconciliation?.account_id || 0);
  const transferAccounts = (state.accounts || [])
    .filter((account) => account.active && Number(account.id) !== sourceAccountId)
    .sort(alphabeticallyByName);
  const pending = state.reconciliation?.pending || {};
  const errors = state.reconciliation?.errors || {};
  return `<div class="table-wrap reconciliation-exception-wrap"><table class="reconciliation-exception-table">
    <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Reconcile</th></tr></thead>
    <tbody>${rows.map((row) => {
      const selection = pending[String(row.id)] || {};
      const selected = String(selection.classification || '');
      const envelopeOptions = [...(state.envelopes || [])].sort(alphabeticallyByName).map((envelope) => {
        const value = `envelope:${envelope.id}`;
        return `<option value="${value}"${selected === value ? ' selected' : ''}>${escapeHtml(envelope.name)}</option>`;
      }).join('');
      const transferDirection = Number(row.amount) < 0 ? 'to' : 'from';
      const transferOptions = transferAccounts.map((account) => {
        const value = `account:${account.id}`;
        return `<option value="${value}"${selected === value ? ' selected' : ''}>${transferDirection} ${escapeHtml(accountOptionLabel(account))}</option>`;
      }).join('');
      const classificationOptions = `<option value="">—</option><optgroup label="Envelopes">${envelopeOptions}</optgroup><optgroup label="Transfer">${transferOptions}</optgroup><option value="ignore"${selected === 'ignore' ? ' selected' : ''}>IGNORE</option>`;
      const changed = selected !== '';
      const description = escapeHtml(row.description_original || 'statement row');
      const error = String(errors[String(row.id)] || '');
      const errorDetail = error ? `<small class="reconciliation-row-error">${escapeHtml(error)}</small>` : '';
      return `<tr data-row-id="${row.id}"${changed ? ' class="pending"' : ''}>
        <td>${formatDate(row.booking_date)}</td>
        <td>${escapeHtml(row.description_original || '')}${errorDetail}</td>
        <td class="num">${moneyForCurrency(Math.abs(Number(row.amount)), row.currency || '')}</td>
        <td><select data-exception-classification="${row.id}" aria-label="Reconcile ${description}">${classificationOptions}</select></td>
      </tr>`;
    }).join('')}</tbody>
  </table></div>`;
}

function showReconciliationMessage(message = '') {
  $('reconciliationMessage').textContent = message;
  $('reconciliationMessage').classList.toggle('hidden', !message);
}

function updateReconciliationSaveState() {
  const button = $('saveReconciliationExceptionsBtn');
  button.disabled = state.reconciliationSaveBusy || !Object.keys(state.reconciliation?.pending || {}).length;
}

function setReconciliationSaveBusy(isBusy) {
  const button = $('saveReconciliationExceptionsBtn');
  state.reconciliationSaveBusy = isBusy;
  button.classList.toggle('is-loading', isBusy);
  button.setAttribute('aria-busy', String(isBusy));
  updateReconciliationSaveState();
}

document.addEventListener('change', (event) => {
  const target = event.target;
  if (!(target instanceof HTMLSelectElement) || !state.reconciliation) return;
  const classificationRowId = target.getAttribute('data-exception-classification');
  const rowId = Number(classificationRowId || 0);
  if (rowId <= 0) return;
  const tableRow = target.closest('tr');
  if (!tableRow) return;
  const classification = target.value;
  state.reconciliation.pending ||= {};
  state.reconciliation.errors ||= {};
  delete state.reconciliation.errors[String(rowId)];
  tableRow.querySelector('.reconciliation-row-error')?.remove();
  if (classification) {
    state.reconciliation.pending[String(rowId)] = { statement_row_id: rowId, classification };
  } else {
    delete state.reconciliation.pending[String(rowId)];
  }
  tableRow.classList.toggle('pending', classification !== '');
  showReconciliationMessage('');
  updateReconciliationSaveState();
});

document.addEventListener('click', async (event) => {
  const target = event.target;
  if (!(target instanceof HTMLElement)) return;
  if (target.id === 'saveReconciliationExceptionsBtn') {
    event.preventDefault();
    const selections = Object.values(state.reconciliation?.pending || {});
    if (!selections.length) return;
    setReconciliationSaveBusy(true);
    try {
      const current = state.reconciliation;
      const data = await apiPost({
        action: 'batchReconcileStatementExpenses',
        account_id: current.account_id,
        selections,
      });
      let results = data.results || [];
      // A row that would repeat an existing transaction exactly is not saved
      // yet. The owner confirms the repetition once and the same rows are
      // submitted again with the explicit confirmation.
      const duplicates = results.filter((result) => result.status === 'duplicate');
      if (duplicates.length && window.confirm(duplicateTransactionBatchWarning(duplicates))) {
        const confirmSelections = duplicates.map((result) => {
          const rowId = String(result.statement_row_id);
          const pendingSelection = (current.pending || {})[rowId] || { statement_row_id: Number(rowId) };
          return { ...pendingSelection, confirm_duplicate: true };
        });
        const confirmed = await apiPost({
          action: 'batchReconcileStatementExpenses',
          account_id: current.account_id,
          selections: confirmSelections,
        });
        const confirmedByRow = new Map((confirmed.results || []).map((result) => [String(result.statement_row_id), result]));
        results = results.map((result) => (result.status === 'duplicate' && confirmedByRow.has(String(result.statement_row_id))
          ? confirmedByRow.get(String(result.statement_row_id))
          : result));
      }
      const pending = { ...(current.pending || {}) };
      const errors = { ...(current.errors || {}) };
      const completedRowIds = new Set();
      for (const result of results) {
        const rowId = String(result.statement_row_id);
        if (result.status === 'error') {
          errors[rowId] = String(result.error || 'Could not save this row.');
        } else if (result.status === 'duplicate') {
          errors[rowId] = 'An identical transaction already exists. Save it again to keep it.';
        } else {
          completedRowIds.add(rowId);
          delete pending[rowId];
          delete errors[rowId];
        }
      }
      const failed = results.filter((result) => result.status === 'error' || result.status === 'duplicate');
      try {
        await loadNextReconciliationBatch(current.account_id, pending, errors);
        showReconciliationMessage(failed.length ? 'Some rows could not be saved.' : 'Selected statement rows saved.');
      } catch (loadError) {
        state.reconciliation = {
          ...current,
          unresolved: (current.unresolved || []).filter((row) => !completedRowIds.has(String(row.id))),
          unresolved_count: Math.max(0, Number(current.unresolved_count ?? current.unresolved?.length ?? 0) - completedRowIds.size),
          pending,
          errors,
        };
        renderReconciliationState();
        showReconciliationMessage(loadError.message || 'Could not load statement exceptions.');
      }
    } catch (error) {
      showReconciliationMessage(error.message || 'Could not save statement rows.');
    } finally {
      setReconciliationSaveBusy(false);
    }
    return;
  }
});

function openAccountDialog(id = null) {
  const account = id ? state.accounts.find((item) => item.id === Number(id)) : null;
  if (id && !account) return toast('Account not found');
  state.currentAccountId = account?.id || null;
  $('accountDialogTitle').textContent = account ? 'Edit account' : 'Add account';
  $('accountCurrentPosition').textContent = account ? `${accountPositionText(account)} · ${account.active ? 'Active' : 'Archived'}` : 'Balance can remain unknown until evidence is available.';
  $('accountId').value = account?.id || '';
  $('accountName').value = account?.name || '';
  $('accountNumber').value = account?.account_number || '';
  $('accountCurrency').value = account?.currency || 'CURRENCY';
  $('accountCurrency').disabled = false;
  $('accountInitialSection').classList.toggle('hidden', Boolean(account));
  $('accountInitialAmount').disabled = Boolean(account);
  $('accountInitialAmount').value = '';
  $('archiveAccountBtn').classList.toggle('hidden', !account);
  $('archiveAccountBtn').querySelector('.button-label').textContent = account?.active ? 'Archive account' : 'Restore account';
  $('archiveAccountBtn').classList.toggle('danger', Boolean(account?.active));
  $('saveAccountBtn').querySelector('.button-label').textContent = account ? 'Save account' : 'Add account';
  $('accountReconcileDetails').classList.toggle('hidden', !account || !account.active);
  $('accountReconcileDetails').open = false;
  setDateInputValue('accountReconcileDate', toIsoDate(new Date()));
  $('accountReconcileDate').max = toIsoDate(new Date());
  $('accountReconcileAmountLabel').firstChild.textContent = `Absolute Balance ${account ? currencyCode(account.currency) : state.currency}`;
  $('accountInitialAmountLabel').firstChild.textContent = `Balance ${account ? currencyCode(account.currency) : currencyCode($('accountCurrency').value)}`;
  $('accountReconcileAmount').value = account && account.display_balance !== null ? decimalInput(account.display_balance, 2) : '';
  $('accountReconcileNote').value = '';
  $('accountHistorySection').classList.toggle('hidden', !account);
  $('accountHistory').innerHTML = account ? '<p class="muted">Loading account history…</p>' : '';
  showAccountFormMessage('');
  $('accountDialog').showModal();
  if (account) loadAccountHistory(account.id);
}

function closeAccountDialog() {
  $('accountDialog').close();
  state.currentAccountId = null;
  renderAccountsList();
}

function accountHistoryDescription(item) {
  if (item.history_kind === 'observation') {
    if (item.observation_source === 'account_creation') return 'Initial Balance observation';
    if (item.observation_source === 'statement') return 'Statement Balance observation';
    if (item.observation_source === 'migration') return 'Migrated Balance observation';
    return 'Balance observation';
  }
  if (item.event_type === 'expense') return item.transaction_envelope || '';
  if (item.event_type === 'refund') return item.transaction_envelope ? `Refund · ${item.transaction_envelope}` : 'Refund';
  if (item.event_type === 'transfer') return `Transfer · ${item.source_account_name || ''} → ${item.destination_account_name || ''}`;
  if (item.event_type === 'income') return 'Income';
  return item.event_type;
}

function accountHistoryDisplayNote(item) {
  const note = String(item.note || '').trim();
  if (item.event_type === 'expense' && note.startsWith('Expense: ')) return note.slice('Expense: '.length);
  if (item.event_type === 'refund' && note.startsWith('Refund: ')) return note.slice('Refund: '.length);
  return note;
}

async function loadAccountHistory(accountId) {
  try {
    const data = await apiGet(`accountHistory&id=${encodeURIComponent(accountId)}`);
    if (state.currentAccountId !== Number(accountId) || !$('accountDialog').open) return;
    const history = data.history || [];
    if (!history.length) {
      $('accountHistory').innerHTML = '<p class="muted">No Account history yet. Balance is unknown.</p>';
      return;
    }
    $('accountHistory').innerHTML = `<div class="table-wrap"><table class="balance-history-table account-history-table"><thead><tr><th>Date</th><th>Event</th><th class="num">Amount / Balance</th></tr></thead><tbody>${history.map((item) => {
      const description = accountHistoryDescription(item);
      const note = accountHistoryDisplayNote(item);
      const primary = description || note || '—';
      const detail = description && note && note !== description ? `<small>${escapeHtml(note)}</small>` : '';
      const displayAmount = item.conversion_available ? item.display_amount : item.amount;
      const displayCurrency = item.conversion_available ? item.display_currency : state.currency;
      const unavailable = item.conversion_available ? '' : '<small>Conversion unavailable</small>';
      const isObservation = item.history_kind === 'observation';
      const amount = isObservation ? moneyForCurrency(displayAmount, displayCurrency) : signedMoney(displayAmount, displayCurrency);
      const remove = isObservation ? `<button type="button" class="ghost small danger" onclick="deleteAccountBalanceObservation(${item.observation_id}, this)"><span class="button-spinner" aria-hidden="true"></span>Delete</button>` : '';
      return `<tr><td>${formatDate(item.movement_date)}</td><td><span>${escapeHtml(primary)}</span>${detail}${remove}</td><td class="num ${isObservation ? '' : (item.amount < 0 ? 'negative' : 'positive')}">${amount}${unavailable}</td></tr>`;
    }).join('')}</tbody></table></div>`;
  } catch (error) {
    $('accountHistory').innerHTML = `<p class="warning bad">${escapeHtml(error.message || 'Could not load account history.')}</p>`;
  }
}

async function saveAccount(event) {
  event.preventDefault();
  showAccountFormMessage('');
  const id = Number($('accountId').value || 0);
  const initialBalanceRaw = $('accountInitialAmount').value.trim();
  const amount = initialBalanceRaw === '' ? null : parseDecimalInput(initialBalanceRaw);
  const account = {
    id: id || undefined,
    name: $('accountName').value.trim(),
    account_number: $('accountNumber').value.trim(),
    currency: $('accountCurrency').value,
  };
  if (!id && initialBalanceRaw !== '') account.initial_balance = amount;
  if (!account.name) return showAccountFormMessage('Account name is required.');
  if (!account.account_number) return showAccountFormMessage('Account number is required.');
  if (!id && initialBalanceRaw !== '' && !Number.isFinite(amount)) return showAccountFormMessage('Enter a valid current Balance.');
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost({ action: 'saveAccount', account, year: state.year }),
    successMessage: id ? 'Account saved' : 'Account added',
    failureMessage: 'Could not save account.',
  });
  if (!data) return;
  applyState(data.state);
  $('accountDialog').close();
  state.currentAccountId = null;
  renderAccountsList();
}

async function toggleCurrentAccountActive(event) {
  const account = state.accounts.find((item) => item.id === state.currentAccountId);
  if (!account) return;
  const active = !account.active;
  if (!active && !confirm(`Archive ${account.name}? Its history stays available, but it will no longer be offered for new expenses or envelope defaults.`)) return;
  const data = await runSaveAction({
    button: event.currentTarget,
    request: () => apiPost({ action: 'setAccountActive', id: account.id, active, year: state.year }),
    successMessage: active ? 'Account restored' : 'Account archived',
    failureMessage: 'Could not update account.',
  });
  if (!data) return;
  applyState(data.state);
  $('accountDialog').close();
  state.currentAccountId = null;
  renderAccountsList();
}

async function reconcileCurrentAccount(event) {
  event.preventDefault();
  const account = state.accounts.find((item) => item.id === state.currentAccountId);
  const amount = parseDecimalInput($('accountReconcileAmount').value);
  const effectiveDate = $('accountReconcileDate').value;
  if (!account || !Number.isFinite(amount)) return toast('Enter a valid Account Balance');
  if (effectiveDate > toIsoDate(new Date())) return toast('Balance date cannot be in the future');
  const saveButton = event.submitter;
  const data = await runSaveAction({
    button: saveButton,
    request: () => apiPost({
      action: 'saveAccountBalanceObservation', id: account.id, balance: amount,
      effective_date: effectiveDate, note: $('accountReconcileNote').value.trim(), year: state.year,
    }),
    successMessage: 'Account Balance recorded',
    failureMessage: 'Could not record Account Balance',
  });
  if (!data) return;
  applyState(data.state);
  const updated = state.accounts.find((item) => item.id === account.id);
  $('accountCurrentPosition').textContent = `${accountPositionText(updated)} · Active`;
  $('accountReconcileAmount').value = updated.display_balance === null ? '' : decimalInput(updated.display_balance, 2);
  $('accountReconcileNote').value = '';
  await loadAccountHistory(account.id);
}

async function deleteAccountBalanceObservation(observationId, button) {
  const account = state.accounts.find((item) => item.id === state.currentAccountId);
  if (!account || !confirm('Delete this Balance observation? Account Balance may become unknown.')) return;
  const data = await runSaveAction({
    button,
    request: () => apiPost({
      action: 'deleteAccountBalanceObservation', account_id: account.id, observation_id: Number(observationId), year: state.year,
    }),
    successMessage: 'Balance observation deleted',
    failureMessage: 'Could not delete Balance observation',
  });
  if (!data) return;
  applyState(data.state);
  const updated = state.accounts.find((item) => item.id === account.id);
  $('accountCurrentPosition').textContent = `${accountPositionText(updated)} · ${updated.active ? 'Active' : 'Archived'}`;
  await loadAccountHistory(account.id);
  renderAccountsList();
}

function renderPlanSummary(month) {
  $('accountSummaryDetails').open = groupIsOpen('plan-summary-accounts', true);
  if (!month) {
    $('planSummaryCards').innerHTML = '<p class="month-status-empty">Select or add a Month.</p>';
    $('accountSummary').innerHTML = '';
    return;
  }
  const accounts = Finance.accountPlanSummary(state.accounts, entriesForMonth(month.id), state.envelopes);
  const totals = accounts.reduce((sum, account) => ({
    plan: sum.plan + account.plan,
    funded: sum.funded + account.funded,
    balance: sum.balance + account.balance,
  }), { plan: 0, funded: 0, balance: 0 });
  const cards = [
    ['plan', 'Plan', totals.plan],
    ['funded', 'Funded', totals.funded],
    ['balance', 'Balance', totals.balance],
  ];
  $('planSummaryCards').innerHTML = cards.map(([key, label, value]) => {
    const tone = key === 'balance' ? (value < 0 ? 'bad' : 'good') : key;
    return `<article class="card ${tone}" data-summary="${key}"><div class="label">${label}</div><div class="value">${money(value)}</div></article>`;
  }).join('');
  $('accountSummary').innerHTML = accounts.length ? accounts.map((account) => {
    return `<div class="account-summary-row"><strong>${escapeHtml(account.name)}</strong><span class="account-summary-plan">${accountPreferenceAmount(account.plan, account, month)}</span><span class="account-summary-funded">${accountPreferenceAmount(account.funded, account, month)}</span><span class="account-summary-balance">${accountSummaryBalanceAmount(account.balance, account)}</span></div>`;
  }).join('') : '<p class="month-status-empty">No current Accounts.</p>';
}

function monthStatusFor(month) {
  return Finance.monthStatus(month, entriesForMonth(month.id), state.transactions, state.envelopes);
}

function renderMonthSection(month) {
  const details = $('monthDetails');
  details.classList.remove('status-on-track', 'status-getting-tight', 'status-needs-attention', 'status-not-started');
  if (!month) {
    details.classList.add('status-not-started');
    $('monthStatusBadge').textContent = 'Not started';
    $('monthStatusBody').innerHTML = '<p class="month-status-empty">Add a Month to begin planning.</p>';
    if (!state.addingMonth) setMonthFormValues(defaultMonth(), true);
    return;
  }
  const status = monthStatusFor(month);
  details.classList.add(`status-${status.state}`);
  $('monthStatusBadge').textContent = status.label;
  if (status.period === 'future') {
    $('monthStatusBody').innerHTML = '<p class="month-status-empty">Month has not started.</p>';
  } else {
    const dailyText = status.period === 'current'
      ? `<span>Spent <strong>${money(status.daily.spent)}</strong></span><span>Remaining <strong>${money(status.daily.remaining)}</strong></span><span><strong>${money(status.daily.safe.amount)}</strong>/day</span>`
      : `<span>Plan <strong>${money(status.daily.planned)}</strong></span><span>Spent <strong>${money(status.daily.spent)}</strong></span><span>Difference <strong>${signedMoney(status.daily.difference)}</strong></span>`;
    const rows = [`<div class="month-status-row status-${status.daily.state}"><span class="month-status-role">Daily spend</span><span class="month-status-values">${dailyText}</span></div>`];
    if (status.reserved.planned > 0) {
      const note = status.reserved.pending > 0 ? `<small>${status.reserved.overdue > 0 ? 'Overdue' : 'Pending'} ${money(status.reserved.pending)}</small>` : '';
      rows.push(`<div class="month-status-row status-${status.reserved.state}"><span class="month-status-role">Reserved</span><span class="month-status-values"><span><strong>${money(status.reserved.funded)}</strong> of ${money(status.reserved.planned)} funded</span>${note}</span></div>`);
    }
    if (status.savings.planned > 0) {
      const note = status.savings.pending > 0 ? `<small>${status.savings.overdue > 0 ? 'Overdue' : 'Pending'} ${money(status.savings.pending)}</small>` : '';
      rows.push(`<div class="month-status-row status-${status.savings.state}"><span class="month-status-role">Savings</span><span class="month-status-values"><span><strong>${money(status.savings.funded)}</strong> of ${money(status.savings.planned)} funded</span>${note}</span></div>`);
    }
    $('monthStatusBody').innerHTML = rows.join('');
  }
  if (!state.addingMonth && (!state.monthFormBaseline || Number(state.monthFormBaseline.id || 0) !== month.id)) setMonthFormValues(month, false);
}

function renderFundingPlanSection(month, rows) {
  const total = planTotals(rows, month);
  const totalNode = $('fundingTotal');
  if (totalNode) totalNode.innerHTML = dualMoney(total);
  const roleOrder = ['daily', 'reserved', 'savings'];

  const body = roleOrder.map((role) => {
    const roleRows = rows.filter((entry) => envelopeRole(state.envelopes.find((envelope) => envelope.id === Number(entry.envelope_id)) || {}) === role);
    if (!roleRows.length) return '';
    const roleTotal = planTotals(roleRows, month);
    const roleKey = `plan:role:${role}`;
    const roleOpen = groupIsOpen(roleKey, true);
    const roleHeader = `<tr class="role-header role-header-${role}"><td colspan="4"><button type="button" class="account-header-inner plan-group-toggle" data-plan-group-toggle="${escapeHtml(roleKey)}" aria-expanded="${roleOpen}"><span class="account-label"><span class="plan-group-chevron" aria-hidden="true">${roleOpen ? '▾' : '▸'}</span>${escapeHtml(envelopeRoleLabels[role])}</span><span class="account-total dual-money">${dualMoney(roleTotal)}</span></button></td></tr>`;
    const accountMap = groupBy(roleRows, (entry) => {
      const envelope = state.envelopes.find((item) => item.id === Number(entry.envelope_id));
      return Number(envelope?.default_account_id || 0);
    });
    const accountGroups = roleOpen ? Array.from(accountMap.entries())
      .map(([accountId, accountRows]) => {
        const account = state.accounts.find((item) => item.id === Number(accountId));
        return { accountId, accountRows, accountName: account?.name || 'No Account' };
      })
      .sort((left, right) => compareDanishLabels(left.accountName, right.accountName))
      .map(({ accountId, accountRows, accountName }) => {
      const accountTotal = planTotals(accountRows, month);
      const accountKey = `plan:account:${role}:${accountId || 'none'}`;
      const accountOpen = groupIsOpen(accountKey, true);
      const accountHeader = `<tr class="account-header account-header-envelope-allocations"><td colspan="4"><button type="button" class="account-header-inner plan-group-toggle" data-plan-group-toggle="${escapeHtml(accountKey)}" aria-expanded="${accountOpen}"><span class="account-label"><span class="plan-group-chevron" aria-hidden="true">${accountOpen ? '▾' : '▸'}</span>${escapeHtml(accountName)}</span><span class="account-total dual-money">${dualMoney(accountTotal)}</span></button></td></tr>`;
      const allocationRows = accountOpen ? [...accountRows]
        .sort((left, right) => {
          const leftEnvelope = state.envelopes.find((item) => item.id === Number(left.envelope_id));
          const rightEnvelope = state.envelopes.find((item) => item.id === Number(right.envelope_id));
          return compareDanishLabels(leftEnvelope?.name, rightEnvelope?.name);
        })
        .map((entry) => {
        const envelope = state.envelopes.find((item) => item.id === Number(entry.envelope_id));
        if (!envelope) return '';
        const editAction = entry.id ? `openEntryDialog(${entry.id})` : `openEntryDialog(null, ${month.id}, ${envelope.id})`;
        const planAmounts = allocationPlanAmounts(entry, month, envelope);
        const currencyPlan = planAmounts.preference === 'CURRENCY'
          ? `<button type="button" class="amount-link" title="Edit this month’s planned funding" onclick="${editAction}">${money(planAmounts.currencyAmount)}</button>`
          : `<span class="plan-amount-secondary">${money(planAmounts.currencyAmount)}</span>`;
        const helperText = planAmounts.helperAmount === null ? 'Conversion unavailable' : helperMoney(planAmounts.helperAmount);
        const helperPlan = planAmounts.preference === 'HELPER_CURRENCY'
          ? `<button type="button" class="amount-link" title="Edit this month’s planned funding" onclick="${editAction}">${helperText}</button>`
          : `<span class="plan-amount-secondary">${helperText}</span>`;
        const allocationNote = allocationDisplayNote(entry.note);
        const visibility = envelopeVisibility(envelope);
        const balance = visibility === 'hidden'
          ? `<span class="plan-currency-values balance-currency-values" aria-label="Balance hidden"><strong class="masked-balance">••• ${escapeHtml(state.currency)}</strong><span class="plan-amount-secondary masked-balance">••• ${escapeHtml(state.helperCurrency)}</span></span>`
          : `<span class="plan-currency-values balance-currency-values"><strong>${money(envelope.balance)}</strong><span class="plan-amount-secondary">${helperForAmount(envelope.balance, month)}</span></span>`;
        const visibilityLabel = visibility === 'today' ? 'Shown on Today' : '';
        const meta = [visibilityLabel, allocationNote].filter(Boolean).join(' · ');
        const directDebitTag = entry.is_bs ? '<abbr class="envelope-tag" title="Direct debit">DD</abbr>' : '';
        const goalProgress = envelopeRole(envelope) === 'savings' && Number(envelope.savings_goal || 0) > 0 && goalVisibilityForEnvelope(envelope) !== 'hidden'
          ? renderGoalProgress(envelope, envelope.name, 'month-goal-progress')
          : '';
        const nextStatus = nextAllocationStatus[entry.status] || 'planned';
        const statusControl = `<button type="button" class="allocation-status-toggle status-${entry.status}" title="${allocationStatusLabels[entry.status]} → ${allocationStatusLabels[nextStatus]}" aria-label="${allocationStatusLabels[entry.status]}; change to ${allocationStatusLabels[nextStatus]}" onclick="cycleAllocationStatus(${month.id}, ${envelope.id}, this)"><span class="allocation-status-letter">${allocationStatusLetters[entry.status]}</span><span class="button-spinner allocation-status-spinner" aria-hidden="true"></span></button>`;
        return `<tr class="entry-row funding-row status-${entry.status}${entry.is_virtual ? ' virtual-allocation' : ''}">
          <td class="entry-name funding-name"><span class="envelope-name-line"><button type="button" class="envelope-name-button" title="Edit envelope settings" onclick="openEnvelopeDialog(${envelope.id})">${escapeHtml(envelope.name)}</button>${directDebitTag}</span>${meta ? `<div class="row-meta"><span class="row-note">${escapeHtml(meta)}</span></div>` : ''}${goalProgress}</td>
          <td class="num funding-metric funding-plan-value"><span class="funding-value-label">Plan</span><span class="plan-currency-values">${currencyPlan}${helperPlan}</span></td>
          <td class="num funding-metric funding-balance-value"><span class="funding-value-label">Balance</span>${balance}</td>
          <td class="funding-status-value">${statusControl}</td>
        </tr>`;
      }).join('') : '';
      return accountHeader + allocationRows;
    }).join('') : '';
    return roleHeader + accountGroups;
  }).join('');

  return `<div class="table-wrap"><table class="plan-table funding-plan-table"><colgroup><col><col class="funding-col-metric"><col class="funding-col-metric"><col class="funding-col-status"></colgroup><tbody>${body}</tbody></table></div>`;
}

function renderUncategorizedWarning(entries) {
  const rows = entries
    .filter((entry) => entry.type === 'expense' && entry.status !== 'skipped' && !Number(entry.envelope_id || 0))
    .sort((left, right) => compareDanishLabels(left.name, right.name));
  if (!rows.length) return '';
  return `<div class="warning bad">You have ${rows.length} non-categorized expense ${rows.length === 1 ? 'entry' : 'entries'} without envelope: ${rows.map((entry) => escapeHtml(entry.name)).join(', ')}</div>`;
}

function openExpenseDialog(id = null) {
  const transaction = id ? state.transactions.find((item) => item.id === Number(id)) : null;
  if (id && !transaction) return toast('Expense not found');
  const sourceMonth = transaction ? state.months.find((item) => item.id === transaction.month_id) : null;
  const actualPrefix = transaction?.spent_date ? String(transaction.spent_date).slice(0, 7) : '';
  const actualMonth = transaction ? state.months.find((item) => monthKey(item.year, item.month) === actualPrefix) : null;
  const month = transaction ? (actualMonth || sourceMonth) : activeMonth();
  if (!transaction && !month) return toast('Create a month first');
  const isAutomatic = Boolean(transaction?.is_automatic);
  const automaticEntry = transaction?.entry_id ? state.entries.find((item) => item.id === Number(transaction.entry_id)) : null;
  const isPlanningControlledAutomatic = isAutomatic && Boolean(automaticEntry) && !automaticEntry.bs_expense_finalized_at;
  const isScheduledAutomatic = isPlanningControlledAutomatic && String(transaction.spent_date) > toIsoDate(new Date());
  renderExpenseOptions();
  $('expenseDialogTitle').textContent = isAutomatic ? 'Direct debit expense' : (transaction ? (transaction.kind === 'refund' ? 'Edit Refund' : 'Edit expense') : 'Add expense');
  $('expenseId').value = transaction?.id || '';
  const transactionEnvelope = transaction ? state.envelopes.find((envelope) => envelope.id === Number(transaction.envelope_id || 0)
    || (!transaction.envelope_id && envelope.name.toLowerCase() === String(transaction.envelope || '').toLowerCase())) : null;
  if (transactionEnvelope) {
    $('expenseEnvelope').value = String(transactionEnvelope.id);
  } else {
    $('expenseEnvelope').selectedIndex = transaction ? -1 : ($('expenseEnvelope').options.length ? 0 : -1);
  }
  renderExpensePaymentAccountOptions(transaction ? transaction.payment_account_id : undefined);
  updateExpenseCurrency(false, transaction);
  const today = new Date();
  const editPrefix = transaction ? actualPrefix : monthKey(month.year, month.month);
  const [editYear, editMonth] = editPrefix.split('-').map(Number);
  $('expenseDate').min = editPrefix ? `${editPrefix}-01` : '';
  $('expenseDate').max = editPrefix && editYear && editMonth ? `${editPrefix}-${String(new Date(editYear, editMonth, 0).getDate()).padStart(2, '0')}` : '';
  const defaultDate = transaction ? transaction.spent_date : (today.getFullYear() === month.year && today.getMonth() + 1 === month.month ? toIsoDate(today) : `${monthKey(month.year, month.month)}-01`);
  setDateInputValue('expenseDate', transaction?.spent_date || defaultDate);
  $('expenseNote').value = transaction?.note || '';
  ['expenseAmount', 'expenseEnvelope', 'expensePaymentAccount', 'expenseDate', 'expenseNote'].forEach((field) => { $(field).disabled = isScheduledAutomatic; });
  $('expenseFormMessage').textContent = isScheduledAutomatic ? 'This scheduled expense is controlled by its Direct debit Plan until its payment date.' : '';
  $('expenseFormMessage').classList.toggle('hidden', !isScheduledAutomatic);
  $('deleteExpenseBtn').classList.toggle('hidden', !transaction || isScheduledAutomatic);
  $('editExpenseAllocationBtn').classList.toggle('hidden', !isAutomatic || !transaction?.entry_id);
  $('saveExpenseBtn').classList.toggle('hidden', isScheduledAutomatic);
  $('saveExpenseBtn').querySelector('.button-label').textContent = transaction ? 'Save changes' : 'Save expense';
  $('expenseDialog').showModal();
}

function updateExpenseCurrency(clearAmount = false, transaction = null) {
  const envelope = state.envelopes.find((item) => item.id === Number($('expenseEnvelope').value));
  const preference = envelope?.currency || 'CURRENCY';
  const code = currencyCode(preference);
  const previousPreference = $('expenseAmount').dataset.currency || preference;
  $('expenseAmountLabel').firstChild.textContent = `Amount ${code}`;
  $('expenseAmountLabel').classList.toggle('is-helper', preference === 'HELPER_CURRENCY');
  $('expenseAmountHint').classList.add('hidden');
  $('expenseAmount').setAttribute('aria-label', `Expense amount in ${code}`);
  $('expenseAmount').placeholder = '0,00';
  if (clearAmount && previousPreference !== preference) {
    $('expenseAmount').value = '';
  } else if (transaction) {
    const value = preference === 'HELPER_CURRENCY' ? helperAmountForDate(transaction.amount, transaction.spent_date) : transaction.amount;
    $('expenseAmount').value = value === null ? '' : (preference === 'HELPER_CURRENCY' ? decimalInput(value, 6) : numberInput(value));
  }
  $('expenseAmount').dataset.currency = preference;
}

function openEnvelopeDialog(id = null) {
  const envelope = id ? state.envelopes.find((item) => item.id === id) : null;
  $('envelopeDialogTitle').textContent = id ? 'Edit envelope' : 'Add envelope';
  $('envelopeId').value = envelope?.id || '';
  $('envelopeName').value = envelope?.name || '';
  renderEnvelopeDefaultAccountOptions(envelope?.default_account_id || null);
  $('envelopeRole').value = envelopeRole(envelope || {});
  $('envelopeCurrency').value = envelope?.currency || 'CURRENCY';
  $('envelopeVisibility').value = envelopeVisibility(envelope || {});
  $('envelopeGoal').value = numberInput(envelope?.savings_goal || 0);
  $('envelopeGoalVisibility').value = envelope && envelopeRole(envelope) === 'savings' ? goalVisibilityForEnvelope(envelope) : 'month';
  updateEnvelopeGoalVisibility();
  $('envelopeManualBaseline').value = envelope?.manual_baseline === null || envelope?.manual_baseline === undefined ? '' : decimalInput(envelope.manual_baseline, 2);
  $('envelopeManualBaselineNote').value = envelope?.manual_baseline_note || '';
  $('envelopeFixed').checked = envelopeAmountSource(envelope || {}) === 'fixed';
  $('envelopeReset').checked = envelopeMonthEndBehavior(envelope || {}) === 'reset';
  $('envelopeNote').value = envelope?.note || '';
  $('deleteEnvelopeBtn').classList.toggle('hidden', !id);
  $('balanceToolsBtn').classList.toggle('hidden', !id);
  showEnvelopeFormMessage('');
  $('envelopeDialog').showModal();
}

function updateEnvelopeGoalVisibility() {
  const role = $('envelopeRole').value;
  const isSavings = role === 'savings';
  $('envelopeGoalLabel').classList.toggle('hidden', !isSavings);
  $('envelopeGoalVisibilityLabel').classList.toggle('hidden', !isSavings);
  $('envelopeGoalVisibility').disabled = !isSavings;
  if (!isSavings) $('envelopeGoal').value = '';
  const isDaily = role === 'daily';
  $('envelopeVisibilityLabel').classList.toggle('hidden', isDaily);
  $('envelopeVisibility').disabled = isDaily;
  if (isDaily) $('envelopeVisibility').value = 'normal';
}

function showEnvelopeFormMessage(message) {
  const node = $('envelopeFormMessage');
  node.textContent = message;
  node.classList.toggle('hidden', !message);
}

async function saveEnvelope(event) {
  event.preventDefault();
  showEnvelopeFormMessage('');
  const id = Number($('envelopeId').value || 0);
  const existing = id ? state.envelopes.find((item) => item.id === id) : null;
  const baselineText = $('envelopeManualBaseline').value.trim();
  const manualBaseline = baselineText === '' ? null : parseDecimalInput(baselineText);
  const envelope = {
    ...(existing || {}),
    id: id || undefined,
    name: $('envelopeName').value.trim(),
    default_account_id: Number($('envelopeDefaultAccount').value || 0) || null,
    role: $('envelopeRole').value,
    currency: $('envelopeCurrency').value,
    visibility: $('envelopeRole').value === 'daily' ? 'normal' : $('envelopeVisibility').value,
    savings_goal: parseDecimalInput($('envelopeGoal').value || '0'),
    goal_visibility: $('envelopeRole').value === 'savings' ? $('envelopeGoalVisibility').value : 'hidden',
    manual_baseline: manualBaseline,
    manual_baseline_note: $('envelopeManualBaselineNote').value.trim(),
    amount_source: $('envelopeFixed').checked ? 'fixed' : 'history',
    month_end_behavior: $('envelopeReset').checked ? 'reset' : 'accumulate',
    note: $('envelopeNote').value.trim(),
    sort_order: existing?.sort_order || (Math.max(0, ...state.envelopes.map((item) => Number(item.sort_order || 0))) + 10),
  };
  if (!envelope.name) return showEnvelopeFormMessage('Envelope name is required.');
  if (!Number.isFinite(envelope.savings_goal) || envelope.savings_goal < 0) return showEnvelopeFormMessage('Enter a valid savings goal using a comma as the decimal separator.');
  if (manualBaseline !== null && (!Number.isFinite(manualBaseline) || manualBaseline < 0)) return showEnvelopeFormMessage('Enter a valid non-negative historical baseline using a comma as the decimal separator.');
  const duplicateName = state.envelopes.some((item) => item.id !== id && item.name.trim().toLowerCase() === envelope.name.toLowerCase());
  if (duplicateName) {
    showEnvelopeFormMessage('An envelope with this name already exists.');
    $('envelopeName').focus();
    $('envelopeName').select();
    return;
  }
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost({ action: 'saveEnvelope', envelope, year: state.year }),
    successMessage: id ? 'Envelope saved' : 'Envelope added',
    failureMessage: 'Failed to save envelope.',
  });
  if (!data) return;
  applyState(data.state);
  $('envelopeDialog').close();
}

async function deleteCurrentEnvelope(event) {
  const id = Number($('envelopeId').value || 0);
  if (!id || !confirm('Delete this envelope?')) return;
  const data = await runSaveAction({
    button: event.currentTarget,
    request: () => apiPost({ action: 'deleteEnvelope', id, year: state.year }),
    successMessage: 'Envelope deleted',
    failureMessage: 'Could not delete envelope.',
  });
  if (!data) return;
  applyState(data.state);
  $('envelopeDialog').close();
}

function openBalanceDialog(envelopeId) {
  const envelope = state.envelopes.find((item) => item.id === Number(envelopeId));
  if (!envelope) return toast('Envelope not found');
  if ($('envelopeDialog').open) $('envelopeDialog').close();
  state.currentBalanceEnvelopeId = envelope.id;
  populateBalanceDialog();
  $('balanceDialog').showModal();
}

function populateBalanceDialog() {
  const envelope = state.envelopes.find((item) => item.id === state.currentBalanceEnvelopeId);
  if (!envelope) {
    if ($('balanceDialog').open) $('balanceDialog').close();
    return;
  }
  const movements = state.movements.filter((movement) => movement.envelope_id === envelope.id);
  const observations = state.envelopeBalanceObservations.filter((observation) => observation.envelope_id === envelope.id);
  const today = toIsoDate(new Date());
  $('balanceEnvelopeId').value = envelope.id;
  $('balanceDialogTitle').textContent = envelope.name;
  $('balanceCurrent').textContent = `Current balance: ${money(envelope.balance)}`;
  $('envelopeBalanceObservationAmount').value = decimalInput(envelope.balance, 2);
  setDateInputValue('envelopeBalanceObservationDate', today);
  $('envelopeBalanceObservationDate').max = today;
  $('envelopeBalanceObservationNote').value = '';
  $('envelopeBalanceObservationDetails').open = false;
  $('transferBalanceDetails').open = false;
  const destinations = state.envelopes
    .filter((item) => item.id !== envelope.id)
    .sort((a, b) => a.name.localeCompare(b.name, 'da', { sensitivity: 'base' }));
  $('transferDestination').innerHTML = '<option value="">Select Envelope</option>' + destinations.map((item) => `<option value="${item.id}">${escapeHtml(item.name)} · ${escapeHtml(item.default_account_name || 'No Account')}</option>`).join('');
  $('transferAmount').value = '';
  setDateInputValue('transferDate', today);
  $('transferNote').value = '';
  renderBalanceHistory(envelope, movements, observations);
}

function movementDescription(movement, envelope) {
  if (movement.type === 'transfer_out') return `To ${movement.destination_envelope}`;
  if (movement.type === 'transfer_in') return `From ${movement.source_envelope}`;
  if (movement.type === 'transfer_reversal_in') return `Undo transfer to ${movement.destination_envelope}`;
  if (movement.type === 'transfer_reversal_out') return `Undo transfer from ${movement.source_envelope}`;
  if (movement.type === 'reversal') return 'Adjustment undo';
  if (movement.type === 'opening_balance') return 'Starting balance';
  if (movement.type === 'archive_rollup') return 'Archived balance brought forward';
  if (movement.type === 'adjustment') return 'Balance adjustment';
  if (movement.type === 'funding') return 'Account funding';
  if (movement.type === 'month_reset') return 'Monthly reset';
  if (movement.type === 'expense') return '';
  if (movement.type === 'refund') return 'Refund';
  return movement.type.replaceAll('_', ' ');
}

function renderBalanceHistory(envelope, movements, observations) {
  const history = [
    ...movements.map((movement) => ({ ...movement, history_kind: 'movement', history_date: movement.movement_date, history_id: movement.id })),
    ...observations.map((observation) => ({ ...observation, history_kind: 'observation', history_date: observation.effective_date, history_id: observation.id })),
  ].sort((left, right) => right.history_date.localeCompare(left.history_date)
    || Number(right.history_kind === 'observation') - Number(left.history_kind === 'observation')
    || right.history_id - left.history_id);
  if (!history.length) {
    $('balanceHistory').innerHTML = '<p class="muted">No Balance history yet.</p>';
    return;
  }
  $('balanceHistory').innerHTML = `<div class="table-wrap"><table class="balance-history-table">
    <thead><tr><th>Date</th><th>Event</th><th class="num">Amount / Balance</th><th></th></tr></thead>
    <tbody>${history.map((item) => {
      const isObservation = item.history_kind === 'observation';
      const description = isObservation ? 'Balance observation' : movementDescription(item, envelope);
      const primary = description || item.note || '—';
      const detail = description && item.note && item.note !== description ? `<small>${escapeHtml(item.note)}</small>` : '';
      let action = '';
      if (isObservation) action = `<button type="button" class="ghost small danger" aria-label="Delete Balance observation" onclick="deleteEnvelopeBalanceObservation(${item.id}, this)"><span class="button-spinner" aria-hidden="true"></span>Delete</button>`;
      else if ((item.type === 'transfer_in' || item.type === 'transfer_out') && item.transfer_id && item.source_envelope_id && item.destination_envelope_id && !item.transfer_reversed_at) action = `<button type="button" class="ghost small" aria-label="Undo transfer" onclick="undoBalanceTransfer(${item.transfer_id}, this)"><span class="button-spinner" aria-hidden="true"></span>Undo</button>`;
      else if ((item.type === 'expense' || item.type === 'refund') && item.transaction_id) action = `<button type="button" class="ghost small" aria-label="Edit ${item.type} from balance history" onclick="openExpenseFromBalanceHistory(${item.transaction_id})">✎</button>`;
      const amount = isObservation ? money(item.balance) : signedMoney(item.amount);
      const amountClass = isObservation ? '' : (item.amount < 0 ? 'negative' : 'positive');
      return `<tr><td>${formatDate(item.history_date)}</td><td><span>${escapeHtml(primary)}</span>${detail}</td><td class="num ${amountClass}">${amount}</td><td class="num">${action}</td></tr>`;
    }).join('')}</tbody></table></div>`;
}

async function recordCurrentEnvelopeBalance(event) {
  event.preventDefault();
  const envelope = state.envelopes.find((item) => item.id === state.currentBalanceEnvelopeId);
  const balance = parseDecimalInput($('envelopeBalanceObservationAmount').value);
  const effectiveDate = $('envelopeBalanceObservationDate').value;
  if (!envelope || !Number.isFinite(balance)) return toast('Enter a valid Envelope Balance');
  if (effectiveDate > toIsoDate(new Date())) return toast('Balance date cannot be in the future');
  const saveButton = event.submitter;
  const data = await runSaveAction({
    button: saveButton,
    request: () => apiPost({
      action: 'saveEnvelopeBalanceObservation', envelope_id: envelope.id, balance,
      effective_date: effectiveDate, note: $('envelopeBalanceObservationNote').value.trim(), year: state.year,
    }),
    successMessage: 'Envelope Balance recorded',
    failureMessage: 'Could not record Envelope Balance',
  });
  if (!data) return;
  applyState(data.state);
  populateBalanceDialog();
}

async function transferFromCurrentEnvelope(event) {
  event.preventDefault();
  const source = state.envelopes.find((item) => item.id === state.currentBalanceEnvelopeId);
  const destination = state.envelopes.find((item) => item.id === Number($('transferDestination').value));
  const amount = parseDecimalInput($('transferAmount').value);
  if (!source || !destination) return toast('Select a destination envelope');
  if (!Number.isFinite(amount) || amount <= 0) return toast('Enter a valid transfer amount');
  const negativeSourceConfirmed = amount > Number(source.balance || 0);
  if (negativeSourceConfirmed && !confirm(`This will make ${source.name} negative. Continue?`)) return;
  const crossAccount = Number(source.default_account_id || 0) !== Number(destination.default_account_id || 0);
  let crossAccountConfirmed = false;
  if (crossAccount) {
    crossAccountConfirmed = confirm(`This moves money from ${source.default_account_name || 'an unspecified Account'} to ${destination.default_account_name || 'an unspecified Account'}. Continue only if the bank transfer has been completed.`);
    if (!crossAccountConfirmed) return;
  }
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost({
      action: 'transferEnvelope',
      transfer: {
        source_envelope_id: source.id,
        destination_envelope_id: destination.id,
        amount: amount,
        transfer_date: $('transferDate').value,
        note: $('transferNote').value.trim(),
        negative_source_confirmed: negativeSourceConfirmed,
        cross_account_confirmed: crossAccountConfirmed,
      },
      year: state.year,
    }),
    successMessage: 'Money moved',
    failureMessage: 'Could not move money.',
  });
  if (!data) return;
  applyState(data.state);
  populateBalanceDialog();
}

async function deleteEnvelopeBalanceObservation(observationId, button) {
  const envelope = state.envelopes.find((item) => item.id === state.currentBalanceEnvelopeId);
  if (!envelope || !confirm('Delete this Balance observation? The Envelope Balance will fall back to earlier observations and movement history.')) return;
  const data = await runSaveAction({
    button,
    request: () => apiPost({
      action: 'deleteEnvelopeBalanceObservation', envelope_id: envelope.id,
      observation_id: Number(observationId), year: state.year,
    }),
    successMessage: 'Balance observation deleted',
    failureMessage: 'Could not delete Balance observation',
  });
  if (!data) return;
  applyState(data.state);
  populateBalanceDialog();
}

async function undoBalanceTransfer(id, element) {
  const movement = state.movements.find((item) => item.transfer_id === Number(id));
  const crossAccount = movement && Number(movement.source_account_id || 0) !== Number(movement.destination_account_id || 0);
  const message = crossAccount
    ? 'Undo this envelope transfer only after reversing the real bank transfer. Continue?'
    : 'Undo this envelope transfer?';
  if (!confirm(message)) return;
  const data = await runSaveAction({
    button: element,
    request: () => apiPost({ action: 'undoEnvelopeTransfer', id, year: state.year }),
    successMessage: 'Transfer undone',
    failureMessage: 'Could not undo transfer.',
  });
  if (!data) return;
  applyState(data.state);
  populateBalanceDialog();
}

function openExpenseFromBalanceHistory(id) {
  $('balanceDialog').close();
  openExpenseDialog(id);
}

async function saveExpense(event) {
  event.preventDefault();
  const submitter = event.submitter;
  const id = Number($('expenseId').value || 0);
  const existing = id ? state.transactions.find((item) => item.id === id) : null;
  const sourceMonth = existing ? state.months.find((item) => item.id === existing.month_id) : activeMonth();
  const actualPrefix = String($('expenseDate').value || '').slice(0, 7);
  const actualMonth = state.months.find((item) => monthKey(item.year, item.month) === actualPrefix);
  const envelope = state.envelopes.find((item) => item.id === Number($('expenseEnvelope').value));
  if (!existing && !sourceMonth) return toast('Create or select a month first');
  if (!envelope) return toast('Select an envelope for this expense');
  const existingEntry = existing?.entry_id ? state.entries.find((item) => item.id === existing.entry_id) : null;
  const entry = existingEntry && Number(existingEntry.envelope_id) === envelope.id
    ? existingEntry
    : (sourceMonth ? budgetEntryForEnvelope(sourceMonth.id, envelope.id) : null);
  const preference = envelope.currency || 'CURRENCY';
  const code = currencyCode(preference);
  const amount = parseDecimalInput($('expenseAmount').value || '0');
  if (!Number.isFinite(amount) || amount <= 0) return toast(`Enter a valid ${code} expense amount using a comma as the decimal separator`);
  const rate = Number(actualMonth?.exchange_rate || 0);
  if (preference === 'HELPER_CURRENCY' && rate <= 0) return toast('Add a usable exchange rate for this expense date before saving');
  const transaction = {
    month_id: existing ? existing.month_id : sourceMonth.id,
    entry_id: entry?.id || null,
    envelope_id: envelope.id,
    envelope: envelope.name,
    payment_account_id: Number($('expensePaymentAccount').value || 0) || null,
    amount,
    spent_date: $('expenseDate').value,
    note: $('expenseNote').value.trim(),
  };
  const save = (confirmDuplicate) => {
    const payload = id
      ? { action: 'updateTransaction', id, transaction, year: state.year }
      : { action: 'createTransaction', transaction, year: state.year };
    if (confirmDuplicate) payload.confirm_duplicate = true;
    return apiPost(payload);
  };
  let data = null;
  if (id) {
    data = await runSaveAction({
      button: submitter,
      request: () => save(false),
      successMessage: existing?.kind === 'refund' ? 'Refund updated' : 'Expense updated',
      failureMessage: 'Failed to save expense',
    });
  } else {
    data = await runSaveAction({
      button: submitter,
      request: () => save(false),
      successMessage: 'Expense added',
      failureMessage: 'Failed to save expense',
      onError: async (error) => {
        const duplicate = error.payload?.duplicate;
        if (!duplicate) return null;
        if (!window.confirm(duplicateTransactionWarning(duplicate))) return null;
        const confirmed = await save(true);
        toast('Expense added');
        return confirmed;
      },
    });
  }
  if (!data) return;
  applyState(data.state);
  $('expenseDialog').close();
}

async function deleteCurrentExpense(event) {
  const id = Number($('expenseId').value || 0);
  const transaction = state.transactions.find((item) => item.id === id);
  const label = transaction?.kind === 'refund' ? 'Refund' : 'Expense';
  if (!id || !confirm(`Delete this ${label.toLowerCase()}?`)) return;
  const data = await runSaveAction({
    button: event.currentTarget,
    request: () => apiPost({ action: 'deleteTransaction', id, year: state.year }),
    successMessage: `${label} deleted`,
    failureMessage: `Could not delete ${label.toLowerCase()}.`,
  });
  if (!data) return;
  applyState(data.state);
  $('expenseDialog').close();
}

async function editAutomaticExpenseAllocation() {
  let transaction = state.transactions.find((item) => item.id === Number($('expenseId').value || 0));
  let entry = transaction?.entry_id ? state.entries.find((item) => item.id === transaction.entry_id) : null;
  let month = transaction ? state.months.find((item) => item.id === transaction.month_id) : null;
  if ((!entry || !month) && transaction?.source_year) {
    const transactionId = transaction.id;
    await loadState(Number(transaction.source_year));
    transaction = state.transactions.find((item) => item.id === transactionId);
    entry = transaction?.entry_id ? state.entries.find((item) => item.id === transaction.entry_id) : null;
    month = transaction ? state.months.find((item) => item.id === transaction.month_id) : null;
  }
  if (!entry || !month) return toast('Plan not found');
  $('expenseDialog').close();
  state.selectedPlanYear = month.year;
  state.selectedPlanMonth = month.month;
  state.activeScreen = 'plan';
  renderAll();
  openEntryDialog(entry.id);
}

function openCopyDialog() {
  renderCopyOptions();
  updateCopyExistingWarning();
  $('copyDialog').showModal();
}

function setCopyTargetFromSource() {
  const [year, month] = $('copyFromMonth').value.split('-').map(Number);
  if (!year || !month) return;
  const next = month === 12 ? { year: year + 1, month: 1 } : { year, month: month + 1 };
  $('copyToYear').value = next.year;
  $('copyToMonth').value = next.month;
}

function copyTargetExists() {
  const year = Number($('copyToYear').value);
  const month = Number($('copyToMonth').value);
  return [...state.months, ...state.reportMonths].some((item) => Number(item.year) === year && Number(item.month) === month);
}

function updateCopyExistingWarning() {
  $('copyExistingWarning').classList.toggle('hidden', !copyTargetExists());
}

function openReportDialog() {
  renderReportOptions();
  $('reportDialog').showModal();
}

function downloadReport(format) {
  const from = $('reportFromMonth').value;
  const to = $('reportToMonth').value;
  if (!from || !to) return toast('Select report range');
  window.location.href = `api.php?action=report&format=${encodeURIComponent(format)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
}

async function fundingRecommendationsForMonth(month, force = false) {
  const key = monthKey(month.year, month.month);
  if (!force && state.fundingRecommendationCache[key]) return state.fundingRecommendationCache[key];
  const data = await apiGet(`fundingRecommendations&year=${encodeURIComponent(month.year)}&month=${encodeURIComponent(month.month)}`);
  state.fundingRecommendationCache[key] = data;
  return data;
}

function recommendationMoney(value) {
  return value === null || value === undefined ? '—' : moneyForCurrency(value, state.currency, Number(value) % 1 ? 2 : 0);
}

// The historical baseline is stored as one authoritative Currency amount. The
// Plan amount of an Envelope that prefers the Helper currency is entered in
// that currency, so the baseline is shown in the same currency next to it and
// keeps the Currency amount as the secondary value.
function recommendationBaselineAmounts(item) {
  const currencyAmount = item?.manual_baseline === null || item?.manual_baseline === undefined ? null : Number(item.manual_baseline);
  if (currencyAmount === null) return null;
  const preference = item?.currency === 'HELPER_CURRENCY' ? 'HELPER_CURRENCY' : 'CURRENCY';
  const month = state.months.find((row) => row.id === Number($('entryMonth').value)) || selectedPlanMonth() || activeMonth();
  const rate = Number(month?.exchange_rate || 0);
  return { preference, currencyAmount, helperAmount: preference === 'HELPER_CURRENCY' && rate > 0 ? currencyAmount / rate : null };
}

function recommendationBaselineText(item) {
  const amounts = recommendationBaselineAmounts(item);
  if (!amounts) return { primary: '', secondary: '' };
  if (amounts.preference === 'HELPER_CURRENCY' && amounts.helperAmount !== null) {
    return { primary: helperMoney(amounts.helperAmount), secondary: `${recommendationMoney(amounts.currencyAmount)} · ` };
  }
  return { primary: recommendationMoney(amounts.currencyAmount), secondary: '' };
}

function fundingGuidanceDetails(item) {
  const stats = item.stats || {};
  const observations = item.observations || [];
  const evidence = Number(item.representative_months || 0) > 0
    ? `<dl class="recommendation-stats">
        <div><dt>Average</dt><dd>${recommendationMoney(stats.average)}</dd></div>
        <div><dt>Median</dt><dd>${recommendationMoney(stats.median)}</dd></div>
        <div><dt>Range</dt><dd>${recommendationMoney(stats.range_low)}–${recommendationMoney(stats.range_high)}</dd></div>
        <div><dt>Pattern</dt><dd>${escapeHtml(item.pattern || 'Not available')}</dd></div>
        <div><dt>Recent trend</dt><dd>${escapeHtml(stats.trend || 'Not available')}</dd></div>
        <div><dt>Buffer</dt><dd>${item.buffer === null || item.buffer === undefined ? 'None' : `${Math.round(Number(item.buffer) * 100)}%`}</dd></div>
      </dl>`
    : '';
  const history = observations.length
    ? `<div class="recommendation-history"><strong>Representative history</strong>${observations.map((observation) => {
        const [year, month] = String(observation.month).split('-').map(Number);
        return `<label class="recommendation-history-row"><span>${escapeHtml(observation.month)} · ${recommendationMoney(observation.actual)}${observation.outlier_limited ? ' · unusual value limited' : ''}</span><span class="recommendation-history-control"><input type="checkbox" ${observation.excluded ? 'checked' : ''} onchange="setRecommendationMonthExcluded(${item.envelope_id}, ${year}, ${month}, this.checked, this)"> Exclude<span class="button-spinner recommendation-spinner" aria-hidden="true"></span></span></label>`;
      }).join('')}</div>`
    : '';
  if (!evidence && !history) return '';
  return `<details class="recommendation-why"><summary>Why?</summary>${evidence}${history}</details>`;
}

function renderAllocationRecommendation(item, envelopeId) {
  const envelope = state.envelopes.find((row) => Number(row.id) === Number(envelopeId));
  if (envelope && envelopeAmountSource(envelope) === 'fixed') {
    $('allocationRecommendation').innerHTML = '';
    return;
  }
  if (!item) {
    $('allocationRecommendation').innerHTML = '<p class="muted">Funding guidance is unavailable.</p>';
    return;
  }
  const baseline = item.manual_baseline === null || item.manual_baseline === undefined ? null : Number(item.manual_baseline);
  const baselineText = recommendationBaselineText(item);
  const finAmount = item.amount === null || item.amount === undefined ? null : Number(item.amount);
  let fin;
  if (item.role === 'savings') {
    fin = '<div class="recommendation-source"><div><strong>FIN recommendation: Not applicable</strong><small>Savings funding follows your goals, not historical withdrawals.</small></div></div>';
  } else if (finAmount !== null) {
    const early = item.confidence === 'low';
    const label = early ? 'FIN estimate' : 'Recommended';
    const action = early ? 'Use estimate' : 'Use';
    const basis = item.basis === 'plan-supported' ? ' · plan-supported' : '';
    fin = `<div class="recommendation-source recommendation-fin"><div><strong>${label}: ${recommendationMoney(finAmount)}/month</strong><small>Based on ${item.representative_months} representative months · ${escapeHtml(item.confidence_label)}${basis}</small></div><button type="button" class="ghost small" onclick="useFundingGuidanceAmount(${finAmount})">${action}</button></div>`;
  } else {
    const needed = Number(item.months_needed || item.minimum_months || 0);
    fin = `<div class="recommendation-source"><div><strong>FIN recommendation: Not available yet</strong><small>Needs ${needed} more completed representative month${needed === 1 ? '' : 's'}.</small></div></div>`;
  }
  const baselineHtml = baseline === null ? '' : `<div class="recommendation-source recommendation-baseline"><div><strong>Historical baseline: ${baselineText.primary}/month</strong><small>${baselineText.secondary}Source: ${escapeHtml(item.manual_baseline_note || 'Manual historical review')}</small></div>${finAmount === null ? `<button type="button" class="ghost small" onclick="useFundingGuidanceAmount(${baseline})">Use baseline</button>` : ''}</div>`;
  const assessment = item.assessment?.source === 'baseline'
    ? `<p class="recommendation-assessment">Baseline comparison: ${escapeHtml(item.assessment.label)}</p>`
    : (item.assessment?.source === 'fin' ? `<p class="recommendation-assessment">${escapeHtml(item.assessment.label)}</p>` : '');
  $('allocationRecommendation').innerHTML = `${fin}${baselineHtml}${assessment}${fundingGuidanceDetails(item)}`;
}

async function loadAndRenderAllocationRecommendation(month, envelopeId) {
  try {
    const data = await fundingRecommendationsForMonth(month);
    if (!$('entryDialog').open || Number(state.activeRecommendationEnvelopeId) !== Number(envelopeId)) return;
    renderAllocationRecommendation((data.recommendations || []).find((item) => Number(item.envelope_id) === Number(envelopeId)), envelopeId);
  } catch (error) {
    console.error('Failed to load funding guidance', error);
    if ($('entryDialog').open) $('allocationRecommendation').innerHTML = '<p class="muted">Funding guidance could not be loaded.</p>';
  }
}

function useFundingGuidanceAmount(currencyAmount) {
  const month = state.months.find((item) => item.id === Number($('entryMonth').value));
  const envelope = state.envelopes.find((item) => item.id === Number($('entryEnvelope').value));
  if (!month || !envelope) return;
  const amount = Number(currencyAmount || 0);
  const rate = Number(month.exchange_rate || 0);
  $('entryAmount').value = envelope.currency === 'HELPER_CURRENCY' && rate > 0 ? numberInput(amount / rate) : numberInput(amount);
  toast('Funding amount filled. Save to apply it.');
}

function reviewAssessmentClass(code) {
  return ['under', 'slightly-under'].includes(code) ? 'review-needs-attention' : (code === 'appropriate' ? 'review-good' : '');
}

function renderFundingReview(data) {
  const groups = ['daily', 'reserved', 'savings'];
  $('fundingReviewContent').innerHTML = groups.map((role) => {
    const items = (data.recommendations || [])
      .filter((item) => item.role === role)
      .sort((left, right) => compareDanishLabels(left.envelope_name, right.envelope_name));
    if (!items.length) return '';
    return `<section class="funding-review-group"><h3>${escapeHtml(envelopeRoleLabels[role])}</h3>
      <div class="funding-review-head"><span>Envelope</span><span>Current</span><span>Baseline</span><span>FIN recommended</span><span>Assessment</span><span>Confidence</span></div>
      ${items.map((item) => `<div class="funding-review-item">
        <div class="funding-review-row ${reviewAssessmentClass(item.assessment?.code)}">
          <button type="button" class="review-envelope-link" onclick="openFundingReviewAllocation(${item.envelope_id})">${escapeHtml(item.envelope_name)}</button>
          <span class="review-value" data-label="Current">${item.current_plan_status === 'skipped' ? 'Skipped' : recommendationMoney(item.current_plan)}</span>
          <span class="review-value" data-label="Baseline">${recommendationMoney(item.manual_baseline)}</span>
          <span class="review-value" data-label="FIN">${recommendationMoney(item.amount)}</span>
          <span class="review-assessment" data-label="Assessment">${escapeHtml(item.assessment?.label || '—')}${item.assessment?.source === 'baseline' ? '<small>Baseline-based</small>' : ''}</span>
          <span class="review-confidence" data-label="Confidence">${escapeHtml(item.amount === null || item.amount === undefined ? (item.role === 'savings' ? '—' : 'Insufficient') : item.confidence_label)}</span>
        </div>${fundingGuidanceDetails(item)}</div>`).join('')}
    </section>`;
  }).join('');
}

async function openFundingReview() {
  const month = selectedPlanMonth() || activeMonth();
  if (!month) return toast('Select a month first');
  $('reportDialog').close();
  $('fundingReviewMonth').textContent = `${monthNames[month.month - 1]} ${month.year}`;
  $('fundingReviewContent').innerHTML = '<p class="muted">Loading funding review…</p>';
  $('fundingReviewDialog').showModal();
  try {
    renderFundingReview(await fundingRecommendationsForMonth(month));
  } catch (error) {
    console.error('Failed to load Funding Review', error);
    $('fundingReviewContent').innerHTML = '<p class="warning bad">Funding Review could not be loaded.</p>';
  }
}

function openFundingReviewAllocation(envelopeId) {
  const month = selectedPlanMonth() || activeMonth();
  if (!month) return;
  const entry = budgetEntryForEnvelope(month.id, envelopeId);
  $('fundingReviewDialog').close();
  openEntryDialog(entry?.id || null, month.id, envelopeId);
}

async function setRecommendationMonthExcluded(envelopeId, year, month, excluded, checkbox) {
  if (!checkbox) return;
  const control = checkbox.closest('.recommendation-history-control') || checkbox;
  checkbox.disabled = true;
  control.classList.add('is-loading');
  checkbox.setAttribute('aria-busy', 'true');
  try {
    await apiPost({ action: 'setRecommendationExclusion', envelope_id: envelopeId, year, month, excluded, reason: excluded ? 'Excluded by user' : '' });
    state.fundingRecommendationCache = {};
    const target = selectedPlanMonth() || activeMonth();
    if (!target) return;
    const data = await fundingRecommendationsForMonth(target, true);
    if ($('entryDialog').open && Number(state.activeRecommendationEnvelopeId) === Number(envelopeId)) renderAllocationRecommendation((data.recommendations || []).find((item) => Number(item.envelope_id) === Number(envelopeId)), envelopeId);
    if ($('fundingReviewDialog').open) renderFundingReview(data);
    toast(excluded ? 'Month excluded from recommendations' : 'Month restored to recommendations');
  } catch (error) {
    console.error('Failed to update recommendation exclusion', error);
    toast(error.message || 'Could not update recommendation history');
  } finally {
    checkbox.disabled = false;
    control.classList.remove('is-loading');
    checkbox.removeAttribute('aria-busy');
  }
}

async function copyMonth(event) {
  event.preventDefault();
  const [fromYear, fromMonth] = $('copyFromMonth').value.split('-').map(Number);
  const toYear = Number($('copyToYear').value);
  const toMonth = Number($('copyToMonth').value);
  if (copyTargetExists() && !confirm('This month already exists. Copying may overwrite existing plan data for the target month. Existing Today expenses will stay. Continue?')) return;
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost({ action: 'duplicateMonth', from_year: fromYear, from_month: fromMonth, to_year: toYear, to_month: toMonth }),
    successMessage: 'Month copied',
    failureMessage: 'Could not copy month.',
  });
  if (!data) return;
  state.selectedPlanYear = toYear;
  state.selectedPlanMonth = toMonth;
  applyState(data.state);
  $('copyDialog').close();
}

function monthFormValues() {
  return {
    id: $('monthId').value,
    year: $('monthYear').value,
    month: $('monthNumber').value,
    exchangeRate: $('exchangeRate').value,
    salary: $('salary').value,
  };
}

function setMonthFormValues(month, adding = false) {
  state.addingMonth = adding;
  $('monthId').value = adding ? '' : (month?.id || '');
  $('monthYear').value = month?.year || state.year;
  $('monthNumber').value = month?.month || nextMonthNumber();
  $('exchangeRate').value = month?.exchange_rate == null ? '' : decimalInput(month.exchange_rate, 6);
  $('salary').value = decimalInput(month?.salary ?? 0, 2);
  state.monthFormBaseline = monthFormValues();
  $('cancelAddMonthBtn').classList.toggle('hidden', !adding || !selectedPlanMonth());
  $('copyMonthBtn').disabled = adding || !selectedPlanMonth();
  $('reportBtn').disabled = adding || !selectedPlanMonth();
  updateMonthFormDirtyState();
  showMonthFormMessage('');
}

function startAddMonth() {
  setMonthFormValues(defaultMonth(), true);
  $('monthDetails').open = true;
  setGroupPref('month', true);
  $('monthYear').focus();
}

function cancelAddMonth() {
  const month = selectedPlanMonth();
  if (month) setMonthFormValues(month, false);
}

function updateMonthFormDirtyState() {
  const baseline = state.monthFormBaseline || {};
  const values = monthFormValues();
  const fields = { year: 'monthYear', month: 'monthNumber', exchangeRate: 'exchangeRate', salary: 'salary' };
  Object.entries(fields).forEach(([key, id]) => {
    $(id).closest('label')?.classList.toggle('month-field-dirty', String(values[key] ?? '') !== String(baseline[key] ?? ''));
  });
}

function showMonthFormMessage(message) {
  const node = $('monthFormMessage');
  node.textContent = message;
  node.classList.toggle('hidden', !message);
}

async function saveMonth(event) {
  event.preventDefault();
  showMonthFormMessage('');
  const month = {
    id: Number($('monthId').value || 0) || undefined,
    year: Number($('monthYear').value),
    month: Number($('monthNumber').value),
    exchange_rate: $('exchangeRate').value.trim() === '' ? null : parseDecimalInput($('exchangeRate').value),
    salary: parseDecimalInput($('salary').value || '0'),
  };
  if (month.exchange_rate !== null && (!Number.isFinite(month.exchange_rate) || month.exchange_rate <= 0)) return showMonthFormMessage('Enter a valid exchange rate using a comma as the decimal separator, or leave it blank when unavailable.');
  if (!Number.isFinite(month.salary) || month.salary < 0) return showMonthFormMessage('Enter a valid salary using a comma as the decimal separator.');
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost({ action: 'saveMonth', month, year: month.year }),
    successMessage: 'Month saved',
    failureMessage: 'Failed to save month.',
  });
  if (!data) return;
  state.selectedPlanYear = month.year;
  state.selectedPlanMonth = month.month;
  state.addingMonth = false;
  state.monthFormBaseline = null;
  applyState(data.state);
}

function openEntryDialog(id = null, monthId = null, envelopeId = null) {
  const entry = id ? state.entries.find((item) => item.id === id) : defaultEntry(monthId, envelopeId);
  if (!entry) return;
  const isIncome = entry.type === 'income';
  const selectedMonthId = entry.month_id || selectedPlanMonth()?.id || activeMonth()?.id || '';
  const selectedMonth = state.months.find((item) => item.id === Number(selectedMonthId));
  const envelope = entry.envelope_id ? state.envelopes.find((item) => item.id === Number(entry.envelope_id)) : null;
  const preference = isIncome ? 'CURRENCY' : (envelope?.currency || 'CURRENCY');
  const code = currencyCode(preference);
  const rate = Number(selectedMonth?.exchange_rate || 0);
  const inputAmount = preference === 'HELPER_CURRENCY' ? (rate > 0 ? Number(entry.amount || 0) / rate : null) : Number(entry.amount || 0);
  $('entryDialogTitle').textContent = isIncome ? 'Edit income' : 'Edit Plan';
  $('entryId').value = entry.id || '';
  $('entryMonth').value = selectedMonthId;
  $('entryType').value = isIncome ? 'income' : 'expense';
  $('entryName').value = entry.name || '';
  renderEntryEnvelopeOptions(selectedMonthId, entry.id || null);
  $('entryEnvelope').value = entry.envelope_id ? String(entry.envelope_id) : '';
  $('entryEnvelope').disabled = !isIncome;
  $('entryAmountLabel').firstChild.textContent = `Planned amount ${code}`;
  $('entryAmount').value = inputAmount === null ? '' : (preference === 'HELPER_CURRENCY' ? decimalInput(inputAmount, 6) : numberInput(inputAmount));
  $('entryStatus').value = entry.status || 'planned';
  $('entryBs').checked = Boolean(entry.is_bs);
  setDateInputValue('dueDate', entry.due_date || '');
  $('entryBsPaymentAccount').innerHTML = realAccountOptions('Unknown / not recorded', entry.bs_payment_account_id);
  $('entryBsPaymentAccount').value = entry.bs_payment_account_id ? String(entry.bs_payment_account_id) : '';
  updateBsDateLabel();
  updateEntryEnvelopeVisibility();
  handleEntryEnvelopeInput(true);
  $('sortOrder').value = entry.sort_order || 0;
  $('isRecurring').value = entry.is_recurring ? '1' : '0';
  $('entryNote').value = entry.note || '';
  $('deleteEntryBtn').classList.toggle('hidden', !id);
  state.activeRecommendationEnvelopeId = Number(entry.envelope_id || 0) || null;
  const fixedAmount = !isIncome && envelope && envelopeAmountSource(envelope) === 'fixed';
  $('allocationRecommendation').innerHTML = isIncome || fixedAmount ? '' : '<p class="muted">Loading funding guidance…</p>';
  $('allocationRecommendation').classList.toggle('hidden', isIncome);
  $('entryDialog').showModal();
  if (!isIncome && selectedMonth && envelope) loadAndRenderAllocationRecommendation(selectedMonth, envelope.id);
}

async function saveEntry(event) {
  event.preventDefault();
  const id = Number($('entryId').value || 0);
  const entry = { ...(id ? state.entries.find((item) => item.id === id) : {}), ...readEntryForm() };
  if (entry.type !== 'income' && !entry.envelope_id) return toast('Select an Envelope');
  if (!Number.isFinite(entry.amount) || entry.amount < 0) return toast('Enter a valid Plan amount using a comma as the decimal separator');
  const duplicate = state.entries.find((item) => item.id !== id && item.month_id === entry.month_id && item.type !== 'income' && Number(item.envelope_id) === Number(entry.envelope_id));
  if (duplicate) return toast('This Envelope already has a Plan for this Month');
  const data = await runSaveAction({
    button: event.submitter,
    request: () => apiPost(id ? { action: 'updateEntry', id, entry, year: state.year } : { action: 'createEntry', entry, year: state.year }),
    successMessage: entry.type === 'income' ? 'Income saved' : (id ? 'Plan updated' : 'Plan added'),
    failureMessage: 'Failed to save Plan',
  });
  if (!data) return;
  applyState(data.state);
  $('entryDialog').close();
}

async function deleteCurrentEntry(event) {
  const id = Number($('entryId').value || 0);
  if (!id || !confirm(`Reset this Month’s Plan to 0 ${state.currency}?`)) return;
  const data = await runSaveAction({
    button: event.currentTarget,
    request: () => apiPost({ action: 'deleteEntry', id, year: state.year }),
    successMessage: `Plan reset to 0 ${state.currency}`,
    failureMessage: 'Could not reset Plan.',
  });
  if (!data) return;
  applyState(data.state);
  $('entryDialog').close();
}

function updateBsDateLabel() {
  const isBs = $('entryBs').checked;
  $('dueDateLabel').firstChild.textContent = isBs ? 'Payment date' : 'Funding date';
  $('entryBsPaymentAccountLabel').classList.toggle('hidden', !isBs);
  $('entryBsPaymentAccount').disabled = !isBs;
  if (isBs && !$('dueDate').value) {
    const month = state.months.find((item) => item.id === Number($('entryMonth').value));
    if (month) setDateInputValue('dueDate', `${monthKey(month.year, month.month)}-01`);
  }
}

function updateEntryEnvelopeVisibility() {
  const isIncome = $('entryType').value === 'income';
  $('entryNameLabel').classList.toggle('hidden', !isIncome);
  $('entryEnvelopeLabel').classList.toggle('hidden', isIncome);
  $('entryName').required = isIncome;
  if (isIncome) {
    $('entryEnvelope').value = '';
    $('entryAmount').dataset.currency = 'CURRENCY';
    $('entryAmountLabel').firstChild.textContent = `Plan amount ${state.currency}`;
  }
}

function readEntryForm() {
  const type = $('entryType').value;
  const envelope = state.envelopes.find((item) => item.id === Number($('entryEnvelope').value));
  return {
    month_id: Number($('entryMonth').value),
    type,
    name: type === 'income' ? $('entryName').value.trim() : (envelope?.name || ''),
    category: type === 'income' ? 'Income' : 'Envelope',
    envelope_id: envelope?.id || null,
    envelope_name: envelope?.name || '',
    amount: parseDecimalInput($('entryAmount').value || '0'),
    status: $('entryStatus').value,
    is_bs: $('entryBs').checked ? 1 : 0,
    bs_payment_account_id: $('entryBs').checked ? (Number($('entryBsPaymentAccount').value || 0) || null) : null,
    due_date: $('dueDate').value,
    note: $('entryNote').value.trim(),
    sort_order: Number($('sortOrder').value || 0),
    is_recurring: $('isRecurring').type === 'checkbox' ? ($('isRecurring').checked ? 1 : 0) : Number($('isRecurring').value || 0),
  };
}

function activeMonth() {
  const selected = selectedPlanMonth();
  if (selected) return selected;
  const today = new Date();
  return state.months.find((m) => m.year === today.getFullYear() && m.month === today.getMonth() + 1)
    || state.months[state.months.length - 1]
    || null;
}

function selectedPlanMonth() {
  return state.months.find((m) => m.year === state.selectedPlanYear && m.month === state.selectedPlanMonth)
    || state.months.find((m) => m.month === state.selectedPlanMonth)
    || null;
}

function goToCurrentMonth() {
  const today = new Date();
  const year = today.getFullYear();
  const month = today.getMonth() + 1;
  state.selectedPlanYear = year;
  state.selectedPlanMonth = month;
  if (state.year !== year) {
    loadState(year);
    return;
  }
  renderAll();
}

function ensureSelectedPlanMonth() {
  state.selectedPlanYear = Number(state.selectedPlanYear || state.year);
  const today = new Date();
  const currentMonth = today.getMonth() + 1;
  const current = state.months.find((m) => m.year === today.getFullYear() && m.month === currentMonth);
  const selected = state.months.find((m) => m.year === state.selectedPlanYear && m.month === state.selectedPlanMonth);
  if (selected) return;
  const pastOrCurrent = state.months.filter((m) => m.year < today.getFullYear() || (m.year === today.getFullYear() && m.month <= currentMonth));
  const fallback = current || pastOrCurrent[pastOrCurrent.length - 1] || state.months[state.months.length - 1] || null;
  if (fallback) {
    state.selectedPlanYear = fallback.year;
    state.selectedPlanMonth = fallback.month;
  }
}

function spendingEnvelopes(month) {
  if (!month) return [];
  return state.envelopes
    .map((envelope) => {
      const totals = envelopeTotalsFromEntries(month.id, envelope.id);
      const balance = Number(envelope.balance || 0);
      return { envelope, label: envelope.name, budget: totals.total, spent: totals.spent, remaining: balance, planned: totals.total, perDay: balance / remainingDaysInMonth(month.year, month.month) };
    });
}

function envelopeRole(envelope) {
  return ['daily', 'reserved', 'savings'].includes(envelope?.role) ? envelope.role : 'daily';
}

function envelopeVisibility(envelope) {
  if (envelopeRole(envelope) === 'daily') return 'normal';
  return ['normal', 'hidden', 'today'].includes(envelope?.visibility) ? envelope.visibility : (envelope?.hidden ? 'hidden' : 'normal');
}

function goalVisibilityForEnvelope(envelope) {
  if (envelopeRole(envelope) !== 'savings') return 'hidden';
  return ['hidden', 'month', 'today'].includes(envelope?.goal_visibility) ? envelope.goal_visibility : 'month';
}

function envelopeAmountSource(envelope) {
  return envelope?.amount_source === 'fixed' ? 'fixed' : 'history';
}

function envelopeMonthEndBehavior(envelope) {
  return envelope?.month_end_behavior === 'reset' ? 'reset' : 'accumulate';
}

function budgetEntryForEnvelope(monthId, envelopeId) {
  return entriesForMonth(monthId).find((entry) => Number(entry.envelope_id || 0) === Number(envelopeId));
}

function transactionSpendingAmount(transaction) {
  const amount = Math.abs(Number(transaction.amount || 0));
  return transaction.kind === 'refund' ? -amount : amount;
}

function envelopeTotalsFromEntries(monthId, envelopeId) {
  const rows = entriesForMonth(monthId).filter((entry) => Number(entry.envelope_id || 0) === Number(envelopeId) && entry.status !== 'skipped');
  const transactionSpent = transactionsForMonth(monthId)
    .filter((tx) => Number(tx.envelope_id || 0) === Number(envelopeId))
    .reduce((sum, tx) => sum + transactionSpendingAmount(tx), 0);
  const total = rows.reduce((sum, entry) => sum + Number(entry.amount || 0), 0);
  const paid = rows.filter((entry) => entry.status === 'paid').reduce((sum, entry) => sum + Number(entry.amount || 0), 0);
  const available = rows.filter((entry) => entry.status !== 'paid').reduce((sum, entry) => sum + Number(entry.amount || 0), 0) - transactionSpent;
  const spent = paid + transactionSpent;
  return { total, available, spent };
}

function envelopeLabel(entry) {
  const envelope = state.envelopes.find((item) => item.id === Number(entry.envelope_id || 0));
  return envelope?.name || entry.name;
}

function handleEntryEnvelopeInput(force = false) {
  if ($('entryType').value === 'income') return;
  const envelope = state.envelopes.find((item) => item.id === Number($('entryEnvelope').value));
  const preference = envelope?.currency || 'CURRENCY';
  if ($('entryAmount').dataset.currency && $('entryAmount').dataset.currency !== preference && !force) $('entryAmount').value = '';
  $('entryAmount').dataset.currency = preference;
  $('entryAmountLabel').firstChild.textContent = `Plan amount ${currencyCode(preference)}`;
}

function comparePlanRows(a, b) {
  const dateA = a.due_date || '';
  const dateB = b.due_date || '';
  if (dateA && dateB && dateA !== dateB) return dateB.localeCompare(dateA);
  if (!dateA && dateB) return 1;
  if (dateA && !dateB) return -1;
  const sortA = Number(a.sort_order || 0);
  const sortB = Number(b.sort_order || 0);
  if (sortA !== sortB) return sortA - sortB;
  const idA = Number(a.id || 0) || 0;
  const idB = Number(b.id || 0) || 0;
  return idB - idA;
}

function groupBy(rows, keyFn) {
  const map = new Map();
  for (const row of rows) {
    const key = keyFn(row);
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(row);
  }
  return map;
}

function monthSections(month, entries) {
  const income = entries.filter((entry) => entry.type === 'income');
  const allocationsByEnvelope = new Map(entries
    .filter((entry) => entry.type !== 'income' && Number(entry.envelope_id || 0) > 0)
    .map((entry) => [Number(entry.envelope_id), entry]));
  const envelopePlan = state.envelopes.map((envelope) => allocationsByEnvelope.get(envelope.id) || {
    id: null,
    month_id: month.id,
    envelope_id: envelope.id,
    type: 'expense',
    name: envelope.name,
    category: 'Envelope',
    amount: 0,
    note: '',
    status: 'planned',
    due_date: null,
    sort_order: envelope.sort_order,
    is_recurring: 1,
    is_virtual: true,
  });
  return { income, envelopePlan };
}

function allocationDisplayNote(note) {
  return String(note || '').split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line && !/^(?:-?\d+(?:\.\d+)?\s+)?[dw]$/i.test(line) && !line.startsWith('Imported name:') && !line.startsWith('Merged historical allocations:'))
    .join(' · ');
}

function allocationPlanAmounts(entry, month, envelope = null) {
  const linkedEnvelope = envelope || state.envelopes.find((item) => item.id === Number(entry.envelope_id || 0));
  const preference = linkedEnvelope?.currency || 'CURRENCY';
  const currencyAmount = Number(entry.amount || 0);
  const rate = Number(month?.exchange_rate || 0);
  return { preference, currencyAmount, helperAmount: rate > 0 ? currencyAmount / rate : null };
}

function planTotals(rows, month) {
  return rows.filter((entry) => entry.status !== 'skipped').reduce((total, entry) => {
    const amounts = allocationPlanAmounts(entry, month);
    total.currency += amounts.currencyAmount;
    total.helper = amounts.helperAmount === null || total.helper === null ? null : total.helper + amounts.helperAmount;
    return total;
  }, { currency: 0, helper: 0 });
}

function entriesForMonth(monthId) {
  return state.entries.filter((entry) => entry.month_id === monthId).sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
}

function transactionsForMonth(monthId, asOf = toIsoDate(new Date())) {
  const month = state.months.find((item) => item.id === Number(monthId));
  if (!month) return [];
  const prefix = monthKey(month.year, month.month);
  return state.transactions
    .filter((tx) => String(tx.spent_date || '').startsWith(prefix) && String(tx.spent_date || '') <= asOf)
    .sort((a, b) => b.spent_date.localeCompare(a.spent_date) || b.id - a.id);
}

function defaultMonth() {
  const next = nextMonthTarget();
  return { year: next.year, month: next.month, exchange_rate: null, salary: 0 };
}

function nextMonthNumber() { return nextMonthTarget().month; }

function nextMonthTarget() {
  if (!state.months.length) return { year: state.year, month: 1 };
  const last = state.months[state.months.length - 1];
  return last.month === 12 ? { year: last.year + 1, month: 1 } : { year: last.year, month: last.month + 1 };
}

function defaultEntry(monthId = null, envelopeId = null) {
  const envelope = state.envelopes.find((item) => item.id === Number(envelopeId || 0));
  return {
    month_id: monthId || selectedPlanMonth()?.id || activeMonth()?.id || state.months[0]?.id,
    envelope_id: envelope?.id || null,
    type: 'expense',
    name: envelope?.name || '',
    category: 'Envelope',
    amount: 0,
    status: 'planned',
    sort_order: envelope?.sort_order || 0,
    is_recurring: 1,
    is_bs: 0,
    bs_payment_account_id: null,
  };
}

function normalizeMonth(month) {
  return { ...month, id: Number(month.id), year: Number(month.year), month: Number(month.month), exchange_rate: month.exchange_rate == null ? null : Number(month.exchange_rate), salary: Number(month.salary) };
}
function normalizeEntry(entry) {
  const status = entry.status === 'handled' ? 'planned' : (entry.status || 'planned');
  return { ...entry, status, id: Number(entry.id), month_id: Number(entry.month_id), envelope_id: entry.envelope_id === null ? null : Number(entry.envelope_id || 0), amount: Number(entry.amount || 0), sort_order: Number(entry.sort_order || 0), is_recurring: Number(entry.is_recurring || 0), is_bs: Number(entry.is_bs || 0), bs_payment_account_id: entry.bs_payment_account_id == null ? null : Number(entry.bs_payment_account_id) };
}
function normalizeAccount(account) {
  const balanceKnown = account.balance_known === true || Number(account.balance_known || 0) === 1;
  return { ...account, id: Number(account.id), active: Number(account.active || 0), currency: account.currency || 'CURRENCY', account_number: account.account_number || '', balance_known: balanceKnown, balance: balanceKnown && account.balance !== null && account.balance !== undefined ? Number(account.balance) : null, display_balance: balanceKnown && account.display_balance !== null && account.display_balance !== undefined ? Number(account.display_balance) : null, display_currency: account.display_currency || currencyCode(account.currency), movement_count: Number(account.movement_count || 0) };
}
function normalizeEnvelope(envelope) {
  const role = ['daily', 'reserved', 'savings'].includes(envelope.role) ? envelope.role : 'daily';
  const currency = envelope.currency === 'HELPER_CURRENCY' ? 'HELPER_CURRENCY' : 'CURRENCY';
  const visibility = role === 'daily' ? 'normal' : (['normal', 'hidden', 'today'].includes(envelope.visibility) ? envelope.visibility : (Number(envelope.hidden || 0) ? 'hidden' : 'normal'));
  const manualBaseline = envelope.manual_baseline === null || envelope.manual_baseline === undefined ? null : Number(envelope.manual_baseline);
  return { ...envelope, role, currency, visibility, goal_visibility: goalVisibilityForEnvelope({ ...envelope, role }), amount_source: envelopeAmountSource(envelope), month_end_behavior: envelopeMonthEndBehavior(envelope), id: Number(envelope.id), default_account_id: envelope.default_account_id == null ? null : Number(envelope.default_account_id), default_account_name: envelope.default_account_name || '', hidden: visibility === 'hidden' ? 1 : 0, sort_order: Number(envelope.sort_order || 0), savings_goal: Number(envelope.savings_goal || 0), manual_baseline: Number.isFinite(manualBaseline) ? manualBaseline : null, manual_baseline_note: envelope.manual_baseline_note || '', balance: Number(envelope.balance || 0), usage_count: Number(envelope.usage_count || 0) };
}
function normalizeTransaction(tx) {
  return { ...tx, kind: tx.kind === 'refund' ? 'refund' : 'expense', id: Number(tx.id), month_id: tx.month_id === null ? null : Number(tx.month_id), entry_id: tx.entry_id === null ? null : Number(tx.entry_id), envelope_id: tx.envelope_id === null ? null : Number(tx.envelope_id || 0), payment_account_id: tx.payment_account_id === null ? null : Number(tx.payment_account_id), amount: Number(tx.amount || 0), is_automatic: Number(tx.is_automatic || 0), source_year: tx.source_year === null ? null : Number(tx.source_year || 0), source_month: tx.source_month === null ? null : Number(tx.source_month || 0) };
}
function normalizeEnvelopeBalanceObservation(observation) {
  return {
    ...observation,
    id: Number(observation.id),
    envelope_id: Number(observation.envelope_id),
    balance: Number(observation.balance),
  };
}
function normalizeMovement(movement) {
  return {
    ...movement,
    id: Number(movement.id),
    envelope_id: Number(movement.envelope_id),
    month_id: movement.month_id === null ? null : Number(movement.month_id),
    entry_id: movement.entry_id === null ? null : Number(movement.entry_id),
    transaction_id: movement.transaction_id === null ? null : Number(movement.transaction_id),
    transfer_id: movement.transfer_id === null ? null : Number(movement.transfer_id),
    reversal_of_movement_id: movement.reversal_of_movement_id === null ? null : Number(movement.reversal_of_movement_id),
    source_envelope_id: movement.source_envelope_id === null ? null : Number(movement.source_envelope_id),
    destination_envelope_id: movement.destination_envelope_id === null ? null : Number(movement.destination_envelope_id),
    amount: Number(movement.amount || 0),
  };
}

function duplicateTransactionWarning(duplicate) {
  const label = duplicate.kind === 'refund' ? 'refund' : 'expense';
  const envelope = duplicate.envelope || 'this envelope';
  const amount = moneyForCurrency(duplicate.amount, state.currency);
  const note = String(duplicate.note || '').trim();
  const description = note ? ` "${note}"` : '';
  return `An identical ${label} already exists: ${envelope}, ${amount} on ${duplicate.spent_date}${description}. Save this one again?`;
}

function duplicateTransactionBatchWarning(results) {
  const lines = results.map((result) => {
    const duplicate = result.duplicate || {};
    const envelope = duplicate.envelope || 'this envelope';
    const amount = moneyForCurrency(duplicate.amount, state.currency);
    const note = String(duplicate.note || '').trim();
    const description = note ? ` "${note}"` : '';
    return `${envelope}, ${amount} on ${duplicate.spent_date}${description}`;
  });
  return `These statement rows repeat a transaction you already have:\n${lines.join('\n')}\nSave them again?`;
}

async function apiGet(action) {
  const res = await fetch(`api.php?action=${action}`);
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}
async function apiPost(payload) {
  const body = state.csrfToken && payload._csrf === undefined ? { ...payload, _csrf: state.csrfToken } : payload;
  const res = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) {
    const error = new Error(data.error || 'Request failed');
    error.status = res.status;
    error.payload = data;
    throw error;
  }
  return data;
}

function showAuth() { $('authView').classList.remove('hidden'); $('appView').classList.add('hidden'); }
function showApp() { $('authView').classList.add('hidden'); $('appView').classList.remove('hidden'); }
function showLoginError(error) { showMasterPinLogin(); setAuthMessage(error.message || String(error)); }
function daysInMonth(year, month) { return Finance.daysInMonth(year, month); }
function remainingDaysInMonth(year, month) { return Finance.remainingDaysInMonth(year, month); }
function money(value) { return moneyForCurrency(value, state.currency, 0); }
function helperMoney(value) { return `${num(value, 2)} ${state.helperCurrency}`; }
function moneyForCurrency(value, currency, maximumFractionDigits = 2) {
  const formatted = new Intl.NumberFormat('da-DK', { maximumFractionDigits }).format(Number(value || 0));
  return `${formatted} ${currency || state.currency}`;
}
function signedMoney(value, currency = state.currency) {
  const amount = Number(value || 0);
  const formatted = new Intl.NumberFormat('da-DK', { maximumFractionDigits: 2 }).format(Math.abs(amount));
  return `${amount > 0 ? '+' : amount < 0 ? '−' : ''}${formatted} ${currency}`;
}
function helperAmountForDate(amount, date) {
  const prefix = String(date || '').slice(0, 7);
  const month = state.months.find((item) => monthKey(item.year, item.month) === prefix);
  const rate = Number(month?.exchange_rate || 0);
  return rate > 0 ? Number(amount || 0) / rate : null;
}
function helperForAmount(amount, month) {
  const rate = Number(month?.exchange_rate || 0);
  return rate > 0 ? helperMoney(Number(amount || 0) / rate) : 'Conversion unavailable';
}
function dualMoney(totals) {
  const helper = totals.helper === null ? 'Conversion unavailable' : helperMoney(totals.helper);
  return `<span class="dual-money-helper">${helper}</span><span class="dual-money-currency">${money(totals.currency)}</span>`;
}
function accountPreferenceAmount(amount, account, month) {
  if (account?.currency !== 'HELPER_CURRENCY') return money(amount);
  const rate = Number(month?.exchange_rate || 0);
  return rate > 0 ? helperMoney(Number(amount || 0) / rate) : '<span class="muted">Conversion unavailable</span>';
}
// Current exchange rate for the Plan summary Balance cell. The Helper
// currency is only a helper: its values are never historical or forecast.
// The Balance cell therefore always uses the exchange rate of the Month
// containing effective_today(), independent of the selected Plan Month
// (FIN iterations 3 and 4). The rate is read from the new
// state.current_exchange_rate field, which api.php get_state computes
// by querying the months table directly (no ECB fallback). If the field
// is null or not positive, the Balance cell shows the existing
// "Conversion unavailable" message. The Balance does not depend on any
// Account's real Balance or on balance_known.
function accountSummaryBalanceAmount(balance, account) {
  if (account.currency !== 'HELPER_CURRENCY') return money(balance);
  const rate = Number(state.currentExchangeRate);
  return rate && rate > 0 ? helperMoney(Number(balance || 0) / rate) : '<span class="muted">Conversion unavailable</span>';
}
function num(value, decimals = 2) { return new Intl.NumberFormat('da-DK', { maximumFractionDigits: decimals, minimumFractionDigits: decimals }).format(Number(value || 0)); }
function decimalInput(value, maximumFractionDigits = 2) {
  const number = Number(value || 0);
  if (!Number.isFinite(number)) return '';
  return number.toLocaleString('da-DK', { useGrouping: false, maximumFractionDigits });
}
function numberInput(value) { const number = Number(value || 0); return number ? decimalInput(number, 2) : ''; }
function parseDecimalInput(value) {
  const raw = String(value ?? '').trim().replace(/[\s\u00a0]/g, '');
  if (!raw) return 0;
  const normalized = raw.includes(',') ? raw.replace(/\./g, '').replace(',', '.') : raw;
  const number = Number(normalized);
  return Number.isFinite(number) ? number : Number.NaN;
}
function formatDate(value) { const [y, m, d] = String(value).split('-'); return d && m && y ? `${d}/${m}/${y}` : value; }
function formatRecentDate(value) { const [y, m, d] = String(value).split('-'); return d && m && y ? `${d}.${m}.${y}` : value; }
function formatRecentShortDate(value) { const [, m, d] = String(value).split('-'); return d && m ? `${d}.${m}` : value; }
function initializeDateInputs() {
  document.querySelectorAll('input[type="date"]').forEach((input) => {
    if (input.closest('.date-input-shell')) return;
    const shell = document.createElement('span');
    shell.className = 'date-input-shell';
    const display = document.createElement('span');
    display.className = 'date-input-display';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'date-picker-button';
    button.dataset.datePickerFor = input.id;
    button.title = 'Choose date';
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M7.5 3v4M16.5 3v4M3.5 10h17"/></svg>';
    input.parentNode.insertBefore(shell, input);
    shell.append(display, button, input);
    input.classList.add('native-date-picker');
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');
    const openPicker = () => {
      if (input.disabled) return;
      try {
        if (typeof input.showPicker === 'function') input.showPicker();
        else { input.focus({ preventScroll: true }); input.click(); }
      } catch {
        input.focus({ preventScroll: true });
        input.click();
      }
    };
    button.addEventListener('click', openPicker);
    display.addEventListener('click', openPicker);
    input.addEventListener('input', () => refreshDateInput(input));
    input.addEventListener('change', () => refreshDateInput(input));
    new MutationObserver(() => refreshDateInput(input)).observe(input, { attributes: true, attributeFilter: ['disabled'] });
    refreshDateInput(input);
  });
}
function refreshDateInput(input) {
  const shell = input?.closest('.date-input-shell');
  if (!shell) return;
  const formatted = input.value ? formatDate(input.value) : 'dd/mm/yyyy';
  shell.querySelector('.date-input-display').textContent = formatted;
  const button = shell.querySelector('.date-picker-button');
  button.disabled = input.disabled;
  button.setAttribute('aria-label', input.value ? `Choose date, current ${formatted}` : 'Choose date');
  shell.classList.toggle('date-input-empty', !input.value);
  shell.classList.toggle('date-input-disabled', input.disabled);
}
function setDateInputValue(id, value) {
  const input = $(id);
  input.value = value || '';
  refreshDateInput(input);
}
function monthKey(year, month) { return `${year}-${String(month).padStart(2, '0')}`; }
function toIsoDate(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
// Shared in-flight helper for save actions. Disables the submit button
// and toggles the existing `.is-loading` + `aria-busy="true"` busy
// state (with the matching `.button-spinner` child element already
// rendered inside the button), runs the request, surfaces success and
// failure through the shared `toast` (the API's reason on failure), and
// re-enables the button in `finally`. Used by Account and Envelope
// Record Balance here; task #19 will apply the same helper to the
// remaining actions.
async function runSaveAction({ button, request, successMessage, failureMessage, onError }) {
  if (button) {
    button.disabled = true;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
  }
  try {
    const data = await request();
    if (successMessage) toast(successMessage);
    return data;
  } catch (error) {
    if (onError) {
      const recovered = await onError(error);
      if (recovered) return recovered;
    }
    toast(error.message || failureMessage || 'Action failed');
    return null;
  } finally {
    if (button) {
      button.disabled = false;
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
    }
  }
}
function toast(message) {
  const node = $('toast');
  node.textContent = message;
  // The browser renders <dialog.showModal()> in the top layer, which
  // sits above every regular stacking context. A toast positioned in
  // the body would therefore be hidden behind any open dialog. When a
  // modal dialog is open, reparent the toast into that dialog so it
  // stays visible; otherwise ensure it lives at the body level.
  const openDialog = document.querySelector('dialog[open]');
  const desiredParent = openDialog || document.body;
  if (node.parentNode !== desiredParent) desiredParent.appendChild(node);
  node.classList.remove('hidden');
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => node.classList.add('hidden'), 2600);
}

window.openEntryDialog = openEntryDialog;
window.openEnvelopeDialog = openEnvelopeDialog;
window.openExpenseDialog = openExpenseDialog;
window.deleteEnvelopeBalanceObservation = deleteEnvelopeBalanceObservation;
window.undoBalanceTransfer = undoBalanceTransfer;
window.openExpenseFromBalanceHistory = openExpenseFromBalanceHistory;
window.showMoreRecentExpenses = showMoreRecentExpenses;
window.showLessRecentExpenses = showLessRecentExpenses;
window.useFundingGuidanceAmount = useFundingGuidanceAmount;
window.setRecommendationMonthExcluded = setRecommendationMonthExcluded;
window.openFundingReviewAllocation = openFundingReviewAllocation;
