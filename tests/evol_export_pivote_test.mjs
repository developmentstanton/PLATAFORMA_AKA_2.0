// Verifica que evolExport arma la hoja con la MISMA forma que el pivote de pantalla:
// Negocio | Conceptos | un mes por columna | Total. No es una copia del algoritmo: extrae y
// ejecuta el codigo real de informes/evol.php, para que la prueba se entere si alguien lo cambia.
//   node tests/evol_export_pivote_test.mjs
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '..');
// Normalizado a LF: git puede dejar el working copy en CRLF (autocrlf en Windows) y los
// marcadores de corte de abajo llevan \n literal; sin esto la prueba revienta segun como se clono.
const src = readFileSync(join(raiz, 'informes', 'evol.php'), 'utf8').replace(/\r\n/g, '\n');

// Recorta un trozo de codigo entre un marcador de inicio y su cierre.
function cortar(inicio, fin) {
  const i = src.indexOf(inicio);
  if (i < 0) throw new Error('no se encontro en evol.php: ' + inicio);
  const j = src.indexOf(fin, i);
  if (j < 0) throw new Error('no cierra: ' + inicio);
  return src.slice(i, j + fin.length);
}

const fabrica = new Function('datos', 'capturar', `
  const nf = n => n, nf2 = n => n;                       // el formateo no interviene en el export
  ${cortar('  const MEDIDAS = [', '\n  ];')}
  ${cortar('  const fmtMesHdr =', '};')}
  const window = { expDataset: capturar, __evollast: datos };
  ${cortar('  window.evolExport = function(){', '\n  };')}
  return window.evolExport;
`);

const datos = {
  meses: ['2025-01', '2025-02'],
  negocios: [{
    negocio: 'BOTA X',
    valores: { compras: { '2025-01': 10, '2025-02': 5 }, ventas: { '2025-01': 3 }, stock: { '2025-02': 7 },
               tiendas: {}, mesesInv: {}, indice: { '2025-01': 1.5 } },
    totales: { compras: 15, ventas: 3, stock: 7 },
  }],
  totalGeneral: {
    valores: { compras: { '2025-01': 10, '2025-02': 5 }, ventas: { '2025-01': 3 } },
    totales: { compras: 15, ventas: 3 },
  },
};

let visto = null;
const exportar = fabrica(datos, (cuadro, hoja, header, filas) => { visto = { header, filas }; return true; });
exportar();

let fail = 0;
const eq = (a, b, msg) => {
  if (JSON.stringify(a) !== JSON.stringify(b)) {
    console.error('FALLO: ' + msg + '\n  esperado: ' + JSON.stringify(b) + '\n  obtenido: ' + JSON.stringify(a));
    fail = 1;
  }
};

eq(visto.header, ['Negocio', 'Conceptos', '2025-Ene', '2025-Feb', 'Total'], 'cabecera = un mes por columna + Total');
eq(visto.filas.length, 12, '6 medidas x (1 negocio + TOTAL general)');
eq(visto.filas[0], ['BOTA X', 'Ingreso', 10, 5, 15], 'Ingreso acumula en Total');
eq(visto.filas[1], ['BOTA X', 'Total Ventas', 3, '', 3], 'mes sin dato queda vacio, no 0');
eq(visto.filas[2], ['BOTA X', 'Stock', '', 7, ''], 'Stock NO acumula: Total vacio aunque el dato exista');
eq(visto.filas[3], ['BOTA X', 'Tiendas con Inv', '', '', ''], 'medida sin datos, la fila igual esta');
eq(visto.filas[5], ['BOTA X', 'Índice Ventas Detal Mes', 1.5, '', ''], 'indice con decimales y sin Total');
eq(visto.filas[6], ['TOTAL', 'Ingreso', 10, 5, 15], 'el bloque TOTAL general va al final');

// El negocio se repite en sus 6 filas (en pantalla es un rowspan) para poder filtrar la hoja.
eq(visto.filas.slice(0, 6).map(f => f[0]), Array(6).fill('BOTA X'), 'negocio repetido en sus 6 filas');

console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (evol export = pivote de pantalla)');
process.exit(fail);
