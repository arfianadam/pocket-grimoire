import "regenerator-runtime/runtime";

const MERCURE_URL = "/.well-known/mercure";
const config = window.DRAW_PLAYER_CONFIG;
const secretKey = `pocket-grimoire:draw-claim:${config.publicId}`;
const picker = document.querySelector("#draw-picker");
const slots = document.querySelector("#draw-slots");
const claimPanel = document.querySelector("#draw-claim");
const message = document.querySelector("#draw-message");
const role = document.querySelector("#draw-role");
const roleImage = document.querySelector("#draw-role-image");
const roleName = document.querySelector("#draw-role-name");
const roleAbility = document.querySelector("#draw-role-ability");
const visibilityButton = document.querySelector("#draw-role-visibility");
const nameForm = document.querySelector("#draw-name-form");
const nameInput = document.querySelector("#draw-player-name");
let claimSecret = window.localStorage.getItem(secretKey);
let claimState = null;
let roleVisible = false;
let refreshPromise = null;
let eventSource = null;
let pickerNotice = "";

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

        const error = new Error(data.message || config.i18n.unknown);
        error.status = response.status;
        error.code = data.error;
        throw error;

    }

    return data;

}

function claimHeaders() {
    return {
        "Authorization": `Bearer ${claimSecret}`
    };
}

function setMessage(text, isError = false) {

    message.textContent = text;
    message.classList.toggle("distributed-draw__error", isError);

}

function setRoleVisibility(visible) {

    roleVisible = visible;
    role.classList.toggle("is-private", !visible);
    role.setAttribute("aria-hidden", String(!visible));
    visibilityButton.textContent = (
        visible
        ? config.i18n.hideRole
        : config.i18n.revealRole
    );

}

function renderPicker(state) {

    picker.hidden = false;
    claimPanel.hidden = true;
    slots.replaceChildren();

    state.slots.forEach((slot) => {

        const wrapper = document.createElement("div");
        const button = document.createElement("button");
        button.type = "button";
        button.className = "character-choice distributed-draw__number";
        button.dataset.number = slot.number;
        button.disabled = slot.state !== "available" || state.status !== "active";
        button.setAttribute("aria-label", String(slot.number));

        const token = document.createElement("span");
        token.className = "character";
        const number = document.createElement("span");
        number.className = "character__unknown";
        number.textContent = slot.number;
        token.append(number);
        button.append(token);
        wrapper.append(button);
        slots.append(wrapper);

    });

    setMessage(
        pickerNotice
        || (
            state.status === "ended"
            ? config.i18n.ended
            : config.i18n.choose
        ),
        Boolean(pickerNotice)
    );
    pickerNotice = "";

}

function renderClaim(claim, keepVisibility = true) {

    claimState = claim;
    picker.hidden = true;
    claimPanel.hidden = false;
    document.querySelector("#draw-claim-number").textContent = claim.number;
    roleName.textContent = claim.role.name;
    roleAbility.textContent = claim.role.ability;
    roleImage.src = claim.role.image || "";
    roleImage.alt = claim.role.name;
    roleImage.hidden = !claim.role.image;
    nameInput.value = claim.name || "";
    nameForm.hidden = (
        claim.state === "completed"
        || claim.sessionStatus !== "active"
    );

    if (claim.state === "completed") {
        setMessage(config.i18n.completed);
    } else if (claim.sessionStatus === "ended") {
        setMessage(config.i18n.ended);
    } else {
        setMessage(config.i18n.claiming);
    }

    setRoleVisibility(keepVisibility ? roleVisible : false);

}

function invalidateClaim(text = config.i18n.released) {

    window.localStorage.removeItem(secretKey);
    claimSecret = null;
    claimState = null;
    roleVisible = false;
    pickerNotice = text;
    setMessage(text, true);

}

async function refresh() {

    if (config.loadError || refreshPromise) {
        return refreshPromise;
    }

    refreshPromise = (async () => {

        if (claimSecret) {

            try {

                const claim = await request(config.claimUrl, {
                    headers: claimHeaders()
                });
                renderClaim(claim);
                return claim;

            } catch (error) {

                if (error.status === 403) {
                    invalidateClaim();
                } else if (error.status === 410) {
                    setMessage(config.i18n.expired, true);
                    return null;
                } else {
                    setMessage(error.message, true);
                    return null;
                }

            }

        }

        try {

            const state = await request(config.statusUrl);

            // A claim response may have completed while this public refresh was
            // in flight. Do not let the stale public snapshot hide that claim.
            if (claimSecret) {
                return state;
            }

            renderPicker(state);
            return state;

        } catch (error) {
            setMessage(
                error.status === 410 ? config.i18n.expired : error.message,
                true
            );
            picker.hidden = true;
            return null;
        }

    })().finally(() => {
        refreshPromise = null;
    });

    return refreshPromise;

}

slots.addEventListener("click", async ({ target }) => {

    const button = target.closest("[data-number]");

    if (!button || button.disabled) {
        return;
    }

    button.disabled = true;
    setMessage(config.i18n.claiming);

    try {

        const result = await request(config.claimsUrl, {
            method: "POST",
            body: JSON.stringify({
                number: Number(button.dataset.number)
            })
        });
        claimSecret = result.claimSecret;
        window.localStorage.setItem(secretKey, claimSecret);
        roleVisible = true;
        renderClaim(result.claim);

    } catch (error) {

        pickerNotice = (
            error.status === 409 ? config.i18n.unavailable : error.message
        );
        setMessage(pickerNotice, true);
        await refresh();

    }

});

nameForm.addEventListener("submit", async (event) => {

    event.preventDefault();
    const submit = nameForm.querySelector("[type=\"submit\"]");
    submit.disabled = true;

    try {

        const claim = await request(config.claimUrl, {
            method: "PATCH",
            headers: claimHeaders(),
            body: JSON.stringify({
                name: nameInput.value
            })
        });
        renderClaim(claim);

    } catch (error) {

        if (error.status === 403) {
            invalidateClaim();
            await refresh();
        } else {
            setMessage(error.message, true);
        }

    } finally {
        submit.disabled = false;
    }

});

visibilityButton.addEventListener("click", () => {
    setRoleVisibility(!roleVisible);
});

function connectEvents() {

    if (!config.topic || config.loadError) {
        return;
    }

    const url = new URL(MERCURE_URL, window.location.origin);
    url.searchParams.append("topic", config.topic);
    eventSource = new EventSource(url);
    eventSource.addEventListener("message", () => refresh());
    eventSource.addEventListener("open", () => refresh());

}

document.addEventListener("visibilitychange", () => {

    if (!document.hidden) {
        refresh();
    }

});
window.addEventListener("online", () => refresh());
window.setInterval(() => refresh(), 30000);

if (!config.loadError) {

    if (claimSecret) {
        refresh();
    } else {
        renderPicker(config.initialState);
    }

    connectEvents();
}
