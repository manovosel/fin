// Self-contained Node helper for generating neutral synthetic OOXML
// statement payloads used by browser tests. This is the fixture-generator
// side of the contract that PHP `tests/php/Fixtures/SyntheticStatementGenerator`
// covers: every workbook byte used by a test originates from deterministic
// test code, never from a real statement file.
//
// Public API:
//   const { buildSyntheticSignedAccountXlsx,
//           buildSyntheticDebitCreditPreheaderXlsx,
//           writeTempXlsx } = require('./SyntheticXlsx.cjs');
//
//   const bytes = buildSyntheticSignedAccountXlsx({ accountNumber, accountName, rows });
//   const path  = writeTempXlsx(prefix, bytes);
//   await page.locator('#statementFile').setInputFiles(path);

'use strict';

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');

function crc32(buf) {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let j = 0; j < 8; j++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
    table[i] = c >>> 0;
  }
  let crc = 0xFFFFFFFF;
  for (let i = 0; i < buf.length; i++) crc = table[(crc ^ buf[i]) & 0xFF] ^ (crc >>> 8);
  return (crc ^ 0xFFFFFFFF) >>> 0;
}

function buildZip(files) {
  const localParts = [];
  const centralParts = [];
  let offset = 0;
  for (const { name, data } of files) {
    const nameBuf = Buffer.from(name, 'utf-8');
    const dataBuf = Buffer.from(data, 'utf-8');
    const crc = crc32(dataBuf);
    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);
    local.writeUInt16LE(0, 6);
    local.writeUInt16LE(0, 8);
    local.writeUInt16LE(0, 10);
    local.writeUInt16LE(0, 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(dataBuf.length, 18);
    local.writeUInt32LE(dataBuf.length, 22);
    local.writeUInt16LE(nameBuf.length, 26);
    local.writeUInt16LE(0, 28);
    localParts.push(local, nameBuf, dataBuf);
    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(20, 4);
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0, 8);
    central.writeUInt16LE(0, 10);
    central.writeUInt16LE(0, 12);
    central.writeUInt16LE(0, 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(dataBuf.length, 20);
    central.writeUInt32LE(dataBuf.length, 24);
    central.writeUInt16LE(nameBuf.length, 28);
    central.writeUInt16LE(0, 30);
    central.writeUInt16LE(0, 32);
    central.writeUInt16LE(0, 34);
    central.writeUInt16LE(0, 36);
    central.writeUInt32LE(0, 38);
    central.writeUInt32LE(offset, 42);
    centralParts.push(central, nameBuf);
    offset += 30 + nameBuf.length + dataBuf.length;
  }
  const localBuf = Buffer.concat(localParts);
  const centralBuf = Buffer.concat(centralParts);
  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(0x06054b50, 0);
  eocd.writeUInt16LE(0, 4);
  eocd.writeUInt16LE(0, 6);
  eocd.writeUInt16LE(files.length, 8);
  eocd.writeUInt16LE(files.length, 10);
  eocd.writeUInt32LE(centralBuf.length, 12);
  eocd.writeUInt32LE(localBuf.length, 16);
  eocd.writeUInt16LE(0, 20);
  return Buffer.concat([localBuf, centralBuf, eocd]);
}

function escapeXml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function danishDecimal(value) {
  const sign = value < 0 ? '-' : '';
  const abs = Math.abs(value).toFixed(2);
  return sign + abs.replace('.', ',');
}

function excelSerialDate(yyyyMmDd) {
  const epoch = Date.UTC(
    Number(yyyyMmDd.slice(0, 4)),
    Number(yyyyMmDd.slice(5, 7)) - 1,
    Number(yyyyMmDd.slice(8, 10))
  );
  const base = Date.UTC(1899, 11, 30);
  return String(Math.round((epoch - base) / 86400000));
}

function displayDate(yyyyMmDd) {
  const parts = yyyyMmDd.split('-');
  if (parts.length !== 3) return yyyyMmDd;
  return String(parts[2]).padStart(2, '0') + '.' + String(parts[1]).padStart(2, '0') + '.' + parts[0];
}

function buildSyntheticSignedAccountSheetXml({ accountNumber, accountName, rows }) {
  const headers = ['Dato', 'Tekst', 'Beløb', 'Saldo', 'Afstemt', 'Kontonummer', 'Kontonavn', 'Hovedkategori', 'Kategori', 'Kommentar'];
  const headerRow = '<row r="1">' + headers.map((h, i) => {
    const col = String.fromCharCode(65 + i);
    return `<c r="${col}1" t="inlineStr"><is><t>${escapeXml(h)}</t></is></c>`;
  }).join('') + '</row>';
  const dataRows = rows.map((row, idx) => {
    const rowNumber = idx + 2;
    const cells = [
      excelSerialDate(row.booking_date),
      row.description,
      danishDecimal(row.amount),
      danishDecimal(row.running_balance ?? 0),
      '',
      accountNumber,
      accountName,
      '',
      '',
      '',
    ];
    return '<row r="' + rowNumber + '">' + cells.map((value, i) => {
      const col = String.fromCharCode(65 + i);
      return `<c r="${col}${rowNumber}" t="inlineStr"><is><t>${escapeXml(value)}</t></is></c>`;
    }).join('') + '</row>';
  }).join('');

  const sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheetData>' + headerRow + dataRows + '</sheetData>'
    + '</worksheet>';
  const contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    + '<Default Extension="xml" ContentType="application/xml"/>'
    + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    + '</Types>';
  const rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    + '</Relationships>';
  const workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
    + '</workbook>';
  const workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    + '</Relationships>';
  const sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>';

  return buildZip([
    { name: '[Content_Types].xml', data: contentTypes },
    { name: '_rels/.rels', data: rootRels },
    { name: 'xl/workbook.xml', data: workbook },
    { name: 'xl/_rels/workbook.xml.rels', data: workbookRels },
    { name: 'xl/worksheets/sheet1.xml', data: sheetXml },
    { name: 'xl/sharedStrings.xml', data: sharedStrings },
  ]);
}

function buildSyntheticDebitCreditPreheaderSheetXml({ accountNumber, currency, rows }) {
  // Row 1 supplies the generic Account-number pre-header primitive used by
  // the neutral Test Foreign Bank profile.
  const preHeaderCells = [
    escapeXml('Transactions'),
    escapeXml('Račun: ' + accountNumber),
    escapeXml('Period: synthetic range'),
  ];
  const preRow = '<row r="1">' + preHeaderCells.map((s, i) => {
    const col = String.fromCharCode(65 + i);
    return `<c r="${col}1" t="inlineStr"><is><t>${s}</t></is></c>`;
  }).join('') + '</row>';

  // The exact positioned normalized vector matches the neutral profile seed.
  // These labels are generic field labels, not an institution identity.
  const headers = [
    'Datum knjiženja',
    'Datum valute',
    'Uplata/Isplata',
    'Iznos isplate',
    'Iznos uplate',
    'Valuta',
    'Broj transakcije',
    'Status',
    'IBAN platitelja',
    'Naziv platitelja',
    'Adresa platitelja',
    'Sjedište platitelja',
    'Poziv na broj platitelja',
    'Stvarni dužnik',
    'IBAN primatelja',
    'Naziv primatelja',
    'Adresa primatelja',
    'Sjedište primatelja',
    'Poziv na broj primatelja',
    'Opis plaćanja',
    'Krajnji primatelj',
    'Šifra namjene',
    'Originalni iznos isplate',
    'Originalni iznos uplate',
    'Originalna valuta',
    'Tečaj',
  ];
  const headerRow = '<row r="2">' + headers.map((h, i) => {
    const col = String.fromCharCode(65 + i);
    return `<c r="${col}2" t="inlineStr"><is><t>${escapeXml(h)}</t></is></c>`;
  }).join('') + '</row>';

  let dataXml = '';
  let txCounter = 100000;
  rows.forEach((row, idx) => {
    const amount = Number(row.amount);
    const direction = row.direction || (amount >= 0 ? 'Credit' : 'Debit');
    const description = row.description || ('synthetic row ' + (idx + 1));
    const counterparty = row.counterparty || ('synthetic counterparty ' + (idx + 1));
    const outflow = amount < 0 ? danishDecimal(-amount) : '';
    const inflow = amount >= 0 ? danishDecimal(amount) : '';
    const recipientName = amount < 0 ? counterparty : '';
    const payerName = amount < 0 ? '' : counterparty;
    const cells = [
      displayDate(row.booking_date),
      displayDate(row.value_date || row.booking_date),
      direction,
      outflow,
      inflow,
      currency,
      String(row.reference || txCounter),
      row.status || 'Processed',
      '',
      payerName,
      '',
      '',
      '',
      '',
      '',
      recipientName,
      '',
      '',
      '',
      description,
      '',
      '',
      '',
      '',
      '',
      '',
    ].map(escapeXml);
    dataXml += '<row r="' + (idx + 3) + '">' + cells.map((value, i) => {
      const col = String.fromCharCode(65 + i);
      return `<c r="${col}${idx + 3}" t="inlineStr"><is><t>${value}</t></is></c>`;
    }).join('') + '</row>';
    txCounter++;
  });

  const sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheetData>' + preRow + headerRow + dataXml + '</sheetData>'
    + '</worksheet>';
  const contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    + '<Default Extension="xml" ContentType="application/xml"/>'
    + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    + '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    + '</Types>';
  const rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    + '</Relationships>';
  const workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    + '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
    + '</workbook>';
  const workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    + '</Relationships>';
  const sharedStrings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    + '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="0" uniqueCount="0"></sst>';

  return buildZip([
    { name: '[Content_Types].xml', data: contentTypes },
    { name: '_rels/.rels', data: rootRels },
    { name: 'xl/workbook.xml', data: workbook },
    { name: 'xl/_rels/workbook.xml.rels', data: workbookRels },
    { name: 'xl/worksheets/sheet1.xml', data: sheetXml },
    { name: 'xl/sharedStrings.xml', data: sharedStrings },
  ]);
}

function buildSyntheticSignedAccountXlsx(opts) {
  return buildSyntheticSignedAccountSheetXml(opts);
}

function buildSyntheticDebitCreditPreheaderXlsx(opts) {
  return buildSyntheticDebitCreditPreheaderSheetXml(opts);
}

function writeTempXlsx(prefix, bytes) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), prefix + '-'));
  const filepath = path.join(dir, 'statement.xlsx');
  fs.writeFileSync(filepath, bytes);
  return { dir, filepath };
}

module.exports = {
  buildSyntheticSignedAccountXlsx,
  buildSyntheticDebitCreditPreheaderXlsx,
  writeTempXlsx,
  excelSerialDate,
  displayDate,
  danishDecimal,
};
