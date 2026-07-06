// Agregación de o45 en cliente: filtra el dataset granular y reproduce EXACTO tab=data.
// dataset = {columnas, filas (array de arrays), precios {'ref|col':num}, rango {desde,hasta,dias,modo_stock}}
// filtros = {marca:[],tipo:[],categoria:[],subcategoria:[],genero:[],publico:[],referencia:[],grupo:[],tienda:[],negocio:[]}
function aggregateO45(dataset, filtros) {
    const C = {}; dataset.columnas.forEach((n, i) => C[n] = i);
    const f = filtros || {};
    const has = k => Array.isArray(f[k]) && f[k].length > 0;
    const set = k => new Set(f[k]);
    const refDims = ['marca','tipo','categoria','subcategoria','genero','publico','referencia'];
    const activeRef = refDims.filter(has).map(k => [C[k], set(k)]);
    const negSet = has('negocio') ? set('negocio') : null;
    const grpSet = has('grupo') ? set('grupo') : null;
    const tieSet = has('tienda') ? set('tienda') : null;
    const dias = dataset.rango.dias;

    const g = new Map();               // negocio-key -> acumulador
    const totTiendas = new Set();
    for (const row of dataset.filas) {
        const esCedi = row[C.es_cedi] === 1;
        // Filtros ref-dim (nivel referencia, aplican a TODAS las filas incl. CEDI)
        let keep = true;
        for (const [i, s] of activeRef) if (!s.has(row[i])) { keep = false; break; }
        if (!keep) continue;
        const negocio = row[C.referencia] + '-' + row[C.color];
        if (negSet && !negSet.has(negocio)) continue;
        // Filtros de bodega: CEDI siempre se conserva (igual que tab=data)
        if (grpSet && !esCedi && !grpSet.has(row[C.grupo])) continue;
        if (tieSet && !esCedi && !tieSet.has(row[C.tienda])) continue;

        const key = row[C.cia] + '|' + row[C.referencia] + '|' + row[C.color];
        let a = g.get(key);
        if (!a) { a = { cia: row[C.cia], referencia: row[C.referencia], color: row[C.color], negocio,
            marca: row[C.marca], ventas: 0, ventas30: 0, stock_cedi: 0, stock_tiendas: 0,
            tallas: new Set(), tiendas: new Set() }; g.set(key, a); }
        if (row[C.marca] > a.marca) a.marca = row[C.marca];              // MAX(marca)
        const disp = row[C.disponible], hold = row[C.hold], ven = row[C.ventas], v30 = row[C.ventas30];
        if (!esCedi) { a.ventas += ven; a.ventas30 += v30; a.stock_tiendas += disp + hold; }
        else a.stock_cedi += disp + hold;
        const activo = (disp + hold > 0) || row[C.inv_hist] === 1 || ven !== 0;
        if (activo) {
            a.tallas.add(row[C.talla]);
            const grp = row[C.grupo];
            if (grp !== 'BODEGA' && grp !== 'ADMINISTRATIVAS') {
                const t = row[C.cia] + '-' + row[C.bodega]; a.tiendas.add(t); totTiendas.add(t);
            }
        }
    }

    const r2 = x => Math.round(x * 100) / 100;
    const filas = [];
    const tot = { ventas: 0, ventas30: 0, stock_cedi: 0, stock_tiendas: 0, total_stock: 0 };
    for (const a of g.values()) {
        const total_stock = a.stock_cedi + a.stock_tiendas;
        const tiendas = a.tiendas.size;
        filas.push({ negocio: a.negocio, referencia: a.referencia, color: a.color, marca: a.marca,
            ventas: a.ventas, tiendas, ventas30: a.ventas30, stock_cedi: a.stock_cedi,
            stock_tiendas: a.stock_tiendas, total_stock,
            ind_inventario: a.ventas30 > 0 ? r2(total_stock / a.ventas30) : null,
            ind_ventas_mes: tiendas > 0 ? r2((a.ventas / tiendas) / (dias / 30)) : 0,
            tallas: a.tallas.size,
            precio: dataset.precios[a.referencia + '|' + a.color] ?? null });
        tot.ventas += a.ventas; tot.ventas30 += a.ventas30; tot.stock_cedi += a.stock_cedi;
        tot.stock_tiendas += a.stock_tiendas; tot.total_stock += total_stock;
    }
    tot.tiendas = totTiendas.size;
    tot.ind_inventario = tot.ventas30 > 0 ? r2(tot.total_stock / tot.ventas30) : null;
    tot.ind_ventas_mes = tot.tiendas > 0 ? r2((tot.ventas / tot.tiendas) / (dias / 30)) : 0;
    filas.sort((x, y) => y.ind_ventas_mes - x.ind_ventas_mes);
    return { filas, total: tot, rango: dataset.rango };
}

if (typeof module !== 'undefined' && module.exports) module.exports = { aggregateO45 };
if (typeof window !== 'undefined') window.aggregateO45 = aggregateO45;
export { aggregateO45 };
