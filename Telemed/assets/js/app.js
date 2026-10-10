// File: assets/js/app.js
document.addEventListener('DOMContentLoaded', function(){
  setTimeout(function(){
    document.querySelectorAll('.alert').forEach(function(el){ el.remove(); });
  }, 5000);
});
