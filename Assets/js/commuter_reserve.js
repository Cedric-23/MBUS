let selectedSeats = [];

const pickup = document.getElementById("pickup");
const destination = document.getElementById("destination");

/* SELECT SEATS */
document.querySelectorAll(".seat-btn").forEach(seat => {

    seat.addEventListener("click", function () {

        if (this.classList.contains("reserved")) return;

        const seatNumber = this.dataset.seat;

        if (this.classList.contains("selected")) {

            this.classList.remove("selected");

            selectedSeats = selectedSeats.filter(
                s => s != seatNumber
            );

        } else {

            this.classList.add("selected");
            selectedSeats.push(seatNumber);

        }

    });

});

/* DESTINATION */
pickup.addEventListener("change", function () {

    destination.innerHTML = "<option value=''>Select Destination</option>";

    let index = route.indexOf(this.value);

    for (let i = index + 1; i < route.length; i++) {

        let option = document.createElement("option");

        option.value = route[i];
        option.text = route[i];

        destination.appendChild(option);

    }

    destination.disabled = !this.value;

});

/* DONE */
document.getElementById("doneBtn")
.addEventListener("click", () => {

    document.getElementById("summaryPanel").classList.remove("show");

    if (selectedSeats.length === 0) {
        showTopError("Please select your seat.");
        return;
    }

    if (!pickup.value) {
        showTopError("Select pickup location.");
        return;
    }

    if (!destination.value) {
        showTopError("Select destination.");
        return;
    }

    document.getElementById("summaryPanel").classList.add("show");

    document.getElementById("summarySeats").innerText =
        selectedSeats.join(", ");

    document.getElementById("summaryPickup").innerText =
        pickup.value;

    document.getElementById("summaryDestination").innerText =
        destination.value;

    renderPassengerTypes();

});

/* PASSENGER TYPES: each seat can be Regular, Student, Senior or PWD.
   The verified account holder's own (first) seat needs no ID; every other discounted seat needs an ID photo. */
const seatTypes = {};
const seatIds = {};

function seatType(seat, i) {
    if (!seatTypes[seat]) seatTypes[seat] = i === 0 ? passengerType : "Regular";
    return seatTypes[seat];
}

function seatCovered(seat, i) {
    return i === 0 && passengerType !== "Regular" && seatType(seat, i) === passengerType;
}

function renderPassengerTypes() {

    const box = document.getElementById("passengerTypes");
    box.innerHTML = "";

    selectedSeats.forEach((seat, i) => {
        const type = seatType(seat, i);
        const needsId = type !== "Regular" && !seatCovered(seat, i);

        const row = document.createElement("div");
        row.className = "passenger-row pt-row";

        const label = document.createElement("div");
        label.textContent = "Seat " + seat;

        const select = document.createElement("select");
        select.className = "pt-select";
        select.setAttribute("aria-label", "Passenger type for seat " + seat);
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
        a.textContent = "Verified student, senior or PWD? Verify your own ID for 20% off";
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
.addEventListener("click", () => calculateFare());

function calculateFare() {

    const pickupFare = fareTable[pickup.value];
    const destinationFare = fareTable[destination.value];

    let farePerSeat = Math.max(14, Math.abs(destinationFare - pickupFare));
    if (pickup.value === destination.value || isNaN(farePerSeat)) farePerSeat = 0;

    let reservationFee = selectedSeats.length * 10;
    let total = 0;
    let html = "";

    selectedSeats.forEach((seat, index) => {

        let type = seatType(seat, index);
        let baseFare = farePerSeat;
        let discount = 0;

        if (type !== "Regular") {
            discount = baseFare * 0.20;
        }

        let finalFare = baseFare - discount;
        total += finalFare;

        html += `
        <p>
            Seat ${seat} (${type})<br>
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
    document.getElementById("summaryPanel").classList.add("show");

}

/* RESERVE FUNCTION */
async function reserveSeats() {

    const formData = new FormData();

    formData.append("confirm_reserve", "1");
    formData.append("csrf_token", csrfToken);
    formData.append("pickup", pickup.value);
    formData.append("destination", destination.value);
    formData.append("seats", selectedSeats.join(","));
    const types = {}, ids = {};
    selectedSeats.forEach((seat, i) => {
        types[seat] = seatType(seat, i);
        if (types[seat] !== "Regular" && !seatCovered(seat, i)) ids[seat] = seatIds[seat];
    });
    formData.append("types", JSON.stringify(types));
    formData.append("ids", JSON.stringify(ids));

    const response = await fetch(
        "reserve.php?schedule_id=" + scheduleId,
        {
            method: "POST",
            body: formData
        }
    );

    const result = await response.text();
    return result.trim() == "ok";

}

/* RESERVE BUTTON */
document.getElementById("reserveBtn")
.addEventListener("click", async () => {

    if (selectedSeats.length === 0) {
        showError("Please select your seat.");
        return;
    }

    if (!pickup.value) {
        showError("Select pickup location.");
        return;
    }

    if (!destination.value) {
        showError("Select destination.");
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
    const success = await reserveSeats();

    if (success) {

        window.location =
            "payment.php?schedule_id=" + scheduleId +
            "&total=" +
            document.getElementById("totalAmount").innerText;

    } else {

        setBusy(btn, false);
        showError("Unable to reserve seats.");

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

    setTimeout(() => {
        box.style.display = "none";
    }, 3000);

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

    const container = document.querySelector(".container");

    errorBox.style.position = "absolute";
    errorBox.style.top = "58px";
    errorBox.style.left = "50%";
    errorBox.style.transform = "translateX(-50%)";
    errorBox.style.zIndex = "999";
    errorBox.style.width = "320px";
    errorBox.style.textAlign = "center";

    container.appendChild(errorBox);

    setTimeout(() => {
        const currentBox = document.getElementById("topErrorBox");
        if (currentBox) currentBox.remove();
    }, 3000);

}