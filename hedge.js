function showHedgeSolutions(){
  fetch('/api/hedge-show')
    .then(function(r){ return r.json(); })
    .then(function(data){
      var h = '<h4>Stored Hedge Solutions (latest 50)</h4><div style="max-height:300px;overflow-y:auto;">';
      data.forEach(function(s){
        h += '<p><b>#' + s.id + '</b> ' + s.ticker + ' - ' + s.focus + ' <small>' + s.created_at + '</small></p>';
      });
      h += '</div>';
      document.getElementById('homework-response').innerHTML = h;
    });
}
function deleteOldestHedge(){
  if(confirm('Delete 10 oldest hedge solutions?')){
    fetch('/api/hedge-delete', {method:'POST'})
      .then(function(){ updateHedgeWarning(); });
  }
}
function updateHedgeWarning(){
  fetch('/api/hedge-count')
    .then(function(r){ return r.json(); })
    .then(function(d){
      var el = document.getElementById('hedge-warning');
      if(el) el.innerText = d.warning;
    });
}
setInterval(updateHedgeWarning, 60000);
