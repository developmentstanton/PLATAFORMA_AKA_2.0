// Verifica periodosAOA (copia idéntica a la de informes/g00.php) sobre días sintéticos.
//   node tests/g00_periodos_export_test.mjs
const ePart = (x, d) => (d > 0 ? (x / d) * 100 : '');
const ePct  = (a, b) => b ? ((a - b) / b) * 100 : '';
function buildArbolPeriodos(dias) {
  const blank = () => ({va:0,vb:0,ua:0,ub:0});
  const add = (o,d) => { o.va+=d.val_act; o.vb+=d.val_ant; o.ua+=d.ups_act; o.ub+=d.ups_ant; };
  const sems = {}; const tot = blank();
  (dias||[]).forEach(d => {
    const sem = d.mes <= 6 ? 1 : 2, tri = Math.ceil(d.mes/3);
    sems[sem] = sems[sem] || {m:blank(), tris:{}}; add(sems[sem].m, d);
    const S = sems[sem];
    S.tris[tri] = S.tris[tri] || {m:blank(), meses:{}}; add(S.tris[tri].m, d);
    const T = S.tris[tri];
    T.meses[d.mes] = T.meses[d.mes] || {m:blank(), dias:{}}; add(T.meses[d.mes].m, d);
    const M = T.meses[d.mes];
    M.dias[d.dia] = M.dias[d.dia] || {m:blank()}; add(M.dias[d.dia].m, d);
    add(tot, d);
  });
  return {sems, tot};
}
function periodosAOA(dias, anio) {
  const a = anio, b = a - 1;
  const MESES = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  const MESAB = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
  const header = ['Semestre','Trimestre','Bimestre','Mes','Día',
    b, 'Part Q '+b, a, 'Part Q '+a, '% Q',
    '$ '+b, 'Part $ '+b, '$ '+a, 'Part $ '+a, '% $'];
  const arbol = buildArbolPeriodos(dias);
  const T = arbol.tot;
  const metr = (dims, m) => dims.concat([
    m.ub, ePart(m.ub, T.ub), m.ua, ePart(m.ua, T.ua), ePct(m.ua, m.ub),
    m.vb, ePart(m.vb, T.vb), m.va, ePart(m.va, T.va), ePct(m.va, m.vb)]);
  const filas = [];
  Object.keys(arbol.sems).map(Number).sort((x,y)=>x-y).forEach(s => {
    const S = arbol.sems[s]; const semL = 'Semestre ' + s;
    filas.push(metr([semL,'','','',''], S.m));
    Object.keys(S.tris).map(Number).sort((x,y)=>x-y).forEach(t => {
      const Tr = S.tris[t]; const triL = 'Trim-' + t;
      filas.push(metr([semL, triL,'','',''], Tr.m));
      Object.keys(Tr.meses).map(Number).sort((x,y)=>x-y).forEach(mn => {
        const M = Tr.meses[mn];
        filas.push(metr([semL, triL, '', MESES[mn-1], ''], M.m));
        Object.keys(M.dias).map(Number).sort((x,y)=>x-y).forEach(d => {
          const bim = 'Bim ' + Math.ceil(mn/2);
          const diaL = MESAB[mn-1] + '-' + String(d).padStart(2,'0');
          filas.push(metr([semL, triL, bim, MESES[mn-1], diaL], M.dias[d].m));
        });
      });
    });
  });
  filas.push(metr(['Total','','','',''], T));
  return { header, filas };
}

const dias = [
  {mes:1, dia:2, val_act:13, val_ant:14, ups_act:13, ups_ant:14},
  {mes:1, dia:3, val_act:11, val_ant: 9, ups_act:11, ups_ant: 9},
];
const r = periodosAOA(dias, 2026);
let fail = 0;
const assert = (cond, msg) => { if (!cond) { console.error('FALLO: ' + msg); fail = 1; } };
assert(r.header.length === 15, 'header 15 cols');
assert(JSON.stringify(r.header.slice(0,5)) === JSON.stringify(['Semestre','Trimestre','Bimestre','Mes','Día']), 'dims');
// Estructura esperada de filas (en orden): Semestre, Trimestre, Mes, Día-02, Día-03, Total = 6 filas.
assert(r.filas.length === 6, 'filas=6, got ' + r.filas.length);
assert(JSON.stringify(r.filas[0].slice(0,5)) === JSON.stringify(['Semestre 1','','','','']), 'subtotal semestre dims vacías');
assert(JSON.stringify(r.filas[2].slice(0,5)) === JSON.stringify(['Semestre 1','Trim-1','','Enero','']), 'subtotal mes con bimestre vacío');
assert(JSON.stringify(r.filas[3].slice(0,5)) === JSON.stringify(['Semestre 1','Trim-1','Bim 1','Enero','Ene-02']), 'fila hoja día-02 completa');
assert(r.filas[3][5] === 14 && r.filas[3][7] === 13, 'día-02 cant ant/act');
assert(r.filas[5][0] === 'Total', 'fila total');
assert(r.filas[5][5] === 23 && r.filas[5][7] === 24, 'total cant ant(14+9)/act(13+11)');
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (periodos explotado + subtotales + bimestre)');
process.exit(fail);
