<?php
// pixgo.php - API Backend para integração com PixGo

// ==================== CONFIGURAÇÕES ====================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

error_log("pixgo.php carregado em " . date('Y-m-d H:i:s'));

define('PIXGO_API_KEY', getenv('PIXGO_API_KEY') ?: 'pk_1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef');
define('PIXGO_BASE_URL', 'https://pixgo.org/api/v1');
define('PIXGO_WEBHOOK_SECRET', getenv('PIXGO_WEBHOOK_SECRET') ?: 'seu_webhook_secret');

// Configuração do MySQL no Railway
define('DB_HOST', getenv('MYSQLHOST') ?: 'metro.proxy.rlw.y.net');
define('DB_PORT', getenv('MYSQLPORT') ?: '17165');
define('DB_NAME', getenv('MYSQLDATABASE') ?: 'railway');
define('DB_USER', getenv('MYSQLUSER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: 'sua_senha');

// ==================== FUNÇÕES AUXILIARES ====================
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function getPDO() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::MYSQL_ATTR_SSL_CA => null,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Erro de conexão MySQL: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Erro de conexão com banco de dados'], 500);
        }
    }
    return $pdo;
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function callPixGoAPI($endpoint, $method = 'POST', $data = null) {
    $url = PIXGO_BASE_URL . $endpoint;
    $ch = curl_init($url);
    $headers = [
        'X-API-Key: ' . PIXGO_API_KEY,
        'Content-Type: application/json',
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'message' => 'Erro na comunicação com PixGo: ' . $error];
    }
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'message' => 'Erro na comunicação com PixGo'];
    }

    $decoded = json_decode($response, true);
    
    if ($httpCode >= 400) {
        $errorMsg = $decoded['message'] ?? $decoded['error'] ?? 'Erro na API PixGo (HTTP ' . $httpCode . ')';
        return ['success' => false, 'message' => $errorMsg, 'httpCode' => $httpCode, 'details' => $decoded];
    }

    return ['success' => true, 'data' => $decoded['data'] ?? $decoded, 'httpCode' => $httpCode];
}

// ==================== ROTEAMENTO ====================
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$basePath = '/pixgo.php';
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
if ($path === '') $path = '/';

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Rota raiz para health check
if ($path === '/') {
    jsonResponse(['success' => true, 'message' => 'API PixGo funcionando!'], 200);
}

// Roteamento principal
switch ($path) {
    case '/api/create-payment':
        if ($requestMethod !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        handleCreatePayment();
        break;

    case (preg_match('/^\/api\/payment\/([a-zA-Z0-9_]+)$/', $path, $matches) ? true : false):
        if ($requestMethod !== 'GET') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        $paymentId = $matches[1];
        handleGetPayment($paymentId);
        break;

    case (preg_match('/^\/api\/payment\/([a-zA-Z0-9_]+)\/status$/', $path, $matches) ? true : false):
        if ($requestMethod !== 'GET') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        $paymentId = $matches[1];
        handleGetPaymentStatus($paymentId);
        break;

    case '/webhook/pixgo':
        if ($requestMethod !== 'POST') {
            jsonResponse(['success' => false, 'message' => 'Método não permitido'], 405);
        }
        handleWebhook();
        break;

    case '/test-db':
        testDatabaseConnection();
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Rota não encontrada: ' . $path], 404);
}

// ==================== HANDLERS ====================
function handleCreatePayment() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'JSON inválido'], 400);
    }

    // Validações básicas
    $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!$amount || $amount < 10) {
        jsonResponse(['success' => false, 'message' => 'Valor mínimo é R$ 10,00'], 422);
    }

    // Campos opcionais
    $payload = [
        'amount' => $amount
    ];

    // Adicionar campos opcionais se fornecidos
    $optionalFields = ['description', 'customer_name', 'customer_cpf', 'customer_email', 
                       'customer_phone', 'customer_address', 'external_id'];
    
    foreach ($optionalFields as $field) {
        if (isset($input[$field]) && !empty($input[$field])) {
            $payload[$field] = $input[$field];
        }
    }

    // Se tiver webhook_url, adicionar
    if (isset($input['webhook_url']) && !empty($input['webhook_url'])) {
        $payload['webhook_url'] = $input['webhook_url'];
    } else {
        // Usar nosso webhook padrão
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $payload['webhook_url'] = $protocol . $host . '/webhook/pixgo';
    }

    // Chamar API PixGo para criar pagamento
    $response = callPixGoAPI('/payment/create', 'POST', $payload);

    if (!$response['success']) {
        // Verificar se é erro de limite
        if (isset($response['details']['error']) && $response['details']['error'] === 'LIMIT_EXCEEDED') {
            jsonResponse([
                'success' => false,
                'error' => 'LIMIT_EXCEEDED',
                'message' => $response['details']['message'] ?? 'Limite excedido',
                'current_limit' => $response['details']['current_limit'] ?? null,
                'amount_requested' => $response['details']['amount_requested'] ?? $amount
            ], 400);
        }
        
        jsonResponse(['success' => false, 'message' => $response['message']], 500);
    }

    $data = $response['data'];

    // Salvar no banco de dados
    $pdo = getPDO();
    $localId = generateUUID();
    
    $sql = "INSERT INTO pixgo_payments 
            (id, payment_id, external_id, amount, description, customer_name, customer_cpf, 
             customer_email, customer_phone, customer_address, status, qr_code, qr_image_url, 
             expires_at, created_at) 
            VALUES 
            (:id, :payment_id, :external_id, :amount, :description, :customer_name, :customer_cpf,
             :customer_email, :customer_phone, :customer_address, :status, :qr_code, :qr_image_url,
             :expires_at, NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $localId,
        ':payment_id' => $data['payment_id'] ?? null,
        ':external_id' => $data['external_id'] ?? $payload['external_id'] ?? null,
        ':amount' => $amount,
        ':description' => $payload['description'] ?? null,
        ':customer_name' => $payload['customer_name'] ?? null,
        ':customer_cpf' => $payload['customer_cpf'] ?? null,
        ':customer_email' => $payload['customer_email'] ?? null,
        ':customer_phone' => $payload['customer_phone'] ?? null,
        ':customer_address' => $payload['customer_address'] ?? null,
        ':status' => $data['status'] ?? 'pending',
        ':qr_code' => $data['qr_code'] ?? null,
        ':qr_image_url' => $data['qr_image_url'] ?? null,
        ':expires_at' => $data['expires_at'] ?? null
    ]);

    jsonResponse([
        'success' => true,
        'message' => 'Pagamento criado com sucesso',
        'data' => [
            'id' => $localId,
            'payment_id' => $data['payment_id'],
            'external_id' => $data['external_id'] ?? $payload['external_id'] ?? null,
            'amount' => $amount,
            'status' => $data['status'],
            'qr_code' => $data['qr_code'],
            'qr_image_url' => $data['qr_image_url'],
            'expires_at' => $data['expires_at']
        ]
    ], 201);
}

function handleGetPayment($id) {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM pixgo_payments WHERE id = ? OR payment_id = ?");
    $stmt->execute([$id, $id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        jsonResponse(['success' => false, 'message' => 'Pagamento não encontrado'], 404);
    }
    
    jsonResponse(['success' => true, 'data' => $payment]);
}

function handleGetPaymentStatus($id) {
    // Primeiro, verificar no banco
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT status, payment_id FROM pixgo_payments WHERE id = ? OR payment_id = ?");
    $stmt->execute([$id, $id]);
    $payment = $stmt->fetch();
    
    if (!$payment) {
        jsonResponse(['success' => false, 'message' => 'Pagamento não encontrado'], 404);
    }

    // Opcional: consultar API PixGo para status atualizado
    $response = callPixGoAPI('/payment/' . $payment['payment_id'] . '/status', 'GET');
    
    if ($response['success']) {
        $status = $response['data']['status'];
        
        // Atualizar banco se status mudou
        if ($status !== $payment['status']) {
            $updateStmt = $pdo->prepare("UPDATE pixgo_payments SET status = ?, updated_at = NOW() WHERE payment_id = ?");
            $updateStmt->execute([$status, $payment['payment_id']]);
        }
        
        jsonResponse(['success' => true, 'data' => ['status' => $status]]);
    } else {
        // Se falhar, retornar status do banco
        jsonResponse(['success' => true, 'data' => ['status' => $payment['status'], 'cached' => true]]);
    }
}

function handleWebhook() {
    $payload = file_get_contents('php://input');
    $event = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';
    $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

    error_log("Webhook PixGo recebido: " . $event);
    error_log("Payload: " . $payload);

    $data = json_decode($payload, true);
    if (!$data || !isset($data['event'])) {
        jsonResponse(['success' => false, 'message' => 'Payload inválido'], 400);
    }

    $eventType = $data['event'];
    $eventData = $data['data'] ?? [];

    $pdo = getPDO();

    // Buscar pagamento pelo payment_id
    $paymentId = $eventData['payment_id'] ?? null;
    if ($paymentId) {
        $stmt = $pdo->prepare("SELECT id FROM pixgo_payments WHERE payment_id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if ($payment) {
            $localId = $payment['id'];
            
            switch ($eventType) {
                case 'payment.completed':
                    $updateSql = "UPDATE pixgo_payments SET status = 'completed', confirmed_at = ?, updated_at = NOW() WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$eventData['completed_at'] ?? date('Y-m-d H:i:s'), $localId]);
                    error_log("Pagamento $localId confirmado");
                    break;
                    
                case 'payment.expired':
                    $updateSql = "UPDATE pixgo_payments SET status = 'expired', updated_at = NOW() WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$localId]);
                    error_log("Pagamento $localId expirado");
                    break;
                    
                case 'payment.refunded':
                    $updateSql = "UPDATE pixgo_payments SET status = 'refunded', updated_at = NOW() WHERE id = ?";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$localId]);
                    error_log("Pagamento $localId estornado");
                    break;
            }
        }
    }

    jsonResponse(['success' => true, 'received' => true]);
}

function testDatabaseConnection() {
    try {
        $pdo = getPDO();
        $result = $pdo->query("SHOW TABLES LIKE 'pixgo_payments'");
        $tableExists = $result->rowCount() > 0;
        
        $response = [
            'success' => true,
            'message' => 'Conexão com banco de dados OK',
            'data' => [
                'connected' => true,
                'table_exists' => $tableExists
            ]
        ];
        
        if (!$tableExists) {
            $response['message'] .= ' (Tabela pixgo_payments não encontrada)';
        }
        
        jsonResponse($response);
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Erro ao conectar com banco de dados',
            'error' => $e->getMessage()
        ], 500);
    }
}

// Criar tabela automaticamente se não existir
function createTableIfNotExists() {
    try {
        $pdo = getPDO();
        $sql = "CREATE TABLE IF NOT EXISTS pixgo_payments (
            id CHAR(36) PRIMARY KEY,
            payment_id VARCHAR(100),
            external_id VARCHAR(50),
            amount DECIMAL(10,2) NOT NULL,
            description VARCHAR(200),
            customer_name VARCHAR(100),
            customer_cpf VARCHAR(14),
            customer_email VARCHAR(255),
            customer_phone VARCHAR(20),
            customer_address VARCHAR(500),
            status ENUM('pending', 'completed', 'expired', 'cancelled', 'refunded') DEFAULT 'pending' NOT NULL,
            qr_code TEXT,
            qr_image_url VARCHAR(255),
            expires_at DATETIME,
            created_at DATETIME NOT NULL,
            confirmed_at DATETIME,
            updated_at DATETIME,
            INDEX idx_payment_id (payment_id),
            INDEX idx_external_id (external_id),
            INDEX idx_status (status),
            INDEX idx_customer_cpf (customer_cpf),
            INDEX idx_customer_email (customer_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sql);
        error_log("Tabela pixgo_payments verificada/criada com sucesso");
    } catch (Exception $e) {
        error_log("Erro ao criar tabela: " . $e->getMessage());
    }
}

// Chamar a criação da tabela
createTableIfNotExists();
