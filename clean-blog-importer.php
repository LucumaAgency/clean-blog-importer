<?php
/**
 * Plugin Name: Clean Blog Importer
 * Plugin URI: https://yourwebsite.com/
 * Description: Importa posts desde CSV limpiando todo el código de Elementor y extrayendo contenido limpio
 * Version: 1.0.0
 * Author: Tu Nombre
 * Author URI: https://yourwebsite.com/
 * License: GPL v2 or later
 * Text Domain: clean-blog-importer
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('CBI_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('CBI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CBI_VERSION', '1.0.0');

// Clase principal del plugin
class CleanBlogImporter {

    private static $instance = null;

    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_import']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            'Clean Blog Importer',
            'Clean Importer',
            'manage_options',
            'clean-blog-importer',
            [$this, 'render_admin_page'],
            'dashicons-upload',
            30
        );
    }

    /**
     * Renderizar página de administración
     */
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Clean Blog Importer</h1>
            <p>Importa posts desde un archivo CSV limpiando todo el código de Elementor.</p>

            <?php if (isset($_GET['message'])): ?>
                <?php if ($_GET['message'] == 'success'): ?>
                    <div class="notice notice-success is-dismissible">
                        <p>¡Importación completada exitosamente! Se importaron <?php echo $_GET['count'] ?? 0; ?> posts.</p>
                        <?php
                        if (isset($_GET['post_ids']) && !empty($_GET['post_ids'])):
                            $post_ids = explode(',', $_GET['post_ids']);
                            ?>
                            <p><strong>Posts creados:</strong></p>
                            <ul>
                                <?php foreach($post_ids as $pid):
                                    $post = get_post($pid);
                                    if ($post):
                                        $original_id = get_post_meta($pid, '_original_import_id', true);
                                        ?>
                                        <li>
                                            ID: <?php echo $pid; ?>
                                            <?php if ($original_id): ?>(Original: <?php echo $original_id; ?>)<?php endif; ?>
                                            - <a href="<?php echo get_edit_post_link($pid); ?>">Editar</a> |
                                            <a href="<?php echo get_permalink($pid); ?>" target="_blank">Ver</a> |
                                            Título: <?php echo esc_html($post->post_title); ?>
                                        </li>
                                    <?php else: ?>
                                        <li style="color: red;">ID: <?php echo $pid; ?> - Post no encontrado</li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php elseif ($_GET['message'] == 'error'): ?>
                    <div class="notice notice-error is-dismissible">
                        <p>Error durante la importación: <?php echo esc_html($_GET['error'] ?? 'Error desconocido'); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            // Verificar si ACF está instalado
            if (!function_exists('update_field')) {
                ?>
                <div class="notice notice-warning">
                    <p><strong>Advertencia:</strong> Advanced Custom Fields (ACF) no está instalado. Las imágenes de galería se guardarán como meta alternativa.</p>
                </div>
                <?php
            }
            ?>

            <form method="post" enctype="multipart/form-data" action="">
                <?php wp_nonce_field('cbi_import_nonce', 'cbi_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="csv_file">Archivo CSV</label>
                        </th>
                        <td>
                            <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                            <p class="description">Selecciona el archivo CSV con los posts a importar.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="import_images">Importar imágenes</label>
                        </th>
                        <td>
                            <input type="checkbox" name="import_images" id="import_images" value="1" checked />
                            <label for="import_images">Descargar e importar imágenes al servidor</label>
                            <p class="description">Las imágenes se descargarán y se guardarán en la biblioteca de medios.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="preserve_dates">Preservar fechas</label>
                        </th>
                        <td>
                            <input type="checkbox" name="preserve_dates" id="preserve_dates" value="1" checked />
                            <label for="preserve_dates">Mantener las fechas originales de publicación</label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="acf_gallery_field">Campo ACF de galería</label>
                        </th>
                        <td>
                            <input type="text" name="acf_gallery_field" id="acf_gallery_field" value="field_686ea8c997852" class="regular-text" />
                            <p class="description">Key del campo ACF donde se guardarán las imágenes de la galería (por defecto: field_686ea8c997852)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="force_publish">Forzar publicación</label>
                        </th>
                        <td>
                            <input type="checkbox" name="force_publish" id="force_publish" value="1" />
                            <label for="force_publish">Publicar todos los posts importados (ignorar estado del CSV)</label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="cbi_import" class="button-primary" value="Importar Posts" />
                </p>
            </form>

            <div id="import-progress" style="display:none;">
                <h3>Progreso de importación</h3>
                <div class="progress-bar">
                    <div class="progress-bar-fill" style="width: 0%;"></div>
                </div>
                <p class="progress-text">Procesando...</p>
            </div>

            <hr style="margin: 40px 0;">

            <h2>Posts Importados Anteriormente</h2>
            <?php
            // Buscar posts con el meta de importación
            $imported_posts = get_posts([
                'post_type' => 'post',
                'posts_per_page' => 50,
                'meta_key' => '_original_import_id',
                'orderby' => 'date',
                'order' => 'DESC',
                'post_status' => 'any'
            ]);

            if ($imported_posts):
            ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID WordPress</th>
                            <th>ID Original</th>
                            <th>Título</th>
                            <th>Estado</th>
                            <th>Fecha Importación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imported_posts as $post):
                            $original_id = get_post_meta($post->ID, '_original_import_id', true);
                            ?>
                            <tr>
                                <td><?php echo $post->ID; ?></td>
                                <td><?php echo $original_id ?: '-'; ?></td>
                                <td><?php echo esc_html($post->post_title); ?></td>
                                <td><?php echo $post->post_status; ?></td>
                                <td><?php echo $post->post_date; ?></td>
                                <td>
                                    <a href="<?php echo get_edit_post_link($post->ID); ?>">Editar</a> |
                                    <a href="<?php echo get_permalink($post->ID); ?>" target="_blank">Ver</a> |
                                    <a href="<?php echo admin_url('post.php?post=' . $post->ID . '&action=trash'); ?>"
                                       onclick="return confirm('¿Eliminar este post?');" style="color: #a00;">Papelera</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No hay posts importados anteriormente.</p>
            <?php endif; ?>
        </div>

        <style>
            .progress-bar {
                width: 100%;
                height: 30px;
                background-color: #f0f0f0;
                border: 1px solid #ccc;
                border-radius: 5px;
                overflow: hidden;
                margin: 20px 0;
            }
            .progress-bar-fill {
                height: 100%;
                background-color: #2271b1;
                transition: width 0.3s ease;
            }
        </style>
        <?php
    }

    /**
     * Manejar importación
     */
    public function handle_import() {
        if (!isset($_POST['cbi_import'])) {
            return;
        }

        // Verificar nonce
        if (!isset($_POST['cbi_nonce']) || !wp_verify_nonce($_POST['cbi_nonce'], 'cbi_import_nonce')) {
            wp_die('Seguridad: Nonce inválido');
        }

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para realizar esta acción');
        }

        // Verificar archivo
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(admin_url('admin.php?page=clean-blog-importer&message=error&error=No se pudo cargar el archivo'));
            exit;
        }

        $file = $_FILES['csv_file']['tmp_name'];

        // Procesar CSV
        try {
            $importer = new CBI_CSV_Processor();
            $result = $importer->process_csv($file, [
                'import_images' => isset($_POST['import_images']),
                'preserve_dates' => isset($_POST['preserve_dates']),
                'acf_gallery_field' => $_POST['acf_gallery_field'] ?? 'field_686ea8c997852',
                'force_publish' => isset($_POST['force_publish'])
            ]);

            // Construir URL de redirección con información adicional
            $redirect_url = add_query_arg([
                'page' => 'clean-blog-importer',
                'message' => 'success',
                'count' => $result['imported'],
                'post_ids' => isset($result['post_ids']) ? implode(',', $result['post_ids']) : ''
            ], admin_url('admin.php'));

            wp_redirect($redirect_url);
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=clean-blog-importer&message=error&error=' . urlencode($e->getMessage())));
        }
        exit;
    }

    /**
     * Cargar scripts de administración
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_clean-blog-importer') {
            return;
        }

        wp_enqueue_script(
            'cbi-admin-script',
            CBI_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            CBI_VERSION,
            true
        );
    }
}

// Incluir clases necesarias
require_once CBI_PLUGIN_PATH . 'includes/class-csv-processor.php';
require_once CBI_PLUGIN_PATH . 'includes/class-content-cleaner.php';
require_once CBI_PLUGIN_PATH . 'includes/class-image-processor.php';

// Inicializar plugin
function cbi_init() {
    CleanBlogImporter::getInstance();
}
add_action('plugins_loaded', 'cbi_init');