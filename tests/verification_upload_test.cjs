const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

const page = read('Commuter/verification.php');
const css = read('Assets/css/commuter_verification.css');
const js = read('Assets/js/commuter_verification.js');

assert.match(page, /enctype="multipart\/form-data"/);
assert.match(page, /id="verification_document"[^>]*name="verification_document"/);
assert.match(page, /class="upload-document-group"/);
assert.match(page, /class="upload-icon"/);
assert.match(page, /class="document-name"/);
assert.match(page, /class="document-input-button"/);
assert.match(page, /class="document-preview"/);
assert.match(page, /UPLOAD_ERR_NO_FILE/);
assert.match(page, /ID photo upload failed/);
assert.match(css, /\.verify-page \.upload-document-group/);
assert.match(css, /\.verify-page \.upload-group\.is-done/);
assert.match(js, /button\.addEventListener\('click'/);
assert.match(js, /new DataTransfer/);
assert.match(js, /async function optimizeImage/);
assert.match(js, /input\.files && input\.files\[0\]/);

console.log('PASS verification upload card, preview, optimization, and upload-error handling');
