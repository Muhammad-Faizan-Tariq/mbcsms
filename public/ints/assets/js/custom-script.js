/*==========================
TOUCHY SCROLL FOR SIDEBAR
==========================*/
$('.accordion_mnu').initMenu();
$("#sidebar").niceScroll({
    cursorcolor: "#2f2e2e",
    cursoropacitymax: 0.7,
    boxzoom: false,
    touchbehavior: true
});
$("#sidebar").mouseover(function(){
  $("#sidebar").niceScroll().resize();
});