// Verifica recoAOA (copia idéntica a informes/o14.php): matriz negocio×talla + TOTAL.
//   node tests/o14_reco_export_test.mjs
function recoAOA(data, medida) {
  const tallas = data.tallas || [];
  const filas0 = (data.filas || []).filter(f => { const o = (f.valores||{})[medida]||{}; for (const t in o) if (o[t]) return true; return false; });
  const header = ['Negocio'].concat(tallas, ['Tot']);
  const tot = {}; tallas.forEach(t => tot[t] = 0);
  const filas = filas0.map(f => { const o = f.valores[medida]||{}; let rt = 0;
    const row = [f.negocio]; tallas.forEach(t => { const v = o[t]||0; rt += v; tot[t] += v; row.push(v || ''); }); row.push(rt); return row; });
  let gtot = 0; tallas.forEach(t => gtot += tot[t]);
  filas.push(['TOTAL'].concat(tallas.map(t => tot[t] || ''), [gtot]));
  return { header, filas };
}
const data = { tallas: ['10','11'], filas: [
  {negocio:'A-NEG', valores:{sobrante:{'10':5}}},
  {negocio:'B-NEG', valores:{sobrante:{'11':3}}},
  {negocio:'C-NEG', valores:{sobrante:{}}},   // se descarta (sin valores)
]};
const r = recoAOA(data, 'sobrante');
let fail = 0;
const assert = (cond, msg) => { if (!cond) { console.error('FALLO: ' + msg); fail = 1; } };
assert(JSON.stringify(r.header) === JSON.stringify(['Negocio','10','11','Tot']), 'header');
assert(r.filas.length === 3, '2 negocios + total (C descartado), got ' + r.filas.length);
assert(JSON.stringify(r.filas[0]) === JSON.stringify(['A-NEG',5,'',5]), 'A-NEG');
assert(JSON.stringify(r.filas[1]) === JSON.stringify(['B-NEG','',3,3]), 'B-NEG');
assert(JSON.stringify(r.filas[2]) === JSON.stringify(['TOTAL',5,3,8]), 'TOTAL');
console.log(fail ? 'RESULTADO: FALLO' : 'RESULTADO: OK (reco matriz + total)');
process.exit(fail);
