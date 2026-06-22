# "Resumen de Pagos Generados" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Añadir una segunda tabla "Resumen de Pagos Generados" (árbol Año→Mes→Día) a la página Análisis de Pagos, alimentada por las tablas de pagos generados filtradas por NIT.

**Architecture:** Endpoint dedicado `api/informe_pagos_generados.php` que une `PAGOS_FECHA_VCTO_PBI` + `HIST_PAGOS_FECHA_VCTO_PBI` filtradas por NIT, agrega por día en SQL y arma en PHP el árbol Año→Mes→Día (lista plana de nodos). El front (`informes/pagos.php`) hace una segunda llamada y dibuja un árbol desplegable reutilizando el patrón del informe de Periodos del G00.

**Tech Stack:** PHP + `sqlsrv` (RDS SIESA), JS vanilla, SheetJS (XLSX) y SweetAlert (Swal) ya cargados.

## Global Constraints

- El NIT se toma SIEMPRE de `$_SESSION['nit']`, NUNCA de un parámetro del cliente.
- Todas las lecturas SQL usan `WITH (NOLOCK)`; parámetros vía placeholders `?` (sin interpolar variables).
- Conexión `conexion/conexion_integracion.php` → `$dbConnect`; reintentar la conexión hasta 4× (el RDS rechaza conexiones rápidas intermitentemente), igual que `api/informe_pagos.php`.
- Filtro por NIT con `RTRIM`. Las tablas `*_PBI` viven en `Integracion.dbo`.
- Respuesta JSON UTF-8: `header('Content-Type: application/json; charset=utf-8')`, `json_encode(..., JSON_UNESCAPED_UNICODE)`. Éxito `{ok:true,...}`, error `{ok:false,"error":...}`. Errores SQL → `error_log` + mensaje genérico (no filtrar SQL al cliente).
- Prefijo del front: `pgGen` / ids `pg-gen-*` (no colisionar con la matriz `pg*` existente).
- DÍAS VENCIDOS = promedio simple de días de mora por documento = `SUM(sumdias)/SUM(n)` por nivel (roll-up sumando, NO promediando promedios).

---

## File Structure

- **Create** `api/informe_pagos_generados.php` — endpoint del árbol de pagos generados.
- **Create** `tests/_endpoint_run_generados.php` — harness (setea `$_SESSION['nit']`, incluye el endpoint).
- **Create** `tests/pagos_generados_test.php` — test del árbol (estructura, roll-up, promedio, aislamiento, filtro fecha).
- **Modify** `informes/pagos.php` — segunda `.card` + JS (`pgGenRender`, `pgGenToggle`, `pgGenExport`) + segunda llamada en `pgLoad`.

**Contrato del endpoint (fijo entre API, tests y front):**
```json
{
  "ok": true,
  "nit": "914480000",
  "nodos": [
    {"id":1,"pid":null,"nivel":1,"label":"2026","anio":2026,"mes":null,"dia":null,"valor":1357209285.0,"dias":173.75},
    {"id":2,"pid":1,"nivel":2,"label":"Mayo","anio":2026,"mes":5,"dia":null,"valor":236407910.0,"dias":150.0},
    {"id":3,"pid":2,"nivel":3,"label":"22/05/2026","anio":2026,"mes":5,"dia":"2026-05-22","valor":156664099.0,"dias":199.40}
  ],
  "total": {"valor": 1357209285.0, "dias": 173.75}
}
```
`nodos` viene en orden de pre-orden (Año, luego sus Meses, cada Mes seguido de sus Días) para render directo. `nivel`: 1=Año, 2=Mes, 3=Día. Años ordenados descendente; meses calendario asc; días asc.

---

## Task 1: Endpoint del árbol de Pagos Generados

**Files:**
- Create: `api/informe_pagos_generados.php`
- Create: `tests/_endpoint_run_generados.php`
- Create: `tests/pagos_generados_test.php`

**Interfaces:**
- Produces: GET `api/informe_pagos_generados.php?fdesde=&fhasta=` → `{ok,nit,nodos:[...],total:{valor,dias}}` (ver contrato arriba).
- Consumes: `conexion/conexion_integracion.php` → `$dbConnect`, `$servidor`, `$infoconn`.

- [ ] **Step 1: Crear el harness**

Crear `tests/_endpoint_run_generados.php`:
```php
<?php
// php tests/_endpoint_run_generados.php "NIT" "querystring"
error_reporting(E_ALL); ini_set('display_errors', '0');
$nit = $argv[1] ?? '';
$qs  = $argv[2] ?? '';
session_start();
$_SESSION = ['usuario' => 'test', 'nit' => $nit];
parse_str($qs, $_GET);
include __DIR__ . '/../api/informe_pagos_generados.php';
```

- [ ] **Step 2: Escribir el test que falla**

Crear `tests/pagos_generados_test.php` (NIT con datos: `914480000` = CURTIEMBRE RUFINO MELERO):
```php
<?php
// php tests/pagos_generados_test.php
$php=PHP_BINARY; $runner=__DIR__.'/_endpoint_run_generados.php';
$nul=(stripos(PHP_OS,'WIN')===0)?'NUL':'/dev/null';
$NIT=getenv('PAGOS_NIT_A')?:'914480000';
function call_ep($php,$runner,$nit,$qs,$nul){$cmd=escapeshellarg($php).' '.escapeshellarg($runner).' '.escapeshellarg($nit).' '.escapeshellarg($qs).' 2>'.$nul;$r=null;for($i=0;$i<4;$i++){$raw=(string)shell_exec($cmd);$a=strpos($raw,'{');$b=strrpos($raw,'}');$r=json_decode(($a!==false&&$b!==false)?substr($raw,$a,$b-$a+1):$raw,true);if(is_array($r)&&!(isset($r['ok'])&&$r['ok']===false&&stripos($r['error']??'','onexi')!==false))return $r;usleep(400000);}return $r;}
$fail=0;
$d=call_ep($php,$runner,$NIT,'',$nul);
if(!($d['ok']??false)){echo "FALLO: ok=0 ".json_encode($d['error']??'')."\nRESULTADO: FALLO\n";exit(1);}
$nodos=$d['nodos']??null;
if(!is_array($nodos)||!count($nodos)){echo "FALLO: nodos vacío\n";$fail=1;}
$by=[]; foreach(($nodos??[]) as $n)$by[$n['id']]=$n;
// niveles y jerarquía coherentes
foreach(($nodos??[]) as $n){
  if(!in_array($n['nivel'],[1,2,3],true)){echo "FALLO: nivel inválido\n";$fail=1;break;}
  if($n['nivel']===1 && $n['pid']!==null){echo "FALLO: año con pid\n";$fail=1;break;}
  if($n['nivel']>1 && !isset($by[$n['pid']])){echo "FALLO: pid inexistente\n";$fail=1;break;}
  if($n['nivel']>1 && $by[$n['pid']]['nivel']!==$n['nivel']-1){echo "FALLO: pid de nivel incorrecto\n";$fail=1;break;}
}
// roll-up: valor de cada año = suma de sus meses = suma de sus días
function hijos($nodos,$pid){return array_values(array_filter($nodos,fn($x)=>$x['pid']===$pid));}
foreach(array_filter($nodos,fn($x)=>$x['nivel']===1) as $anio){
  $meses=hijos($nodos,$anio['id']); $sm=0; foreach($meses as $m)$sm+=$m['valor'];
  if(abs($sm-$anio['valor'])>1){echo "FALLO: valor año != suma meses (".$anio['label'].")\n";$fail=1;break;}
  foreach($meses as $m){$dias=hijos($nodos,$m['id']); $sd=0; foreach($dias as $x)$sd+=$x['valor'];
    if(abs($sd-$m['valor'])>1){echo "FALLO: valor mes != suma días\n";$fail=1;break 2;}}
}
// total = suma de años
$sa=0; foreach(array_filter($nodos,fn($x)=>$x['nivel']===1) as $a)$sa+=$a['valor'];
if(abs($sa-($d['total']['valor']??0))>1){echo "FALLO: total != suma años\n";$fail=1;}
// aislamiento: NIT inexistente -> sin nodos; sin NIT -> ok:false
$z=call_ep($php,$runner,'',' ',$nul);
if(($z['ok']??true)!==false){echo "FALLO: sin NIT debería ser ok:false\n";$fail=1;}
echo $fail?"RESULTADO: FALLO\n":"RESULTADO: OK (árbol coherente + roll-up + total + guard NIT)\n";
exit($fail);
```

- [ ] **Step 3: Verificar que falla**

Run: `php tests/pagos_generados_test.php`
Expected: FALL — el endpoint aún no existe.

- [ ] **Step 4: Implementar el endpoint**

Crear `api/informe_pagos_generados.php`:
```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit; }
$nit = trim($_SESSION['nit'] ?? '');
if ($nit === '') { echo json_encode(['ok'=>false,'error'=>'No se pudo resolver el NIT del proveedor']); exit; }

$fdesde = trim($_GET['fdesde'] ?? '');
$fhasta = trim($_GET['fhasta'] ?? '');

require __DIR__ . '/../conexion/conexion_integracion.php';
for ($i=0; $dbConnect === false && $i < 4; $i++) { usleep(300000); $dbConnect = sqlsrv_connect($servidor, $infoconn); }
if ($dbConnect === false) { echo json_encode(['ok'=>false,'error'=>'Conexión DB fallida']); exit; }

$where = "WHERE RTRIM(NIT) = ?";
$params = [$nit];
if ($fdesde !== '') { $where .= " AND CONVERT(date, FECHA) >= ?"; $params[] = $fdesde; }
if ($fhasta !== '') { $where .= " AND CONVERT(date, FECHA) <= ?"; $params[] = $fhasta; }

$sql = "
SELECT CONVERT(date, FECHA) dia, SUM(VALOR_NETO) valor, COUNT(*) n,
       SUM(DATEDIFF(day, FECHA_VCTO, FECHA)) sumdias
FROM (
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
    UNION ALL
    SELECT FECHA, FECHA_VCTO, VALOR_NETO, NIT FROM Integracion.dbo.HIST_PAGOS_FECHA_VCTO_PBI WITH (NOLOCK)
) u
$where
GROUP BY CONVERT(date, FECHA)
ORDER BY dia";

$stmt = sqlsrv_query($dbConnect, $sql, $params);
if ($stmt === false) { error_log(json_encode(sqlsrv_errors())); echo json_encode(['ok'=>false,'error'=>'Consulta fallida']); exit; }

// Agregados por día -> estructura años/meses/días con sum(valor), sum(n), sum(sumdias)
$tree = []; // [anio][mes][diaStr] = ['valor'=>,'n'=>,'sumdias'=>]
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $diaObj = $r['dia']; // DateTime
    $diaStr = is_object($diaObj) ? $diaObj->format('Y-m-d') : (string)$diaObj;
    $anio = (int)substr($diaStr,0,4); $mes = (int)substr($diaStr,5,2);
    $tree[$anio][$mes][$diaStr] = ['valor'=>(float)$r['valor'], 'n'=>(int)$r['n'], 'sumdias'=>(float)$r['sumdias']];
}
sqlsrv_close($dbConnect);

$MES = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$nodos = []; $id = 0; $totV = 0; $totN = 0; $totSD = 0;
krsort($tree); // años descendente
foreach ($tree as $anio => $meses) {
    $aV=0; $aN=0; $aSD=0; $anioId = ++$id; $anioIdx = count($nodos);
    $nodos[] = ['id'=>$anioId,'pid'=>null,'nivel'=>1,'label'=>(string)$anio,'anio'=>$anio,'mes'=>null,'dia'=>null,'valor'=>0,'dias'=>0];
    ksort($meses); // meses calendario asc
    foreach ($meses as $mes => $dias) {
        $mV=0; $mN=0; $mSD=0; $mesId = ++$id; $mesIdx = count($nodos);
        $nodos[] = ['id'=>$mesId,'pid'=>$anioId,'nivel'=>2,'label'=>$MES[$mes],'anio'=>$anio,'mes'=>$mes,'dia'=>null,'valor'=>0,'dias'=>0];
        ksort($dias);
        foreach ($dias as $diaStr => $agg) {
            $dt = DateTime::createFromFormat('Y-m-d', $diaStr);
            $label = $dt ? $dt->format('d/m/Y') : $diaStr;
            $dDias = $agg['n'] > 0 ? $agg['sumdias']/$agg['n'] : 0;
            $nodos[] = ['id'=>++$id,'pid'=>$mesId,'nivel'=>3,'label'=>$label,'anio'=>$anio,'mes'=>$mes,'dia'=>$diaStr,
                        'valor'=>$agg['valor'],'dias'=>round($dDias,2)];
            $mV+=$agg['valor']; $mN+=$agg['n']; $mSD+=$agg['sumdias'];
        }
        $nodos[$mesIdx]['valor'] = $mV; $nodos[$mesIdx]['dias'] = $mN>0 ? round($mSD/$mN,2) : 0;
        $aV+=$mV; $aN+=$mN; $aSD+=$mSD;
    }
    $nodos[$anioIdx]['valor'] = $aV; $nodos[$anioIdx]['dias'] = $aN>0 ? round($aSD/$aN,2) : 0;
    $totV+=$aV; $totN+=$aN; $totSD+=$aSD;
}

echo json_encode([
    'ok'=>true, 'nit'=>$nit, 'nodos'=>$nodos,
    'total'=>['valor'=>$totV, 'dias'=>$totN>0 ? round($totSD/$totN,2) : 0],
], JSON_UNESCAPED_UNICODE);
```

- [ ] **Step 5: Verificar que pasa**

Run: `php tests/pagos_generados_test.php`
Expected: `RESULTADO: OK`. (Si el NIT no tiene datos, exportar `PAGOS_NIT_A` con uno que sí; 914480000 tiene historial.)

- [ ] **Step 6: Commit**

```bash
git add api/informe_pagos_generados.php tests/_endpoint_run_generados.php tests/pagos_generados_test.php
git commit -m "feat(pagos): endpoint arbol Pagos Generados (Anio/Mes/Dia) por NIT"
```

---

## Task 2: Tabla "Resumen de Pagos Generados" en la página

**Files:**
- Modify: `informes/pagos.php` (segunda `.card` + JS)

**Interfaces:**
- Consumes: `api/informe_pagos_generados.php` (`{ok,nit,nodos,total}`); `pgParams()` existente (para el rango fecha); `XLSX`, `Swal`.
- Produces: `pgGenLoad()` / `pgGenRender(d)` / `pgGenToggle(id,el)` / `pgGenExport()`. `pgLoad()` llama también a `pgGenLoad()`.

- [ ] **Step 1: Añadir la segunda tarjeta (HTML)**

En `informes/pagos.php`, después de la `.card` de la matriz de flujo (tras su `</div>` de cierre) y antes del `</div>` de `#page-informes-pagos`, insertar:
```html
  <div class="card">
    <div class="card-title">Resumen de Pagos Generados<button class="g00-btn-export" onclick="pgGenExport()">&#10515; Excel</button></div>
    <div style="overflow-x:auto;"><table id="pg-gen-tabla" class="disp-table"></table></div>
  </div>
```

- [ ] **Step 2: Añadir el JS del árbol**

Dentro del `<script>` (IIFE) de `informes/pagos.php`, antes del cierre `})();`, añadir:
```js
  let pgGenData = null;
  const MES_PG = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  const pgDias = (n) => Number(n||0).toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2});

  function pgGenLoad() {
    fetch('api/informe_pagos_generados.php?' + pgParams(), { credentials:'same-origin' })
      .then(r => r.json())
      .then(d => { if (!d.ok) { if (window.Swal) Swal.fire('Pagos Generados', d.error||'Error', 'error'); return; }
        pgGenData = d; pgGenRender(d); })
      .catch(e => { if (window.Swal) Swal.fire('Pagos Generados', 'No se pudo cargar: ' + e.message, 'error'); });
  }

  function pgGenRender(d) {
    const nodos = d.nodos || [];
    if (!nodos.length) { document.getElementById('pg-gen-tabla').innerHTML = '<tbody><tr><td style="text-align:center;color:var(--text-light);padding:20px;">Sin datos</td></tr></tbody>'; return; }
    const hijos = {}; nodos.forEach(n => { const k = n.pid===null?'root':n.pid; (hijos[k] ??= []).push(n); });
    let h = '<thead><tr><th>FECHA</th><th class="num">VALOR TOTAL</th><th class="num">D&Iacute;AS VENCIDOS</th></tr></thead><tbody>';
    function emit(n) {
      const tiene = !!hijos[n.id];
      const pad = 'padding-left:' + (4 + (n.nivel-1)*18) + 'px;';
      const disp = n.nivel===1 ? '' : 'display:none;';
      const caret = tiene ? '<span class="g00-caret">&#9656;</span>' : '';
      const onclk = tiene ? ' onclick="pgGenToggle(' + n.id + ',this)"' : '';
      const cls = tiene ? 'g00-marca-row g00-collapsed' : '';
      h += '<tr class="' + cls + '" data-rid="' + n.id + '" data-pid="' + (n.pid===null?'':n.pid) + '" data-lvl="' + n.nivel + '" style="' + disp + '"' + onclk + '>'
         + '<td style="' + pad + '">' + caret + n.label + '</td>'
         + '<td class="num">' + pgMoney(n.valor) + '</td>'
         + '<td class="num">' + pgDias(n.dias) + '</td></tr>';
      (hijos[n.id]||[]).forEach(emit);
    }
    (hijos['root']||[]).forEach(emit);
    h += '<tr class="g00-total"><td>Total</td><td class="num">' + pgMoney(d.total.valor) + '</td><td class="num">' + pgDias(d.total.dias) + '</td></tr>';
    h += '</tbody>';
    document.getElementById('pg-gen-tabla').innerHTML = h;
  }

  window.pgGenToggle = function (id, el) {
    const collapsed = el.classList.toggle('g00-collapsed');
    // mostrar/ocultar descendientes recursivamente: al colapsar, ocultar todo el subárbol
    const tabla = document.getElementById('pg-gen-tabla');
    function setHijos(pid, show) {
      tabla.querySelectorAll('tr[data-pid="' + pid + '"]').forEach(tr => {
        tr.style.display = show ? '' : 'none';
        const rid = tr.getAttribute('data-rid');
        if (!show) { tr.classList.add('g00-collapsed'); const c = tr.querySelector('.g00-caret'); if (c) c.innerHTML = '&#9656;'; setHijos(rid, false); }
      });
    }
    setHijos(id, !collapsed);
    const caret = el.querySelector('.g00-caret'); if (caret) caret.innerHTML = collapsed ? '&#9656;' : '&#9662;';
  };

  function pgGenExport() {
    if (!pgGenData) return;
    if (typeof XLSX === 'undefined') { if (window.Swal) Swal.fire('Exportar','No se pudo cargar Excel.','error'); return; }
    const rows = (pgGenData.nodos||[]).map(n => [ ('  '.repeat(n.nivel-1)) + n.label, n.valor, n.dias ]);
    rows.push(['Total', pgGenData.total.valor, pgGenData.total.dias]);
    const ws = XLSX.utils.aoa_to_sheet([['FECHA','VALOR TOTAL','DÍAS VENCIDOS'], ...rows]);
    const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Pagos Generados');
    const fecha = new Date().toISOString().slice(0,10);
    XLSX.writeFile(wb, 'Pagos_Generados_' + fecha + '.xlsx');
  }
  window.pgGenLoad = pgGenLoad; window.pgGenExport = pgGenExport;
```

- [ ] **Step 3: Encadenar la carga en `pgLoad`**

En `pgLoad()` (función existente), dentro del `.then(d => {...})` tras `pgRender(d);`, añadir la segunda carga:
```js
        pgRender(d);
        pgGenLoad();
```
(Así ambas tablas se cargan al aplicar filtros; `pgGenLoad` usa el mismo `pgParams()` para compartir el rango Fecha.)

- [ ] **Step 4: Verificar en navegador**

Abrir `http://localhost/plataforma_20/dashboard.php` → login proveedor (ej. el usuario que mapea a NIT 914480000) → PAGOS → Análisis de Pagos. Esperado: bajo la matriz aparece "Resumen de Pagos Generados" con años colapsados; al hacer clic en un año despliega meses, y en un mes despliega días; VALOR TOTAL y DÍAS VENCIDOS coherentes; fila Total amarilla; export Excel descarga el árbol.

- [ ] **Step 5: Commit**

```bash
git add informes/pagos.php
git commit -m "feat(pagos): tabla Resumen de Pagos Generados (arbol Anio/Mes/Dia) en la pagina"
```

---

## Validación final
- Comparar VALOR TOTAL y DÍAS VENCIDOS de un proveedor (ej. 914480000) contra el Power BI P78.
- Confirmar con Rafael si DÍAS VENCIDOS debe ser promedio ponderado por valor (hoy es promedio simple por documento); si sí, cambiar a `SUM(VALOR_NETO*DATEDIFF)/SUM(VALOR_NETO)` en el SQL y el roll-up.
- Registrar en `changelog_plataforma20.md`.
