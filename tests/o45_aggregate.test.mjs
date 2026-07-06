import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import assert from 'node:assert/strict';
import { aggregateO45 } from '../informes/o45_aggregate.js';

const here = dirname(fileURLToPath(import.meta.url));
const fx = join(here, 'fixtures', 'o45');
const slugs = readdirSync(fx).filter(f => f.startsWith('dataset_')).map(f => f.slice('dataset_'.length, -'.json'.length));

const NUM = ['ventas','tiendas','ventas30','stock_cedi','stock_tiendas','total_stock','ind_inventario','ind_ventas_mes','tallas'];
const norm = v => v == null ? null : (typeof v === 'number' ? Math.round(v*100)/100 : v);

// Combos de filtros a probar (vacío = todo; y un par de subconjuntos derivados del propio dataset).
function combos(dataset) {
    const col = i => dataset.filas.map(r => r[i]);
    const idx = n => dataset.columnas.indexOf(n);
    const uniq = a => [...new Set(a)].filter(x => x !== '' && x != null);
    const marcas = uniq(col(idx('marca')));
    const grupos = uniq(col(idx('grupo')));
    return [
        {},                                              // sin filtros
        marcas.length ? { marca: [marcas[0]] } : {},     // una marca
        grupos.length ? { grupo: [grupos[0]] } : {},     // un grupo
    ];
}

// Clave-tupla COMPLETA de una fila (negocio + ref + color + todas las medidas normalizadas).
// No se puede usar `negocio` como clave: un ref-color puede existir bajo 2 cías (tab=data agrupa
// por cia,negocio,ref,color) -> 2 filas con el mismo negocio. Se compara como MULTISET de tuplas.
function rowKey(f) { return [f.negocio, f.referencia, f.color, ...NUM.map(c => norm(f[c]))].join('|'); }
function compara(got, oracle, ctx) {
    assert.equal(got.filas.length, oracle.filas.length, `${ctx}: #filas difiere`);
    const gc = new Map(); for (const f of got.filas)   { const k = rowKey(f); gc.set(k, (gc.get(k) || 0) + 1); }
    const oc = new Map(); for (const f of oracle.filas) { const k = rowKey(f); oc.set(k, (oc.get(k) || 0) + 1); }
    for (const [k, c] of oc) assert.equal(gc.get(k) || 0, c, `${ctx}: fila del oráculo sin match exacto -> ${k}`);
    for (const k of gc.keys()) assert.ok(oc.has(k), `${ctx}: fila extra en got -> ${k}`);
    for (const c of ['ventas','tiendas','total_stock','ind_ventas_mes'])
        assert.equal(norm(got.total[c]), norm(oracle.total[c]), `${ctx}: total.${c}`);
}

let n = 0;
for (const slug of slugs) {
    const dataset = JSON.parse(readFileSync(join(fx, `dataset_${slug}.json`)));
    // Caso SIN filtros vs oráculo sin filtros.
    const oracleSinFiltro = JSON.parse(readFileSync(join(fx, `tabdata_${slug}.json`)));
    compara(aggregateO45(dataset, {}), oracleSinFiltro, `${slug}[sinfiltro]`);
    // rango debe ser IDÉNTICO al de tab=data (misma forma: desde,hasta,w30desde,dias,stock_corte).
    assert.deepEqual(aggregateO45(dataset, {}).rango, oracleSinFiltro.rango, `${slug}: rango difiere de tab=data`);
    // Casos CON filtros: cada tabdata_<slug>__<f>.json tiene su filtros_<slug>__<f>.json.
    for (const ff of readdirSync(fx).filter(f => f.startsWith(`tabdata_${slug}__`))) {
        const tag = ff.slice(`tabdata_${slug}__`.length, -'.json'.length);
        const filtros = JSON.parse(readFileSync(join(fx, `filtros_${slug}__${tag}.json`)));
        compara(aggregateO45(dataset, filtros), JSON.parse(readFileSync(join(fx, ff))), `${slug}[${tag}]`);
    }
    // Smoke de combos derivados (no rompe).
    for (const f of combos(dataset)) assert.ok(Array.isArray(aggregateO45(dataset, f).filas));
    n++;
    console.log(`OK ${slug}`);
}
console.log(`\nGOLDEN o45_aggregate: ${n} proveedores (sin filtro + filtrados) ✔`);
