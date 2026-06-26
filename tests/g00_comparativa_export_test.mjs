// Verifica comparativaAOA (copia idéntica a informes/g00.php): split padre/hijo + total.
//   node tests/g00_comparativa_export_test.mjs
const eDif  = (a, b) => (a || 0) - (b || 0);
const ePct  = (a, b) => b ? ((a - b) / b) * 100 : '';
const eProm = (v, u) => (u > 0 ? v / u : 0);
function comparativaAOA(dim, rows, opts) {
  const a = opts.anio, b = a - 1, full = opts.full !== false;
  const parts = String(dim).split(' / ');
  const hasChild = parts.length > 1;
  const dimHdr = hasChild ? [parts[0], parts[1]] : [parts[0]];
  const metricHdr = full
    ? [b, a, 'Dif Q', '%Q', '$ '+b, '$ '+a, 'Dif $', '%$', 'MB', '$Prom '+b, '$Prom '+a, '%Prom', 'Tdas '+b, 'Tdas '+a, '≠Tdas']
    : [b, a, 'Dif Q', '%Q', '$ '+b, '$ '+a, 'Dif $', '%$', 'MB', '$Prom '+b, '$Prom '+a];
  const header = dimHdr.concat(metricHdr);
  const metr = (r) => {
    const pa = eProm(r.val_act, r.ups_act), pb = eProm(r.val_ant, r.ups_ant);
    const base = [r.ups_ant||0, r.ups_act||0, eDif(r.ups_act,r.ups_ant), ePct(r.ups_act,r.ups_ant),
      r.val_ant||0, r.val_act||0, eDif(r.val_act,r.val_ant), ePct(r.val_act,r.val_ant),
      r.margen||0, pb, pa];
    if (full) base.push(ePct(pa,pb), r.tiendas_ant||0, r.tiendas_act||0, eDif(r.tiendas_act,r.tiendas_ant));
    return base;
  };
  const dimCells = (parent, child) => hasChild ? [parent, child] : [parent];
  const filas = [];
  let sv=0, sb=0, su=0, sub=0; const margenes=[];
  (rows||[]).forEach(r => {
    sv+=r.val_act||0; sb+=r.val_ant||0; su+=r.ups_act||0; sub+=r.ups_ant||0;
    if (r.margen>0) margenes.push(r.margen);
    filas.push(dimCells(r.label||'', '').concat(metr(r)));
    (r.children||[]).forEach(c => filas.push(dimCells(r.label||'', c.label||'').concat(metr(c))));
  });
  const mbTot = margenes.length ? margenes.reduce((x,y)=>x+y,0)/margenes.length : 0;
  const tdas = opts.totalTdas || {act:0, ant:0};
  const totRow = {label:'Total', val_act:sv, val_ant:sb, ups_act:su, ups_ant:sub, margen:mbTot, tiendas_act:tdas.act, tiendas_ant:tdas.ant};
  filas.push(dimCells('Total','').concat(metr(totRow)));
  return { header, filas };
}
let fail = 0;
const assert = (cond, msg) => { if (!cond) { console.error('FALLO: ' + msg); fail = 1; } };
// Caso padre/hijo (full:false → 11 cols métrica + 2 dim = 13).
const rPC = comparativaAOA('Tienda / Negocio',
  [{label:'Tienda A', val_act:100, val_ant:80, ups_act:10, ups_ant:8, margen:30, tiendas_act:1, tiendas_ant:1,
    children:[{label:'Neg1', val_act:60, val_ant:50, ups_act:6, ups_ant:5, margen:0}]}],
  {anio:2026, full:false});
assert(JSON.stringify(rPC.header.slice(0,2)) === JSON.stringify(['Tienda','Negocio']), 'header split');
assert(rPC.header.length === 13, 'header 13 (2 dim + 11 metric, full:false)');
assert(rPC.filas[0][0] === 'Tienda A' && rPC.filas[0][1] === '', 'fila padre col hijo vacía');
assert(rPC.filas[1][0] === 'Tienda A' && rPC.filas[1][1] === 'Neg1', 'fila hijo padre repetido');
assert(rPC.filas[2][0] === 'Total' && rPC.filas[2][1] === '', 'fila total');
assert(rPC.filas[2][2] === 8 && rPC.filas[2][3] === 10, 'total cant ant/act');
// Caso una sola dimensión (sin ' / ').
const rG = comparativaAOA('Grupo',
  [{label:'AKA', val_act:5, val_ant:4, ups_act:2, ups_ant:1, margen:10, tiendas_act:3, tiendas_ant:3}],
  {anio:2026, full:true});
assert(rG.header[0] === 'Grupo' && rG.header[1] === 2025, 'una dim: header sin hijo');
assert(rG.filas[0].length === 16, 'una dim: 1 + 15 metric');
assert(rG.filas[0][0] === 'AKA', 'una dim: fila');
assert(rG.filas[1][0] === 'Total', 'una dim: total');
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (split padre/hijo + total)');
process.exit(fail);
