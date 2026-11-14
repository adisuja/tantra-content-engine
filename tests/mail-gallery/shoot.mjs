// tantra: screenshots of the email gallery (tests/Feature/Tantra/MailGalleryTest.php writes the HTML).
// Usage: node tests/mail-gallery/shoot.mjs <html dir> <png dir>
// Each email at 600px and 375px wide, light and dark. Images on content.usetantra.com are
// served from public/ when the file is there, so assets that are not deployed yet still show.
import fs from 'node:fs';
import path from 'node:path';

import { chromium } from 'playwright';

const [, , src, out] = process.argv;
const publicDir = path.resolve('public');
const types = { '.png': 'image/png', '.gif': 'image/gif', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.svg': 'image/svg+xml', '.webp': 'image/webp' };
fs.mkdirSync(out, { recursive: true });
const mediaMap = fs.existsSync(path.join(src, 'media.json')) ? JSON.parse(fs.readFileSync(path.join(src, 'media.json'), 'utf8')) : {};

const browser = await chromium.launch({ args: ['--disable-dev-shm-usage'] });
for (const file of fs.readdirSync(src).filter((f) => f.endsWith('.html')).sort()) {
    const name = file.replace(/\.html$/, '');
    const html = fs.readFileSync(path.join(src, file), 'utf8');
    for (const width of [600, 375]) {
        for (const scheme of ['light', 'dark']) {
            const context = await browser.newContext({ viewport: { width, height: 800 }, colorScheme: scheme, deviceScaleFactor: 1 });
            const page = await context.newPage();
            await page.route('https://content.usetantra.com/**', async (route) => {
                const mapped = mediaMap[route.request().url()];
                if (mapped) {
                    const file = path.resolve(src, mapped);
                    return route.fulfill({ body: fs.readFileSync(file), contentType: types[path.extname(file)] ?? 'image/jpeg' });
                }
                const local = path.join(publicDir, decodeURIComponent(new URL(route.request().url()).pathname));
                if (local.startsWith(publicDir) && fs.existsSync(local) && fs.statSync(local).isFile()) {
                    return route.fulfill({ body: fs.readFileSync(local), contentType: types[path.extname(local)] ?? 'application/octet-stream' });
                }
                return route.continue();
            });
            await page.setContent(html, { waitUntil: 'networkidle' }).catch(() => {});
            await page.screenshot({ path: path.join(out, `${name}-${width}-${scheme}.png`), fullPage: true });
            await context.close();
        }
    }
    console.log('shot', name);
}
await browser.close();
