// Verifica o45AOA (copia idéntica a informes/o45.php): fila por negocio + TOTAL.
//   node tests/o45_export_test.mjs
function o45AOA(d) {
  const COLS = [['negocio','Negocio'],['marca','Marca'],['ventas','Ventas (und)'],['tiendas','#tiendas'],
    ['ind_inventario','Índice de inventario'],['stock_cedi','Stock CEDI'],['stock_tiendas','Stock Tiendas'],
    ['total_stock','Total Stock'],['ind_ventas_mes','Índice de Ventas mes'],['tallas','Tallas'],['precio','Precio de Venta Detal']];
  const header = COLS.map(c => c[1]);
  const filas = (d.filas || []).map(f => COLS.map(c => { const v = f[c[0]]; return (v == null ? '' : v); }));
  const t = d.total || {};
  const num = k => (t[k] == null ? '' : t[k]);
  filas.push(['TOTAL', '', num('ventas'), num('tiendas'), num('ind_inventario'), num('stock_cedi'),
    num('stock_tiendas'), num('total_stock'), num('ind_ventas_mes'), '', '']);
  return { header, filas };
}
const d = { filas: [{negocio:'A', marca:'X', ventas:10, tiendas:2, ind_inventario:1.5, stock_cedi:3,
  stock_tiendas:4, total_stock:7, ind_ventas_mes:0.5, tallas:5, precio:1000}],
  total: {ventas:10, tiendas:2, ind_inventario:1.5, stock_cedi:3, stock_tiendas:4, total_stock:7, ind_ventas_mes:0.5} };
const r = o45AOA(d);
let fail = 0;
const assert = (cond, msg) => { if (!cond) { console.error('FALLO: ' + msg); fail = 1; } };
assert(r.header.length === 11 && r.header[0] === 'Negocio', 'header 11');
assert(r.filas[0][0] === 'A' && r.filas[0][2] === 10 && r.filas[0][10] === 1000, 'fila negocio numérica');
assert(r.filas[1][0] === 'TOTAL' && r.filas[1][1] === '', 'total marca vacía');
assert(r.filas[1][7] === 7 && r.filas[1][9] === '' && r.filas[1][10] === '', 'total stock + tallas/precio vacíos');
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (o45 dataset + total)');
process.exit(fail);
