let selectedSeats = [];

const pickup = document.getElementById("pickup");
const destination = document.getElementById("destination");

/* SELECT SEATS */
document.querySelectorAll(".seat").forEach(seat => {

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

/* PASSENGER TYPES */
function renderPassengerTypes() {

    let html = "";

    selectedSeats.forEach(seat => {

        html += `
        <div class="passenger-row">
            <div>Seat ${seat}</div>
            <select class="ptype">
                <option value="regular">Regular</option>
                <option value="student">Student</option>
                <option value="senior">Senior</option>
                <option value="pwd">PWD</option>
            </select>
        </div>
        `;

    });

    document.getElementById("passengerTypes").innerHTML = html;

}

/* CALCULATE */
document.getElementById("calculateBtn")
.addEventListener("click", () => {

    const pickupFare = fareTable[pickup.value];
    const destinationFare = fareTable[destination.value];

    let farePerSeat = destinationFare - pickupFare;
    if (farePerSeat < 0) farePerSeat = 0;

    let reservationFee = selectedSeats.length * 10;
    let total = 0;
    let html = "";

    document.querySelectorAll(".ptype").forEach((select, index) => {

        let type = select.value;
        let baseFare = farePerSeat;
        let discount = 0;

        if (type === "student" || type === "senior" || type === "pwd") {
            discount = baseFare * 0.20;
        }

        let finalFare = baseFare - discount;
        total += finalFare;

        html += `
        <p>
            Seat ${selectedSeats[index]} (${type.toUpperCase()})<br>
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

});

/* RESERVE FUNCTION */
async function reserveSeats() {

    const formData = new FormData();

    formData.append("confirm_reserve", "1");
    formData.append("pickup", pickup.value);
    formData.append("destination", destination.value);
    formData.append("seats", selectedSeats.join(","));

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

    const success = await reserveSeats();

    if (success) {

        showSuccess("Redirecting to My Reservations...");

        setTimeout(() => {
            window.location = "my_reservations.php";
        }, 1000);

    } else {

        showError("Unable to reserve seats.");

    }

});

/* PAY */
document.getElementById("payBtn")
.addEventListener("click", async () => {

    if (selectedSeats.length === 0) {
        showError("Please select your seat.");
        return;
    }

    const success = await reserveSeats();

    if (success) {

        window.location =
            "payment.php?schedule_id=" + scheduleId +
            "&total=" +
            document.getElementById("totalAmount").innerText;

    } else {

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