import Observer from "../../classes/Observer.js";
import Dialog from "../../classes/Dialog.js";
import TokenStore from "../../classes/TokenStore.js";
import Template from "../../classes/Template.js";
import {
    empty,
    lookupOne,
    lookupOneCached,
    replaceContentsMany
} from "../../utils/elements.js";
import {
    shuffle
} from "../../utils/arrays.js";
import {
    clamp
} from "../../utils/numbers.js";

const gameObserver = Observer.create("game");
const characterDecisionDialog = Dialog.create(lookupOne("#character-decision"));
const playerName = lookupOne("#player-name");
let drawQueue = [];

/**
 * Builds the draw queue while placing the Imp at the provided draw order, when
 * possible.
 *
 * @param {Array.<CharacterToken>} characters
 *        Collection of characters to present for drawing.
 * @param {?Number} impDrawOrder
 *        1-based order in which the Imp should be drawn, or null for random.
 * @return {Array.<CharacterToken>}
 *        Ordered collection of characters.
 */
function buildDrawQueue(characters, impDrawOrder) {

    if (!Array.isArray(characters) || !characters.length) {
        return [];
    }

    const clones = characters.map((character) => character.clone());

    if (!impDrawOrder) {
        return shuffle(clones);
    }

    const impIndex = clones.findIndex((character) => (
        typeof character?.getId === "function"
        && character.getId() === "imp"
    ));

    if (impIndex === -1) {
        return shuffle(clones);
    }

    const impCharacter = clones[impIndex];
    const remaining = clones.filter((_, index) => index !== impIndex);
    const shuffledRemaining = shuffle(remaining);
    const insertIndex = clamp(0, impDrawOrder - 1, shuffledRemaining.length);

    shuffledRemaining.splice(insertIndex, 0, impCharacter);

    return shuffledRemaining;

}

gameObserver.on("character-draw", ({ detail }) => {

    if (detail.isShowAll) {
        drawQueue = [];
        return;
    }

    const template = Template.create(
        lookupOneCached("#character-choice-template")
    );

    drawQueue = buildDrawQueue(detail.characters || [], detail.impDrawOrder);
    const { length } = drawQueue;

    replaceContentsMany(
        lookupOneCached("#character-choice-wrapper"),
        Array.from({ length }).map((_, i) => template.draw({
            "[data-id]"(element) {
                element.dataset.id = String(i);
            },
            ".js--character-choice--number"(element) {
                element.textContent = i + 1;
            }
        }))
    );

    Dialog.create(lookupOneCached("#character-choice")).show();

});

gameObserver.on("character-draw", ({ detail }) => {

    if (!detail.isShowAll) {
        return;
    }

    lookupOneCached("#grimoire").open = true;

    TokenStore.ready((tokenStore) => {

        detail.characters.forEach((character) => {

            gameObserver.trigger("character-drawn", {
                character: character.clone(),
                isAutoAdd: true
            });

        });

    });

});

lookupOneCached("#character-choice").addEventListener("click", ({ target }) => {

    const element = target.closest("[data-id]");

    if (!element || element.disabled) {
        return;
    }

    const character = drawQueue.shift();

    if (!character) {
        return;
    }

    gameObserver.trigger("character-drawn", {
        element,
        character
    });

});

gameObserver.on("character-drawn", ({ detail }) => {

    const {
        element
    } = detail;

    if (element) {
        element.disabled = true;
    }

});

gameObserver.on("character-drawn", ({ detail }) => {

    const {
        isAutoAdd,
        character
    } = detail;

    if (isAutoAdd) {
        return;
    }

    empty(lookupOneCached("#character-decision-wrapper")).append(
        character.drawToken()
    );
    lookupOneCached("#character-decision-ability").textContent = (
        character.getAbility()
    );
    characterDecisionDialog.show();

});

// Allow a name to be set when the character is revealed.
// We do this by checking to see if a name was entered when the "remember your
// character" dialog is closed, using it if it was.

let character = null;

gameObserver.on("character-drawn", ({ detail }) => {
    character = detail.character;
});

characterDecisionDialog.on(Dialog.SHOW, () => {
    playerName.value = playerName.defaultValue;
});

characterDecisionDialog.on(Dialog.HIDE, () => {

    const {
        pad
    } = lookupOneCached(".js--pad");
    const {
        value
    } = playerName;
    const trimmed = (value || "").trim();

    if (pad && trimmed && character) {
        pad.setPlayerName(character, trimmed);
    }

    character = null;

});

lookupOne("#character-decision-form").addEventListener("submit", (e) => {
    e.preventDefault();
    characterDecisionDialog.hide();
});
