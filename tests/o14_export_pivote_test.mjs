// Verifica que o14Export arma las hojas B y C con la MISMA forma que el pivote de pantalla:
// cabecera de 2 niveles (la medida arriba, tallas + Tot abajo) y, en C, el arbol abierto en tres
// columnas Grupo | Almacen | Negocio. No es una copia del algoritmo: extrae y ejecuta el codigo
// real de informes/o14.php, para que la prueba se entere si alguien lo cambia.
//   node tests/o14_export_pivote_test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
// Normalizado a LF: git puede dejar el working copy en CRLF (autocrlf en Windows) y los
// marcadores de corte de abajo llevan \n literal; sin esto la prueba revienta segun como se clono.
const src = readFileSync(join(raiz, 'informes', 'o14.php'), 'utf8').replace(/\r\n/g, '\n');

function cortar(inicio, fin) {
  const i = src.indexOf(inicio);
  if (i < 0) throw new Error('no se encontro en o14.php: ' + inicio);
  const j = src.indexOf(fin, i);
  if (j < 0) throw new Error('no cierra: ' + inicio);
  return src.slice(i, j + fin.length);
}

// currentTab / lastData / shownC son locales del IIFE del informe: entran como parametros.
const fabrica = new Function('currentTab', 'lastData', 'shownC', 'capturar', `
  ${cortar('  const MED_LABEL = {', '};')}
  ${cortar('  function sumValores(', '\n  }\n')}
  const window = { expDataset: capturar };
  ${cortar('  window.o14Export = function(){', '\n  };\n')}
  return window.o14Export;
`);

function exportar(tab, lastData, shownC) {
  let visto = null;
  fabrica(tab, lastData, shownC, (cuadro, hoja, header, filas) => { visto = { header, filas }; return true; })();
  return visto;
}

let fail = 0;
const eq = (a, b, msg) => {
  if (JSON.stringify(a) !== JSON.stringify(b)) {
    console.error('FALLO: ' + msg + '\n  esperado: ' + JSON.stringify(b) + '\n  obtenido: ' + JSON.stringify(a));
    fail = 1;
  }
};

// ---------- Pestana B: una fila por negocio ----------
const dataB = { tallas: ['10', '11'], medidas: ['siembra', 'ventas'], filas: [
  { key: { negocio: 'A-NEG' }, valores: { siembra: { '10': 2, '11': 3 }, ventas: { '10': 1 } } },
  { key: { negocio: 'B-NEG' }, valores: { siembra: { '11': 4 } } },
]};
const b = exportar('b', { b: dataB, c: null }, null);
eq(b.header.length, 2, 'B: la cabecera son 2 filas, no una');
eq(b.header[0], ['Negocio', 'Siembra', '', '', 'Ventas', '', ''], 'B: fila 1 = la medida sobre su bloque');
eq(b.header[1], ['', '10', '11', 'Tot', '10', '11', 'Tot'], 'B: fila 2 = tallas + Tot por bloque');
eq(b.filas[0], ['A-NEG', 2, 3, 5, 1, '', 1], 'B: el 0 va vacio, el Tot siempre sale');
eq(b.filas[1], ['B-NEG', '', 4, 4, '', '', 0], 'B: medida sin datos => Tot 0');
eq(b.filas[2], ['TOTAL', 2, 7, 9, 1, '', 1], 'B: fila TOTAL al final, como en pantalla');

// ---------- Pestana C: el arbol en tres columnas ----------
const dataC = { tallas: ['10'], medidas: ['siembra'], grupos: [
  { grupo: 'G1', almacenes: [
    { bodega: '01', nombre: 'CEDI', negocios: [
      { negocio: 'A-NEG', valores: { siembra: { '10': 2 } } },
      { negocio: 'B-NEG', valores: { siembra: { '10': 3 } } } ] },
    { bodega: '02', nombre: 'SUR', negocios: [
      { negocio: 'A-NEG', valores: { siembra: { '10': 1 } } } ] },
  ]},
]};
const c = exportar('c', { b: null, c: dataC }, dataC);
eq(c.header[0], ['Grupo', 'Almacén', 'Negocio', 'Siembra', ''], 'C: el arbol se abre en tres columnas');
eq(c.header[1], ['', '', '', '10', 'Tot'], 'C: segundo nivel de la cabecera');
eq(c.filas[0], ['G1', '01 · CEDI', '', 5, 5], 'C: subtotal del almacen, con Negocio vacio');
eq(c.filas[1], ['G1', '01 · CEDI', 'A-NEG', 2, 2], 'C: grupo y almacen repetidos en la fila hoja');
eq(c.filas[2], ['G1', '01 · CEDI', 'B-NEG', 3, 3], 'C: segundo negocio del mismo almacen');
eq(c.filas[3], ['G1', '02 · SUR', '', 1, 1], 'C: subtotal del segundo almacen');
eq(c.filas[4], ['G1', '02 · SUR', 'A-NEG', 1, 1], 'C: hoja del segundo almacen');
eq(c.filas[5], ['G1', 'Total G1', '', 6, 6], 'C: total del grupo, un nivel mas adentro');
eq(c.filas[6], ['TOTAL', '', '', 6, 6], 'C: total general al final');
eq(c.filas.length, 7, 'C: sin filas de mas');

// C exporta lo que esta pintado (shownC), no el dataset completo: si hay un negocio seleccionado
// el arbol en pantalla viene filtrado y la hoja debe traer lo mismo.
const filtrado = { tallas: ['10'], medidas: ['siembra'], grupos: [
  { grupo: 'G1', almacenes: [ { bodega: '01', nombre: 'CEDI', negocios: [
    { negocio: 'A-NEG', valores: { siembra: { '10': 2 } } } ] } ] },
]};
const cf = exportar('c', { b: null, c: dataC }, filtrado);
eq(cf.filas.map(f => f[2]), ['', 'A-NEG', '', ''], 'C filtrada: solo el negocio seleccionado');

console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (o14 export B y C = pivote de pantalla)');
process.exit(fail);
