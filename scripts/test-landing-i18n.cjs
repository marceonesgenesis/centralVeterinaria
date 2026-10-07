// Run against a rendered landing preview with Playwright installed.
// Example: PLAYWRIGHT_MODULE=/path/to/playwright node scripts/test-landing-i18n.cjs http://127.0.0.1:8768/
const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const url = process.argv[2] || 'http://127.0.0.1:8768/';
    await page.goto(url);
    await page.locator('[data-add="pro"]').waitFor();
    assert.equal(await page.locator('html').getAttribute('lang'), 'pt-BR');
    await page.selectOption('#landing-language', 'en');
    assert.match(await page.locator('h1').innerText(), /From scheduling to follow-up/);
    assert.match(await page.locator('.login-link').innerText(), /Log in/);
    assert.match(await page.locator('.plan.featured').innerText(), /Most popular/);
    assert.equal(await page.locator('.compare td.yes').first().getAttribute('aria-label'), 'Included');
    await page.locator('[data-add="pro"]').click();
    await page.locator('#lead-name').fill('Recepção');
    await page.locator('#lead-clinic').fill('Agenda');
    await page.locator('#lead-email').fill('test@example.com');
    await page.locator('#lead-phone').fill('11912345678');
    await page.selectOption('#cart-language', 'es');
    assert.equal(await page.locator('#lead-name').inputValue(), 'Recepção');
    assert.equal(await page.locator('#lead-clinic').inputValue(), 'Agenda');
    assert.equal(await page.locator('#cart-count').innerText(), '1');
    assert.match(await page.locator('#cart-title').innerText(), /Tu carrito/);
    assert.match(await page.locator('#lead-form').innerText(), /Tus datos para la activación/);
    assert.equal(await page.locator('#lead-name').getAttribute('placeholder'), 'Ej.: Dra. Ana Ribeiro');
    await page.locator('#submit-lead').click();
    assert.equal(await page.locator('#consent-err').innerText(), 'Autoriza el contacto antes de enviar.');
    await page.selectOption('#cart-language', 'en');
    assert.equal(await page.locator('#consent-err').innerText(), 'Authorize contact before submitting.');
    assert.match(await page.locator('#submit-lead').innerText(), /Submit request/);
    await page.route('**/lead.php', route => route.fulfill({ status: 201, contentType: 'application/json', body: '{"accepted":true}' }));
    await page.locator('#lead-consent').check();
    await page.locator('#submit-lead').click();
    await page.getByRole('heading', { name: 'Request received' }).waitFor();
    assert.match(await page.locator('.done-box').innerText(), /Recepção/);
    assert.match(await page.locator('.done-box').innerText(), /Agenda/);
    await page.selectOption('#cart-language', 'es');
    assert.match(await page.locator('.done-box').innerText(), /Solicitud recibida/);
    assert.match(await page.locator('.done-box').innerText(), /Recepção/);
    await page.reload();
    await page.locator('[data-add="pro"]').waitFor();
    assert.equal(await page.locator('#landing-language').inputValue(), 'es');
    assert.equal(await page.locator('html').getAttribute('lang'), 'es');
    await page.selectOption('#landing-language', 'pt');
    assert.match(await page.locator('h1').innerText(), /Da agenda ao retorno/);
    assert.match(await page.locator('.login-link').innerText(), /Entrar/);
    await page.setViewportSize({ width: 360, height: 800 });
    await page.selectOption('#landing-language', 'en');
    const bounds = await page.locator('#landing-language').boundingBox();
    assert.ok(bounds && bounds.x >= 0 && bounds.x + bounds.width <= 360);
    assert.deepEqual(errors, []);
    await context.close();
    const restricted = await browser.newContext();
    await restricted.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage disabled'); } }));
    const fallback = await restricted.newPage();
    await fallback.goto(url);
    await fallback.locator('[data-add="starter"]').waitFor();
    await fallback.selectOption('#landing-language', 'en');
    assert.match(await fallback.locator('h1').innerText(), /From scheduling to follow-up/);
    await restricted.close();
    console.log('PASS: three languages, dynamic content, validation, preserved form/cart, user text, saved preference, mobile selector and unavailable storage.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
