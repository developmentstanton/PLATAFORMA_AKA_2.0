// Verifica kpisFromArbol (copia idéntica a la de informes/o14.php) sobre un árbol sintético.
//   node tests/o14_kpis_arbol_test.mjs
function kpisFromArbol(data){
  const k={siembra:0,disponible:0,hold:0,ventas:0,sobrantes:0,faltante:0};
  const negSet={}, negSiem={}, tdaSiem=new Set(), tdaInv=new Set(), tdaVta=new Set();
  (data.grupos||[]).forEach(g=>{
    (g.almacenes||[]).forEach(a=>{
      const cia=String(a.llave).slice(0, String(a.llave).length - String(a.bodega).length - 1);
      let aSi=0, aDi=0, aVeAny=false;
      (a.negocios||[]).forEach(n=>{
        const negKey=cia+'|'+n.negocio, v=n.valores||{};
        const sumM=(m)=>{ let s=0; const o=v[m]||{}; for(const t in o) s+=o[t]; return s; };
        const si=sumM('siembra'), di=sumM('disponible'), ho=sumM('hold'), ve=sumM('ventas');
        k.siembra+=si; k.disponible+=di; k.hold+=ho; k.ventas+=ve;
        const tallas=new Set([...Object.keys(v.siembra||{}),...Object.keys(v.disponible||{}),...Object.keys(v.hold||{})]);
        tallas.forEach(t=>{ const bal=((v.siembra||{})[t]||0)-(((v.disponible||{})[t]||0)+((v.hold||{})[t]||0));
          if(bal>0) k.faltante+=bal; else if(bal<0) k.sobrantes+=-bal; });
        negSet[negKey]=1; if(si>0) negSiem[negKey]=1;
        aSi+=si; aDi+=di;
        const vo=v.ventas||{}; for(const t in vo) if(vo[t]!==0) aVeAny=true;
      });
      if(aSi>0) tdaSiem.add(a.llave);
      if(aDi>0) tdaInv.add(a.llave);
      if(aVeAny) tdaVta.add(a.llave);
    });
  });
  k.total_stock=k.disponible+k.hold;
  k.negocios=Object.keys(negSet).length;
  k.negocios_con_siembra=Object.keys(negSiem).length;
  k.tiendas_con_siembra=tdaSiem.size;
  k.tiendas_con_inv=tdaInv.size;
  k.tiendas_con_venta=tdaVta.size;
  return k;
}

const data={grupos:[
  {grupo:'AKA',almacenes:[
    {llave:'007-245',bodega:'245',nombre:'TUNJA',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':5},disponible:{'10':2},hold:{'10':1},ventas:{'10':3}}},
    ]},
    {llave:'007-547',bodega:'547',nombre:'X',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':0},disponible:{'10':0},hold:{'10':0},ventas:{'10':1}}},
    ]},
  ]},
  {grupo:'BODEGA',almacenes:[
    {llave:'007-CEDI',bodega:'CEDI',nombre:'CEDI',negocios:[
      {negocio:'A-NEG',referencia:'A',color:'NEG',valores:{siembra:{'10':9},disponible:{'10':9},hold:{'10':0},ventas:{'10':0}}},
    ]},
  ]},
]};

const k=kpisFromArbol(data);
const exp={siembra:14,disponible:11,hold:1,total_stock:12,ventas:4,faltante:2,sobrantes:0,
  negocios:1,negocios_con_siembra:1,tiendas_con_siembra:2,tiendas_con_inv:2,tiendas_con_venta:2};
let fail=0;
for(const key in exp){ if(k[key]!==exp[key]){ console.error(`FALLO: ${key}=${k[key]} esperado ${exp[key]}`); fail=1; } }
console.log('kpis:',JSON.stringify(k));
console.log(fail?'RESULTADO: FALLO':'RESULTADO: OK (CEDI excluido, balance y conteos)');
process.exit(fail);
