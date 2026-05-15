function togglePassengers(scheduleId){

const row=document.getElementById(
'passengers-'+scheduleId
);

if(row.style.display==='none'){

row.style.display='table-row';

}else{

row.style.display='none';

}

}