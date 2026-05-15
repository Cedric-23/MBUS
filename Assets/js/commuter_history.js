async function clearHistory(id,btn){

btn.disabled = true;
btn.innerText = "Removing...";

const res = await fetch("clear_history.php",{
method:"POST",
headers:{
"Content-Type":"application/x-www-form-urlencoded"
},
body:"reservation_id="+id
});

const result = await res.text();

if(result.trim()=="ok"){

const card = btn.closest(".card");
card.style.opacity = "0";

setTimeout(()=>{
card.remove();

/* CHECK IF WALA NG LAMAN */
const container = document.getElementById("history-container");
if(container.children.length === 0){
container.innerHTML = `
<div class="empty">
No trip history found.
</div>
`;
}

},300);

}else{
btn.disabled = false;
btn.innerText = "Clear";
}

}
