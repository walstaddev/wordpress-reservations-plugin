<?php
/**
 * Plugin Name: CH Reservas (Premium AJAX)
 * Description: Formulario de reservas por shortcode (AJAX + SweetAlert2) + panel admin para ver reservas.
 * Version: 2.0.0
 * Author: CH
 */

if (!defined('ABSPATH')) exit;

class CH_Reservas_Plugin {
  const TABLE_SUFFIX = 'ch_reservas';
  const AJAX_ACTION  = 'ch_reservas_submit_ajax';
  const NONCE_ACTION = 'ch_reservas_ajax_nonce';

  public function __construct() {
    register_activation_hook(__FILE__, [$this, 'activate']);

    add_shortcode('ch_reservas', [$this, 'shortcode_form']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    // AJAX handlers
    add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_submit']);
    add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'ajax_submit']);

    // Admin
    add_action('admin_menu', [$this, 'admin_menu']);
  }

  private function table_name() {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SUFFIX;
  }

  public function activate() {
    global $wpdb;
    $table = $this->table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      name VARCHAR(191) NOT NULL,
      email VARCHAR(191) NOT NULL,
      phone VARCHAR(50) DEFAULT '',
      guests INT(11) NOT NULL DEFAULT 2,
      res_date DATE NOT NULL,
      res_time TIME NOT NULL,
      notes TEXT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      ip VARCHAR(45) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      PRIMARY KEY  (id),
      KEY res_date (res_date),
      KEY status (status)
    ) $charset_collate;";

    dbDelta($sql);
  }

  public function enqueue_assets() {
    // Solo cargamos si la página contiene el shortcode
    if (!is_singular()) return;

    global $post;
    if (!$post || !has_shortcode($post->post_content, 'ch_reservas')) return;

    // SweetAlert2
    wp_enqueue_script(
      'sweetalert2',
      'https://cdn.jsdelivr.net/npm/sweetalert2@11',
      [],
      null,
      true
    );

    // Config para JS (AJAX url + nonce)
    wp_register_script('ch-reservas-frontend', '', ['sweetalert2'], '2.0.0', true);

    wp_localize_script('ch-reservas-frontend', 'CH_RESERVAS', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'action'   => self::AJAX_ACTION,
      'nonce'    => wp_create_nonce(self::NONCE_ACTION),
      'i18n'     => [
        'sending'   => 'Enviando…',
        'send'      => 'Enviar solicitud',
        'success_t' => '¡Reserva enviada!',
        'success_m' => 'Te confirmaremos la disponibilidad lo antes posible.',
        'error_t'   => 'Ups…',
        'error_m'   => 'No se pudo enviar la reserva. Revisa los datos e inténtalo de nuevo.',
        'invalid_t' => 'Revisa el formulario',
      ],
      'ui' => [
        'confirm_color' => '#6b4a2f'
      ]
    ]);

    wp_enqueue_script('ch-reservas-frontend');

    // JS inline (sin archivo extra)
    $inline_js = <<<JS
document.addEventListener('DOMContentLoaded', function () {
  var form = document.querySelector('form[data-ch-reservas="1"]');
  if (!form) return;

  var btn = form.querySelector('button[type="submit"]');
  var btnOriginalText = btn ? btn.textContent : '';

  function setLoading(isLoading) {
    if (!btn) return;
    btn.disabled = !!isLoading;
    btn.style.opacity = isLoading ? '0.75' : '1';
    btn.textContent = isLoading ? (CH_RESERVAS?.i18n?.sending || 'Enviando…') : (CH_RESERVAS?.i18n?.send || btnOriginalText);
  }

  function swalError(title, msg) {
    if (window.Swal) {
      Swal.fire({
        icon: 'error',
        title: title || (CH_RESERVAS?.i18n?.error_t || 'Error'),
        text: msg || (CH_RESERVAS?.i18n?.error_m || 'Algo salió mal.'),
        confirmButtonColor: CH_RESERVAS?.ui?.confirm_color || '#6b4a2f'
      });
    } else {
      alert((title ? title + "\\n" : "") + (msg || 'Error'));
    }
  }

  function swalSuccess(title, msg) {
    if (window.Swal) {
      Swal.fire({
        icon: 'success',
        title: title || (CH_RESERVAS?.i18n?.success_t || 'OK'),
        text: msg || (CH_RESERVAS?.i18n?.success_m || ''),
        confirmButtonColor: CH_RESERVAS?.ui?.confirm_color || '#6b4a2f'
      });
    } else {
      alert((title ? title + "\\n" : "") + (msg || 'OK'));
    }
  }

  function validateBasic(fd) {
    var name = (fd.get('name') || '').toString().trim();
    var email = (fd.get('email') || '').toString().trim();
    var date = (fd.get('res_date') || '').toString().trim();
    var time = (fd.get('res_time') || '').toString().trim();
    var guests = parseInt((fd.get('guests') || '0').toString(), 10);

    if (!name || !email || !date || !time || !guests || guests < 1) return false;
    return true;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    var fd = new FormData(form);

    // Mapear a nombres esperados por el backend
    // (ya vienen con esos names, pero aseguramos consistencia)
    if (!validateBasic(fd)) {
      swalError(CH_RESERVAS?.i18n?.invalid_t || 'Revisa el formulario', 'Completa nombre, email, fecha, hora y personas.');
      return;
    }

    // Payload AJAX
    var payload = new FormData();
    payload.append('action', CH_RESERVAS.action);
    payload.append('nonce', CH_RESERVAS.nonce);

    payload.append('name', fd.get('name'));
    payload.append('email', fd.get('email'));
    payload.append('phone', fd.get('phone'));
    payload.append('guests', fd.get('guests'));
    payload.append('res_date', fd.get('res_date'));
    payload.append('res_time', fd.get('res_time'));
    payload.append('notes', fd.get('notes'));

    setLoading(true);

    try {
      var res = await fetch(CH_RESERVAS.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        body: payload
      });

      var data;
      try {
        data = await res.json();
      } catch (_) {
        data = null;
      }

      if (!res.ok || !data || !data.success) {
        var msg = (data && data.data && data.data.message) ? data.data.message : (CH_RESERVAS?.i18n?.error_m || 'No se pudo enviar.');
        swalError(CH_RESERVAS?.i18n?.error_t || 'Ups…', msg);
        setLoading(false);
        return;
      }

      // Éxito
      swalSuccess(CH_RESERVAS?.i18n?.success_t || '¡Reserva enviada!', CH_RESERVAS?.i18n?.success_m || '');
      form.reset();
      setLoading(false);

    } catch (err) {
      swalError(CH_RESERVAS?.i18n?.error_t || 'Ups…', CH_RESERVAS?.i18n?.error_m || 'No se pudo enviar.');
      setLoading(false);
    }
  });
});
JS;

    wp_add_inline_script('ch-reservas-frontend', $inline_js);

    // CSS inline simple (si quieres, lo dejamos “minimal”)
    $inline_css = <<<CSS
.ch-reservas-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.ch-reservas-grid label{display:block;margin-bottom:6px;font-weight:500;}
.ch-reservas-grid input,.ch-reservas-grid select,.ch-reservas-grid textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;}
.ch-reservas-btn{margin-top:14px;background:#6b4a2f;color:#fff;border:none;border-radius:6px;padding:10px 16px;cursor:pointer;}
CSS;

    wp_register_style('ch-reservas-style', false);
    wp_enqueue_style('ch-reservas-style');
    wp_add_inline_style('ch-reservas-style', $inline_css);
  }

  public function shortcode_form($atts = []) {
    ob_start();
    ?>
    <form method="post" data-ch-reservas="1" style="max-width:900px;">
      <div class="ch-reservas-grid">
        <div>
          <label>Nombre</label>
          <input required type="text" name="name" autocomplete="name" />
        </div>

        <div>
          <label>Email</label>
          <input required type="email" name="email" autocomplete="email" />
        </div>

        <div>
          <label>Teléfono (opcional)</label>
          <input type="text" name="phone" autocomplete="tel" />
        </div>

        <div>
          <label>Personas</label>
          <select name="guests">
            <?php for ($i=1; $i<=12; $i++): ?>
              <option value="<?php echo $i; ?>" <?php selected($i, 2); ?>><?php echo $i; ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div>
          <label>Fecha</label>
          <input required type="date" name="res_date" />
        </div>

        <div>
          <label>Hora</label>
          <input required type="time" name="res_time" />
        </div>

        <div style="grid-column:1 / -1;">
          <label>Notas (opcional)</label>
          <textarea name="notes" rows="4"></textarea>
        </div>
      </div>

      <button class="ch-reservas-btn" type="submit">Enviar solicitud</button>

      <noscript>
        <p style="margin-top:10px;color:#a00;">Para enviar reservas necesitas activar JavaScript.</p>
      </noscript>
    </form>
    <?php
    return ob_get_clean();
  }

  public function ajax_submit() {
    // Solo JSON
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
      wp_send_json_error(['message' => 'Acceso inválido.'], 400);
    }

    // Nonce
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Token inválido. Recarga la página e inténtalo de nuevo.'], 403);
    }

    // Sanitizar
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $email  = sanitize_email($_POST['email'] ?? '');
    $phone  = sanitize_text_field($_POST['phone'] ?? '');
    $guests = isset($_POST['guests']) ? (int) $_POST['guests'] : 0;
    $date   = sanitize_text_field($_POST['res_date'] ?? '');
    $time   = sanitize_text_field($_POST['res_time'] ?? '');
    $notes  = sanitize_textarea_field($_POST['notes'] ?? '');

    // Validación básica
    if ($name === '' || !is_email($email) || $guests < 1 || $date === '' || $time === '') {
      wp_send_json_error(['message' => 'Completa nombre, email, personas, fecha y hora.'], 422);
    }

    // Formato simple
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      wp_send_json_error(['message' => 'Fecha inválida.'], 422);
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
      wp_send_json_error(['message' => 'Hora inválida.'], 422);
    }

    global $wpdb;
    $table = $this->table_name();

    $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

    $ok = $wpdb->insert(
      $table,
      [
        'name'       => $name,
        'email'      => $email,
        'phone'      => $phone,
        'guests'     => $guests,
        'res_date'   => $date,
        'res_time'   => $time,
        'notes'      => $notes,
        'status'     => 'pending',
        'ip'         => $ip,
        'user_agent' => $ua,
      ],
      ['%s','%s','%s','%d','%s','%s','%s','%s','%s','%s']
    );

    if (!$ok) {
      wp_send_json_error(['message' => 'No se pudo guardar la reserva. Inténtalo más tarde.'], 500);
    }

    wp_send_json_success([
      'message' => 'Reserva enviada correctamente.',
      'id'      => (int) $wpdb->insert_id,
    ]);
  }

  public function admin_menu() {
    add_menu_page(
      'Reservas',
      'Reservas',
      'manage_options',
      'ch-reservas',
      [$this, 'admin_page'],
      'dashicons-calendar-alt',
      26
    );
  }

  public function admin_page() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table = $this->table_name();

    // Borrar una reserva
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'delete') {
      check_admin_referer('ch_reservas_delete_' . (int)$_GET['id']);
      $wpdb->delete($table, ['id' => (int)$_GET['id']], ['%d']);
      echo '<div class="updated notice"><p>Reserva eliminada.</p></div>';
    }

    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    $where = "WHERE 1=1";
    $params = [];

    if ($status !== '') {
      $where .= " AND status = %s";
      $params[] = $status;
    }

    if ($search !== '') {
      $where .= " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s)";
      $like = '%' . $wpdb->esc_like($search) . '%';
      $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 500";
    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    ?>
    <div class="wrap">
      <h1>Reservas</h1>

      <form method="get" style="margin:12px 0; display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="page" value="ch-reservas" />
        <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por nombre, email o teléfono" style="min-width:320px;">
        <select name="status">
          <option value="">— Todas —</option>
          <?php foreach (['pending','confirmed','cancelled'] as $st): ?>
            <option value="<?php echo esc_attr($st); ?>" <?php selected($status, $st); ?>><?php echo esc_html($st); ?></option>
          <?php endforeach; ?>
        </select>
        <button class="button">Filtrar</button>
      </form>

      <table class="widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Creada</th>
            <th>Nombre</th>
            <th>Email</th>
            <th>Teléfono</th>
            <th>Personas</th>
            <th>Fecha</th>
            <th>Hora</th>
            <th>Estado</th>
            <th>Notas</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="11">No hay reservas.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r->id; ?></td>
              <td><?php echo esc_html($r->created_at); ?></td>
              <td><?php echo esc_html($r->name); ?></td>
              <td><?php echo esc_html($r->email); ?></td>
              <td><?php echo esc_html($r->phone); ?></td>
              <td><?php echo (int)$r->guests; ?></td>
              <td><?php echo esc_html($r->res_date); ?></td>
              <td><?php echo esc_html($r->res_time); ?></td>
              <td><?php echo esc_html($r->status); ?></td>
              <td><?php echo esc_html(mb_strimwidth((string)$r->notes, 0, 60, '…')); ?></td>
              <td>
                <?php
                  $del_url = wp_nonce_url(
                    admin_url('admin.php?page=ch-reservas&action=delete&id='.(int)$r->id),
                    'ch_reservas_delete_' . (int)$r->id
                  );
                ?>
                <a class="button button-small" href="<?php echo esc_url($del_url); ?>" onclick="return confirm('¿Eliminar esta reserva?');">Borrar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>

      <p style="margin-top:10px;color:#666;">
        Tabla usada: <code><?php echo esc_html($table); ?></code>
      </p>
    </div>
    <?php
  }
}

new CH_Reservas_Plugin();
