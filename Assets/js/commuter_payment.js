function updateCountdown(){

const now=new Date().getTime();
const distance=expiryTime-now;

if(distance<=0){
window.location="schedule.php";
return;
}

const minutes=Math.floor((distance%(1000*60*60))/(1000*60));
const seconds=Math.floor((distance%(1000*60))/1000);

document.getElementById("countdown").innerText =
minutes+"m "+seconds+"s";

}

setInterval(updateCountdown,1000);
updateCountdown();