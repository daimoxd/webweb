# Imagen base con Apache y PHP ya configurado.
FROM php:8.3-apache

# El endpoint usa __DIR__, date() y json_encode; no hay dependencias
# externas (sin Composer), asi que no hace falta instalar nada mas.

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

# --- Permisos de escritura -------------------------------------------------
# api/data/ es la unica carpeta que el endpoint necesita escribir (tarjetas.json).
# En un bind mount el propietario es el del host, no www-data, asi que
# ajustamos el grupo para que ambos puedan escribir.
RUN mkdir -p /var/www/html/api/data \
    && chown -R www-data:www-data /var/www/html/api/data \
    && chmod 775 /var/www/html/api/data

EXPOSE 80

CMD ["apache2-foreground"]