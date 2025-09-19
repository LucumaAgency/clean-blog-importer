<?php
/**
 * Procesador de CSV
 *
 * Clase encargada de leer y procesar archivos CSV
 */

if (!defined('ABSPATH')) {
    exit;
}

class CBI_CSV_Processor {

    private $cleaner;
    private $image_processor;

    public function __construct() {
        $this->cleaner = new CBI_Content_Cleaner();
        $this->image_processor = new CBI_Image_Processor();
    }

    /**
     * Procesar archivo CSV
     */
    public function process_csv($file_path, $options = []) {
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        // Abrir archivo CSV
        if (($handle = fopen($file_path, 'r')) === FALSE) {
            throw new Exception('No se pudo abrir el archivo CSV');
        }

        // Leer encabezados
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new Exception('El archivo CSV está vacío o mal formateado');
        }

        // Convertir encabezados a minúsculas y limpiar BOM
        $headers = array_map(function($header) {
            // Eliminar BOM si existe
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            return strtolower(trim($header));
        }, $headers);

        // Procesar cada fila
        while (($data = fgetcsv($handle)) !== FALSE) {
            try {
                $post_data = $this->parse_row($headers, $data);

                if (empty($post_data['title'])) {
                    continue;
                }

                $imported_id = $this->import_post($post_data, $options);

                if ($imported_id) {
                    $result['imported']++;
                } else {
                    $result['skipped']++;
                }

            } catch (Exception $e) {
                $result['errors'][] = $e->getMessage();
            }
        }

        fclose($handle);
        return $result;
    }

    /**
     * Parsear una fila del CSV
     */
    private function parse_row($headers, $data) {
        $post = [];

        foreach ($headers as $index => $header) {
            if (isset($data[$index])) {
                $value = $data[$index];

                switch ($header) {
                    case 'id':
                        $post['original_id'] = $value;
                        break;

                    case 'title':
                        $post['title'] = $this->cleaner->clean_text($value);
                        break;

                    case 'content':
                        $post['content'] = $this->cleaner->clean_content($value);
                        break;

                    case 'excerpt':
                        $post['excerpt'] = $this->cleaner->clean_text($value);
                        break;

                    case 'date':
                        $post['date'] = $value;
                        break;

                    case 'post type':
                        $post['post_type'] = $value;
                        break;

                    case 'status':
                        $post['status'] = $value;
                        break;

                    case 'slug':
                        $post['slug'] = sanitize_title($value);
                        break;

                    case 'categorías':
                    case 'categories':
                        $post['categories'] = $this->parse_terms($value);
                        break;

                    case 'etiquetas':
                    case 'tags':
                        $post['tags'] = $this->parse_terms($value);
                        break;

                    case 'url':
                        // Puede ser URL de imagen destacada
                        if (strpos($header, 'featured') !== false || $index == 12) {
                            $post['featured_image'] = $value;
                        }
                        break;

                    case 'author username':
                        $post['author'] = $value;
                        break;
                }
            }
        }

        // Extraer imágenes del contenido
        if (!empty($post['content'])) {
            $post['content_images'] = $this->cleaner->extract_images($post['content']);
        }

        return $post;
    }

    /**
     * Parsear términos (categorías o etiquetas)
     */
    private function parse_terms($terms_string) {
        if (empty($terms_string)) {
            return [];
        }

        // Dividir por | o ,
        $terms = preg_split('/[|,]/', $terms_string);

        return array_map('trim', array_filter($terms));
    }

    /**
     * Importar post a WordPress
     */
    private function import_post($post_data, $options) {
        // Preparar datos del post
        $post_args = [
            'post_title'   => $post_data['title'] ?? '',
            'post_content' => $post_data['content'] ?? '',
            'post_excerpt' => $post_data['excerpt'] ?? '',
            'post_status'  => $post_data['status'] ?? 'draft',
            'post_type'    => $post_data['post_type'] ?? 'post',
            'post_name'    => $post_data['slug'] ?? '',
        ];

        // Establecer fecha si está configurado
        if (!empty($options['preserve_dates']) && !empty($post_data['date'])) {
            $post_args['post_date'] = $post_data['date'];
            $post_args['post_date_gmt'] = get_gmt_from_date($post_data['date']);
        }

        // Establecer autor
        if (!empty($post_data['author'])) {
            $user = get_user_by('login', $post_data['author']);
            if ($user) {
                $post_args['post_author'] = $user->ID;
            }
        }

        // Insertar post
        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            throw new Exception('Error al crear post: ' . $post_id->get_error_message());
        }

        // Agregar categorías
        if (!empty($post_data['categories'])) {
            $category_ids = [];
            foreach ($post_data['categories'] as $cat_name) {
                $term = term_exists($cat_name, 'category');
                if (!$term) {
                    $term = wp_insert_term($cat_name, 'category');
                }
                if (!is_wp_error($term)) {
                    $category_ids[] = is_array($term) ? $term['term_id'] : $term;
                }
            }
            if (!empty($category_ids)) {
                wp_set_post_categories($post_id, $category_ids);
            }
        }

        // Agregar etiquetas
        if (!empty($post_data['tags'])) {
            wp_set_post_tags($post_id, $post_data['tags']);
        }

        // Procesar imagen destacada
        if (!empty($options['import_images']) && !empty($post_data['featured_image'])) {
            try {
                $attachment_id = $this->image_processor->import_image(
                    $post_data['featured_image'],
                    $post_id,
                    $post_data['title'] . ' - Imagen destacada'
                );

                if ($attachment_id) {
                    set_post_thumbnail($post_id, $attachment_id);
                }
            } catch (Exception $e) {
                // Log error pero continuar con la importación
                error_log('Error importando imagen destacada: ' . $e->getMessage());
            }
        }

        // Procesar imágenes del contenido
        if (!empty($options['import_images']) && !empty($post_data['content_images'])) {
            $updated_content = $post_data['content'];

            foreach ($post_data['content_images'] as $img_url) {
                try {
                    $attachment_id = $this->image_processor->import_image(
                        $img_url,
                        $post_id,
                        $post_data['title'] . ' - Imagen de contenido'
                    );

                    if ($attachment_id) {
                        $new_url = wp_get_attachment_url($attachment_id);
                        if ($new_url) {
                            $updated_content = str_replace($img_url, $new_url, $updated_content);
                        }
                    }
                } catch (Exception $e) {
                    // Log error pero continuar
                    error_log('Error importando imagen de contenido: ' . $e->getMessage());
                }
            }

            // Actualizar contenido con nuevas URLs de imágenes
            if ($updated_content !== $post_data['content']) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $updated_content
                ]);
            }
        }

        // Guardar ID original como meta
        if (!empty($post_data['original_id'])) {
            update_post_meta($post_id, '_original_import_id', $post_data['original_id']);
        }

        return $post_id;
    }
}