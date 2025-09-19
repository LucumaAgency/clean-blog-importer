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

        // Configurar para manejar CSVs con contenido complejo
        ini_set('auto_detect_line_endings', true);

        // Leer encabezados
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($handle);
            throw new Exception('El archivo CSV está vacío o mal formateado');
        }

        error_log('Número de columnas detectadas: ' . count($headers));

        // Convertir encabezados a minúsculas y limpiar BOM
        $headers = array_map(function($header) {
            // Eliminar BOM si existe
            $header = str_replace("\xEF\xBB\xBF", '', $header);
            return strtolower(trim($header));
        }, $headers);

        // Procesar cada fila
        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
            // Verificar que la fila tenga el mismo número de columnas que los headers
            if (count($data) !== count($headers)) {
                error_log('⚠ Fila con número incorrecto de columnas: ' . count($data) . ' vs ' . count($headers) . ' esperadas');
            }

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

        // Debug: mostrar mapeo de columnas
        error_log('=== PARSEANDO FILA DEL CSV ===');
        error_log('Headers encontrados: ' . implode(', ', array_slice($headers, 0, 15)));

        foreach ($headers as $index => $header) {
            if (isset($data[$index])) {
                $value = $data[$index];

                // Debug para columnas importantes
                if ($index < 15 && !empty($value)) {
                    error_log("Columna $index ($header): " . substr($value, 0, 100));
                }

                switch ($header) {
                    case 'id':
                        $post['original_id'] = $value;
                        break;

                    case 'title':
                        $post['title'] = $this->cleaner->clean_text($value);
                        error_log("TÍTULO ASIGNADO: " . $post['title']);
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
                        // Limpiar y validar el tipo de post
                        $value = trim(strtolower($value));
                        $post['post_type'] = (!empty($value) && $value !== 'post') ? $value : 'post';
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

                    case 'featured':
                        // Columna 11: Imagen destacada
                        if (!empty($value)) {
                            $post['featured_image'] = $value;
                            error_log("Imagen destacada encontrada en columna Featured: $value");
                        }
                        break;

                    case 'url':
                        // Columna 7: Primera URL (puede ser imagen destacada si no hay columna Featured)
                        // Columna 12: Segunda URL (vacía en este caso)
                        if ($index == 7 && !empty($value) && empty($post['featured_image'])) {
                            // Solo usar como imagen destacada si no se ha establecido ya
                            $post['featured_image'] = $value;
                            error_log("Imagen destacada encontrada en columna URL (index 7): $value");
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

        // Debug final del post parseado
        error_log('=== POST PARSEADO ===');
        error_log('Título final: ' . ($post['title'] ?? 'SIN TÍTULO'));
        error_log('ID Original: ' . ($post['original_id'] ?? 'SIN ID'));
        error_log('Imagen destacada: ' . ($post['featured_image'] ?? 'SIN IMAGEN'));
        error_log('Tipo de post: ' . ($post['post_type'] ?? 'post'));
        error_log('Estado: ' . ($post['status'] ?? 'draft'));

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
        error_log('ID Original del CSV: ' . ($post_data['original_id'] ?? 'No especificado'));
        error_log('Título: ' . ($post_data['title'] ?? 'Sin título'));
        error_log('Estado original: ' . ($post_data['status'] ?? 'draft'));
        error_log('Tipo de post: ' . ($post_data['post_type'] ?? 'post'));
        error_log('Opciones: ' . print_r($options, true));
        error_log('Datos completos del post: ' . print_r($post_data, true));

        // Verificar si ya existe un post con este ID original
        if (!empty($post_data['original_id'])) {
            $existing_post = $this->find_post_by_original_id($post_data['original_id']);
            if ($existing_post) {
                error_log('Post ya existe con ID WordPress: ' . $existing_post->ID . ' (ID original: ' . $post_data['original_id'] . ')');

                // Opción: Actualizar el post existente en lugar de crear uno nuevo
                if (!empty($options['update_existing'])) {
                    error_log('Actualizando post existente...');
                    $post_data['ID'] = $existing_post->ID;
                    return $this->update_existing_post($post_data, $options);
                } else {
                    error_log('Saltando post duplicado');
                    return $existing_post->ID; // Retornar el ID existente
                }
            }
        }

        // Preparar datos del post
        $post_args = [
            'post_title'   => $post_data['title'] ?? '',
            'post_content' => $post_data['content'] ?? '',
            'post_excerpt' => $post_data['excerpt'] ?? '',
            'post_status'  => $post_data['status'] ?? 'draft',
            'post_type'    => $post_data['post_type'] ?? 'post',
            'post_name'    => $post_data['slug'] ?? '',
        ];

        // Verificar que el título no esté vacío
        if (empty($post_args['post_title'])) {
            error_log('⚠ ADVERTENCIA: Título vacío, usando título por defecto');
            $post_args['post_title'] = 'Post importado ' . date('Y-m-d H:i:s');
        }

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

        // Verificar que el post realmente existe
        $created_post = get_post($post_id);
        if (!$created_post) {
            error_log('ERROR: El post con ID ' . $post_id . ' no existe después de crearlo');
            throw new Exception('El post no se creó correctamente');
        }

        error_log('Verificación del post creado:');
        error_log('- ID: ' . $created_post->ID);
        error_log('- Título: ' . $created_post->post_title);
        error_log('- Estado: ' . $created_post->post_status);
        error_log('- Tipo: ' . $created_post->post_type);
        error_log('- URL: ' . get_permalink($post_id));

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
            error_log('URLs a procesar:');
            foreach ($post_data['content_images'] as $idx => $url) {
                error_log(($idx + 1) . '. ' . basename($url));
            }

            $updated_content = $post_data['content'];

            foreach ($post_data['content_images'] as $img_url) {
                try {
                    // Verificar que no sea la misma imagen que la destacada
                    if (!empty($post_data['featured_image']) && $img_url === $post_data['featured_image']) {
                        error_log('Saltando imagen duplicada (ya es imagen destacada): ' . basename($img_url));
                        continue;
                    }

                    $attachment_id = $this->image_processor->import_image(
                        $img_url,
                        $post_id,
                        $post_data['title'] . ' - Imagen de galería'
                    );

                    if ($attachment_id) {
                        // Agregar a array de galería
                        $gallery_images[] = $attachment_id;

                        $new_url = wp_get_attachment_url($attachment_id);
                        if ($new_url) {
                            $updated_content = str_replace($img_url, $new_url, $updated_content);
                        }
                        error_log('Imagen procesada para galería: ' . basename($img_url) . ' -> ID ' . $attachment_id);
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
        if (!empty($gallery_images)) {
            error_log('Total de imágenes para galería ACF: ' . count($gallery_images));
            error_log('IDs de attachments para galería: ' . implode(', ', $gallery_images));

            if (function_exists('update_field')) {
                // Campo ACF para galería de imágenes (configurable)
                $acf_gallery_field = $options['acf_gallery_field'] ?? 'field_686ea8c997852';

                // Actualizar campo ACF
                $result = update_field($acf_gallery_field, $gallery_images, $post_id);

                if ($result) {
                    error_log('✓ Galería ACF actualizada exitosamente con ' . count($gallery_images) . ' imágenes');
                    error_log('  Campo ACF usado: ' . $acf_gallery_field);
                    error_log('  Post ID: ' . $post_id);

                    // Verificar que se guardó correctamente
                    $saved_gallery = get_field($acf_gallery_field, $post_id);
                    if ($saved_gallery) {
                        error_log('✓ Verificación: Galería guardada correctamente con ' . count($saved_gallery) . ' imágenes');
                    } else {
                        error_log('⚠ Advertencia: No se pudo verificar la galería guardada');
                    }
                } else {
                    error_log('✗ Error al actualizar galería ACF');
                    // Intentar guardar como meta alternativa
                    update_post_meta($post_id, 'gallery_images', $gallery_images);
                    update_post_meta($post_id, '_gallery_images_ids', implode(',', $gallery_images));
                    error_log('  Guardado como meta alternativa');
                }
            } else {
                // Si ACF no está disponible, guardar como meta alternativa
                update_post_meta($post_id, 'gallery_images', $gallery_images);
                update_post_meta($post_id, '_gallery_images_ids', implode(',', $gallery_images));
                error_log('⚠ ACF no disponible - Imágenes guardadas como meta');
            }
        } else {
            error_log('No hay imágenes para la galería ACF');
        }

        // Guardar ID original como meta
        if (!empty($post_data['original_id'])) {
            update_post_meta($post_id, '_original_import_id', $post_data['original_id']);
        }

        error_log('=== IMPORTACIÓN COMPLETADA ===');
        return $post_id;
    }

    /**
     * Buscar post por ID original
     */
    private function find_post_by_original_id($original_id) {
        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_original_import_id'
             AND meta_value = %s
             LIMIT 1",
            $original_id
        ));

        if ($post_id) {
            return get_post($post_id);
        }

        return null;
    }

    /**
     * Actualizar post existente
     */
    private function update_existing_post($post_data, $options) {
        $post_args = [
            'ID' => $post_data['ID'],
            'post_title'   => $post_data['title'] ?? '',
            'post_content' => $post_data['content'] ?? '',
            'post_excerpt' => $post_data['excerpt'] ?? '',
        ];

        // Solo actualizar estado si se fuerza publicación
        if (!empty($options['force_publish'])) {
            $post_args['post_status'] = 'publish';
        }

        $post_id = wp_update_post($post_args, true);

        if (is_wp_error($post_id)) {
            throw new Exception('Error al actualizar post: ' . $post_id->get_error_message());
        }

        error_log('Post actualizado exitosamente: ID ' . $post_id);
        return $post_id;
    }
}