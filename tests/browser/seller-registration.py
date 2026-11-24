"""Check the Blade registration UI with mocked location responses and isolated data.

Run after npm run build: python3 tests/browser/seller-registration.py
Requires the Python Playwright package and its Chromium browser.
"""
import asyncio
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = Path(tempfile.gettempdir()) / 'walatra-registration-preview'
OUTPUT.mkdir(exist_ok=True)
PAGES = {}
CHECKS = []

PHP = r'''
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$request = Illuminate\Http\Request::create($argv[2].'/seller/register');
$app->instance('request', $request);
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$request->setLaravelSession(app('session')->driver());
$user = new App\Models\User(['name' => '', 'email' => null, 'phone' => '628123456789']);
Illuminate\Support\Facades\Auth::setUser($user);
$errors = new Illuminate\Support\ViewErrorBag;
if ($argv[3] === 'restore') {
 session()->put('_old_input', [
  'name' => 'Seller Pengujian', 'email' => 'seller@example.test', 'store_name' => 'Toko Pengujian',
  'province_id' => 'p==', 'province' => 'Jawa Barat', 'city_id' => 'c=', 'city' => 'Bandung Barat',
  'district_id' => 'd=', 'district' => 'Batujajar', 'sub_district_id' => 's=',
  'sub_district_name' => 'Bojonghaleuang', 'postal_code' => '40561', 'address' => 'Jalan Pengujian',
 ]);
 $errors->put('default', new Illuminate\Support\MessageBag(['address' => ['Periksa alamat lengkap.']]));
}
$app['view']->share('errors', $errors);
echo view('seller.register', ['user' => $user])->render();
'''


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT / 'public'), **kwargs)

    def do_GET(self):
        path = urlparse(self.path).path
        if path in PAGES:
            body = PAGES[path]
            self.send_response(200)
            self.send_header('Content-Type', 'text/html; charset=utf-8')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        elif path.startswith('/build/'):
            super().do_GET()
        else:
            self.send_error(404)

    def log_message(self, *_):
        pass


async def setup(context, mode='normal'):
    page = await context.new_page()
    errors = []
    state = {'failed': False}
    requested = asyncio.Event()
    page.on('pageerror', lambda error: errors.append(str(error)))

    async def api(route):
        url = urlparse(route.request.url)
        kind, query = url.path.rsplit('/', 1)[-1], parse_qs(url.query)
        if kind == 'provinces':
            if mode in ['empty', '503'] and not state['failed']:
                state['failed'] = True
                await route.fulfill(status=503 if mode == '503' else 200, json={'data': {'locations': []}})
                return
            rows = [{'province_id': 'p==', 'province_name': 'Jawa Barat'}, {'province_id': 'p2=', 'province_name': 'Jawa Tengah'}]
        elif kind == 'cities':
            assert query['province_id'] in [['p=='], ['p2=']]
            if query['province_id'] == ['p==']:
                if mode == 'race':
                    requested.set()
                    await asyncio.sleep(.35)
                rows = [{'id': 'c=', 'name': 'Bandung Barat'}]
            else:
                rows = [{'city_id': 'c2=', 'city_name': 'Semarang'}]
        elif kind == 'districts':
            assert query['city_id'] == ['c=']
            rows = [{'district_id': 'd=', 'district_name': 'Batujajar'}]
        else:
            assert query['district_id'] == ['d=']
            rows = [{'sub_district_id': 's=', 'sub_district_name': 'Bojonghaleuang', 'postal_code': '40561'}]
        try:
            await route.fulfill(json={'success': True, 'data': {'locations': rows}})
        except Exception:
            if mode != 'race':
                raise

    await page.route('**/api/v1/shipping/locations/**', api)
    return page, errors, requested


async def fill_account(page):
    await page.locator('#name').fill('Seller Pengujian')
    await page.locator('#email').fill('seller@example.test')
    await page.locator('#password').fill('secret6')
    await page.locator('#next-step').click()
    await page.locator('#store_name').fill('Toko Pengujian')
    await page.locator('#description').fill('<img src=x onerror=alert(1)>')
    await page.locator('#next-step').click()


async def fill_address(page):
    for field, value in [('province', 'p=='), ('city', 'c='), ('district', 'd='), ('sub_district', 's=')]:
        await page.locator('#'+field+'_id').select_option(value)
    assert await page.locator('#postal_code').input_value() == '40561'
    await page.locator('#address').fill('Jalan Pengujian Nomor 10')


async def run(base):
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, args=['--no-sandbox'])
        try:
            context = await browser.new_context(viewport={'width': 1280, 'height': 900})
            page, errors, _ = await setup(context)
            submitted = []

            async def submit(route):
                if route.request.method == 'POST':
                    submitted.append(parse_qs(route.request.post_data))
                    await asyncio.sleep(.2)
                    await route.fulfill(content_type='text/html', body='Pengajuan diterima')
                else:
                    await route.continue_()

            await page.route('**/seller/register', submit)
            await page.goto(base+'/seller/register')
            await page.wait_for_load_state('networkidle')
            await page.screenshot(path=str(OUTPUT / 'desktop-account.png'), full_page=True)
            await page.locator('#next-step').click()
            assert await page.locator('[data-step="1"]').is_visible()
            assert await page.locator('#name').evaluate('(el) => el === document.activeElement')
            await page.locator('#toggle-password').click()
            assert await page.locator('#password').get_attribute('type') == 'text'
            await page.locator('#toggle-password').click()
            assert await page.locator('#password').get_attribute('type') == 'password'
            CHECKS.append('required fields, focus and password visibility')
            await fill_account(page)
            await fill_address(page)
            await page.screenshot(path=str(OUTPUT / 'desktop-address.png'), full_page=True)
            await page.locator('#next-step').click()
            assert await page.locator('#registration-review').is_visible()
            assert await page.locator('[data-review-field="province"]').inner_text() == 'Jawa Barat'
            assert await page.locator('#registration-review img').count() == 0
            assert await page.locator('[data-review-field="description"]').inner_text() == '<img src=x onerror=alert(1)>'
            assert 'secret6' not in await page.locator('#registration-review').inner_text()
            assert await page.locator('[data-state="complete"]').count() == 3
            assert await page.locator('[data-step-check]:visible').count() == 3
            CHECKS.append('review uses selected labels, safe text and excludes password')
            await page.locator('[data-edit-step="2"]').click()
            await page.locator('#store_name').fill('Toko yang Diperbarui')
            await page.locator('#next-step').click()
            assert await page.locator('#registration-review').is_visible()
            assert await page.locator('[data-review-field="store_name"]').inner_text() == 'Toko yang Diperbarui'
            await page.locator('#previous-step').click()
            assert await page.locator('[data-step="3"]').is_visible()
            assert await page.locator('#sub_district_id').input_value() == 's='
            await page.locator('#next-step').click()
            CHECKS.append('edit section and back from review preserve form data')
            await page.screenshot(path=str(OUTPUT / 'desktop-review.png'), full_page=True)
            for width in [320, 390, 600, 768, 1024, 1280]:
                await page.set_viewport_size({'width': width, 'height': 900})
                assert await page.evaluate('document.documentElement.scrollWidth <= innerWidth'), width
                if width == 390:
                    await page.screenshot(path=str(OUTPUT / 'mobile-review.png'), full_page=True)
                if width == 768:
                    await page.screenshot(path=str(OUTPUT / 'tablet-review.png'), full_page=True)
            CHECKS.append('no horizontal overflow at six phone, tablet and desktop widths')
            await page.locator('#submit-registration').click(no_wait_after=True)
            await page.get_by_text('Pengajuan diterima').wait_for()
            assert len(submitted) == 1
            assert submitted[0]['province_id'] == ['p==']
            assert submitted[0]['sub_district_name'] == ['Bojonghaleuang']
            assert submitted[0]['store_name'] == ['Toko yang Diperbarui']
            assert not errors, errors
            CHECKS.append('final submission sends reviewed data and location IDs')
            await page.close()

            for mode in ['503', 'empty']:
                page, errors, _ = await setup(context, mode)
                await page.goto(base+'/seller/register')
                await page.wait_for_load_state('networkidle')
                await fill_account(page)
                assert await page.locator('#retry-locations').is_visible()
                await page.locator('#retry-locations').click()
                await page.locator('#province_id').select_option('p==')
                assert await page.locator('#retry-locations').is_hidden()
                assert not errors, errors
                CHECKS.append(mode+' location response remains recoverable')
                await page.close()

            page, errors, requested = await setup(context, 'race')
            await page.goto(base+'/seller/register')
            await fill_account(page)
            await page.locator('#province_id').select_option('p==')
            await asyncio.wait_for(requested.wait(), 3)
            await page.locator('#province_id').select_option('p2=')
            await page.locator('#city_id option[value="c2="]').wait_for(state='attached')
            await asyncio.sleep(.5)
            assert await page.locator('#city_id option[value="c="]').count() == 0
            assert await page.locator('#postal_code').input_value() == ''
            assert not errors, errors
            CHECKS.append('old province response cannot restore stale city or postal code')
            await page.close()

            page, errors, _ = await setup(context)
            await page.goto(base+'/restore')
            await page.wait_for_load_state('networkidle')
            assert await page.locator('[data-step="3"]').is_visible()
            assert await page.locator('#sub_district_id').input_value() == 's='
            assert await page.locator('#postal_code').input_value() == '40561'
            await page.locator('#next-step').click()
            assert await page.locator('[data-step="1"]').is_visible()
            assert await page.locator('#password').input_value() == ''
            assert not errors, errors
            CHECKS.append('validation restores location and requires password re-entry')
            await context.close()
        finally:
            await browser.close()


def main():
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    base = 'http://127.0.0.1:'+str(server.server_address[1])
    env = dict(os.environ, APP_ENV='testing', DB_CONNECTION='sqlite', DB_DATABASE=':memory:', DB_URL='',
               CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAXCHAT_ENABLED='false')
    for path, variant in [('/seller/register', 'normal'), ('/restore', 'restore')]:
        result = subprocess.run(['php', '-r', PHP, '--', str(ROOT), base, variant], cwd=ROOT, env=env,
                                capture_output=True, check=True)
        PAGES[path] = result.stdout
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        asyncio.run(run(base))
        report = {'passed': len(CHECKS), 'checks': CHECKS, 'screenshots': str(OUTPUT)}
        (OUTPUT / 'checks.json').write_text(json.dumps(report, indent=2)+'\n')
        print(json.dumps(report, indent=2))
    finally:
        server.shutdown()
        server.server_close()
        thread.join()


if __name__ == '__main__':
    main()
