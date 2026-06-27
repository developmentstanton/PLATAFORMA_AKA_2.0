# Estandarización de descargas a "conjunto de datos" — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unificar todos los botones de descarga de informes para que generen un conjunto de datos plano (dimensiones en columnas separadas, conservando subtotales/Total), con nombres de archivo legibles (`Cuadro - Proveedor - Fecha.xlsx`) y sin fallos mudos.

**Architecture:** Dos helpers globales en `dashboard.php` (`window.expFile`, `window.expDataset`) centralizan nombre + guard + escritura del .xlsx (XLSX ya se carga en el `<head>`). Cada informe arma su AOA plano mediante builders **puras** `(datos) -> {header, filas}` y delega en `window.expDataset`. Las builders de riesgo se prueban con tests node `.mjs` que copian la función (convención del repo). No se toca backend, SQL ni el render de las tablas en pantalla.

**Tech Stack:** PHP monolítico + JS vanilla en `<script>` dentro de cada `informes/*.php`; SheetJS/XLSX 0.20.3 (CDN); SweetAlert2; tests con `node` (`.mjs`).

## Global Constraints

- **Conservar subtotales y fila "Total"** en todos los exports que hoy los tienen (decisión de Rafael: "no quites los totales, estándar"). En filas agregadas, las columnas de nivel más profundo van vacías (`''`).
- **Nombre de archivo:** `<Cuadro> - <Proveedor> - <Fecha>.xlsx`. Fecha `YYYY-MM-DD`. Si el proveedor está vacío, se omite ese segmento. Sanear solo caracteres ilegales de Windows (`/ \ : * ? " < > |`) → espacio.
- **Sin fallos mudos:** sin datos → Swal info "Aún no hay datos cargados. Carga el informe primero."; sin XLSX → Swal error. Nunca `return` silencioso.
- **No tocar** las tablas en pantalla, el backend ni el SQL. Las descargas de archivo (`Archivos/codificacion.xlsx`, `api/documentos_descargar.php`) quedan igual.
- **Builders puras** devuelven `{ header: string[], filas: any[][] }`. Valores numéricos crudos (sin formatear) para que Excel los trate como números.
- Tras cada tarea: `php -l` limpio en los `.php` tocados. Verificación E2E en navegador la hace Rafael al final.

---

### Task 1: Helpers globales de descarga en `dashboard.php`

**Files:**
- Modify: `dashboard.php` (insertar bloque `<script>` justo después de la carga de XLSX, línea 35)
- Test: `tests/export_filename_test.mjs`

**Interfaces:**
- Produces:
  - `window.expFile(cuadro: string, proveedor?: string) -> string` — nombre de archivo saneado.
  - `window.expDataset(cuadro: string, hoja: string, header: any[], filas: any[][], proveedor?: string) -> boolean` — escribe el .xlsx; devuelve `false` y muestra Swal si falta XLSX o `filas` está vacío.

- [ ] **Step 1: Escribir el test que falla** (`tests/export_filename_test.mjs`)

```js
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
```

- [ ] **Step 2: Correr el test y verificar que pasa** (es una copia pura, valida la lógica antes de portarla)

Run: `node tests/export_filename_test.mjs`
Expected: `RESULTADO: OK (expFile saneo+segmentos)` (exit 0)

- [ ] **Step 3: Insertar los helpers en `dashboard.php`** justo después de la línea 35 (`<script src="https://cdn.sheetjs.com/...">`)

```html
    <script>
    // ===== Helpers de descarga compartidos por todos los informes =====
    // XLSX y SweetAlert2 ya se cargan arriba. Centralizan nombre + guard + escritura.
    window.expFile = function (cuadro, proveedor) {
      const prov = (proveedor || window.PROVEEDOR_ACTUAL || '').trim();
      const fecha = new Date().toISOString().slice(0, 10);
      const limpio = s => String(s == null ? '' : s).replace(/[\/\\:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim();
      const c = limpio(cuadro), p = limpio(prov);
      return c + (p ? ' - ' + p : '') + ' - ' + fecha + '.xlsx';
    };
    window.expDataset = function (cuadro, hoja, header, filas, proveedor) {
      if (typeof XLSX === 'undefined') {
        if (window.Swal) Swal.fire('Exportar', 'No se pudo cargar la librería de Excel.', 'error');
        return false;
      }
      if (!Array.isArray(filas) || !filas.length) {
        if (window.Swal) Swal.fire('Exportar', 'Aún no hay datos cargados. Carga el informe primero.', 'info');
        return false;
      }
      const ws = XLSX.utils.aoa_to_sheet([header, ...filas]);
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, String(hoja).slice(0, 31));
      XLSX.writeFile(wb, window.expFile(cuadro, proveedor));
      return true;
    };
    </script>
```

- [ ] **Step 4: Verificar PHP** `php -l dashboard.php`
Expected: `No syntax errors detected in dashboard.php`

- [ ] **Step 5: Commit**

```bash
git add dashboard.php tests/export_filename_test.mjs
git commit -m "feat(export): helpers globales expFile/expDataset + test de nombre"
```

---

### Task 2: G00 — export de Periodos a dataset explotado

Reescribe `periodosAOA` para explotar `Semestre · Trimestre · Bimestre · Mes · Día` en columnas (hoy es una sola columna "Periodo" con sangría). Conserva subtotales y Total. `buildArbolPeriodos` (g00.php:764) NO se toca.

**Files:**
- Modify: `informes/g00.php` — `periodosAOA` (líneas 1253-1279) y `g00ExpPeriodos` (1285-1289)
- Test: `tests/g00_periodos_export_test.mjs`

**Interfaces:**
- Consumes: `window.expDataset` (Task 1); `buildArbolPeriodos(dias) -> {sems, tot}` (existente); helpers `ePart`, `ePct` (existentes en g00.php).
- Produces: `periodosAOA(dias, anio) -> {header, filas}`.

- [ ] **Step 1: Escribir el test que falla** (`tests/g00_periodos_export_test.mjs`)

```js
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
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `node tests/g00_periodos_export_test.mjs`
Expected: por ahora PASA (copia pura) — sirve de golden. Si no imprime `RESULTADO: OK`, corregir el test antes de seguir.

(Nota: como la convención del repo es copiar la función al test, el "rojo" real es que `periodosAOA` en `g00.php` aún devuelve `{header, body}` con una sola columna "Periodo"; el test fija el contrato nuevo que el Step 3 debe replicar verbatim.)

- [ ] **Step 3: Reemplazar `periodosAOA` en `informes/g00.php`** (líneas 1253-1279) por la versión del test (sin las copias de `ePart`/`ePct`/`buildArbolPeriodos`, que ya existen en el archivo):

```js
    function periodosAOA(dias, anio) {
        const a = anio, b = a - 1;
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
        Object.keys(arbol.sems).map(Number).sort((x, y) => x - y).forEach(s => {
            const S = arbol.sems[s]; const semL = 'Semestre ' + s;
            filas.push(metr([semL, '', '', '', ''], S.m));
            Object.keys(S.tris).map(Number).sort((x, y) => x - y).forEach(t => {
                const Tr = S.tris[t]; const triL = 'Trim-' + t;
                filas.push(metr([semL, triL, '', '', ''], Tr.m));
                Object.keys(Tr.meses).map(Number).sort((x, y) => x - y).forEach(mn => {
                    const M = Tr.meses[mn];
                    filas.push(metr([semL, triL, '', MESES_ES[mn - 1], ''], M.m));
                    Object.keys(M.dias).map(Number).sort((x, y) => x - y).forEach(d => {
                        const bim = 'Bim ' + Math.ceil(mn / 2);
                        const diaL = MESAB[mn - 1] + '-' + String(d).padStart(2, '0');
                        filas.push(metr([semL, triL, bim, MESES_ES[mn - 1], diaL], M.dias[d].m));
                    });
                });
            });
        });
        filas.push(metr(['Total', '', '', '', ''], T));
        return { header, filas };
    }
```

(Usa la constante existente `MESES_ES` de g00.php:558 para los nombres completos de mes.)

- [ ] **Step 4: Actualizar `g00ExpPeriodos`** (líneas 1285-1289):

```js
    window.g00ExpPeriodos = function () {
        if (!lastPeriodos) { window.expDataset('Ventas por Periodos', 'Periodos', [], []); return; }
        const r = periodosAOA(lastPeriodos.dias, lastPeriodos.anio);
        window.expDataset('Ventas por Periodos', 'Periodos', r.header, r.filas, proveedorActual);
    };
```

- [ ] **Step 5: Correr test + lint**

Run: `node tests/g00_periodos_export_test.mjs && php -l informes/g00.php`
Expected: `RESULTADO: OK ...` y `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add informes/g00.php tests/g00_periodos_export_test.mjs
git commit -m "feat(export): G00 Periodos a dataset explotado (Sem/Trim/Bim/Mes/Día)"
```

---

### Task 3: G00 — cuadros padre/hijo y mensual a dataset

Reescribe `comparativaAOA` para partir `dim` "Padre / Hijo" en 2 columnas (hoy indenta el hijo en una sola columna), conservando subtotales (filas padre) y Total. Normaliza `mensualAOA` a `{header, filas}`. Reenruta los 7 botones (`g00ExpGrupo/Marca/Tienda/Negocio/Categoria/Genero/Mensual`) por `window.expDataset` con nombres legibles.

**Files:**
- Modify: `informes/g00.php` — `comparativaAOA` (1166-1192), `mensualAOA` (1236-1252), y las funciones `g00Exp*` (1194-1234, 1280-1283)
- Modify: `informes/g00.php` — borrar el stub muerto `window.g00Export` (línea 1333)
- Test: `tests/g00_comparativa_export_test.mjs`

**Interfaces:**
- Consumes: `window.expDataset` (Task 1); helpers `eDif`, `ePct`, `eProm` (existentes).
- Produces: `comparativaAOA(dim, rows, opts) -> {header, filas}`; `mensualAOA(rows, tdas, anio) -> {header, filas}`.

- [ ] **Step 1: Escribir el test que falla** (`tests/g00_comparativa_export_test.mjs`)

```js
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
// Caso padre/hijo (full:false → 12 cols métrica + 2 dim = 14).
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
```

- [ ] **Step 2: Correr el test** (copia pura → debe pasar como golden)

Run: `node tests/g00_comparativa_export_test.mjs`
Expected: `RESULTADO: OK (split padre/hijo + total)` (exit 0)

- [ ] **Step 3: Reemplazar `comparativaAOA` en `informes/g00.php`** (líneas 1166-1192) por la versión del test (idéntica, conservando la indentación de 4 espacios del archivo). Quitar la antigua que devolvía `{header, body}` con `line(r, indent)`.

- [ ] **Step 4: Normalizar `mensualAOA`** (líneas 1236-1252): renombrar la variable de retorno `body` → `filas` y la clave de retorno a `{ header, filas }`. El resto del cuerpo queda igual (una sola dimensión `Mes`, conserva fila Total).

```js
        // ...cuerpo igual, pero:
        const filas = []; let sv = 0, sb = 0, su = 0, sub = 0;
        // ...usar filas.push(...) en vez de body.push(...)
        // ...última línea:
        return { header, filas };
```

- [ ] **Step 5: Reenrutar los 7 botones** por `window.expDataset` con nombres legibles. Reemplazar las 7 funciones (1194-1234 y `g00ExpMensual` 1280-1283):

```js
    window.g00ExpGrupo = function () {
        if (!lastDetal) { window.expDataset('Ventas por Grupo de Tiendas', 'Por Grupo', [], []); return; }
        const r = comparativaAOA('Grupo', lastDetal.por_grupo, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        window.expDataset('Ventas por Grupo de Tiendas', 'Por Grupo', r.header, r.filas, proveedorActual);
    };
    window.g00ExpMarca = function () {
        if (!lastDetal) { window.expDataset('Ventas por Marca y Tipo', 'Por Marca-Tipo', [], []); return; }
        const r = comparativaAOA('Marca / Tipo', lastDetal.por_marca, { anio: lastDetal.anio, full: true,
            totalTdas: { act: lastDetal.kpis.tiendas_actual, ant: lastDetal.kpis.tiendas_anterior } });
        window.expDataset('Ventas por Marca y Tipo', 'Por Marca-Tipo', r.header, r.filas, proveedorActual);
    };
    window.g00ExpTienda = function () {
        if (!lastTiendas) { window.expDataset('Ventas por Tienda', 'Por Tienda', [], []); return; }
        const rows = (lastTiendas.tiendas || []).map(t => ({
            label: t.nombre || t.cod || '', val_act: t.val_act, val_ant: t.val_ant, ups_act: t.ups_act, ups_ant: t.ups_ant, margen: t.margen,
            children: (t.children || []).map(c => ({ label: c.negocio || '', val_act: c.val_act, val_ant: c.val_ant, ups_act: c.ups_act, ups_ant: c.ups_ant, margen: c.margen }))
        }));
        const r = comparativaAOA('Tienda / Negocio', rows, { anio: lastTiendas.anio, full: false });
        window.expDataset('Ventas por Tienda', 'Por Tienda', r.header, r.filas, proveedorActual);
    };
    window.g00ExpNegocio = function () {
        if (!lastProductos) { window.expDataset('Ventas por Negocio', 'Por Negocio', [], []); return; }
        const n = lastProductos.negocios || { rows: [], total: {} };
        const r = comparativaAOA('Negocio / Talla', n.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: n.total.tiendas_act, ant: n.total.tiendas_ant } });
        window.expDataset('Ventas por Negocio', 'Por Negocio', r.header, r.filas, proveedorActual);
    };
    window.g00ExpCategoria = function () {
        if (!lastProductos) { window.expDataset('Ventas por Categoría', 'Por Categoria', [], []); return; }
        const c = lastProductos.categorias || { rows: [], total: {} };
        const r = comparativaAOA('Categoría / Subcategoría', c.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: c.total.tiendas_act, ant: c.total.tiendas_ant } });
        window.expDataset('Ventas por Categoría', 'Por Categoria', r.header, r.filas, proveedorActual);
    };
    window.g00ExpGenero = function () {
        if (!lastProductos) { window.expDataset('Ventas por Género', 'Por Genero', [], []); return; }
        const g = lastProductos.generos || { rows: [], total: {} };
        const r = comparativaAOA('Género / Público', g.rows, { anio: lastProductos.anio, full: true,
            totalTdas: { act: g.total.tiendas_act, ant: g.total.tiendas_ant } });
        window.expDataset('Ventas por Género', 'Por Genero', r.header, r.filas, proveedorActual);
    };
    window.g00ExpMensual = function () {
        if (!lastDetal) { window.expDataset('Ventas Mensuales', 'Mensual', [], []); return; }
        const r = mensualAOA(lastDetal.mensual, lastDetal.mensual_tdas, lastDetal.anio);
        window.expDataset('Ventas Mensuales', 'Mensual', r.header, r.filas, proveedorActual);
    };
```

- [ ] **Step 6: Borrar el stub muerto** `window.g00Export = function () { alert('Export a Excel/PDF: pendiente.'); };` (línea 1333) y el helper `exportAOA`/`expFilename` (1148-1159) que ya no se usan.

- [ ] **Step 7: Correr test + lint**

Run: `node tests/g00_comparativa_export_test.mjs && php -l informes/g00.php`
Expected: `RESULTADO: OK ...` y `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add informes/g00.php tests/g00_comparativa_export_test.mjs
git commit -m "feat(export): G00 padre/hijo y mensual a dataset + nombres legibles"
```

---

### Task 4: O45 — export desde el dato (no DOM)

`o45Export` deja de volcar el DOM (`#o45-tbl`) y se construye desde `window.__o45last`. Dataset plano: una fila por negocio con las 11 columnas de `COLS`, valores numéricos crudos, conservando la fila TOTAL.

**Files:**
- Modify: `informes/o45.php` — `o45Export` (148-157); agregar builder `o45AOA` justo antes
- Test: `tests/o45_export_test.mjs`

**Interfaces:**
- Consumes: `window.expDataset` (Task 1); `window.__o45last = {filas:[], total:{}}` (existente, set en `o45Load`).
- Produces: `o45AOA(d) -> {header, filas}`.

- [ ] **Step 1: Escribir el test que falla** (`tests/o45_export_test.mjs`)

```js
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
```

- [ ] **Step 2: Correr el test** (copia pura → pasa como golden)

Run: `node tests/o45_export_test.mjs`
Expected: `RESULTADO: OK (o45 dataset + total)` (exit 0)

- [ ] **Step 3: Reemplazar `o45Export`** (148-157) e insertar `o45AOA` antes:

```js
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
    window.o45Export = function(){
      const d = window.__o45last;
      if (!d || !(d.filas||[]).length) { window.expDataset('Índice de Ventas', 'Indice', [], []); return; }
      const r = o45AOA(d);
      window.expDataset('Índice de Ventas', 'Indice', r.header, r.filas);
    };
```

- [ ] **Step 4: Correr test + lint**

Run: `node tests/o45_export_test.mjs && php -l informes/o45.php`
Expected: `RESULTADO: OK ...` y `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add informes/o45.php tests/o45_export_test.mjs
git commit -m "feat(export): O45 desde el dato (dataset + total), nombre legible"
```

---

### Task 5: O14 — recomendaciones desde el dato + b/c y nombres

Las 3 matrices de recomendaciones dejan de volcar el DOM y se construyen desde `lastReco` con el builder `recoAOA` (matriz negocio×talla + TOTAL, conserva el TOTAL). El botón principal `o14Export` para tabs b/c se reenruta por `expDataset`, y para la pestaña reco exporta las 3 matrices en 3 hojas.

**Files:**
- Modify: `informes/o14.php` — agregar `recoAOA`; reescribir `o14Export` (564-603) y `o14RecoExp` (390-396)
- Test: `tests/o14_reco_export_test.mjs`

**Interfaces:**
- Consumes: `window.expDataset`, `window.expFile` (Task 1); `lastReco`, `recoFiltered(data)`, `RECO_MED`, `MED_LABEL`, `lastData` (existentes en o14.php).
- Produces: `recoAOA(data, medida) -> {header, filas}`.

- [ ] **Step 1: Escribir el test que falla** (`tests/o14_reco_export_test.mjs`)

```js
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
```

- [ ] **Step 2: Correr el test** (copia pura → pasa como golden)

Run: `node tests/o14_reco_export_test.mjs`
Expected: `RESULTADO: OK (reco matriz + total)` (exit 0)

- [ ] **Step 3: Insertar `recoAOA`** en `informes/o14.php` (junto al bloque reco, p.ej. tras `renderRecoMatrices`, ~línea 389):

```js
  // Construye la matriz negocio×talla de una medida de reco como dataset (con fila TOTAL).
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
```

- [ ] **Step 4: Reescribir `o14RecoExp`** (390-396):

```js
  const RECO_CUADRO = { sobrante:'Recomendación - Reubicación Sobrante', faltante:'Recomendación - Faltante', proveedor:'Recomendación - Solicitud a Proveedor' };
  window.o14RecoExp = function(i){
    const med = RECO_MED[i];
    if(!lastReco){ window.expDataset(RECO_CUADRO[med], 'Reco', [], []); return; }
    const r = recoAOA(recoFiltered(lastReco), med);
    window.expDataset(RECO_CUADRO[med], 'Reco', r.header, r.filas);
  };
```

- [ ] **Step 5: Reescribir `o14Export`** (564-603):

```js
  window.o14Export = function(){
    if(currentTab !== 'b' && currentTab !== 'c'){
      // Reco: las 3 matrices, cada una en su hoja.
      if(!lastReco){ window.expDataset('Recomendaciones', 'Reco', [], []); return; }
      if(typeof XLSX === 'undefined'){ Swal.fire('Exportar','No se cargó el componente de Excel.','error'); return; }
      const dataR = recoFiltered(lastReco);
      const wb = XLSX.utils.book_new();
      const HOJA = { sobrante:'Sobrante', faltante:'Faltante', proveedor:'Proveedor' };
      RECO_MED.forEach(med => { const r = recoAOA(dataR, med);
        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([r.header, ...r.filas]), HOJA[med]); });
      XLSX.writeFile(wb, window.expFile('Recomendaciones'));
      return;
    }
    const data = lastData[currentTab];
    if(!data){ window.expDataset('Siembra Stock Ventas', 'O14', [], []); return; }
    const tallas = data.tallas||[], medidas = data.medidas||[];
    const mlabel = m => (MED_LABEL[m]||m);
    let header, filas = [];
    if(currentTab === 'c'){
      header = ['Grupo','Almacen','Negocio','Medida','Talla','Valor'];
      (data.grupos||[]).forEach(gr=>{ (gr.almacenes||[]).forEach(a=>{ const alm=(a.bodega||'')+(a.nombre?(' · '+a.nombre):'');
        (a.negocios||[]).forEach(n=>{ medidas.forEach(m=>{ const o=(n.valores||{})[m]||{};
          tallas.forEach(t=>{ const v=o[t]||0; if(v) filas.push([gr.grupo, alm, n.negocio, mlabel(m), t, v]); }); }); }); }); });
    } else {
      header = ['Negocio','Medida','Talla','Valor'];
      (data.filas||[]).forEach(fila=>{ const neg=(fila.key&&fila.key.negocio)||'';
        medidas.forEach(m=>{ const o=(fila.valores||{})[m]||{};
          tallas.forEach(t=>{ const v=o[t]||0; if(v) filas.push([neg, mlabel(m), t, v]); }); }); });
    }
    window.expDataset('Siembra Stock Ventas', 'O14', header, filas);
  };
```

- [ ] **Step 6: Borrar `tableToAOA`** (546-561) si ya no tiene otros usos.

Run: `grep -n tableToAOA informes/o14.php`
Expected: 0 referencias restantes tras el reemplazo → borrar la definición. (Si quedara alguna, no borrar.)

- [ ] **Step 7: Correr test + lint**

Run: `node tests/o14_reco_export_test.mjs && php -l informes/o14.php`
Expected: `RESULTADO: OK ...` y `No syntax errors detected`

- [ ] **Step 8: Commit**

```bash
git add informes/o14.php tests/o14_reco_export_test.mjs
git commit -m "feat(export): O14 reco desde el dato + b/c y nombres legibles"
```

---

### Task 6: Evol y Pagos — reenrutar + eliminar fallos mudos

`evolExport`, `pgExport` y `pgGenExport` ya producen dataset largo. Solo se reenrutan por `window.expDataset` (nombre legible) y se **elimina el `return` silencioso** de Pagos. Sin cambio de forma → verificación por `php -l` + E2E navegador (no hay builder puro nuevo que aislar).

**Files:**
- Modify: `informes/evol.php` — `evolExport` (164-181)
- Modify: `informes/pagos.php` — `pgExport` (175-199) y `pgGenExport` (252-264)

**Interfaces:**
- Consumes: `window.expDataset` (Task 1); objetos `window.__evollast`, `pgData`, `pgGenData`, helpers `pgBuildGroups`, `MESES_PG`/`MES_PG`, `fmtMesHdr`, `MEDIDAS` (existentes).

- [ ] **Step 1: Reescribir `evolExport`** (`informes/evol.php` 164-181):

```js
  window.evolExport = function(){
    const d = window.__evollast;
    if(!d){ window.expDataset('Evolución Histórica', 'Evolucion', [], []); return; }
    const meses = d.meses||[], negs = d.negocios||[];
    const header = ['Negocio','Concepto','Mes','Valor'];
    const filas = [];
    negs.forEach(n=>{ MEDIDAS.forEach(med=>{ const serie=(n.valores||{})[med.k]||{};
      meses.forEach(m=>{ const v=serie[m]; if(v!==undefined && v!==null && v!=='') filas.push([n.negocio, med.t, fmtMesHdr(m), v]); }); }); });
    window.expDataset('Evolución Histórica', 'Evolucion', header, filas);
  };
```

- [ ] **Step 2: Reescribir `pgExport`** (`informes/pagos.php` 175-199) — quitar `if (!pgData) return;` mudo:

```js
  function pgExport() {
    if (!pgData) { window.expDataset('Resumen Proyectado de Pagos', 'Pagos', [], []); return; }
    const d = pgData;
    const { meses, grupos } = pgBuildGroups(d);
    const header = ['RAZON_SOCIAL', 'Fecha vencimiento', 'Anio', 'Mes', 'En Pesos'];
    const filas = [];
    Object.keys(grupos).sort().forEach(fch => {
      const g = grupos[fch];
      meses.forEach(m => { const v = g.cells[m.anio + '-' + m.mes] || 0;
        if (v) filas.push([d.razon_social || '', fch, m.anio, MESES_PG[m.mes - 1], v]); });
    });
    window.expDataset('Resumen Proyectado de Pagos', 'Pagos', header, filas, d.razon_social);
  }
```

- [ ] **Step 3: Reescribir `pgGenExport`** (`informes/pagos.php` 252-264) — quitar `if (!pgGenData) return;` mudo:

```js
  function pgGenExport() {
    if (!pgGenData) { window.expDataset('Pagos Generados', 'Pagos Generados', [], []); return; }
    const header = ['Anio','Mes','Dia','Valor Total','Dias Vencidos'];
    const filas = (pgGenData.nodos||[]).filter(n => n.nivel === 3).map(n => [n.anio, MES_PG[(n.mes||1)-1] || n.mes, n.dia, n.valor, n.dias]);
    const prov = (pgGenData.razon_social || (pgData && pgData.razon_social) || '');
    window.expDataset('Pagos Generados', 'Pagos Generados', header, filas, prov);
  }
```

(Si `pgGenData.razon_social` no existe, cae a `pgData.razon_social`; si ninguno, `expFile` usa `window.PROVEEDOR_ACTUAL`.)

- [ ] **Step 4: Lint**

Run: `php -l informes/evol.php && php -l informes/pagos.php`
Expected: `No syntax errors detected` en ambos

- [ ] **Step 5: Commit**

```bash
git add informes/evol.php informes/pagos.php
git commit -m "fix(export): Evol/Pagos reenrutados + sin returns silenciosos"
```

---

### Task 7: Verificación E2E + despliegue

**Files:** ninguno (verificación + deploy)

- [ ] **Step 1: Correr toda la suite node de exports + lint global**

Run:
```bash
node tests/export_filename_test.mjs && node tests/g00_periodos_export_test.mjs && \
node tests/g00_comparativa_export_test.mjs && node tests/o45_export_test.mjs && \
node tests/o14_reco_export_test.mjs && \
php -l dashboard.php && php -l informes/g00.php && php -l informes/o14.php && \
php -l informes/o45.php && php -l informes/evol.php && php -l informes/pagos.php
```
Expected: 5× `RESULTADO: OK` + 6× `No syntax errors detected`

- [ ] **Step 2: E2E navegador (Rafael) en `localhost/plataforma_20`** — checklist por botón:
  - G00 (8 cuadros): descarga; nombre `Ventas por … - <prov> - <fecha>.xlsx` sin códigos; Periodos con columnas `Semestre/Trimestre/Bimestre/Mes/Día` separadas; padre/hijo en 2 columnas; subtotales y Total presentes.
  - O14 (b/c, 3 reco, y botón principal en pestaña reco → 3 hojas).
  - O45, Evol: descargan, nombre legible, TOTAL (O45) presente.
  - Pagos (2): descargan **con datos** y, **sin** cargar, muestran el aviso (ya no quedan mudos).

- [ ] **Step 3: Re-sync a `plataforma_20_produccion`** (tras OK de Rafael): copiar runtime tocado (`dashboard.php`, `informes/{g00,o14,o45,evol,pagos}.php`), verificar idénticos por hash + `php -l`. Los tests `.mjs` no se despliegan. No tocar `conexion/` ni `cache/`.

- [ ] **Step 4: Merge + push** (preguntar a Rafael: ¿rama+PR o directo a main?). Actualizar `changelog_plataforma20.md` y memorias.

---

## Notas de implementación

- **Convención de tests:** los `.mjs` **copian** la función pura (igual que `tests/o14_kpis_arbol_test.mjs`). Al editar una builder en el `.php`, replicar el cambio en su `.mjs` (mismo cuerpo) para que no haya drift.
- **`proveedorActual` vs `window.PROVEEDOR_ACTUAL`:** G00 y Pagos pasan el proveedor explícito a `expDataset`; O14/O45/Evol omiten el arg y `expFile` cae a `window.PROVEEDOR_ACTUAL`.
- **Riesgo de drift de líneas:** los números de línea son del estado actual; localizar por nombre de función si difieren.
