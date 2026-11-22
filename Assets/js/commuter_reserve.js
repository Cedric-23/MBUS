let selectedSeats = [];
/* every rider (seat) has their own pickup and destination */
const seatPickups = {};
const seatDestinations = {};
const seatPins = {};
/* ...and their own passenger classification (+ ID photo for discounted seats that are not the account holder's own).
   Declared up here because the seat cards below read them. */
const seatTypes = {};
const seatIds = {};
const PASSENGER_TYPE_OPTIONS = [
    ["Regular", "Regular (Standard)"],
    ["Student", "Student"],
    ["Senior", "Senior Citizen"],
    ["PWD", "PWD"]
];
const PASSENGER_TYPE_SHORT = { Regular: "Regular", Student: "Student", Senior: "Senior Citizen", PWD: "PWD" };
let reservationMode = "solo";
let reviewed = false;
let pinMode = "pickup";
let reservationMap = null;
let reservationPinLayer = null;

function renderSeatPins() {
    const select = document.getElementById("pinSeat"), status = document.getElementById("pinStatus");
    if (!select || !status) return;
    const previous = select.value;
    select.replaceChildren(...selectedSeats.map(seat => new Option("Seat " + seat, String(seat))));
    select.value = selectedSeats.some(seat => String(seat) === previous) ? previous : (selectedSeats.length ? String(selectedSeats[0]) : "");
    if (reservationPinLayer) reservationPinLayer.clearLayers();
    if (!selectedSeats.length) { status.textContent = "Select seats, then place optional pickup and drop-off pins."; return; }
    const seat = select.value, pins = seatPins[seat] || {};
    status.textContent = `Seat ${seat}: pickup ${pins.pickup ? "pinned" : "not set"}; drop-off ${pins.destination ? "pinned" : "not set"}.`;
    if (!reservationMap || !reservationPinLayer) return;
    selectedSeats.forEach(number => ["pickup", "destination"].forEach(kind => {
        const point = seatPins[number] && seatPins[number][kind];
        if (!point) return;
        const active = String(number) === seat;
        L.circleMarker([point.lat, point.lng], {radius:active ? 9 : 7, color:"#fff", weight:2, fillColor:kind === "pickup" ? "#16834a" : "#244a9b", fillOpacity:active ? 1 : .5})
            .addTo(reservationPinLayer).bindTooltip(`Seat ${number} · ${kind === "pickup" ? "Pickup" : "Drop-off"}`);
    }));
}

function initReservationMap() {
    const host = document.getElementById("reservationMap");
    if (!host) return;
    if (!window.L) { document.getElementById("pinStatus").textContent = "Map unavailable; named stops can still be used."; return; }
    const bounds = routeBounds.map(Number), [oLat,oLng,dLat,dLng] = bounds;
    const center = bounds.every(Number.isFinite) ? [(oLat+dLat)/2, (oLng+dLng)/2] : [14.75,120.27];
    reservationMap = L.map(host, {scrollWheelZoom:false}).setView(center, 12);
    L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", {maxZoom:19, attribution:"&copy; OpenStreetMap contributors"}).addTo(reservationMap);
    if (bounds.every(Number.isFinite)) reservationMap.fitBounds([[oLat,oLng],[dLat,dLng]], {padding:[20,20], maxZoom:13});
    reservationPinLayer = L.layerGroup().addTo(reservationMap);
    reservationMap.on("click", event => {
        const seat = document.getElementById("pinSeat").value;
        if (!seat || !selectedSeats.some(number => String(number) === seat)) { document.getElementById("pinStatus").textContent = "Select a seat before placing a pin."; return; }
        seatPins[seat] = seatPins[seat] || {};
        seatPins[seat][pinMode] = {lat:event.latlng.lat, lng:event.latlng.lng};
        hideReview(); renderSeatPins();
    });
    document.getElementById("pinSeat").addEventListener("change", renderSeatPins);
    document.querySelectorAll("[data-pin-mode]").forEach(button => button.addEventListener("click", () => {
        pinMode = button.dataset.pinMode;
        document.querySelectorAll("[data-pin-mode]").forEach(other => other.classList.toggle("active", other === button));
    }));
    document.querySelector(".reserve-pin-clear").addEventListener("click", () => {
        const seat = document.getElementById("pinSeat").value;
        if (seat) delete seatPins[seat];
        hideReview(); renderSeatPins();
    });
    renderSeatPins();
}

/* Styles for the read-only rider list in the Reservation Summary (MBUS-48) */
(function addSummaryStyles() {
    const style = document.createElement("style");
    style.textContent = `
.sum-seats{display:grid;gap:10px;margin:12px 0}
.sum-seat{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 12px;border:0;border-radius:14px;background:#f4f8ff}
.sum-seat-badge{display:inline-grid;place-items:center;padding:4px 11px;border-radius:999px;background:#eaf1ff;color:#1f4fb8;font-weight:700;font-size:.8rem;white-space:nowrap}
.sum-seat-body{display:grid;gap:4px;min-width:0}
.sum-seat-line{display:grid;grid-template-columns:74px minmax(0,1fr);gap:8px;align-items:baseline;font-size:.88rem}
.sum-seat-line span{opacity:.7}
.sum-seat-line strong{overflow-wrap:anywhere}
.sum-seat-type{align-self:start;padding:4px 10px;border-radius:999px;background:rgba(47,111,228,.12);color:#2f6fe4;font-size:.78rem;font-weight:600;white-space:nowrap}
@media(max-width:420px){.sum-seat-body:empty{display:none}}
`;
    document.head.appendChild(style);
})();

function updateMultiSeatWarning() {
    const warning = document.getElementById("multiSeatWarning");
    if (warning) warning.hidden = reservationMode !== "multi" || selectedSeats.length >= 2;
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
        ? "Select at least two seats. Each rider sets their own pickup, destination and classification."
        : "Choose one seat, then set its pickup and destination.";
    document.querySelectorAll(".seat-btn.selected").forEach(seat => seat.classList.remove("selected"));
    selectedSeats = [];
    clearSeatTrips();
    hideReview();
    updateMultiSeatWarning();
    renderSeatTripControls();
    renderSeatPins();
}

document.querySelectorAll(".reservation-mode-tab").forEach(tab => {
    tab.addEventListener("click", () => setReservationMode(tab.dataset.mode));
});

/* PICKUP + DESTINATION + CLASSIFICATION, one set of fields per seat.
   The last stop can only be a destination, and a destination must come after that seat's pickup. */
function clearSeatTrips() {
    Object.keys(seatPickups).forEach(seat => delete seatPickups[seat]);
    Object.keys(seatDestinations).forEach(seat => delete seatDestinations[seat]);
    Object.keys(seatTypes).forEach(seat => delete seatTypes[seat]);
    Object.keys(seatIds).forEach(seat => delete seatIds[seat]);
    Object.keys(seatPins).forEach(seat => delete seatPins[seat]);
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

/* Passenger classification of a seat. The first selected seat is the account holder's own and follows the account status. */
function seatType(seat, i) {
    if (i === 0) return passengerType;
    if (!seatTypes[seat]) seatTypes[seat] = "Regular";
    return seatTypes[seat];
}

function seatCovered(seat, i) {
    return i === 0;
}

/* ID photo upload for a discounted seat that is not the account holder's own */
function idUploadField(seat) {
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
    return up;
}

function renderSeatTripControls() {
    const box = document.getElementById("seatTrips");
    box.innerHTML = "";
    document.getElementById("seatTripsEmpty").hidden = selectedSeats.length > 0;

    selectedSeats.forEach((seat, i) => {
        if (!(seat in seatPickups)) seatPickups[seat] = fixedPickup;

        const card = document.createElement("div");
        card.className = "seat-trip";
        card.setAttribute("role", "group");
        card.setAttribute("aria-label", "Pickup, destination and classification for seat " + seat);

        const head = document.createElement("div");
        head.className = "seat-trip-head";
        const badge = document.createElement("span");
        badge.className = "seat-trip-badge";
        badge.textContent = "Seat " + seat;
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

        /* passenger classification: Regular (Standard), Student, Senior Citizen, PWD */
        const type = seatType(seat, i);
        const typeSelect = document.createElement("select");
        typeSelect.id = "type_" + seat;
        typeSelect.className = "form-control";
        typeSelect.setAttribute("aria-label", "Passenger classification for seat " + seat);
        PASSENGER_TYPE_OPTIONS.forEach(pair => {
            const option = document.createElement("option");
            option.value = pair[0];
            option.textContent = pair[1];
            if (pair[0] === type) option.selected = true;
            typeSelect.appendChild(option);
        });
        if (i === 0) {
            typeSelect.disabled = true;
            typeSelect.title = "Your own seat follows your account status";
        }
        typeSelect.addEventListener("change", () => {
            seatTypes[seat] = typeSelect.value;
            if (typeSelect.value === "Regular") delete seatIds[seat];
            hideReview();
            renderSeatTripControls();
        });

        const fields = document.createElement("div");
        fields.className = "seat-trip-fields";
        fields.appendChild(seatTripField("Pickup", pickupSelect));
        fields.appendChild(seatTripField("Destination", destinationSelect));
        fields.appendChild(seatTripField("Passenger classification", typeSelect));

        card.appendChild(head);
        card.appendChild(fields);
        if (i > 0 && type !== "Regular") card.appendChild(idUploadField(seat));
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
            delete seatPins[seatNumber];
            delete seatTypes[seatNumber];
            delete seatIds[seatNumber];

        } else {

            this.classList.add("selected");
            selectedSeats.push(seatNumber);

        }

        renderSeatTripControls();
        renderSeatPins();
        updateMultiSeatWarning();

    });

});

renderSeatTripControls();
initReservationMap();

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
    const partialPin = selectedSeats.find(seat => Boolean(seatPins[seat]?.pickup) !== Boolean(seatPins[seat]?.destination));
    if (partialPin) {
        showTopError("For seat " + partialPin + ", set both map pins or clear the one already placed.");
        document.getElementById("reservationMap").scrollIntoView({behavior:"smooth",block:"center"});
        return;
    }

    reviewed = true;
    document.getElementById("summaryPanel").hidden = false;

    document.getElementById("summarySeats").innerText =
        selectedSeats.join(", ");

    /* one rider: show their stops here. Several riders: each seat's own stops are listed
       once, in the rider list below */
    const manyRiders = selectedSeats.length > 1;
    document.getElementById("summaryPanel").classList.toggle("multi-dest", manyRiders);
    document.getElementById("summaryPickup").innerText = manyRiders ? "" : seatPickups[selectedSeats[0]];
    document.getElementById("summaryDestination").textContent = manyRiders ? "" : seatDestinations[selectedSeats[0]];

    renderPassengerTypes();

});

/* RESERVATION SUMMARY RIDERS: read-only. Classification is chosen per seat in the trip configuration above,
   so the summary only shows it (MBUS-47) and lists each seat's pickup and destination in an aligned layout (MBUS-48). */
function summaryLine(label, value) {
    const line = document.createElement("div");
    line.className = "sum-seat-line";
    const l = document.createElement("span");
    l.textContent = label;
    const v = document.createElement("strong");
    v.textContent = value;
    line.appendChild(l);
    line.appendChild(v);
    return line;
}

function renderPassengerTypes() {

    const box = document.getElementById("passengerTypes");
    box.innerHTML = "";

    const manyRiders = selectedSeats.length > 1;
    const list = document.createElement("div");
    list.className = "sum-seats";

    selectedSeats.forEach((seat, i) => {
        const type = seatType(seat, i);
        /* one Regular rider: Seats/Pickup/Destination above already say everything */
        if (!manyRiders && type === "Regular") return;

        const row = document.createElement("div");
        row.className = "sum-seat";

        const badge = document.createElement("span");
        badge.className = "sum-seat-badge";
        badge.textContent = "Seat " + seat;

        const body = document.createElement("div");
        body.className = "sum-seat-body";
        if (manyRiders) {
            const route = document.createElement("strong");
            route.className = "sum-seat-route";
            route.textContent = seatPickups[seat] + " \u2192 " + seatDestinations[seat];
            body.appendChild(route);
        }

        const pill = document.createElement("span");
        pill.className = "sum-seat-type";
        pill.textContent = (PASSENGER_TYPE_SHORT[type] || type) + (i > 0 && type !== "Regular" && seatIds[seat] ? " · ID attached" : "");

        row.appendChild(badge);
        row.appendChild(body);
        row.appendChild(pill);
        list.appendChild(row);
    });

    if (list.children.length) box.appendChild(list);

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
    formData.append("stop_pins", JSON.stringify(seatPins));
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
            lost.forEach(seat => {
                delete seatPickups[seat];
                delete seatDestinations[seat];
                delete seatTypes[seat];
                delete seatIds[seat];
            });
            hideReview();
            renderSeatTripControls();
            updateMultiSeatWarning();
            showError("Seat " + lost.join(", ") + " was just taken by someone else. Please pick another.");
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
