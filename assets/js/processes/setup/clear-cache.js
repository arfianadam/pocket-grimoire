import Store from "../../classes/Store.js";
import Dialog from "../../classes/Dialog.js";
import Observer from "../../classes/Observer.js";
import {
    lookupOne,
    lookupCached,
    lookupOneCached,
    announceInput
} from "../../utils/elements.js";

const store = Store.create("pocket-grimoire");
const gameObserver = Observer.create("game");
const clears = lookupCached("input[name=\"clear\"]");

lookupOne("#clear-all").addEventListener("change", ({ target }) => {

    lookupOneCached("#clear-individual").hidden = target.checked;

    if (target.checked) {

        clears.forEach((input) => {

            input.checked = true;
            announceInput(input);

        });

    }

});

lookupOne("#clear-tokens").addEventListener("change", ({ target }) => {
    lookupOneCached("#token-warning").hidden = !target.checked;
});

lookupOne("#clear-infoTokens").addEventListener("change", ({ target }) => {
    lookupOneCached("#info-token-warning").hidden = !target.checked;
});

lookupOne("#cache-form").addEventListener("submit", async (e) => {

    e.preventDefault();
    const clearedValues = clears
        .filter(({ checked }) => checked)
        .map(({ value }) => value);

    if (clearedValues.includes("tokens")) {

        const pending = [];

        gameObserver.trigger("distributed-draw-clear", {
            waitUntil(promise) {
                pending.push(promise);
            }
        });
        await Promise.allSettled(pending);

    }

    clears.forEach(({ value, checked }) => {

        if (checked) {
            store.delete(value);
        }

    });

    if (clearedValues.includes("tokens")) {
        gameObserver.trigger("clear");
    }

    if (lookupOneCached("#clear-refresh").checked) {
        window.location.reload();
    }

    Dialog.create(lookupOneCached("#clear-cache")).hide();

});
