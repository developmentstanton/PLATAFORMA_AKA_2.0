<style>
  #page-verificacion .verif-fila { display:grid; grid-template-columns: 260px 340px 1fr; gap:14px;
    align-items:start; padding:14px 0; border-bottom:1px solid var(--border); }
  #page-verificacion .verif-fila:last-child { border-bottom:none; }
  #page-verificacion .verif-nombre { font-weight:600; color:var(--primary); padding-top:6px; }
  #page-verificacion .verif-ops { display:flex; gap:16px; padding-top:6px; flex-wrap:wrap; }
  #page-verificacion .verif-ops label { display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; }
  #page-verificacion textarea { width:100%; min-height:52px; padding:8px 10px; border:1px solid var(--border);
    border-radius:6px; font-family:'Space Grotesk',sans-serif; font-size:12px; resize:vertical; }
  #page-verificacion textarea:focus { outline:none; border-color:var(--primary); }
  #page-verificacion .verif-estado { font-size:11px; color:var(--text-light); min-height:14px; padding-top:4px; }
  #page-verificacion .verif-estado.ok { color:#2e7d4f; }
  #page-verificacion .verif-estado.error { color:#c0392b; }
  #page-verificacion .verif-ficha { display:flex; gap:26px; flex-wrap:wrap; font-size:12px; color:var(--text-light); }
  #page-verificacion .verif-ficha b { color:var(--text); }
</style>
<div class="page" id="page-verificacion">
  <div class="card" id="verif-identificacion">
    <h3 style="margin-bottom:6px;">Identificación del auditor</h3>
    <p style="font-size:12px;color:var(--text-light);margin-bottom:14px;">
      Esta sesión usa las credenciales del aliado auditado, así que el registro no puede
      deducir quién eres. Escribe tu nombre para abrir la verificación.
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <input type="text" id="verif-auditor" maxlength="120" placeholder="Nombre del auditor"
             style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;min-width:280px;
                    font-family:'Space Grotesk',sans-serif;font-size:13px;">
      <button class="btn btn-primary" onclick="verifAbrir()">Comenzar verificación</button>
    </div>
    <div class="verif-estado error" id="verif-error-abrir"></div>
  </div>

  <div class="card" id="verif-formulario" style="display:none;">
    <div class="verif-ficha" style="margin-bottom:16px;">
      <div>Tercero auditado: <b id="verif-proveedor"></b></div>
      <div>Auditor: <b id="verif-nombre-auditor"></b></div>
      <div>Registro N.: <b id="verif-id"></b></div>
    </div>
    <div id="verif-filas"></div>

    <h4 style="margin:20px 0 8px;">Comentarios generales</h4>
    <textarea id="verif-comentarios" placeholder="Observaciones sobre la revisión en conjunto (opcional)"></textarea>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:18px;align-items:center;">
      <span class="verif-estado" id="verif-estado-cierre"></span>
      <button class="btn btn-primary" onclick="verifCerrar()">Cerrar verificación y enviar</button>
    </div>
  </div>
</div>

<script>
// Los cinco puntos de control. El orden debe coincidir con VERIF_INFORMES de
// api/lib_verificacion.php: la vista, el PDF y la base listan lo mismo en el mismo orden.
const VERIF_INFORMES = [
  ['g00',  'Ventas'],
  ['o14',  'Siembra / Stock / Ventas'],
  ['o45',  'Índice de Ventas'],
  ['evol', 'Evolución Histórica'],
  ['geo',  'Georreferenciación'],
];
const VERIF_CSRF = document.querySelector('meta[name="csrf-token"]').content;
let verifId = 0;

function verifOnEnter() {
  // Si ya se abrió en esta visita, no se vuelve a preguntar el nombre.
  if (verifId) return;
  document.getElementById('verif-auditor').focus();
}

function verifPost(url, datos) {
  const cuerpo = new URLSearchParams(datos);
  cuerpo.set('csrf_token', VERIF_CSRF);
  return fetch('api/' + url, { method:'POST', body: cuerpo })
    .then(r => r.json().then(j => ({ status: r.status, json: j })));
}

function verifAbrir() {
  const auditor = document.getElementById('verif-auditor').value.trim();
  const err = document.getElementById('verif-error-abrir');
  err.textContent = '';
  if (!auditor) { err.textContent = 'Escribe tu nombre.'; return; }

  verifPost('verificacion_abrir.php', { auditor })
    .then(({ status, json }) => {
      if (status !== 200 || !json.ok) { err.textContent = json.error || 'No se pudo abrir.'; return; }
      verifId = json.id;
      document.getElementById('verif-proveedor').textContent      = json.proveedor;
      document.getElementById('verif-nombre-auditor').textContent = json.auditor;
      document.getElementById('verif-id').textContent             = json.id;
      // Si se recupera una en curso, el auditor original manda sobre lo que se escribió.
      document.getElementById('verif-auditor').value = json.auditor;
      verifPintar(json.puntos || {});
      document.getElementById('verif-identificacion').style.display = 'none';
      document.getElementById('verif-formulario').style.display = '';
    })
    .catch(() => { err.textContent = 'Error de red.'; });
}

// El texto del aliado y del auditor se pinta con innerHTML, asi que hay que escapar: un
// nombre con < o & romperia el marcado. La observacion viene de la base, pero pudo
// escribirla cualquiera desde el POST.
function verifEsc(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
  })[c]);
}

function verifPintar(puntos) {
  const cont = document.getElementById('verif-filas');
  cont.innerHTML = VERIF_INFORMES.map(([clave, nombre]) => {
    const p   = puntos[clave] || {};
    const sel = r => p.resultado === r ? ' checked' : '';
    const obs = p.observacion ? verifEsc(p.observacion) : '';
    return '<div class="verif-fila">'
      + '<div class="verif-nombre">' + nombre + '</div>'
      + '<div>'
      +   '<div class="verif-ops">'
      +     '<label><input type="radio" name="r-' + clave + '" value="aprobado"' + sel('aprobado') + '> Aprobado</label>'
      +     '<label><input type="radio" name="r-' + clave + '" value="no_aprobado"' + sel('no_aprobado') + '> No aprobado</label>'
      +     '<label><input type="radio" name="r-' + clave + '" value="no_aplica"' + sel('no_aplica') + '> No aplica</label>'
      +   '</div>'
      +   '<div class="verif-estado" id="verif-msg-' + clave + '"></div>'
      + '</div>'
      + '<textarea id="verif-obs-' + clave + '" maxlength="1000" '
      +   'placeholder="Observación (obligatoria si no se aprueba)">' + obs + '</textarea>'
      + '</div>';
  }).join('');

  // Se guarda al marcar y al salir del textarea: cada punto viaja solo, para que una
  // sesión caída a media revisión no se lleve el avance por delante.
  VERIF_INFORMES.forEach(([clave]) => {
    document.querySelectorAll('input[name="r-' + clave + '"]').forEach(radio => {
      radio.addEventListener('change', () => verifGuardar(clave));
    });
    document.getElementById('verif-obs-' + clave).addEventListener('blur', () => {
      if (document.querySelector('input[name="r-' + clave + '"]:checked')) verifGuardar(clave);
    });
  });
}

function verifGuardar(clave) {
  const radio = document.querySelector('input[name="r-' + clave + '"]:checked');
  if (!radio) return;
  const msg = document.getElementById('verif-msg-' + clave);
  msg.className = 'verif-estado';
  msg.textContent = 'Guardando…';

  verifPost('verificacion_guardar.php', {
    auditoria_id: verifId,
    informe:      clave,
    resultado:    radio.value,
    observacion:  document.getElementById('verif-obs-' + clave).value,
  }).then(({ status, json }) => {
    if (status === 200 && json.ok) { msg.className = 'verif-estado ok'; msg.textContent = 'Guardado'; }
    else { msg.className = 'verif-estado error'; msg.textContent = json.error || 'No se pudo guardar.'; }
  }).catch(() => { msg.className = 'verif-estado error'; msg.textContent = 'Error de red.'; });
}

function verifCerrar() {
  const est = document.getElementById('verif-estado-cierre');
  est.className = 'verif-estado';
  est.textContent = '';

  Swal.fire({
    title: '¿Cerrar la verificación?',
    text:  'Se guardará el registro y se enviará por correo. No se puede deshacer.',
    icon:  'question',
    showCancelButton: true,
    confirmButtonText: 'Sí, cerrar y enviar',
    cancelButtonText:  'Cancelar',
    confirmButtonColor: '#4A4782',
  }).then(res => {
    if (!res.isConfirmed) return;
    verifPost('verificacion_cerrar.php', {
      auditoria_id: verifId,
      comentarios:  document.getElementById('verif-comentarios').value,
    }).then(({ status, json }) => {
      if (status === 200 && json.ok) {
        Swal.fire({ icon: json.correo_enviado ? 'success' : 'warning',
                    title: 'Verificación cerrada',
                    text: json.aviso || 'Se envió el aviso por correo.' });
        document.getElementById('verif-formulario').style.display = 'none';
        verifId = 0;
      } else {
        Swal.fire({ icon:'error', title:'No se pudo cerrar', text: json.error || 'Error inesperado.' });
      }
    }).catch(() => Swal.fire({ icon:'error', title:'Error de red' }));
  });
}
</script>
