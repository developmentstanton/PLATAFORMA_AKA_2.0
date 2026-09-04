// Verifica kpisFromArbol sobre un arbol sintetico. El CEDI aporta a los TOTALES (siembra, stock,
// ventas) porque el arbol de la pestana C lo muestra, pero NO cuenta como tienda en los conteos:
// no es una tienda, y contandolo el KPI daba uno mas que el mismo KPI en Ventas (g00).
//
// Antes este archivo llevaba una copia a mano de la funcion y se quedo desincronizado sin avisar:
// segui pasando en verde mientras el codigo real cambiaba. Ahora extrae y ejecuta el codigo real
// de informes/o14.php.
//   node tests/o14_kpis_arbol_test.mjs
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

const kpisFromArbol = new Function(`
  ${cortar('  function kpisFromArbol(data){', '\n  }\n')}
  return kpisFromArbol;
`)();

const data = { grupos: [
  { grupo: 'AKA', almacenes: [
    { llave: '007-245', bodega: '245', nombre: 'TUNJA', negocios: [
      { negocio: 'A-NEG', referencia: 'A', color: 'NEG', valores: { siembra: {'10':5}, disponible: {'10':2}, hold: {'10':1}, ventas: {'10':3} } },
    ]},
    { llave: '007-547', bodega: '547', nombre: 'X', negocios: [
      { negocio: 'A-NEG', referencia: 'A', color: 'NEG', valores: { siembra: {'10':0}, disponible: {'10':0}, hold: {'10':0}, ventas: {'10':1} } },
    ]},
  ]},
  { grupo: 'BODEGA', almacenes: [
    { llave: '007-CEDI', bodega: 'CEDI', nombre: 'CEDI', negocios: [
      { negocio: 'A-NEG', referencia: 'A', color: 'NEG', valores: { siembra: {'10':9}, disponible: {'10':9}, hold: {'10':0}, ventas: {'10':0} } },
    ]},
  ]},
]};

const k = kpisFromArbol(data);
const exp = {
  // Totales: el CEDI SI suma (5+0+9 de siembra, 2+0+9 de disponible).
  siembra: 14, disponible: 11, hold: 1, total_stock: 12, ventas: 4, faltante: 2, sobrantes: 0,
  negocios: 1, negocios_con_siembra: 1,
  // Conteos de tienda: el CEDI NO cuenta. Solo 245 tiene siembra e inventario;
  // 245 y 547 tienen venta (el CEDI no vende, por eso este no cambia).
  tiendas_con_siembra: 1, tiendas_con_inv: 1, tiendas_con_venta: 2,
};
let fail = 0;
for (const key in exp) {
  if (k[key] !== exp[key]) { console.error(`FALLO: ${key}=${k[key]} esperado ${exp[key]}`); fail = 1; }
}
console.log('kpis:', JSON.stringify(k));
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (el CEDI suma en totales, no cuenta como tienda)');
process.exit(fail);
