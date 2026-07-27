import CharacterToken from "../classes/CharacterToken.js";
import Dialog from "../classes/Dialog.js";
import Observer from "../classes/Observer.js";
import QRCode from "../lib/qrcode-svg.js";
import TokenStore from "../classes/TokenStore.js";
import {
    empty,
    lookupOneCached
} from "../utils/elements.js";

const STORAGE_KEY = "pocket-grimoire:draw-host";
const MERCURE_URL = "/.well-known/mercure";
const gameObserver = Observer.create("game");
const hostDialog = Dialog.create(lookupOneCached("#distributed-draw-host"));
const pad = lookupOneCached(".js--pad").pad;
const message = lookupOneCached("#distributed-draw-host-message");
const activePanel = lookupOneCached("#distributed-draw-host-active");
const slotsElement = lookupOneCached("#distributed-draw-host-slots");
const openButton = lookupOneCached("#distributed-draw-open");
let hostSession = readHostSession();
let eventSource = null;
let refreshPromise = null;

function readHostSession() {

    try {
        return JSON.parse(window.localStorage.getItem(STORAGE_KEY));
    } catch (ignore) {
        return null;
    }

}

function storeHostSession(session) {

    hostSession = session;

    if (session) {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
    } else {
        window.localStorage.removeItem(STORAGE_KEY);
    }

    openButton.disabled = !session;

}

async function request(url, options = {}) {

    const response = await fetch(url, {
        cache: "no-store",
        credentials: "same-origin",
        ...options,
        headers: {
            "Accept": "application/json",
            ...(options.body ? { "Content-Type": "application/json" } : {}),
            ...(options.headers || {})
        }
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {

        const error = new Error(data.message || window.I18N.distributedUnknown);
        error.status = response.status;
        error.code = data.error;
        throw error;

    }

    return data;

}

function hostHeaders() {
    return {
        "Authorization": `Bearer ${hostSession.hostSecret}`
    };
}

function hostUrl(path = "") {
    return `${window.DRAW_SESSION_CONFIG.createUrl}/${hostSession.publicId}/host${path}`;
}

function setMessage(text, isError = false) {

    message.textContent = text || "";
    message.classList.toggle("distributed-draw__error", isError);

}

function metadataMatches(character, publicId, number) {

    const metadata = character.distributedMetadata;

    return (
        metadata
        && metadata.sessionId === publicId
        && metadata.slotNumber === number
    );

}

function mappedCharacter(publicId, number) {
    return pad.characters
        .map(({ character }) => character)
        .find((character) => metadataMatches(character, publicId, number));
}

function reconcileGrimoire(state) {

    TokenStore.ready(() => {

        state.slots.forEach((slot) => {

            const character = mappedCharacter(state.publicId, slot.number);

            if (slot.state !== "completed" && character) {
                pad.removeCharacter(character);
            }

        });

        state.slots
            .filter(({ state: slotState }) => slotState === "completed")
            .sort((left, right) => (
                Date.parse(left.completedAt) - Date.parse(right.completedAt)
            ))
            .forEach((slot) => {

                let character = mappedCharacter(state.publicId, slot.number);

                if (!character) {

                    character = new CharacterToken(slot.role);
                    character.distributedMetadata = {
                        sessionId: state.publicId,
                        slotNumber: slot.number
                    };
                    lookupOneCached("#grimoire").open = true;
                    gameObserver.trigger("character-drawn", {
                        character,
                        isAutoAdd: true
                    });

                }

                if (pad.getPlayerName(character) !== slot.name) {
                    pad.setPlayerName(character, slot.name);
                }

            });

    });

}

function statusLabel(slot) {
    return window.I18N[`distributed${slot.state[0].toUpperCase()}${slot.state.slice(1)}`];
}

function drawSlots(state) {

    empty(slotsElement);

    state.slots.forEach((slot) => {

        const item = document.createElement("li");
        item.className = `distributed-draw__host-slot is-${slot.state}`;
        item.dataset.number = slot.number;

        const heading = document.createElement("strong");
        heading.textContent = statusLabel(slot);
        item.append(heading);

        if (slot.state === "completed") {

            const form = document.createElement("form");
            form.className = "distributed-draw__rename";
            form.dataset.action = "rename";
            const input = document.createElement("input");
            input.className = "input";
            input.name = "name";
            input.required = true;
            input.maxLength = 80;
            input.value = slot.name || "";
            input.setAttribute("aria-label", window.I18N.distributedPlayerName);
            const save = document.createElement("button");
            save.className = "button";
            save.type = "submit";
            save.textContent = window.I18N.distributedSave;
            form.append(input, save);
            item.append(form);

        }

        if (slot.state !== "available" && state.status === "active") {

            const release = document.createElement("button");
            release.type = "button";
            release.className = "button button--warning distributed-draw__release";
            release.dataset.action = "release";
            release.textContent = window.I18N.distributedRelease;
            item.append(release);

        }

        slotsElement.append(item);

    });

}

function renderHost(state) {

    activePanel.hidden = false;
    setMessage(state.status === "ended" ? window.I18N.distributedEnded : "");
    lookupOneCached("#distributed-draw-progress").textContent = (
        window.I18N.distributedProgress
            .replace("{completed}", state.progress.completed)
            .replace("{total}", state.progress.total)
    );

    const expiry = lookupOneCached("#distributed-draw-expiry");
    expiry.dateTime = state.expiresAt;
    expiry.textContent = new Date(state.expiresAt).toLocaleString();

    const anchor = lookupOneCached("#distributed-draw-link");
    anchor.href = hostSession.joinUrl;
    lookupOneCached("#distributed-draw-link-text").textContent = hostSession.joinUrl;
    empty(lookupOneCached("#distributed-draw-qr")).append(QRCode({
        msg: hostSession.joinUrl,
        ecl: "L"
    }));

    lookupOneCached("#distributed-draw-end").hidden = state.status !== "active";
    drawSlots(state);
    reconcileGrimoire(state);

}

function connectEvents() {

    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }

    if (!hostSession?.topic) {
        return;
    }

    const url = new URL(MERCURE_URL, window.location.origin);
    url.searchParams.append("topic", hostSession.topic);
    eventSource = new EventSource(url);
    eventSource.addEventListener("message", () => refreshHost());
    eventSource.addEventListener("open", () => refreshHost());

}

async function refreshHost() {

    if (!hostSession) {
        return null;
    }

    if (refreshPromise) {
        return refreshPromise;
    }

    const publicId = hostSession.publicId;
    refreshPromise = request(hostUrl(), {
        headers: hostHeaders()
    }).then((state) => {

        if (hostSession?.publicId === publicId) {
            renderHost(state);
        }

        return state;

    }).catch((error) => {

        if (error.status === 410) {
            storeHostSession(null);
            activePanel.hidden = true;
        }

        setMessage(error.message, true);
        return null;

    }).finally(() => {
        refreshPromise = null;
    });

    return refreshPromise;

}

async function clearDistributedRoom() {

    if (!hostSession) {
        return;
    }

    const session = hostSession;
    const endUrl = (
        `${window.DRAW_SESSION_CONFIG.createUrl}`
        + `/${session.publicId}/host/end`
    );

    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }

    storeHostSession(null);
    activePanel.hidden = true;

    try {

        await request(endUrl, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${session.hostSecret}`
            }
        });

    } catch (ignore) {
        // Clearing local state must still succeed if the room is already
        // ended, expired, or temporarily unreachable.
    }

}

gameObserver.on("distributed-draw-clear", ({ detail }) => {
    detail.waitUntil(clearDistributedRoom());
});

async function endExistingRoom() {

    if (!hostSession) {
        return true;
    }

    const state = await refreshHost();

    if (!state || state.status !== "active") {
        return true;
    }

    if (!window.confirm(window.I18N.distributedReplaceConfirm)) {
        return false;
    }

    await request(hostUrl("/end"), {
        method: "POST",
        headers: hostHeaders()
    });

    return true;

}

gameObserver.on("distributed-draw-request", async ({ detail }) => {

    try {

        if (!await endExistingRoom()) {
            return;
        }

        hostDialog.show();
        activePanel.hidden = true;
        setMessage(window.I18N.distributedCreating);

        const response = await request(window.DRAW_SESSION_CONFIG.createUrl, {
            method: "POST",
            body: JSON.stringify({
                _token: window.DRAW_SESSION_CONFIG.csrfToken,
                characters: detail.characters.map((character) => (
                    character.getAllData()
                )),
                impDrawOrder: detail.impDrawOrder
            })
        });

        storeHostSession({
            publicId: response.publicId,
            hostSecret: response.hostSecret,
            joinUrl: response.joinUrl,
            expiresAt: response.expiresAt,
            topic: response.topic
        });
        renderHost(response.hostState);
        connectEvents();

    } catch (error) {
        setMessage(error.message, true);
    }

});

slotsElement.addEventListener("submit", async (event) => {

    const form = event.target.closest("[data-action=\"rename\"]");

    if (!form) {
        return;
    }

    event.preventDefault();
    const number = form.closest("[data-number]").dataset.number;

    try {

        const state = await request(hostUrl(`/slots/${number}`), {
            method: "PATCH",
            headers: hostHeaders(),
            body: JSON.stringify({
                name: new FormData(form).get("name")
            })
        });
        renderHost(state);

    } catch (error) {
        setMessage(error.message, true);
    }

});

slotsElement.addEventListener("click", async ({ target }) => {

    const button = target.closest("[data-action=\"release\"]");

    if (!button || !window.confirm(window.I18N.distributedReleaseConfirm)) {
        return;
    }

    const number = button.closest("[data-number]").dataset.number;

    try {

        const state = await request(hostUrl(`/slots/${number}/release`), {
            method: "POST",
            headers: hostHeaders()
        });
        renderHost(state);

    } catch (error) {
        setMessage(error.message, true);
    }

});

lookupOneCached("#distributed-draw-end").addEventListener("click", async () => {

    if (!window.confirm(window.I18N.distributedEndConfirm)) {
        return;
    }

    try {

        const state = await request(hostUrl("/end"), {
            method: "POST",
            headers: hostHeaders()
        });
        renderHost(state);

    } catch (error) {
        setMessage(error.message, true);
    }

});

openButton.addEventListener("click", () => {
    hostDialog.show();
    refreshHost();
});

document.addEventListener("visibilitychange", () => {

    if (!document.hidden) {
        refreshHost();
    }

});
window.addEventListener("online", () => refreshHost());
window.setInterval(() => refreshHost(), 30000);

if (hostSession) {
    openButton.disabled = false;
    refreshHost();
    connectEvents();
}
