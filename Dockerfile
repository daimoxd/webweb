# Imagen base con Apache y PHP 8.5 (ultima estable). Incluye el parche de
# CVE-2026-109618 / CVE-2026-109619 desde 8.5.12. Reconstruye con
# `docker compose build --pull` para recibir futuros parches de seguridad.
FROM php:8.5-apache

# No hay dependencias externas (sin Composer). Lo unico que hace falta es el
# driver de MySQL para PDO, que es como la API habla con el servicio `db`.
RUN docker-php-ext-install pdo_mysql

# Apache sirve el sitio desde /var/www/html. El proyecto ya usa rutas
# relativas (api/index.php, css/..., js/..., src/assets/...), por lo que
# el DocumentRoot puede ser la raiz del proyecto tal cual.
WORKDIR /var/www/html

# Copiamos primero los archivos y despues activamos mod_rewrite, para no
# invalidar la cache de capas en cada cambio.
COPY . /var/www/html

# El endpoint responde JSON en UTF-8 con acentos; forzamos el charset por
# defecto para no depender de que el navegador detecte la codificacion.
RUN { \
      echo 'AddDefaultCharset UTF-8'; \
    } > /etc/apache2/conf-available/charset.conf \
    && a2enconf charset

# --- Permisos y datos heredados --------------------------------------------
# api/data/ ya no se escribe: MySQL es la fuente de datos. Los JSON que queden
# ahi solo se leen una vez para importarlos cuando las tablas estan vacias.
RUN mkdir -p /var/www/html/api/data \
    && chown -R www-data:www-data /var/www/html/api/data \
    && chmod 775 /var/www/html/api/data

# Bloquea el acceso HTTP directo a api/data/ (por si queda el usuarios.json
# antiguo con hashes de contrasenas). PHP puede seguir leyendo los archivos.
RUN { \
      echo '<Directory /var/www/html/api/data>'; \
      echo '    Require all denied'; \
      echo '</Directory>'; \
    } > /etc/apache2/conf-available/api-data.conf \
    && a2enconf api-data

EXPOSE 80

CMD ["apache2-foreground"]
