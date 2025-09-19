<?php
/**
 * Limpiador de contenido
 *
 * Clase encargada de limpiar el contenido HTML de Elementor y otros builders
 */

if (!defined('ABSPATH')) {
    exit;
}

class CBI_Content_Cleaner {

    /**
     * Limpiar contenido completo
     */
    public function clean_content($content) {
        if (empty($content)) {
            return '';
        }

        // Eliminar comillas dobles escapadas
        $content = str_replace('""', '"', $content);

        // Eliminar todo el código de Elementor
        $content = $this->remove_elementor_code($content);

        // Procesar bloques de Gutenberg
        $content = $this->process_gutenberg_blocks($content);

        // Limpiar HTML
        $content = $this->clean_html($content);

        // Eliminar estilos inline
        $content = $this->remove_inline_styles($content);

        // Limpiar emojis de Facebook
        $content = $this->clean_facebook_emojis($content);

        // Limpiar espacios extra y líneas vacías
        $content = $this->clean_whitespace($content);

        return $content;
    }

    /**
     * Limpiar texto simple
     */
    public function clean_text($text) {
        if (empty($text)) {
            return '';
        }

        // Decodificar entidades HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Eliminar tags HTML
        $text = strip_tags($text);

        // Limpiar espacios
        $text = trim($text);

        return $text;
    }

    /**
     * Eliminar código de Elementor
     */
    private function remove_elementor_code($content) {
        // Eliminar enlaces con data-elementor
        $patterns = [
            '/<a[^>]*data-elementor[^>]*>.*?<\/a>/is',
            '/<a[^>]*e-action-hash[^>]*>.*?<\/a>/is',
            '/<style[^>]*>.*?elementor.*?<\/style>/is',
            '/<img[^>]*class="[^"]*elementor[^"]*"[^>]*>/is',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        // Eliminar divs y sections de Elementor
        $content = preg_replace('/<(div|section)[^>]*elementor[^>]*>.*?<\/\1>/is', '', $content);

        return $content;
    }

    /**
     * Procesar bloques de Gutenberg
     */
    private function process_gutenberg_blocks($content) {
        // Patrón para detectar bloques de Gutenberg
        $pattern = '/<!-- wp:(\w+)(?:\s+({[^}]*}))? -->(.*?)<!-- \/wp:\1 -->/s';

        $content = preg_replace_callback($pattern, function($matches) {
            $block_type = $matches[1];
            $block_content = $matches[3];

            // Procesar según el tipo de bloque
            switch ($block_type) {
                case 'paragraph':
                    return $this->clean_paragraph($block_content);

                case 'heading':
                    return $block_content;

                case 'list':
                    return $block_content;

                case 'image':
                    return $this->process_image_block($block_content);

                case 'gallery':
                    return $this->process_gallery_block($block_content);

                default:
                    // Para otros bloques, devolver solo el contenido
                    return $block_content;
            }
        }, $content);

        return $content;
    }

    /**
     * Limpiar párrafo
     */
    private function clean_paragraph($content) {
        // Eliminar párrafos vacíos
        if (preg_match('/<p[^>]*>\s*<\/p>/', $content)) {
            return '';
        }

        return $content;
    }

    /**
     * Procesar bloque de imagen
     */
    private function process_image_block($content) {
        // Extraer solo la etiqueta img
        if (preg_match('/<img[^>]+>/i', $content, $matches)) {
            $img = $matches[0];

            // Limpiar atributos innecesarios
            $img = preg_replace('/\s+srcset="[^"]*"/', '', $img);
            $img = preg_replace('/\s+sizes="[^"]*"/', '', $img);
            $img = preg_replace('/\s+loading="[^"]*"/', '', $img);

            return '<figure class="wp-block-image">' . $img . '</figure>';
        }

        return $content;
    }

    /**
     * Procesar bloque de galería
     */
    private function process_gallery_block($content) {
        // Mantener estructura básica de galería
        $images = [];
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);

        if (!empty($matches[1])) {
            $gallery_html = '<div class="wp-block-gallery">';
            foreach ($matches[1] as $src) {
                $gallery_html .= '<figure class="wp-block-image">';
                $gallery_html .= '<img src="' . esc_url($src) . '" alt="" />';
                $gallery_html .= '</figure>';
            }
            $gallery_html .= '</div>';

            return $gallery_html;
        }

        return '';
    }

    /**
     * Limpiar HTML
     */
    private function clean_html($content) {
        // Crear DOM
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Suprimir errores de HTML mal formado
        libxml_use_internal_errors(true);

        // Cargar HTML con UTF-8
        $content_with_meta = '<?xml encoding="UTF-8">' . $content;
        @$dom->loadHTML($content_with_meta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Limpiar errores
        libxml_clear_errors();

        // Eliminar scripts y estilos
        $remove = ['script', 'style', 'link'];
        foreach ($remove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $element = $elements->item($i);
                $element->parentNode->removeChild($element);
            }
        }

        // Obtener HTML limpio
        $clean = $dom->saveHTML();

        // Eliminar declaración XML
        $clean = str_replace('<?xml encoding="UTF-8">', '', $clean);

        return $clean;
    }

    /**
     * Eliminar estilos inline
     */
    private function remove_inline_styles($content) {
        // Eliminar atributo style
        $content = preg_replace('/\sstyle="[^"]*"/i', '', $content);

        // Eliminar atributos data-*
        $content = preg_replace('/\sdata-[a-z\-]+"[^"]*"/i', '', $content);

        // Eliminar clases específicas de builders
        $content = preg_replace('/\sclass="[^"]*elementor[^"]*"/i', '', $content);

        return $content;
    }

    /**
     * Limpiar emojis de Facebook
     */
    private function clean_facebook_emojis($content) {
        // Patrón para detectar imágenes de emojis de Facebook
        $pattern = '/<img[^>]*src="https:\/\/static\.xx\.fbcdn\.net\/images\/emoji[^"]*"[^>]*>/i';

        // Mapeo de algunos emojis comunes
        $emoji_map = [
            '1f9d2.png' => '🧒',
            '1f467.png' => '👧',
            '1f94e.png' => '🥎',
            '1f4d8.png' => '📘',
            '1f3b7.png' => '🎷',
            '2728.png'  => '✨',
            '1f331.png' => '🌱',
            '1f4da.png' => '📚',
            '1f680.png' => '🚀'
        ];

        $content = preg_replace_callback($pattern, function($matches) use ($emoji_map) {
            // Intentar extraer el código del emoji del src
            if (preg_match('/\/([a-f0-9]+)\.png/i', $matches[0], $emoji_match)) {
                $emoji_code = $emoji_match[1];
                if (isset($emoji_map[$emoji_code . '.png'])) {
                    return $emoji_map[$emoji_code . '.png'];
                }
            }

            // Si no se encuentra, devolver emoji genérico
            return '😊';
        }, $content);

        return $content;
    }

    /**
     * Limpiar espacios en blanco excesivos
     */
    private function clean_whitespace($content) {
        // Eliminar múltiples líneas vacías
        $content = preg_replace("/\n\s*\n\s*\n/", "\n\n", $content);

        // Eliminar espacios al inicio y final
        $content = trim($content);

        // Eliminar espacios múltiples
        $content = preg_replace('/\s+/', ' ', $content);

        // Restaurar saltos de línea en párrafos
        $content = str_replace('</p> <p', "</p>\n<p", $content);

        return $content;
    }

    /**
     * Extraer URLs de imágenes del contenido
     */
    public function extract_images($content) {
        $images = [];

        // Buscar todas las imágenes en tags img
        preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                // Filtrar solo URLs válidas y excluir emojis de Facebook
                if (filter_var($url, FILTER_VALIDATE_URL) &&
                    strpos($url, 'static.xx.fbcdn.net/images/emoji') === false) {
                    $images[] = $url;
                }
            }
        }

        // Buscar imágenes en enlaces href (galerías de Elementor)
        preg_match_all('/href="([^"]*uploads[^"]+\.(?:jpg|jpeg|png|gif|webp))"/i', $content, $href_matches);
        if (!empty($href_matches[1])) {
            foreach ($href_matches[1] as $url) {
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $images[] = $url;
                }
            }
        }

        // Buscar imágenes en atributos srcset
        preg_match_all('/srcset="([^"]+)"/i', $content, $srcset_matches);
        if (!empty($srcset_matches[1])) {
            foreach ($srcset_matches[1] as $srcset) {
                $urls = explode(',', $srcset);
                foreach ($urls as $url_part) {
                    $url = trim(explode(' ', trim($url_part))[0]);
                    if (filter_var($url, FILTER_VALIDATE_URL) &&
                        strpos($url, 'static.xx.fbcdn.net/images/emoji') === false) {
                        $images[] = $url;
                    }
                }
            }
        }

        // Eliminar duplicados
        $images = array_unique($images);

        // Log para debug
        if (!empty($images)) {
            error_log('Imágenes extraídas del contenido (sin emojis): ' . count($images));
            foreach (array_slice($images, 0, 5) as $img) {
                error_log(' - ' . basename($img));
            }
        }

        return array_values($images);
    }
}