"""Playwright checks against a disposable, loopback-only WordPress site."""

import asyncio
import os
import re
import uuid

from playwright.async_api import async_playwright, expect


async def main():
    base = os.environ['WPUS_SMOKE_URL']

    async def local_only(route):
        if route.request.url.startswith(base + '/'):
            await route.continue_()
        else:
            await route.abort()

    async with async_playwright() as playwright:
        browser = await playwright.chromium.launch()
        page = await browser.new_page(accept_downloads=True)
        await page.route('**/*', local_only)
        await page.goto(base + '/wp-login.php', wait_until='domcontentloaded')
        await page.locator('#user_login').fill(os.environ['WPUS_SMOKE_USER'])
        await page.locator('#user_pass').fill(os.environ['WPUS_SMOKE_PASSWORD'])
        await page.locator('#user_pass').press('Enter')
        await expect(page).to_have_url(re.compile('/wp-admin/'))

        overview = base + '/wp-admin/admin.php?page=wpus'
        await page.goto(overview, wait_until='domcontentloaded')
        pause = page.get_by_role('checkbox', name='Pause all Utility Suite outbound traffic on this site')
        await pause.press('Space')
        await expect(pause).to_be_checked()
        await page.get_by_role('button', name='Save lifecycle controls').press('Enter')
        await expect(page).to_have_url(re.compile('wpus_status=saved'))
        await expect(pause).to_be_checked()

        await page.goto(overview + '&tab=admin_tools', wait_until='domcontentloaded')
        marker = 'Chromium fixture ' + uuid.uuid4().hex[:8]
        await page.get_by_text('Add a note', exact=True).press('Enter')
        note = page.locator('details[open]')
        await note.get_by_label('Title', exact=True).fill(marker)
        await note.get_by_label('Note', exact=True).fill('Text saved with a keyboard.')
        await page.get_by_role('button', name='Create note').press('Enter')
        await expect(page).to_have_url(re.compile('wpus_status=saved'))
        await expect(page.get_by_text(marker, exact=False).first).to_be_visible()

        await page.goto(overview + '&tab=security', wait_until='domcontentloaded')
        async with page.expect_download() as download:
            await page.get_by_role('button', name='Download filtered JSON Lines', exact=False).press('Enter')
        assert (await download.value).suggested_filename.endswith('.jsonl')
        await browser.close()
    print('PASS Chromium keyboard login, emergency pause, notes and audit export')


asyncio.run(main())
