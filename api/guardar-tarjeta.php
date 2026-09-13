<?php
// Apagamos los errores en pantalla para que NUNCA rompan el JSON de respuesta
error_reporting(0);
date_default_timezone_set("America/Mexico_City");
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido. Usa POST.', 405);
    }

    // Leemos los datos (soporta FormData)
    $inputJSON = isset($_POST['datos_tarjeta']) ? $_POST['datos_tarjeta'] : file_get_contents('php://input');
    $nuevaTarjeta = json_decode($inputJSON, true);

    if (!$nuevaTarjeta || empty($nuevaTarjeta['name'])) {
        throw new Exception('Datos inválidos o incompletos.', 400);
    }

    $nuevaTarjeta['id'] = uniqid('user_');
    $nuevaTarjeta['createdAt'] = date('Y-m-d H:i:s');

    // EL CAMBIO MAESTRO: Guardamos el JSON en la MISMA carpeta 'api'
    $rutaArchivo = __DIR__ . '/tarjetas-usuarios.json';
    $tarjetas = [];

    // Si el archivo existe, lo leemos
    if (file_exists($rutaArchivo)) {
        $contenidoActual = file_get_contents($rutaArchivo);
        if ($contenidoActual) {
            $tarjetas = json_decode($contenidoActual, true);
            // Prevenir errores si el archivo está corrupto
            if (!is_array($tarjetas)) {
                $tarjetas = [];
            }
        }
    }

    $tarjetas[] = $nuevaTarjeta;

    // Intentamos guardar el archivo
    $resultadoGuardado = file_put_contents($rutaArchivo, json_encode($tarjetas, JSON_PRETTY_PRINT));

    if ($resultadoGuardado === false) {
        throw new Exception('Error de permisos. El servidor no permite a PHP escribir en la carpeta api/.', 500);
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Tarjeta guardada correctamente en el servidor.',
        'data' => $nuevaTarjeta
    ]);

} catch (Exception $e) {
    // Si algo falla, atrapamos el error y lo devolvemos como JSON válido
    $codigoHttp = $e->getCode() ? $e->getCode() : 500;
    http_response_code($codigoHttp);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
