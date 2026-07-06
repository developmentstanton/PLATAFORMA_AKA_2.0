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

function compara(got, oracle, ctx) {
    const gMap = new Map(got.filas.map(f => [f.negocio, f]));
    const oMap = new Map(oracle.filas.map(f => [f.negocio, f]));
    assert.equal(gMap.size, oMap.size, `${ctx}: #negocios difiere`);
    for (const [k, of] of oMap) {
        const gf = gMap.get(k); assert.ok(gf, `${ctx}: falta negocio ${k}`);
        for (const c of NUM) assert.equal(norm(gf[c]), norm(of[c]), `${ctx}: ${k}.${c}`);
    }
    for (const c of ['ventas','tiendas','total_stock','ind_ventas_mes'])
        assert.equal(norm(got.total[c]), norm(oracle.total[c]), `${ctx}: total.${c}`);
}

let n = 0;
for (const slug of slugs) {
    const dataset = JSON.parse(readFileSync(join(fx, `dataset_${slug}.json`)));
    // Caso SIN filtros vs oráculo sin filtros.
    compara(aggregateO45(dataset, {}), JSON.parse(readFileSync(join(fx, `tabdata_${slug}.json`))), `${slug}[sinfiltro]`);
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
