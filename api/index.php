<?php
    error_reporting(0);
    date_default_timezone_set("America/Mexico_City");
    header('Content-Type: application/json; charset=utf-8');

    // Sesión PHP: se usa para login/logout y para saber quién guarda tarjetas.
    session_start();

    /*--------------------------------------------------------------------------
    * Configuración de MySQL.
    * En Docker las variables las inyecta docker-compose.yml. Los valores por
    * defecto permiten probar el endpoint contra un MySQL local sin configurar
    * nada; en producción cámbialos con un archivo .env o variables de entorno.
    *--------------------------------------------------------------------------*/
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: 'sp123_web2');
    define('DB_USER', getenv('DB_USER') ?: 'sp123_user');
    // getenv() devuelve false cuando la variable no existe; se permite una
    // contraseña vacía si alguien la define explícitamente así.
    define('DB_PASSWORD', getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : 'sp123_pass');

    // api/data/tarjetas.json es el unico JSON que queda: se lee una sola vez
    // para importar las tarjetas creadas antes de usar MySQL. Los usuarios
    // viven unicamente en la base de datos.
    define('RUTA_TARJETAS_JSON', __DIR__ . '/data/tarjetas.json');
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
    * Persistencia: MySQL mediante PDO (extensión pdo_mysql).
    *--------------------------------------------------------------------------*/
    function conexion() {
        static $pdo = null;

        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prepared statements reales: evita interpolación en el cliente.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            inicializarEsquema($pdo);
        } catch (PDOException $e) {
            // No se exponen credenciales ni detalles del motor al cliente.
            responderError('No se pudo conectar con la base de datos.', 500);
        }

        return $pdo;
    }

    function inicializarEsquema(PDO $pdo) {
        // utf8mb4_unicode_ci hace que email y nickname sean únicos sin
        // distinguir mayúsculas, igual que el strcasecmp() anterior.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usuarios (
                id         VARCHAR(64)  NOT NULL,
                nombre     VARCHAR(150) NOT NULL,
                nacimiento DATE         NOT NULL,
                educacion  VARCHAR(50)  NOT NULL,
                telefono   VARCHAR(30)  NOT NULL,
                horas      VARCHAR(10)  NOT NULL,
                email      VARCHAR(190) NOT NULL,
                nickname   VARCHAR(80)  NOT NULL,
                password   VARCHAR(255) NOT NULL,
                createdAt  DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_usuarios_email (email),
                UNIQUE KEY uq_usuarios_nickname (nickname)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Las tarjetas tienen campos libres (name, bio, avatarUrl, color...).
        // Se guardan como JSON para no perder ninguno y se indexan id,
        // usuario_id y createdAt para poder consultarlas y ordenarlas.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tarjetas (
                seq       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id        VARCHAR(64)     NOT NULL,
                usuario_id VARCHAR(64)    NULL,
                datos     JSON            NOT NULL,
                createdAt DATETIME        NOT NULL,
                PRIMARY KEY (seq),
                UNIQUE KEY uq_tarjetas_id (id),
                KEY idx_tarjetas_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Marca las importaciones ya ejecutadas. Sin esto, al borrar todos los
        // usuarios la tabla quedaría vacía y el JSON se volvería a importar.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS migraciones (
                clave        VARCHAR(64) NOT NULL,
                ejecutada_en DATETIME    NOT NULL,
                PRIMARY KEY (clave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        migrarTarjetasSiHaceFalta($pdo);
    }

    function leerJson($ruta) {
        if (!is_readable($ruta)) {
            return [];
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false || trim($contenido) === '') {
            return [];
        }

        $datos = json_decode($contenido, true);
        return is_array($datos) ? $datos : [];
    }

    // Importa api/data/tarjetas.json una sola vez, cuando la tabla está vacía.
    // Así las tarjetas creadas antes de migrar a MySQL no se pierden.
    function migrarTarjetasSiHaceFalta(PDO $pdo) {
        // Se conserva la clave de la migración anterior para que, si ya corrió,
        // no se vuelva a importar nada.
        $clave = 'json_inicial';

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM migraciones WHERE clave = ?');
        $stmt->execute([$clave]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $hayTarjetas = (int) $pdo->query('SELECT COUNT(*) FROM tarjetas')->fetchColumn();

        if ($hayTarjetas === 0) {
            $tarjetas = leerJson(RUTA_TARJETAS_JSON);
            if ($tarjetas) {
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO tarjetas (id, usuario_id, datos, createdAt)
                     VALUES (?, ?, ?, ?)'
                );

                foreach ($tarjetas as $tarjeta) {
                    if (empty($tarjeta['id'])) {
                        continue;
                    }

                    $datos = $tarjeta;
                    unset($datos['id'], $datos['createdAt'], $datos['usuario_id']);

                    $stmt->execute([
                        $tarjeta['id'],
                        // Los usuarios ya no se migran desde JSON: las tarjetas
                        // heredadas quedan sin dueño.
                        null,
                        json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        $tarjeta['createdAt'] ?? date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        // Se marca aunque no hubiera JSON: la importación inicial ya se evaluó.
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO migraciones (clave, ejecutada_en) VALUES (?, ?)'
        );
        $stmt->execute([$clave, date('Y-m-d H:i:s')]);
    }

    // Quita el hash de la contraseña antes de devolver un usuario al cliente.
    function usuarioPublico($usuario) {
        unset($usuario['password']);
        return $usuario;
    }
    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    try {
        switch($action){
            case 'crear_tarjeta':
                accionCrearTarjeta();
                break;
            case 'listar_tarjeta':
            case 'listar_tarjetas':
                accionListarTarjetas();
                break;
            case 'registrar_usuario':
                accionRegistrarUsuario();
                break;
            case 'login':
                accionLogin();
                break;
            case 'logout':
                accionLogout();
                break;
            case 'check_session':
                accionCheckSession();
                break;
            default:
                responderError('Accion no reconocida o no especificada', 404);
        }
    } catch (PDOException $e) {
        responderError('Error al acceder a la base de datos.', 500);
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

        if (!is_array($nuevaTarjeta) || trim((string) ($nuevaTarjeta['name'] ?? '')) === '') {
            responderError('Datos inválidos o incompletos.', 400);
        }

        $nuevaTarjeta['id'] = uniqid('user_');
        $nuevaTarjeta['createdAt'] = date('Y-m-d H:i:s');

        // Si hay una sesión activa, la tarjeta queda ligada a ese usuario.
        $nuevaTarjeta['usuario_id'] = $_SESSION['usuario']['id'] ?? null;

        // id, usuario_id y createdAt tienen columnas propias; el resto de los
        // campos libres de la tarjeta se conserva en la columna JSON `datos`.
        $datos = $nuevaTarjeta;
        unset($datos['id'], $datos['createdAt'], $datos['usuario_id']);

        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            responderError('No se pudo serializar la tarjeta.', 500);
        }

        $stmt = conexion()->prepare(
            'INSERT INTO tarjetas (id, usuario_id, datos, createdAt) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $nuevaTarjeta['id'],
            $nuevaTarjeta['usuario_id'],
            $json,
            $nuevaTarjeta['createdAt'],
        ]);

        responderExito('Tarjeta guardada correctamente en el servidor.', $nuevaTarjeta);
    }
    function accionListarTarjetas() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            responderError('Método no permitido. Usa GET.', 405);
        }

        $filas = conexion()
            ->query('SELECT id, usuario_id, datos, createdAt FROM tarjetas ORDER BY seq ASC')
            ->fetchAll();

        $tarjetas = array_map(function ($fila) {
            $tarjeta = json_decode($fila['datos'], true);
            if (!is_array($tarjeta)) {
                $tarjeta = [];
            }

            $tarjeta['id'] = $fila['id'];
            $tarjeta['createdAt'] = $fila['createdAt'];
            $tarjeta['usuario_id'] = $fila['usuario_id'];

            return $tarjeta;
        }, $filas);

        responderExito('Tarjetas obtenidas correctamente.', $tarjetas);
    }
    function accionRegistrarUsuario() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderError('Método no permitido. Usa POST.', 405);
        }

        // Mismos campos que el formulario de registro del frontend.
        $camposObligatorios = [
            'nombre', 'nacimiento', 'educacion', 'telefono',
            'horas', 'email', 'nickname', 'password',
        ];
        foreach ($camposObligatorios as $campo) {
            if (!isset($_POST[$campo]) || trim((string) $_POST[$campo]) === '') {
                responderError("El campo \"$campo\" es obligatorio.", 422);
            }
        }

        $email = trim($_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responderError('El correo electrónico no es válido.', 422);
        }

        $nacimiento = trim($_POST['nacimiento']);
        $fecha = DateTime::createFromFormat('Y-m-d', $nacimiento);
        if (!$fecha || $fecha->format('Y-m-d') !== $nacimiento) {
            responderError('La fecha de nacimiento no es válida.', 422);
        }

        $password = (string) $_POST['password'];
        if (strlen($password) < 6) {
            responderError('La contraseña debe tener al menos 6 caracteres.', 422);
        }

        $nickname = trim($_POST['nickname']);
        $pdo = conexion();

        // Comprobación para devolver un mensaje claro. Los índices UNIQUE de la
        // tabla siguen protegiendo ante dos registros simultáneos.
        $stmt = $pdo->prepare(
            'SELECT id, email, nickname FROM usuarios WHERE email = ? OR nickname = ? LIMIT 1'
        );
        $stmt->execute([$email, $nickname]);
        $usuarioExistente = $stmt->fetch();

        if ($usuarioExistente) {
            if (strcasecmp($usuarioExistente['email'], $email) === 0) {
                responderError('Ya existe una cuenta registrada con ese correo.', 409);
            }
            responderError('Ese nickname ya está en uso.', 409);
        }

        $usuario = [
            'id'         => uniqid('user_'),
            'nombre'     => trim($_POST['nombre']),
            'nacimiento' => $nacimiento,
            'educacion'  => trim($_POST['educacion']),
            'telefono'   => trim($_POST['telefono']),
            'horas'      => trim($_POST['horas']),
            'email'      => $email,
            'nickname'   => $nickname,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'createdAt'  => date('Y-m-d H:i:s'),
        ];

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios
                    (id, nombre, nacimiento, educacion, telefono, horas, email, nickname, password, createdAt)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(array_values($usuario));
        } catch (PDOException $e) {
            // 23000 = violación de llave única (carrera entre dos registros).
            if ($e->getCode() === '23000') {
                responderError('El correo o el nickname ya están registrados.', 409);
            }
            throw $e;
        }

        responderExito('Registro exitoso. Ahora puedes iniciar sesión.', usuarioPublico($usuario));
    }
    function accionLogin() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            responderError('Método no permitido. Usa POST.', 405);
        }

        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            responderError('Correo y contraseña son obligatorios.', 422);
        }

        // La colación utf8mb4_unicode_ci hace que la comparación no distinga
        // mayúsculas de minúsculas, igual que antes con strcasecmp().
        $stmt = conexion()->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $usuarioEncontrado = $stmt->fetch();

        // Mismo mensaje para correo inexistente y contraseña incorrecta:
        // así no se filtra qué correos están registrados.
        if (
            !$usuarioEncontrado
            || !isset($usuarioEncontrado['password'])
            || !password_verify($password, $usuarioEncontrado['password'])
        ) {
            responderError('Correo o contraseña incorrectos.', 401);
        }

        // Evita fijación de sesión al iniciar sesión.
        session_regenerate_id(true);
        $usuario = usuarioPublico($usuarioEncontrado);
        $_SESSION['usuario'] = $usuario;

        responderExito('Bienvenido de nuevo.', $usuario);
    }
    function accionLogout() {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        responderExito('Sesión cerrada correctamente.');
    }
    function accionCheckSession() {
        if (empty($_SESSION['usuario'])) {
            responderError('No hay una sesión activa.', 401);
        }

        responderExito('Sesión activa.', $_SESSION['usuario']);
    }
