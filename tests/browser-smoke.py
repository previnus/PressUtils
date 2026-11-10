"""Playwright checks against a disposable, loopback-only WordPress site."""

import os
import re
import uuid

from playwright.sync_api import expect, sync_playwright


base = os.environ['WPUS_SMOKE_URL']
with sync_playwright() as playwright:
    browser = playwright.chromium.launch()
    page = browser.new_page(accept_downloads=True)
    page.goto(base + '/wp-login.php')
    page.locator('#user_login').fill(os.environ['WPUS_SMOKE_USER'])
    page.locator('#user_pass').fill(os.environ['WPUS_SMOKE_PASSWORD'])
    page.locator('#user_pass').press('Enter')
    expect(page).to_have_url(re.compile('/wp-admin/'))

    overview = base + '/wp-admin/admin.php?page=wpus'
    page.goto(overview)
    pause = page.get_by_role('checkbox', name='Pause all Utility Suite outbound traffic on this site')
    pause.press('Space')
    expect(pause).to_be_checked()
    page.get_by_role('button', name='Save lifecycle controls').press('Enter')
    expect(page).to_have_url(re.compile('wpus_status=saved'))
    expect(pause).to_be_checked()

    page.goto(overview + '&tab=admin_tools')
    marker = 'Chromium fixture ' + uuid.uuid4().hex[:8]
    page.get_by_label('Title', exact=True).first.fill(marker)
    page.get_by_label('Note', exact=True).first.fill('Text saved with a keyboard.')
    page.get_by_role('button', name='Create note').press('Enter')
    expect(page).to_have_url(re.compile('wpus_status=saved'))
    expect(page.get_by_text(marker, exact=False).first).to_be_visible()

    page.goto(overview + '&tab=security')
    with page.expect_download() as download:
        page.get_by_role('button', name='Download filtered JSON Lines', exact=False).press('Enter')
    assert download.value.suggested_filename.endswith('.jsonl')
    print('PASS Chromium keyboard login, emergency pause, notes and audit export')
