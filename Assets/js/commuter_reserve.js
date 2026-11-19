let selectedSeats = [];
/* every rider (seat) has their own pickup and destination */
const seatPickups = {};
const seatDestinations = {};
let reservationMode = "solo";
let reviewed = false;

function updateMultiSeatWarning() {
    const warning = document.getElementById("multiSeatWarning");
    warning.hidden = reservationMode !== "multi" || selectedSeats.length >= 2;
}

function hideReview() {
    reviewed = false;
    document.getElementById("summaryPanel").hidden = true;
    document.getElementById("fareBreakdown").style.display = "none";
}

function setReservationMode(mode) {
    reservationMode = mode === "multi" ? "multi" : "solo";
    document.querySelectorAll(".reservation-mode-tab").forEach(tab => {
        const active = tab.dataset.mode === reservationMode;
        tab.classList.toggle("active", active);
        tab.setAttribute("aria-selected", String(active));
    });
    const multiTab = document.getElementById("multiModeTab");
    multiTab.textContent = reservationMode === "multi" ? "Multi reservation selected" : "Multi reservation";
    document.getElementById("reservationModeHelp").textContent = reservationMode === "multi"
        ? "Select at least two seats. Each rider sets their own pickup and destination."
        : "Choose one seat, then set its pickup and destination.";
    document.querySelectorAll(".seat-btn.selected").forEach(seat => seat.classList.remove("selected"));
    selectedSeats = [];
    clearSeatTrips();
    hideReview();
    updateMultiSeatWarning();
    renderSeatTripControls();
}

document.querySelectorAll(".reservation-mode-tab").forEach(tab => {
    tab.addEventListener("click", () => setReservationMode(tab.dataset.mode));
});

/* PICKUP + DESTINATION, one pair of dropdowns per seat.
   The last stop can only be a destination, and a destination must come after that seat's pickup. */
function clearSeatTrips() {
    Object.keys(seatPickups).forEach(seat => delete seatPickups[seat]);
    Object.keys(seatDestinations).forEach(seat => delete seatDestinations[seat]);
}

function fillStops(select, stops, placeholder, selected) {
    select.innerHTML = "";
    const first = document.createElement("option");
    first.value = "";
    first.textContent = placeholder;
    select.appendChild(first);
    stops.forEach(stop => {
        const option = document.createElement("option");
        option.value = option.textContent = stop;
        select.appendChild(option);
    });
    select.value = stops.indexOf(selected) === -1 ? "" : selected;
}

function destinationStops(pickupStop) {
    const index = route.indexOf(pickupStop);
    return index === -1 ? [] : route.slice(index + 1);
}

function seatTripField(labelText, select) {
    const field = document.createElement("label");
    field.className = "seat-trip-field";
    const text = document.createElement("span");
    text.textContent = labelText;
    field.appendChild(text);
    field.appendChild(select);
    return field;
}

function renderSeatTripControls() {
    const box = document.getElementById("seatTrips");
    box.innerHTML = "";
    document.getElementById("seatTripsEmpty").hidden = selectedSeats.length > 0;

    selectedSeats.forEach(seat => {
        if (!(seat in seatPickups)) seatPickups[seat] = fixedPickup;

        const card = document.createElement("div");
        card.className = "seat-trip";
        card.setAttribute("role", "group");
        card.setAttribute("aria-label", "Pickup and destination for seat " + seat);

        const head = document.createElement("div");
        head.className = "seat-trip-head";
        const badge = document.createElement("span");
        badge.className = "seat-trip-badge";
        badge.textContent = seat;
        const name = document.createElement("strong");
        name.textContent = "Seat " + seat;
        head.appendChild(badge);
        head.appendChild(name);

        const pickupSelect = document.createElement("select");
        pickupSelect.id = "pickup_" + seat;
        pickupSelect.className = "form-control";
        fillStops(pickupSelect, route.slice(0, -1), "Select pickup", seatPickups[seat]);

        const destinationSelect = document.createElement("select");
        destinationSelect.id = "destination_" + seat;
        destinationSelect.className = "form-control";
        fillStops(destinationSelect, destinationStops(seatPickups[seat]), "Select destination", seatDestinations[seat]);
        if (!destinationSelect.value) delete seatDestinations[seat];

        pickupSelect.addEventListener("change", () => {
            seatPickups[seat] = pickupSelect.value;
            fillStops(destinationSelect, destinationStops(pickupSelect.value), "Select destination", seatDestinations[seat]);
            if (destinationSelect.value) seatDestinations[seat] = destinationSelect.value;
            else delete seatDestinations[seat];
            hideReview();
        });
        destinationSelect.addEventListener("change", () => {
            if (destinationSelect.value) seatDestinations[seat] = destinationSelect.value;
            else delete seatDestinations[seat];
            hideReview();
        });

        const fields = document.createElement("div");
        fields.className = "seat-trip-fields";
        fields.appendChild(seatTripField("Pickup", pickupSelect));
        fields.appendChild(seatTripField("Destination", destinationSelect));

        card.appendChild(head);
        card.appendChild(fields);
        box.appendChild(card);
    });
}

/* SELECT SEATS */
document.querySelectorAll(".seat-btn").forEach(seat => {

    seat.addEventListener("click", function () {

        if (this.classList.contains("reserved")) return;

        const seatNumber = this.dataset.seat;

        hideReview();

        if (reservationMode === "solo" && !this.classList.contains("selected")) {
            document.querySelectorAll(".seat-btn.selected").forEach(other => other.classList.remove("selected"));
            selectedSeats = [];
            clearSeatTrips();
        }

        if (this.classList.contains("selected")) {

            this.classList.remove("selected");

            selectedSeats = selectedSeats.filter(
                s => s != seatNumber
            );
            delete seatPickups[seatNumber];
            delete seatDestinations[seatNumber];

        } else {

            this.classList.add("selected");
            selectedSeats.push(seatNumber);

        }

        renderSeatTripControls();
        updateMultiSeatWarning();

    });

});

renderSeatTripControls();

/* The first selected seat that is missing a valid pickup/destination, or null when all are set. */
function firstIncompleteSeat() {
    for (const seat of selectedSeats) {
        const pickupIndex = route.indexOf(seatPickups[seat]);
        if (pickupIndex === -1 || pickupIndex >= route.length - 1) return { seat: seat, field: "pickup" };
        const destinationIndex = route.indexOf(seatDestinations[seat]);
        if (destinationIndex === -1 || destinationIndex <= pickupIndex) return { seat: seat, field: "destination" };
    }
    return null;
}

/* Tell the rider which seat still needs what, and jump to that dropdown. */
function tripError(missing, show) {
    show(missing.field === "pickup"
        ? "Choose a pickup for seat " + missing.seat + "."
        : "Choose a destination for seat " + missing.seat + ".");
    const field = document.getElementById(missing.field + "_" + missing.seat);
    if (field) { field.scrollIntoView({ behavior: "smooth", block: "center" }); field.focus(); }
}

/* DONE */
document.getElementById("doneBtn")
.addEventListener("click", () => {

    hideReview();

    if (selectedSeats.length === 0) {
        showTopError("Please select your seat.");
        return;
    }

    if (reservationMode === "solo" && selectedSeats.length !== 1) {
        showTopError("Solo reservation allows exactly one seat.");
        return;
    }
    if (reservationMode === "multi" && selectedSeats.length < 2) {
        updateMultiSeatWarning();
        showTopError("Multi reservation requires at least two seats.");
        return;
    }

    const missing = firstIncompleteSeat();
    if (missing) {
        tripError(missing, showTopError);
        return;
    }

    reviewed = true;
    document.getElementById("summaryPanel").hidden = false;

    document.getElementById("summarySeats").innerText =
        selectedSeats.join(", ");

    /* one rider: show their stops here. Several riders: each seat's own stops are listed
       once, next to its passenger type below */
    const manyRiders = selectedSeats.length > 1;
    document.getElementById("summaryPanel").classList.toggle("multi-dest", manyRiders);
    document.getElementById("summaryPickup").innerText = manyRiders ? "" : seatPickups[selectedSeats[0]];
    document.getElementById("summaryDestination").textContent = manyRiders ? "" : seatDestinations[selectedSeats[0]];

    renderPassengerTypes();

});

/* PASSENGER TYPES: each seat can be Regular, Student, Senior or PWD.
   The verified account holder's own (first) seat needs no ID; every other discounted seat needs an ID photo. */
const seatTypes = {};
const seatIds = {};

function seatType(seat, i) {
    if (i === 0) return passengerType;
    if (!seatTypes[seat]) seatTypes[seat] = "Regular";
    return seatTypes[seat];
}

function seatCovered(seat, i) {
    return i === 0;
}

function renderPassengerTypes() {

    const box = document.getElementById("passengerTypes");
    box.innerHTML = "";

    selectedSeats.forEach((seat, i) => {
        const type = seatType(seat, i);
        const needsId = i > 0 && type !== "Regular";

        const row = document.createElement("div");
        row.className = "passenger-row pt-row";

        const label = document.createElement("div");
        label.className = "pt-label";
        const strong = document.createElement("strong");
        strong.textContent = "Seat " + seat;
        label.appendChild(strong);
        if (selectedSeats.length > 1) {
            const stop = document.createElement("small");
            stop.textContent = seatPickups[seat] + " → " + seatDestinations[seat];
            label.appendChild(stop);
        }

        const select = document.createElement("select");
        select.className = "pt-select";
        select.setAttribute("aria-label", "Passenger type for seat " + seat);
        if (i === 0) { select.disabled = true; select.title = "Your own seat follows your account status"; }
        ["Regular", "Student", "Senior", "PWD"].forEach(t => {
            const o = document.createElement("option");
            o.value = o.textContent = t;
            if (t === type) o.selected = true;
            select.appendChild(o);
        });
        select.addEventListener("change", () => {
            seatTypes[seat] = select.value;
            renderPassengerTypes();
            if (document.getElementById("fareBreakdown").style.display !== "none") calculateFare();
        });

        row.appendChild(label);
        row.appendChild(select);
        box.appendChild(row);

        if (needsId) {
            const up = document.createElement("label");
            up.className = "pt-upload";
            const img = document.createElement("img");
            img.alt = "ID preview";
            img.hidden = !seatIds[seat];
            if (seatIds[seat]) img.src = seatIds[seat];
            const text = document.createElement("span");
            text.textContent = seatIds[seat] ? "ID attached · tap to replace" : "Upload ID photo";
            const file = document.createElement("input");
            file.type = "file"; file.accept = "image/*"; file.hidden = true;
            file.addEventListener("change", () => readIdPhoto(file.files && file.files[0], seat, img, text));
            up.appendChild(img); up.appendChild(text); up.appendChild(file);
            box.appendChild(up);
        } else {
            delete seatIds[seat];
        }
    });

    if (passengerType === "Regular") {
        const a = document.createElement("a");
        a.className = "verify-hint";
        a.href = "verification.php";
        a.textContent = "Student, senior or PWD? Verify your account for 20% off your own seat";
        box.appendChild(a);
    }
}

/* Resize the ID photo in the browser (max 1000px JPEG) so it uploads quickly. */
function readIdPhoto(file, seat, img, text) {
    if (!file) return;
    const url = URL.createObjectURL(file), image = new Image();
    image.onload = () => {
        const k = Math.min(1, 1000 / Math.max(image.width, image.height));
        const c = document.createElement("canvas");
        c.width = Math.round(image.width * k); c.height = Math.round(image.height * k);
        c.getContext("2d").drawImage(image, 0, 0, c.width, c.height);
        seatIds[seat] = c.toDataURL("image/jpeg", 0.8);
        img.src = seatIds[seat]; img.hidden = false;
        text.textContent = "ID attached · tap to replace";
        URL.revokeObjectURL(url);
    };
    image.onerror = () => { text.textContent = "Could not read that photo"; URL.revokeObjectURL(url); };
    image.src = url;
}

/* CALCULATE */
document.getElementById("calculateBtn")
.addEventListener("click", () => {
    if (!reviewed) {
        showError("Click Review my trip before calculating the fare.");
        return;
    }
    calculateFare();
});

function calculateFare() {

    if (!reviewed) return;

    let reservationFee = selectedSeats.length * 10;
    let total = 0;
    let html = "";

    selectedSeats.forEach((seat, index) => {

        let type = seatType(seat, index);
        const seatPickup = seatPickups[seat];
        const seatDestination = seatDestinations[seat];
        const pickupFare = fareTable[seatPickup];
        const destinationFare = fareTable[seatDestination];
        let farePerSeat = Math.max(14, Math.abs(destinationFare - pickupFare));
        if (seatPickup === seatDestination || isNaN(farePerSeat)) farePerSeat = 0;
        let baseFare = farePerSeat;
        let discount = 0;

        if (type !== "Regular") {
            discount = baseFare * 0.20;
        }

        let finalFare = baseFare - discount;
        total += finalFare;

        html += `
        <p>
            Seat ${seat}: ${seatPickup} → ${seatDestination} (${type})<br>
            Base Fare: ₱${baseFare.toFixed(2)}<br>
            Discount: ₱${discount.toFixed(2)}<br>
            Final Fare: ₱${finalFare.toFixed(2)}
        </p>
        <hr>
        `;

    });

    total += reservationFee;

    html += `
    <p>Reservation Fee: ₱${reservationFee.toFixed(2)}</p>
    `;

    document.getElementById("fareDetails").innerHTML = html;
    document.getElementById("totalAmount").innerText = total.toFixed(2);
    document.getElementById("fareBreakdown").style.display = "block";

}

/* RESERVE FUNCTION */
async function reserveSeats() {

    const formData = new FormData();

    formData.append("confirm_reserve", "1");
    formData.append("csrf_token", csrfToken);
    formData.append("reviewed", "1");
    formData.append("reservation_mode", reservationMode);
    formData.append("pickups", JSON.stringify(seatPickups));
    formData.append("destinations", JSON.stringify(seatDestinations));
    formData.append("seats", selectedSeats.join(","));
    const types = {}, ids = {};
    selectedSeats.forEach((seat, i) => {
        types[seat] = seatType(seat, i);
        if (types[seat] !== "Regular" && !seatCovered(seat, i)) ids[seat] = seatIds[seat];
    });
    formData.append("types", JSON.stringify(types));
    formData.append("ids", JSON.stringify(ids));

    try {
        const response = await fetch(
            "reserve.php?schedule_id=" + scheduleId,
            {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            }
        );
        const text = (await response.text()).trim();
        if (response.ok && text === "ok") return { ok: true };

        let message = text;
        if (response.redirected || /<html|<!doctype/i.test(text)) {
            message = "Your session expired. Please log in again, then reserve your seats.";
        } else if (response.status === 403) {
            message = "Your page is out of date. Reload the page and try again.";
        } else if (message === "") {
            message = "Unable to reserve seats. Please try again.";
        }
        return { ok: false, status: response.status, message: message };
    } catch (err) {
        return { ok: false, message: "No connection. Check your internet and try again." };
    }

}

/* RESERVE BUTTON */
document.getElementById("reserveBtn")
.addEventListener("click", async () => {

    if (!reviewed) {
        showError("Click Review my trip before paying.");
        return;
    }

    if (selectedSeats.length === 0) {
        showError("Please select your seat.");
        return;
    }

    const missing = firstIncompleteSeat();
    if (missing) {
        tripError(missing, showError);
        return;
    }

    for (let i = 0; i < selectedSeats.length; i++) {
        const seat = selectedSeats[i];
        if (seatType(seat, i) !== "Regular" && !seatCovered(seat, i) && !seatIds[seat]) {
            showError("Seat " + seat + " is a " + seatType(seat, i) + " ticket: upload their ID photo.");
            return;
        }
    }

    const btn = document.getElementById("reserveBtn");
    setBusy(btn, true, "Reserving");
    window.__mbusReserving = true;
    const result = await reserveSeats();
    if (!result.ok) window.__mbusReserving = false;

    if (result.ok) {

        window.location =
            "payment.php?schedule_id=" + scheduleId +
            "&total=" +
            document.getElementById("totalAmount").innerText;

    } else {

        setBusy(btn, false);
        showError(result.message || "Unable to reserve seats.");

    }

});

/* SUCCESS */
function showSuccess(message) {

    const box = document.getElementById("successBox");

    document.getElementById("summaryPanel").classList.add("show");

    box.style.display = "block";
    box.innerText = message;

    setTimeout(() => {
        box.style.display = "none";
    }, 3000);

}

/* ERROR */
function showError(message) {

    document.getElementById("summaryPanel").classList.add("show");

    const box = document.getElementById("errorBox");

    box.style.display = "block";
    box.innerText = message;
    box.scrollIntoView({ behavior: "smooth", block: "center" });

    clearTimeout(box._hideTimer);
    box._hideTimer = setTimeout(() => {
        box.style.display = "none";
    }, 8000);

}

/* TOP ERROR */
function showTopError(message) {

    const oldBox = document.getElementById("topErrorBox");
    if (oldBox) oldBox.remove();

    const errorBox = document.createElement("div");

    errorBox.id = "topErrorBox";
    errorBox.style.background = "#f8d7da";
    errorBox.style.color = "#721c24";
    errorBox.style.padding = "12px";
    errorBox.style.borderRadius = "8px";
    errorBox.style.marginBottom = "15px";
    errorBox.style.fontWeight = "bold";
    errorBox.style.fontSize = "14px";

    errorBox.innerText = message;
    errorBox.setAttribute("role", "alert");

    /* fixed to the screen so the rider sees it wherever they have scrolled (the page has no .container wrapper) */
    errorBox.style.position = "fixed";
    errorBox.style.top = "72px";
    errorBox.style.left = "50%";
    errorBox.style.transform = "translateX(-50%)";
    errorBox.style.zIndex = "999";
    errorBox.style.width = "min(320px, calc(100vw - 32px))";
    errorBox.style.boxSizing = "border-box";
    errorBox.style.textAlign = "center";

    document.body.appendChild(errorBox);

    setTimeout(() => {
        const currentBox = document.getElementById("topErrorBox");
        if (currentBox) currentBox.remove();
    }, 3000);

}

/* LIVE SEAT LOCKS: other people's reservations lock a seat for 10 minutes (or for good once paid);
   unpaid seats free up again. The map refreshes on its own. */
(function () {
    const counter = document.querySelector(".seat-counter");
    let warned = false;

    function apply(data) {
        const taken = new Set((data.taken || []).map(String));
        const mine = {};
        (data.mine || []).forEach(m => { mine[String(m.seat)] = m.status; });
        let lost = [];

        document.querySelectorAll(".seat-btn").forEach(btn => {
            const seat = btn.dataset.seat;
            const isTaken = taken.has(seat);
            // Your own just-made hold counts as taken on the server; that is not "someone else".
            if (isTaken && !mine[seat] && btn.classList.contains("selected")) {
                btn.classList.remove("selected");
                selectedSeats = selectedSeats.filter(s => s != seat);
                lost.push(seat);
            }
            btn.classList.toggle("reserved", isTaken);
            btn.disabled = isTaken;
            btn.setAttribute("aria-disabled", isTaken ? "true" : "false");
            const status = mine[seat];
            btn.classList.toggle("mine", !!status && status !== "Pending");
            btn.title = status ? (status === "Pending" ? "Your seat, waiting for payment" : "Your seat, paid") : (isTaken ? "Taken" : "");
        });

        if (counter && typeof data.available === "number") counter.textContent = data.available + " seats left";
        if (lost.length) {
            showError("Seat " + lost.join(", ") + " was just taken by someone else. Please pick another.");
            if (typeof renderPassengerTypes === "function") renderPassengerTypes();
        }
    }

    async function poll() {
        if (document.hidden || window.__mbusReserving) return;
        try {
            const r = await fetch("../api/get_seat_availability.php?schedule_id=" + scheduleId, { cache: "no-store" });
            if (r.ok) apply(await r.json());
        } catch (e) { /* offline: keep the current map */ }
    }

    poll();
    setInterval(poll, 8000);
    document.addEventListener("visibilitychange", () => { if (!document.hidden) poll(); });
})();
