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
            'errors' => [],
            'post_ids' => []
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
                    $result['post_ids'][] = $imported_id;
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
        // Debug logging
        error_log('=== INICIANDO IMPORTACIÓN DE POST ===');
        error_log('Título: ' . ($post_data['title'] ?? 'Sin título'));
        error_log('Estado original: ' . ($post_data['status'] ?? 'draft'));
        error_log('Opciones: ' . print_r($options, true));

        // Preparar datos del post
        $post_args = [
            'post_title'   => $post_data['title'] ?? '',
            'post_content' => $post_data['content'] ?? '',
            'post_excerpt' => $post_data['excerpt'] ?? '',
            'post_status'  => $post_data['status'] ?? 'draft',
            'post_type'    => $post_data['post_type'] ?? 'post',
            'post_name'    => $post_data['slug'] ?? '',
        ];

        // Si se fuerza publicación, establecer como publicado
        if (!empty($options['force_publish'])) {
            $post_args['post_status'] = 'publish';
            error_log('Forzando publicación del post');
        }

        // Asegurar que el estado sea válido
        $valid_statuses = ['publish', 'draft', 'pending', 'private'];
        if (!in_array($post_args['post_status'], $valid_statuses)) {
            $post_args['post_status'] = 'draft';
        }

        error_log('Datos del post preparados: ' . print_r($post_args, true));

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
            error_log('ERROR al crear post: ' . $post_id->get_error_message());
            throw new Exception('Error al crear post: ' . $post_id->get_error_message());
        }

        error_log('Post creado exitosamente con ID: ' . $post_id);

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
                error_log('Categorías asignadas: ' . implode(', ', $category_ids));
            }
        }

        // Agregar etiquetas
        if (!empty($post_data['tags'])) {
            wp_set_post_tags($post_id, $post_data['tags']);
            error_log('Etiquetas asignadas: ' . implode(', ', $post_data['tags']));
        }

        // Array para guardar IDs de imágenes de galería
        $gallery_images = [];

        // Procesar imagen destacada
        if (!empty($options['import_images']) && !empty($post_data['featured_image'])) {
            error_log('Procesando imagen destacada: ' . $post_data['featured_image']);
            try {
                $attachment_id = $this->image_processor->import_image(
                    $post_data['featured_image'],
                    $post_id,
                    $post_data['title'] . ' - Imagen destacada'
                );

                if ($attachment_id) {
                    $result = set_post_thumbnail($post_id, $attachment_id);
                    if ($result) {
                        error_log('Imagen destacada establecida: ID ' . $attachment_id);
                    } else {
                        error_log('No se pudo establecer la imagen destacada');
                    }
                }
            } catch (Exception $e) {
                error_log('Error importando imagen destacada: ' . $e->getMessage());
            }
        }

        // Procesar imágenes del contenido
        if (!empty($options['import_images']) && !empty($post_data['content_images'])) {
            error_log('Procesando ' . count($post_data['content_images']) . ' imágenes del contenido');
            $updated_content = $post_data['content'];

            foreach ($post_data['content_images'] as $img_url) {
                try {
                    $attachment_id = $this->image_processor->import_image(
                        $img_url,
                        $post_id,
                        $post_data['title'] . ' - Imagen de contenido'
                    );

                    if ($attachment_id) {
                        // Agregar a array de galería
                        $gallery_images[] = $attachment_id;

                        $new_url = wp_get_attachment_url($attachment_id);
                        if ($new_url) {
                            $updated_content = str_replace($img_url, $new_url, $updated_content);
                        }
                        error_log('Imagen procesada: ' . $img_url . ' -> ID ' . $attachment_id);
                    }
                } catch (Exception $e) {
                    error_log('Error importando imagen de contenido: ' . $e->getMessage());
                }
            }

            // Actualizar contenido con nuevas URLs de imágenes
            if ($updated_content !== $post_data['content']) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $updated_content
                ]);
                error_log('Contenido actualizado con nuevas URLs de imágenes');
            }
        }

        // Guardar imágenes en campo ACF de galería
        if (!empty($gallery_images) && function_exists('update_field')) {
            // Campo ACF para galería de imágenes (configurable)
            $acf_gallery_field = $options['acf_gallery_field'] ?? 'field_686ea8c997852';
            update_field($acf_gallery_field, $gallery_images, $post_id);
            error_log('Galería ACF actualizada con ' . count($gallery_images) . ' imágenes en campo: ' . $acf_gallery_field);
        } elseif (!empty($gallery_images)) {
            // Si ACF no está disponible, guardar como meta alternativa
            update_post_meta($post_id, 'gallery_images', $gallery_images);
            error_log('Imágenes de galería guardadas como meta (ACF no disponible)');
        }

        // Guardar ID original como meta
        if (!empty($post_data['original_id'])) {
            update_post_meta($post_id, '_original_import_id', $post_data['original_id']);
        }

        error_log('=== IMPORTACIÓN COMPLETADA ===');
        return $post_id;
    }
}