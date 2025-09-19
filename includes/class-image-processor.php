<?php
/**
 * Procesador de imágenes
 *
 * Clase encargada de descargar e importar imágenes a la biblioteca de medios
 */

if (!defined('ABSPATH')) {
    exit;
}

class CBI_Image_Processor {

    /**
     * Cache de imágenes ya procesadas
     */
    private $processed_images = [];

    /**
     * Importar imagen desde URL
     */
    public function import_image($url, $post_id = 0, $title = '') {
        if (empty($url)) {
            return false;
        }

        // Verificar si ya procesamos esta imagen
        if (isset($this->processed_images[$url])) {
            return $this->processed_images[$url];
        }

        // Verificar si la imagen ya existe en la biblioteca
        $existing_id = $this->image_exists($url);
        if ($existing_id) {
            $this->processed_images[$url] = $existing_id;
            return $existing_id;
        }

        // Descargar imagen
        $file = $this->download_image($url);
        if (!$file) {
            return false;
        }

        // Preparar archivo para upload
        $file_array = [
            'name'     => basename($file['file']),
            'tmp_name' => $file['file'],
            'error'    => 0,
            'size'     => filesize($file['file'])
        ];

        // Manejar el upload
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Subir archivo
        $attachment_id = media_handle_sideload($file_array, $post_id);

        // Verificar errores
        if (is_wp_error($attachment_id)) {
            @unlink($file['file']);
            throw new Exception('Error al subir imagen: ' . $attachment_id->get_error_message());
        }

        // Establecer título si se proporciona
        if (!empty($title)) {
            wp_update_post([
                'ID'         => $attachment_id,
                'post_title' => $title
            ]);
        }

        // Guardar URL original como meta
        update_post_meta($attachment_id, '_original_url', $url);

        // Guardar en cache
        $this->processed_images[$url] = $attachment_id;

        return $attachment_id;
    }

    /**
     * Descargar imagen desde URL
     */
    private function download_image($url) {
        // Validar URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Obtener extensión del archivo
        $file_info = pathinfo($url);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : '';

        // Si no hay extensión, intentar detectarla
        if (empty($extension)) {
            $headers = @get_headers($url, 1);
            if ($headers && isset($headers['Content-Type'])) {
                $mime = is_array($headers['Content-Type']) ? $headers['Content-Type'][0] : $headers['Content-Type'];
                $extension = $this->get_extension_from_mime($mime);
            }
        }

        // Validar extensión
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $extension = strtolower(explode('?', $extension)[0]); // Eliminar parámetros de query

        if (!in_array($extension, $allowed_extensions)) {
            $extension = 'jpg'; // Usar jpg por defecto
        }

        // Crear archivo temporal
        $tmp_file = wp_tempnam($url);
        if (!$tmp_file) {
            return false;
        }

        // Descargar archivo
        $response = wp_safe_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            'stream'      => true,
            'filename'    => $tmp_file
        ]);

        // Verificar respuesta
        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            @unlink($tmp_file);
            return false;
        }

        // Renombrar archivo con extensión correcta
        $new_file = $tmp_file . '.' . $extension;
        if (!rename($tmp_file, $new_file)) {
            @unlink($tmp_file);
            return false;
        }

        return [
            'file' => $new_file,
            'url'  => $url,
            'type' => wp_check_filetype($new_file)['type']
        ];
    }

    /**
     * Obtener extensión desde MIME type
     */
    private function get_extension_from_mime($mime) {
        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        ];

        $mime = strtolower(trim($mime));

        return isset($mime_to_ext[$mime]) ? $mime_to_ext[$mime] : 'jpg';
    }

    /**
     * Verificar si la imagen ya existe en la biblioteca
     */
    private function image_exists($url) {
        global $wpdb;

        // Buscar por URL original
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_original_url'
             AND meta_value = %s
             LIMIT 1",
            $url
        ));

        if ($attachment_id) {
            return $attachment_id;
        }

        // Buscar por nombre de archivo
        $filename = basename($url);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND guid LIKE %s
             LIMIT 1",
            '%' . $wpdb->esc_like($filename) . '%'
        ));

        return $attachment_id;
    }

    /**
     * Procesar múltiples imágenes
     */
    public function process_images_batch($images, $post_id = 0) {
        $results = [];

        foreach ($images as $image_url) {
            try {
                $attachment_id = $this->import_image($image_url, $post_id);
                if ($attachment_id) {
                    $results[$image_url] = [
                        'success' => true,
                        'attachment_id' => $attachment_id,
                        'new_url' => wp_get_attachment_url($attachment_id)
                    ];
                } else {
                    $results[$image_url] = [
                        'success' => false,
                        'error' => 'No se pudo importar la imagen'
                    ];
                }
            } catch (Exception $e) {
                $results[$image_url] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Optimizar imagen después de subirla
     */
    public function optimize_image($attachment_id) {
        // Obtener path del archivo
        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        // Obtener tipo MIME
        $mime_type = get_post_mime_type($attachment_id);

        // Aplicar optimizaciones según el tipo
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $this->optimize_jpeg($file_path);
                break;

            case 'image/png':
                $this->optimize_png($file_path);
                break;
        }

        // Regenerar thumbnails
        if (function_exists('wp_generate_attachment_metadata')) {
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return true;
    }

    /**
     * Optimizar JPEG
     */
    private function optimize_jpeg($file_path) {
        $image = imagecreatefromjpeg($file_path);
        if ($image) {
            imagejpeg($image, $file_path, 85); // Calidad 85%
            imagedestroy($image);
        }
    }

    /**
     * Optimizar PNG
     */
    private function optimize_png($file_path) {
        $image = imagecreatefrompng($file_path);
        if ($image) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, $file_path, 8); // Compresión nivel 8
            imagedestroy($image);
        }
    }
}