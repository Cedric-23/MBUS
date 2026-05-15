let editMode=false;

function toggleEditMode(){
editMode=!editMode;

document.querySelectorAll('.edit-controls').forEach(el=>{
el.style.display=editMode?'block':'none';
});

document.querySelectorAll('.view-mode').forEach(el=>{
el.style.display=editMode?'none':'inline-block';
});
}

function filterSchedules(route,btn){
document.querySelectorAll('.schedule-row').forEach(row=>{
row.style.display=(route==='all'||row.dataset.route===route)?'':'none';
});

document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active-filter'));
btn.classList.add('active-filter');
}