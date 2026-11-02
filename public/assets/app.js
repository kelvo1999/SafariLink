'use strict';
document.documentElement.classList.add('js');
const boxes=[...document.querySelectorAll('input[name="seats[]"]')];
const total=document.getElementById('seat-total');
function updateSeats(){
 const selected=boxes.filter(b=>b.checked);
 if(selected.length>8){selected[selected.length-1].checked=false;alert('Choose up to eight seats.');return updateSeats();}
 let cents=0;
 for(const box of boxes){
  if(box.checked)cents+=Number.parseInt(box.dataset.fare,10);
  for(const row of document.querySelectorAll('.passenger-field'))if(row.dataset.seat===box.value){row.classList.toggle('active',box.checked);row.querySelector('input').required=box.checked;}
 }
 if(total)total.textContent=selected.length?'KES '+Math.floor(cents/100).toLocaleString('en-KE')+'.'+String(cents%100).padStart(2,'0'):'Select seats';
}
boxes.forEach(box=>box.addEventListener('change',updateSeats));updateSeats();
for(const form of document.querySelectorAll('form[method="post"]'))form.addEventListener('submit',()=>{
 if(!form.checkValidity())return;
 const button=form.querySelector('button');if(button){button.disabled=true;button.textContent='Please wait…';}
});
window.addEventListener('pageshow',()=>{for(const b of document.querySelectorAll('button'))b.disabled=false;});

const scanButton=document.getElementById('scan-qr');
scanButton?.addEventListener('click',async()=>{
 const status=document.getElementById('scanner-status');const video=document.getElementById('qr-camera');
 if(!('BarcodeDetector' in window)||!navigator.mediaDevices?.getUserMedia){status.textContent='Camera scanning is unavailable in this browser. Enter the printed token manually.';return;}
 let stream;
 try{
  const detector=new BarcodeDetector({formats:['qr_code']});stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});video.srcObject=stream;video.hidden=false;await video.play();scanButton.disabled=true;
  const end=Date.now()+30000;
  while(Date.now()<end){const codes=await detector.detect(video);const code=codes.find(c=>/^[a-f0-9]{64}$/.test(c.rawValue));if(code){document.querySelector('input[name="token"]').value=code.rawValue;status.textContent='Token scanned. Verify the trip and stop, then select Validate & board.';return;}await new Promise(resolve=>setTimeout(resolve,200));}
  status.textContent='Scan timed out. Try again or enter the token manually.';
 }catch(error){status.textContent='Camera unavailable. Allow camera access over HTTPS or enter the token manually.';}
 finally{stream?.getTracks().forEach(track=>track.stop());video.hidden=true;scanButton.disabled=false;}
});
