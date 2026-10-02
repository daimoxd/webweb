<?php
    error_reporting(0);
    date_default_timezone_set("America/Mexico_City");
    header('Content-Type: application/json; charset=utf-8');

    // Origen de datos:
    // Se movió FUERA de la ruta pública directa (antes se leía con fetch
    // directo desde app.js); ahora solo este endpoint lo toca.
    define('RUTA_DATOS', __DIR__ . '/data/tarjetas.json');
    /**
    * --------------------------------------------------
    * Utilidades de respuesta
    * --------------------------------------------------
    */
    function responder($statusCode, $payload) {
        http_response_code($statusCode);
        echo json_encode($payload);
        exit;
    }

    function responderExito($mensaje, $data = null) {
        responder(200, [
            'success' => true,
            'message' => $mensaje,
            'data' => $data,
        ]);
    }

    function responderError($mensaje, $statusCode = 400) {
        responder($statusCode, [
            'success' => false,
            'error' => $mensaje,
        ]);
    }
    /*--------------------------------------------------------------------------
    * Persistencia: el archivo JSON es la única fuente de datos.
    *--------------------------------------------------------------------------*/
    function leerTarjetas() {
        if (!file_exists(RUTA_DATOS)) {
            return [];
        }

        $contenido = file_get_contents(RUTA_DATOS);
        if ($contenido === false || trim($contenido) === '') {
            return [];
        }

        $tarjetas = json_decode($contenido, true);
        // Si el JSON está corrupto se trata como lista vacía en lugar de
        // romper toda la galería.
        return is_array($tarjetas) ? $tarjetas : [];
    }
    function guardarTarjetas($tarjetas) {
        $directorio = dirname(RUTA_DATOS);
        if (!is_dir($directorio)) {
            mkdir($directorio, 0755, true);
        }

        $json = json_encode(
            $tarjetas,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            return false;
        }

        // LOCK_EX evita que dos escrituras simultáneas se pisen entre sí.
        return file_put_contents(RUTA_DATOS, $json, LOCK_EX) !== false;
    }
    $action = $_GET['action'] ?? $_POST['action'] ?? null;
    switch($action){
        case 'crear_tarjeta':
            accionCrearTarjeta();
            break;
        case 'listar_tarjeta':
            accionListarTarjetas();
            break;
        default:
            responderError('Accion no reconocida o no especificada', 404);
    }
    /*--------------------------------------------------------------------------
    * Acciones
    *--------------------------------------------------------------------------*/
    function accionCrearTarjeta() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderError('Método no permitido. Usa POST.', 405);
        }

        $inputJSON = $_POST['datos_tarjeta'] ?? file_get_contents('php://input');
        $nuevaTarjeta = json_decode($inputJSON, true);

        if (!$nuevaTarjeta || empty($nuevaTarjeta['name'])) {
            responderError('Datos inválidos o incompletos.', 400);
        }

        $nuevaTarjeta['id'] = uniqid('user_');
        $nuevaTarjeta['createdAt'] = date('Y-m-d H:i:s');

        // Campo reservado para cuando exista autenticación. Hoy no se
        // valida ni se rellena con un usuario real.
        $nuevaTarjeta['usuario_id'] = $nuevaTarjeta['usuario_id'] ?? null;

        $tarjetas = leerTarjetas();
        $tarjetas[] = $nuevaTarjeta;

        if (guardarTarjetas($tarjetas) === false) {
            responderError('Error de permisos. El servidor no permite escribir en api/data/.', 500);
        }

        responderExito('Tarjeta guardada correctamente en el servidor.', $nuevaTarjeta);
    }
    function accionListarTarjetas() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            responderError('Método no permitido. Usa GET.', 405);
        }

        $tarjetas = leerTarjetas();
        responderExito('Tarjetas obtenidas correctamente.', $tarjetas);
    }
