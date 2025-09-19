# Clean Blog Importer

Un plugin de WordPress para importar posts desde archivos CSV, limpiando completamente todo el código de Elementor y otros page builders, dejando contenido HTML limpio y optimizado.

## 🎯 Características

- ✅ **Limpieza completa de Elementor**: Elimina todo rastro de código Elementor, widgets, galerías, lightbox, etc.
- ✅ **Procesamiento inteligente de contenido**: Mantiene bloques de Gutenberg y estructura HTML limpia
- ✅ **Importación de imágenes**: Descarga automáticamente imágenes y las importa a la biblioteca de medios
- ✅ **Conversión de emojis**: Convierte emojis de Facebook a Unicode nativo
- ✅ **Gestión de taxonomías**: Crea automáticamente categorías y etiquetas
- ✅ **Preservación de metadatos**: Mantiene fechas, autores, slugs y estados de publicación

## 📦 Instalación

1. Descarga o clona este repositorio
2. Copia la carpeta `clean-blog-importer` a `/wp-content/plugins/`
3. Activa el plugin desde el panel de administración de WordPress
4. Ve a **Clean Importer** en el menú principal

## 🚀 Uso

### Preparar el archivo CSV

El archivo CSV debe tener las siguientes columnas (no todas son obligatorias):

- `ID`: Identificador único del post
- `Title`: Título del post
- `Content`: Contenido HTML (puede incluir código de Elementor, será limpiado)
- `Excerpt`: Extracto del post
- `Date`: Fecha de publicación (formato: YYYY-MM-DD HH:MM:SS)
- `Post Type`: Tipo de post (por defecto: post)
- `Status`: Estado de publicación (publish, draft, etc.)
- `Slug`: URL amigable del post
- `Categorías` o `Categories`: Categorías separadas por | o ,
- `Etiquetas` o `Tags`: Etiquetas separadas por | o ,
- `Author Username`: Nombre de usuario del autor
- `URL`: URL de imagen destacada (columna 13 del CSV)

### Proceso de importación

1. **Subir CSV**: Haz clic en "Seleccionar archivo" y elige tu CSV
2. **Configurar opciones**:
   - ✅ Importar imágenes: Descarga e importa todas las imágenes
   - ✅ Preservar fechas: Mantiene las fechas originales
3. **Importar**: Haz clic en "Importar Posts"

## 🧹 ¿Qué limpia el plugin?

### Código de Elementor
- Enlaces con `data-elementor-*`
- Atributos `e-action-hash`
- Clases CSS de Elementor
- Widgets y secciones de Elementor
- Estilos inline de Elementor
- Galerías con lightbox de Elementor

### HTML general
- Scripts y estilos inline
- Atributos `data-*` innecesarios
- Espacios y líneas vacías excesivas
- HTML malformado
- Párrafos vacíos

### Optimizaciones
- Convierte emojis de Facebook a Unicode
- Optimiza imágenes JPEG (calidad 85%)
- Optimiza imágenes PNG (compresión nivel 8)
- Elimina atributos srcset y sizes innecesarios

## 📁 Estructura del plugin

```
clean-blog-importer/
├── clean-blog-importer.php    # Archivo principal del plugin
├── includes/
│   ├── class-csv-processor.php    # Procesador de CSV
│   ├── class-content-cleaner.php  # Limpiador de contenido
│   └── class-image-processor.php  # Procesador de imágenes
└── README.md
```

## 🔧 Requisitos

- WordPress 5.0 o superior
- PHP 7.2 o superior
- Permisos para subir archivos
- Librería GD de PHP (para optimización de imágenes)

## 🛠️ Personalización

### Agregar más limpieza

Puedes extender la clase `CBI_Content_Cleaner` en `includes/class-content-cleaner.php` para agregar más patrones de limpieza:

```php
private function remove_custom_builder($content) {
    // Tu código de limpieza aquí
    return $content;
}
```

### Modificar procesamiento de imágenes

La clase `CBI_Image_Processor` en `includes/class-image-processor.php` maneja toda la lógica de imágenes. Puedes modificar la calidad de optimización o agregar más formatos.

## 🐛 Solución de problemas

### Las imágenes no se importan
- Verifica que las URLs de las imágenes sean accesibles
- Asegúrate de que WordPress tenga permisos de escritura en `wp-content/uploads`
- Revisa el límite de tiempo de ejecución de PHP

### El contenido no se limpia correctamente
- Verifica que el CSV esté codificado en UTF-8
- Asegúrate de que las comillas estén bien escapadas en el CSV

### Error de memoria
- Aumenta el límite de memoria de PHP en `wp-config.php`:
```php
define('WP_MEMORY_LIMIT', '256M');
```

## 📝 Licencia

GPL v2 o posterior

## 🤝 Contribuciones

Las contribuciones son bienvenidas. Por favor:

1. Fork el repositorio
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📧 Soporte

Si encuentras algún problema o tienes sugerencias, por favor abre un issue en GitHub.

---

Desarrollado con ❤️ para la comunidad WordPress