const items = document.querySelectorAll('.rcec-li__item');
const avisos = document.querySelectorAll('.rcec-li__aviso');
const out = {
  tarjetas: items.length,
  avisos: avisos.length,
  textoAviso: avisos.length ? avisos[0].textContent.trim() : null,
  ariaBusy: document.getElementById('rcec-li').getAttribute('aria-busy'),
  botonPie: document.querySelector('.rcec-li__boton') ? document.querySelector('.rcec-li__boton').textContent.trim() : null,
  enlaces: [...items].map(i => i.querySelector('a').href),
};
console.log(JSON.stringify(out, null, 2));
