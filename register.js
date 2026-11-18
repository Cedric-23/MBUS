const optionBar = document.getElementById("option-bar");
const optionPassenger = document.getElementById("option-passenger");
const optionConductor = document.getElementById("option-conductor");
const optionDriver = document.getElementById("option-driver");
const passengerForm = document.getElementById("passenger");
const driverForm = document.getElementById("driver");
const pickerText = document.getElementById("picker-text");
const userTypeInput = document.getElementById("user-type");
const passengerSubmit = document.getElementById("passenger-submit");
const registerForm = document.querySelector(".form-body");
const driverSteps = driverForm.querySelectorAll(".driver-input-group");
const stepBars = driverForm.querySelectorAll(".step-progress-bar");
const stepBack = document.getElementById("step-back");
const stepNext = document.getElementById("step-next");
const stepSubmit = document.getElementById("step-submit");
const lastStep = driverSteps.length - 1;
let currentStep = 0;

const pickerMessages = {
    passenger: "Book seats and keep all your trips in one place",
    conductor: "Register to validate tickets and assist passengers on board.",
    driver: "Register to manage assigned routes and passenger trips."
};

const submitMessages = {
    passenger: "Create passenger account",
    conductor: "Create conductor account"
};

function toggleFields(form, enabled) {
    // The fieldsets start disabled for the hidden tab; they must be switched too or every field inside stays locked.
    form.querySelectorAll("fieldset").forEach(function (fs) {
        fs.disabled = !enabled;
    });
    form.querySelectorAll("input, select, textarea").forEach(function (field) {
        field.disabled = !enabled;
    });
}

function setUserType(type) {
    const isDriver = type === "driver";
    const isConductor = type === "conductor";
    const isPassenger = type === "passenger";

    optionBar.classList.toggle("active", isDriver);
    optionBar.classList.toggle("conductor", isConductor);
    optionBar.classList.toggle("disabled", isPassenger);

    optionPassenger.classList.toggle("active", isPassenger);
    optionConductor.classList.toggle("active", isConductor);
    optionDriver.classList.toggle("active", isDriver);

    passengerForm.classList.toggle("active", !isDriver);
    driverForm.classList.toggle("active", isDriver);

    toggleFields(passengerForm, !isDriver);
    toggleFields(driverForm, isDriver);

    if (!isDriver) {
        passengerSubmit.textContent = submitMessages[type];
    }

    pickerText.textContent = pickerMessages[type];
    userTypeInput.value = type;
}

function setStep(index) {
    currentStep = index;

    driverSteps.forEach(function (step, i) {
        step.classList.toggle("active", i === index);
    });

    stepBars.forEach(function (bar, i) {
        bar.classList.toggle("active", i <= index);
    });

    stepBack.classList.toggle("active", index > 0);
    stepNext.classList.toggle("active", index < lastStep);
    stepSubmit.classList.toggle("active", index === lastStep);
}

function validateStep(index) {
    const fields = driverSteps[index].querySelectorAll("input, select");

    for (const field of fields) {
        if (!field.checkValidity()) {
            field.reportValidity();
            return false;
        }
    }

    return true;
}

function goNext() {
    if (currentStep < lastStep && validateStep(currentStep)) {
        setStep(currentStep + 1);
    }
}

stepNext.addEventListener("click", goNext);

stepBack.addEventListener("click", function () {
    if (currentStep > 0) {
        setStep(currentStep - 1);
    }
});

registerForm.addEventListener("submit", function (e) {
    if (userTypeInput.value === "driver" && currentStep < lastStep) {
        e.preventDefault();
        goNext();
    }
});

document.querySelectorAll(".upload-group").forEach(function (group) {
    const input = group.querySelector("input[type='file']");
    const browse = group.querySelector(".document-input-button button");
    const name = group.querySelector(".document-name");
    const preview = group.querySelector(".document-preview");
    const image = group.querySelector(".document-preview-image");
    const frame = group.querySelector(".document-preview-frame");
    const maxFileSize = Number(input.dataset.maxBytes || 3 * 1024 * 1024);
    const defaultName = name.textContent;
    let objectUrl = null;

    browse.addEventListener("click", function () {
        input.click();
    });

    input.addEventListener("change", function () {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        input.setCustomValidity("");
        image.removeAttribute("src");
        frame.removeAttribute("src");
        image.classList.remove("active");
        frame.classList.remove("active");
        preview.classList.remove("active");

        const file = input.files[0];

        if (!file) {
            name.textContent = defaultName;
            return;
        }

        if (file.size > maxFileSize) {
            input.value = "";
            name.textContent = defaultName;
            input.setCustomValidity(maxFileSize < 1024 * 1024
                ? `File must be ${Math.round(maxFileSize / 1024)} KB or less.`
                : `File must be ${Math.round(maxFileSize / (1024 * 1024))} MB or less.`);
            input.reportValidity();
            return;
        }

        const isPdf = file.type === "application/pdf";
        objectUrl = URL.createObjectURL(file);
        name.textContent = file.name;

        if (isPdf) {
            frame.src = objectUrl;
            frame.classList.add("active");
        } else {
            image.src = objectUrl;
            image.classList.add("active");
        }

        preview.classList.add("active");
    });
});

[optionPassenger, optionConductor, optionDriver].forEach(function (option) {
    option.addEventListener("click", function (e) {
        e.preventDefault();
        const type = option.querySelector("a").dataset.type;
        setUserType(type);
        history.replaceState(null, "", "register.php?type=" + type);
    });
});

setUserType(userTypeInput.value);
setStep(window.MBUS_START_STEP || 0);

document.querySelectorAll("input[inputmode='numeric']").forEach(function (el) {
    el.addEventListener("input", function () { var d = el.value.replace(/\D/g, "");
        if (/^63/.test(d) && d.length > 10) d = "0" + d.slice(2);
        el.value = d.slice(0, 11); });
});

/* live password checklist under every "create password" field (matches the reset password page) */
(function () {
    var rules = [
        ["At least 8 characters", function (v) { return v.length >= 8; }],
        ["One uppercase letter", function (v) { return /[A-Z]/.test(v); }],
        ["One lowercase letter", function (v) { return /[a-z]/.test(v); }],
        ["One number", function (v) { return /\d/.test(v); }],
        ["One symbol", function (v) { return /[^A-Za-z0-9]/.test(v); }]
    ];
    document.querySelectorAll(".password-input input[type='password'], .password-input .pw-wrap input").forEach(function (input) {
        if (input.dataset.pwRules) return;
        input.dataset.pwRules = "1";
        var ul = document.createElement("ul");
        ul.className = "password-requirements";
        var items = rules.map(function (r) { var li = document.createElement("li"); li.textContent = r[0]; ul.appendChild(li); return li; });
        input.closest(".password-input").appendChild(ul);
        function update() { items.forEach(function (li, i) { li.classList.toggle("met", rules[i][1](input.value)); }); }
        input.addEventListener("input", update);
        update();
    });
})();
