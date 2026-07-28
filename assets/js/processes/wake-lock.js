import {
    lookupOneCached
} from "../utils/elements.js";

const toggle = lookupOneCached("#keep-screen-awake");
const status = lookupOneCached("#wake-lock-status");
const wakeLockApi = navigator.wakeLock;

let wakeLock = null;
let requestPending = false;

function setStatus(message) {

    status.textContent = message;
    status.hidden = !message;

}

function handleRelease(sentinel) {

    if (wakeLock === sentinel) {
        wakeLock = null;
    }

    if (!toggle.checked || document.visibilityState !== "visible") {
        setStatus("");
        return;
    }

    setStatus(status.dataset.error);

}

async function requestWakeLock() {

    if (
        !wakeLockApi
        || wakeLock
        || requestPending
        || !toggle.checked
        || document.visibilityState !== "visible"
    ) {
        return;
    }

    requestPending = true;

    try {

        const sentinel = await wakeLockApi.request("screen");

        sentinel.addEventListener("release", () => handleRelease(sentinel));

        if (
            !toggle.checked
            || document.visibilityState !== "visible"
            || sentinel.released
        ) {

            if (!sentinel.released) {
                await sentinel.release();
            }

            return;

        }

        wakeLock = sentinel;
        setStatus(status.dataset.active);

    } catch (error) {

        if (toggle.checked) {
            setStatus(status.dataset.error);
        }

    } finally {
        requestPending = false;
    }

}

async function releaseWakeLock() {

    const sentinel = wakeLock;

    wakeLock = null;
    setStatus("");

    if (sentinel && !sentinel.released) {

        try {
            await sentinel.release();
        } catch (error) {
            // The browser may already have released the wake lock.
        }

    }

}

if (!wakeLockApi || typeof wakeLockApi.request !== "function") {

    toggle.disabled = true;
    setStatus(status.dataset.unsupported);

} else {

    toggle.addEventListener("input", () => {

        if (toggle.checked) {
            requestWakeLock();
        } else {
            releaseWakeLock();
        }

    });

    document.addEventListener("visibilitychange", () => {

        if (
            toggle.checked
            && document.visibilityState === "visible"
            && !wakeLock
        ) {
            requestWakeLock();
        }

    });

}
