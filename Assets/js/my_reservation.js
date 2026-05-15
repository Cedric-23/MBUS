/* LIVE TIMER */

document.querySelectorAll(".countdown").forEach(timer=>{

let seconds=parseInt(timer.dataset.seconds);

const interval=setInterval(()=>{

if(seconds<=0){

const card=timer.closest(".card");

const topStatus=
card.querySelector(".top .status");

topStatus.innerText="Cancelled";

topStatus.className=
"status cancelled";

const timerBox=
card.querySelector(".timer");

if(timerBox){
timerBox.remove();
}

const actions=
card.querySelector(".actions");

actions.innerHTML=`

<div class="status cancelled">
Reservation Cancelled
</div>

`;

clearInterval(interval);

return;

}

seconds--;

let mins=Math.floor(seconds/60);

let secs=seconds%60;

timer.innerText=
`${mins}:${secs.toString().padStart(2,'0')}`;

},1000);

});

/* CANCEL */

async function cancelReservation(reservationId,button){

const response=await fetch(
"cancel_pending.php",
{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:"reservation_id="+reservationId
}
);

const result=await response.text();

if(result.trim()=="ok"){

const card=button.closest(".card");

const topStatus=
card.querySelector(".top .status");

topStatus.innerText="Cancelled";

topStatus.className=
"status cancelled";

const timer=
card.querySelector(".timer");

if(timer){
timer.remove();
}

const actions=
card.querySelector(".actions");

actions.innerHTML=`

<div class="status cancelled">
Reservation Cancelled
</div>

`;

}

}
