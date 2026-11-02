const driver = document.getElementById("option-driver")
const passenger = document.getElementById("option-passenger")
const driver_p = driver.querySelector("p")
const passenger_p = passenger.querySelector("p")
const option_bar = document.getElementById("option-bar")

const driver_form = document.getElementById("driver")
const passenger_form = document.getElementById("driver")


driver.addEventListener("click", () => {

    option_bar.classList.remove("disabled")
    option_bar.classList.add("active")

    passenger_p.style.color = "#101827"
    passenger_p.style.opacity = "50%"

    driver_p.style.color = "#086FCF"
    driver_p.style.opacity = "100%"

    driver_form.style.display = "flex"
    passenger_form.style.display = "none"
});

passenger.addEventListener("click", () => {

    option_bar.classList.remove("active")
    option_bar.classList.add("disabled")

    passenger_p.style.color = "#086FCF"
    passenger_p.style.opacity = "100%"

    driver_p.style.color = "#101828"
    driver_p.style.opacity = "50%"

    driver_form.style.display = "flex"
    passenger_form.style.display = "none"
})