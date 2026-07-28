const { test, expect } = require("@playwright/test");

test("keeps the screen awake and restores the preference", async ({
    browser
}) => {

    const context = await browser.newContext();

    await context.addInitScript(() => {

        class FakeWakeLockSentinel extends EventTarget {

            constructor() {
                super();
                this.released = false;
            }

            async release() {

                if (this.released) {
                    return;
                }

                this.released = true;
                this.dispatchEvent(new Event("release"));

            }

        }

        window.testWakeLocks = [];

        Object.defineProperty(navigator, "wakeLock", {
            configurable: true,
            value: {
                async request(type) {

                    if (type !== "screen") {
                        throw new Error(`Unexpected wake lock type: ${type}`);
                    }

                    const sentinel = new FakeWakeLockSentinel();

                    window.testWakeLocks.push(sentinel);

                    return sentinel;

                }
            }
        });

    });

    const page = await context.newPage();

    await page.goto("/en_GB/");

    const toggle = page.getByLabel("Keep screen awake");
    const status = page.locator("#wake-lock-status");

    await toggle.check({ force: true });
    await expect(status).toHaveText(
        "The screen will stay awake while this page is visible."
    );
    await expect.poll(() => page.evaluate(() => (
        window.testWakeLocks.length
    ))).toBe(1);

    await page.evaluate(async () => {

        Object.defineProperty(document, "visibilityState", {
            configurable: true,
            value: "hidden"
        });
        await window.testWakeLocks[0].release();
        Object.defineProperty(document, "visibilityState", {
            configurable: true,
            value: "visible"
        });
        document.dispatchEvent(new Event("visibilitychange"));

    });

    await expect.poll(() => page.evaluate(() => (
        window.testWakeLocks.length
    ))).toBe(2);
    await expect(status).toHaveText(
        "The screen will stay awake while this page is visible."
    );

    await page.reload();
    await expect(toggle).toBeChecked();
    await expect.poll(() => page.evaluate(() => (
        window.testWakeLocks.length
    ))).toBe(1);

    await toggle.uncheck({ force: true });
    await expect(status).toBeHidden();
    await expect.poll(() => page.evaluate(() => (
        window.testWakeLocks[0].released
    ))).toBe(true);

    await context.close();

});

test("disables the setting when screen wake locks are unavailable", async ({
    browser
}) => {

    const context = await browser.newContext();

    await context.addInitScript(() => {
        Object.defineProperty(navigator, "wakeLock", {
            configurable: true,
            value: undefined
        });
    });

    const page = await context.newPage();

    await page.goto("/en_GB/");

    await expect(page.getByLabel("Keep screen awake")).toBeDisabled();
    await expect(page.locator("#wake-lock-status")).toHaveText(
        "This browser does not support keeping the screen awake."
    );

    await context.close();

});
