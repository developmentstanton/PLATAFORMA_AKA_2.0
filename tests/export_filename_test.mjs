// Verifica expFile (copia node-friendly de la de dashboard.php): saneo + segmentos.
//   node tests/export_filename_test.mjs
function expFile(cuadro, proveedor, fecha) {
  const limpio = s => String(s == null ? '' : s).replace(/[\/\\:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim();
  const c = limpio(cuadro), p = limpio(proveedor);
  return c + (p ? ' - ' + p : '') + ' - ' + fecha + '.xlsx';
}
let fail = 0;
const eq = (got, exp, msg) => { if (got !== exp) { console.error(`FALLO ${msg}: '${got}' != '${exp}'`); fail = 1; } };
// Caracter ilegal '/' en el cuadro → espacio; proveedor presente.
eq(expFile('Ventas por Marca / Tipo', 'BELTRANY SAS', '2026-06-26'),
   'Ventas por Marca Tipo - BELTRANY SAS - 2026-06-26.xlsx', 'marca/tipo+prov');
// Sin proveedor → se omite el segmento.
eq(expFile('Índice de Ventas', '', '2026-06-26'),
   'Índice de Ventas - 2026-06-26.xlsx', 'sin prov');
// Acentos y espacios se conservan.
eq(expFile('Evolución Histórica', 'PROV  X', '2026-01-01'),
   'Evolución Histórica - PROV X - 2026-01-01.xlsx', 'acentos');
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (expFile saneo+segmentos)');
process.exit(fail);
