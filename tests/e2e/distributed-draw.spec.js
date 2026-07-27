const { test, expect } = require("@playwright/test");

test("setup player count creates visible roles for players", async ({ browser }) => {

    const hostContext = await browser.newContext();
    await hostContext.addInitScript(() => {
        window.localStorage.setItem("pocket-grimoire", JSON.stringify({
            lookup: {
                "characters_en-GB": []
            }
        }));
    });
    const host = await hostContext.newPage();
    await host.goto("/en_GB/");

    await host.getByRole("button", { name: "Select Edition" }).click();
    await host.getByLabel("Trouble Brewing").check({ force: true });
    await host.locator("#select-edition-form [type=\"submit\"]").click();
    await expect(
        host.getByRole("button", { name: "Select Characters" })
    ).toBeEnabled();
    await host.getByRole("button", { name: "Select Characters" }).click();

    const playerCount = host.getByLabel("Number of players");
    await playerCount.fill("5");
    await expect(host.locator("#player-count-output")).toHaveText("5");
    await expect(
        host.locator(".js--character-select--name").filter({ visible: true })
    ).not.toHaveCount(0);

    await host.getByRole("button", { name: "Highlight Random" }).click();
    await expect(
        host.locator(".js--character-select--input:checked")
    ).toHaveCount(5);
    await host.getByRole("button", { name: "Draw on devices" }).click();

    const hostSlots = host.locator(
        "#distributed-draw-host-slots [data-number]"
    );
    await expect(hostSlots).toHaveCount(5);
    await expect(hostSlots.first().locator("strong")).toHaveText("Available");

    const joinUrl = await host.locator("#distributed-draw-link")
        .getAttribute("href");
    const playerContext = await browser.newContext();
    const player = await playerContext.newPage();
    await player.goto(joinUrl);
    await player.locator('[data-number="1"]').click();

    await expect(player.locator("#draw-role")).not.toHaveClass(/is-private/);
    await expect(player.locator("#draw-role-name")).not.toBeEmpty();
    await expect(player.locator("#draw-role-name")).toBeVisible();

    await player.getByLabel("Enter your name to finish").fill("Cache Test");
    await player.getByRole("button", {
        name: "Add me to the grimoire"
    }).click();
    await host.evaluate(() => {
        window.dispatchEvent(new Event("online"));
    });
    const grimoireTokens = host.locator(".token--movable");
    await expect(grimoireTokens).toHaveCount(1);

    await host.locator("#distributed-draw-host")
        .getByRole("button", { name: "Close" })
        .click();
    await host.getByRole("button", { name: "Clear Cache" }).click();
    await host.getByLabel("Refresh afterwards").uncheck({ force: true });
    await host.locator("#cache-form [type=\"submit\"]").click();
    await expect.poll(() => host.evaluate(() => {
        const stored = JSON.parse(
            window.localStorage.getItem("pocket-grimoire")
        );
        return stored.tokens.length;
    })).toBe(0);
    await expect.poll(() => host.evaluate(() => (
        window.localStorage.getItem("pocket-grimoire:draw-host") !== null
    ))).toBe(false);
    await expect(grimoireTokens).toHaveCount(0);
    await expect(host.getByRole("button", { name: "Draw room" })).toBeDisabled();
    await expect.poll(() => player.evaluate(async () => {
        const response = await fetch(window.DRAW_PLAYER_CONFIG.statusUrl);
        const state = await response.json();
        return state.status;
    })).toBe("ended");
    await host.reload();
    await expect(grimoireTokens).toHaveCount(0);
    await expect(host.getByRole("button", { name: "Draw room" })).toBeDisabled();

    await Promise.all([
        hostContext.close(),
        playerContext.close()
    ]);

});

test("two devices draw privately and synchronize with the grimoire", async ({
    browser
}) => {

    const hostContext = await browser.newContext();
    const host = await hostContext.newPage();
    await host.goto("/en_GB/");

    const created = await host.evaluate(async () => {

        const characters = [
            {
                id: "imp",
                name: "Imp",
                ability: "Each night, choose a player: they die.",
                team: "demon",
                image: ""
            },
            {
                id: "custom-chef",
                name: "Chef",
                ability: "You start knowing how many pairs of evil players there are.",
                team: "townsfolk",
                image: ""
            }
        ];
        const response = await fetch(window.DRAW_SESSION_CONFIG.createUrl, {
            method: "POST",
            headers: {
                "Accept": "application/json",
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                _token: window.DRAW_SESSION_CONFIG.csrfToken,
                characters,
                impDrawOrder: 1
            })
        });
        const result = await response.json();

        if (!response.ok) {
            throw new Error(JSON.stringify(result));
        }

        window.localStorage.setItem("pocket-grimoire:draw-host", JSON.stringify({
            publicId: result.publicId,
            hostSecret: result.hostSecret,
            joinUrl: result.joinUrl,
            expiresAt: result.expiresAt,
            topic: result.topic
        }));

        return result;

    });

    await host.reload();
    await expect(host.getByRole("button", { name: "Draw room" })).toBeEnabled();
    await host.getByRole("button", { name: "Draw room" }).click();
    await expect(host.locator("#distributed-draw-qr svg")).toBeVisible();
    await expect(host.locator("#distributed-draw-link")).toHaveAttribute(
        "href",
        created.joinUrl
    );

    const playerContextOne = await browser.newContext();
    const playerContextTwo = await browser.newContext();
    const playerOne = await playerContextOne.newPage();
    const playerTwo = await playerContextTwo.newPage();
    await Promise.all([
        playerOne.goto(created.joinUrl),
        playerTwo.goto(created.joinUrl)
    ]);

    const firstOne = playerOne.locator('[data-number="1"]');
    const firstTwo = playerTwo.locator('[data-number="1"]');

    await playerOne.locator('[data-number="2"]').click();
    await expect(playerOne.locator("#draw-role-name")).toHaveText("Imp");
    await expect(playerTwo.locator('[data-number="2"]')).toBeDisabled();
    const secondHostSlot = host.locator(
        '#distributed-draw-host-slots [data-number="2"]'
    );
    await expect(secondHostSlot).toHaveClass(/is-naming/);
    host.once("dialog", (dialog) => dialog.accept());
    await secondHostSlot.getByRole("button", { name: "Release number" }).click();
    await expect(playerOne.locator("#draw-picker")).toBeVisible();
    await expect(playerOne.locator("#draw-message")).toContainText("released");

    await Promise.allSettled([
        firstOne.click(),
        firstTwo.click()
    ]);

    await expect.poll(async () => (
        Number(await playerOne.locator("#draw-claim").isVisible())
        + Number(await playerTwo.locator("#draw-claim").isVisible())
    )).toBe(1);

    const winner = (
        await playerOne.locator("#draw-claim").isVisible()
        ? playerOne
        : playerTwo
    );
    const loser = winner === playerOne ? playerTwo : playerOne;
    await expect(loser.locator('[data-number="1"]')).toBeDisabled();
    await expect(winner.locator("#draw-role-name")).toHaveText("Chef");

    await winner.getByRole("button", { name: "Hide role" }).click();
    await expect(winner.locator("#draw-role")).toHaveClass(/is-private/);
    await winner.getByRole("button", { name: "Reveal role" }).click();
    await expect(winner.locator("#draw-role")).not.toHaveClass(/is-private/);

    await winner.getByLabel("Enter your name to finish").fill("Alice");
    await winner.getByRole("button", { name: "Add me to the grimoire" }).click();
    await expect(winner.locator("#draw-name-form")).toBeHidden();

    const hostSlot = host.locator(
        '#distributed-draw-host-slots [data-number="1"]'
    );
    await expect(hostSlot.locator('input[name="name"]')).toHaveValue("Alice");
    const grimoireNames = host.locator(
        ".token--movable .js--character--player-name"
    );
    await expect(grimoireNames).toHaveText("Alice");

    await hostSlot.locator('input[name="name"]').fill("Alicia");
    await hostSlot.getByRole("button", { name: "Save" }).click();
    await expect(grimoireNames).toHaveText("Alicia");

    await host.reload();
    await host.getByRole("button", { name: "Draw room" }).click();
    await expect(grimoireNames).toHaveCount(1);
    await expect(grimoireNames).toHaveText("Alicia");
    await expect(hostSlot.locator('input[name="name"]')).toHaveValue("Alicia");

    await winner.reload();
    await expect(winner.locator("#draw-role")).toHaveClass(/is-private/);
    await expect(winner.getByRole("button", { name: "Reveal role" })).toBeVisible();

    await loser.reload();
    await expect(loser.locator('[data-number="1"]')).toBeDisabled();

    host.once("dialog", (dialog) => dialog.accept());
    await hostSlot.getByRole("button", { name: "Release number" }).click();
    await expect(grimoireNames).toHaveCount(0);
    await expect(winner.locator("#draw-picker")).toBeVisible();
    await expect(winner.locator("#draw-message")).toContainText("released");
    await expect(loser.locator('[data-number="1"]')).toBeEnabled();

    await Promise.all([
        hostContext.close(),
        playerContextOne.close(),
        playerContextTwo.close()
    ]);

});
