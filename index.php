<?php
require __DIR__ . '/../app/core/helpers.php';
require __DIR__ . '/../app/core/Database.php';
require __DIR__ . '/../app/core/Auth.php';
require __DIR__ . '/../app/core/MercadoPago.php';

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($rawPath !== '/' && strlen($rawPath) > 1 && str_ends_with($rawPath, '/')) {
    $cleanPath = rtrim($rawPath, '/');
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $cleanPath . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}
$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$basePath = trim((string)parse_url(config()['site_url'], PHP_URL_PATH), '/');
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = trim(substr($path, strlen($basePath)), '/');
}
publish_due_posts();

$redirect = find_redirect($path);
if ($redirect) {
    register_redirect_hit((int)$redirect['id']);
    header('Location: ' . $redirect['target_url'], true, (int)$redirect['status_code']);
    exit;
}

if ($path === 'admin/checkout-config') {
    if (!Auth::user()) {
        header('Location: ' . site_url('/admin?action=login'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (['smtp_host','smtp_port','smtp_security','smtp_user','smtp_from_email','smtp_from_name','checkout_internal_email','mp_environment','mp_test_access_token','mp_test_public_key','mp_production_access_token','mp_production_public_key'] as $key) {
            save_setting($key, trim((string)($_POST[$key] ?? '')));
        }
        if (trim((string)($_POST['smtp_pass'] ?? '')) !== '') {
            save_setting('smtp_pass', trim((string)$_POST['smtp_pass']));
        }
        header('Location: ' . site_url('/admin/checkout-config?saved=1'));
        exit;
    }
    render_checkout_admin_config();
    exit;
}

if ($path === 'admin/checkout-email-test') {
    if (!Auth::user()) {
        header('Location: ' . site_url('/admin?action=login'));
        exit;
    }
    $result = null;
    $to = trim((string)($_POST['test_email'] ?? setting('checkout_internal_email', 'secretaria@ibetp.com.br')));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subject = 'Teste de e-mail automático — IBETP';
        $html = '<h2>Teste de e-mail automático IBETP</h2>'
            . '<p>Este é um envio de teste do sistema de pré-matrícula e checkout.</p>'
            . '<p>Quando um pagamento aprovado for confirmado pelo Mercado Pago, o aluno receberá a mensagem institucional e a Secretaria IBETP receberá os dados completos da pré-matrícula.</p>'
            . '<p><strong>IBETP — Instituto Brasileiro de Educação Técnica e Profissional</strong></p>';
        $result = checkout_send_email($to, 'Teste IBETP', $subject, $html);
    }
    render_checkout_email_test($to, $result);
    exit;
}

if ($path === 'admin/criar-acesso-seguro') {
    render_admin_access_reset();
    exit;
}

if ($path === 'admin/pre-matriculas') {
    if (!Auth::user()) {
        header('Location: ' . site_url('/admin?action=login'));
        exit;
    }
    checkout_ensure_schema();
    $notice = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_paid_enrollment_id'])) {
        $id = (int)$_POST['confirm_paid_enrollment_id'];
        Database::exec("UPDATE pre_enrollments SET payment_status='approved', payment_id=COALESCE(NULLIF(payment_id,''),'confirmacao-manual'), updated_at=NOW() WHERE id=?", [$id]);
        $result = checkout_send_approved_emails($id, true);
        $notice = ($result['student'] ?? false) && ($result['internal'] ?? false)
            ? 'Pagamento confirmado manualmente e e-mails enviados.'
            : 'Pagamento confirmado manualmente, mas algum e-mail falhou. Confira o SMTP e tente reenviar.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_enrollment_id'])) {
        $result = checkout_send_approved_emails((int)$_POST['resend_enrollment_id'], true);
        $notice = ($result['student'] ?? false) && ($result['internal'] ?? false)
            ? 'E-mails reenviados para o aluno e para a secretaria.'
            : 'Tentativa realizada. Se algum e-mail falhou, confira a configuração SMTP e tente novamente.';
    }
    render_checkout_enrollments_admin($notice);
    exit;
}

if ($path === 'admin' || str_starts_with($path, 'admin/')) {
    require __DIR__ . '/../app/admin/panel.php';
    exit;
}

if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $rows = Database::all("SELECT slug, type, updated_at, featured_image AS image, title FROM posts WHERE status='published' AND noindex=0 UNION SELECT slug, 'product' type, updated_at, image, title FROM products WHERE status='active'");
    $seenProductSlugs = [];
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    echo '<url><loc>' . e(site_url('/')) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>';
    foreach ($rows as $row) {
        if ($row['type'] === 'product' && !product_publicly_visible($row)) continue;
        $prefix = $row['type'] === 'glossary' ? 'glossario' : ($row['type'] === 'product' ? 'produto' : 'blog');
        if ($row['type'] === 'page') $prefix = '';
        if ($row['type'] === 'product') $seenProductSlugs[ibetp_slug_key((string)$row['slug'])] = true;
        $priority = $row['type'] === 'product' ? '0.9' : ($row['type'] === 'page' ? '0.8' : '0.7');
        echo '<url><loc>' . e(site_url($prefix . '/' . $row['slug'])) . '</loc><lastmod>' . e(substr($row['updated_at'], 0, 10)) . '</lastmod><changefreq>weekly</changefreq><priority>' . $priority . '</priority>';
        $rowImage = $row['type'] === 'product' ? premium_product_image($row) : (string)($row['image'] ?? '');
        if (!empty($rowImage)) {
            echo '<image:image><image:loc>' . e(absolute_asset($rowImage)) . '</image:loc><image:title>' . e($row['title']) . '</image:title></image:image>';
        }
        echo '</url>';
    }
    foreach (official_no_internship_technical_products() as $product) {
        $slugKey = ibetp_slug_key((string)$product['slug']);
        if (isset($seenProductSlugs[$slugKey])) continue;
        echo '<url><loc>' . e(site_url('/produto/' . $product['slug'])) . '</loc><lastmod>' . e(substr((string)$product['updated_at'], 0, 10)) . '</lastmod><changefreq>weekly</changefreq><priority>0.9</priority>';
        $productImage = premium_product_image($product);
        if (!empty($productImage)) {
            echo '<image:image><image:loc>' . e(absolute_asset($productImage)) . '</image:loc><image:title>' . e($product['title']) . '</image:title></image:image>';
        }
        echo '</url>';
    }
    foreach (array_merge(official_technologist_products(), official_post_technical_products(), official_sequential_products(), official_postgrad_products()) as $product) {
        $slugKey = ibetp_slug_key((string)$product['slug']);
        if (isset($seenProductSlugs[$slugKey])) continue;
        echo '<url><loc>' . e(site_url('/produto/' . $product['slug'])) . '</loc><lastmod>' . e(substr((string)$product['updated_at'], 0, 10)) . '</lastmod><changefreq>weekly</changefreq><priority>0.9</priority>';
        $productImage = premium_product_image($product);
        if (!empty($productImage)) {
            echo '<image:image><image:loc>' . e(absolute_asset($productImage)) . '</image:loc><image:title>' . e($product['title']) . '</image:title></image:image>';
        }
        echo '</url>';
    }
    echo '</urlset>';
    exit;
}

if ($path === 'feed.xml') {
    header('Content-Type: application/rss+xml; charset=utf-8');
    $rows = Database::all("SELECT slug, type, title, excerpt, content, updated_at, published_at FROM posts WHERE status='published' AND noindex=0 ORDER BY published_at DESC LIMIT 50");
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>';
    echo '<title>Blog e Glossário IBETP</title><link>' . e(site_url('/')) . '</link><description>Conteúdos do Instituto Brasileiro de Educação Técnica e Profissional</description>';
    foreach ($rows as $row) {
        $prefix = $row['type'] === 'glossary' ? 'glossario' : 'blog';
        $url = site_url($prefix . '/' . $row['slug']);
        echo '<item><title>' . e($row['title']) . '</title><link>' . e($url) . '</link><guid>' . e($url) . '</guid><pubDate>' . e(date(DATE_RSS, strtotime($row['published_at'] ?: $row['updated_at']))) . '</pubDate><description>' . e($row['excerpt'] ?: excerpt($row['content'], 240)) . '</description></item>';
    }
    echo '</channel></rss>';
    exit;
}

if ($path === 'checkout' && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    $product = checkout_product_from_request();
    if (!$product) { http_response_code(404); layout('Produto indisponível', 'Produto indisponível.', '<main><h1>Produto indisponível</h1></main>', null, true); exit; }
    $product = product_for_checkout($product);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['pre_enrollment_submit'] ?? '') === '1') {
        try {
            checkout_ensure_schema();
            $data = checkout_validate_pre_enrollment($_POST);
            $enrollmentId = checkout_save_pre_enrollment($product, $data);
            $preference = checkout_create_preference($product, $enrollmentId, $data);
            header('Location: ' . $preference['init_point']);
        } catch (Throwable $e) {
            render_checkout_form($product, $_POST, [$e->getMessage()]);
        }
        exit;
    }

    render_checkout_form($product);
    exit;
}

if ($path === 'mercado-pago/webhook' && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    checkout_ensure_schema();
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];
    if (!$payload) $payload = $_GET;
    $payment = checkout_fetch_webhook_payment($payload);
    $external = $payment['external_reference'] ?? ($payload['external_reference'] ?? ($_GET['external_reference'] ?? null));
    $status = $payment['status'] ?? ($payload['status'] ?? ($payload['action'] ?? 'received'));
    $paymentId = $payment['id'] ?? checkout_payload_payment_id($payload);
    $responsePayload = $payment ? json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw;

    if ($external) {
        Database::exec("UPDATE payment_orders SET status=?, payment_id=?, raw_response=?, updated_at=NOW() WHERE external_reference=?", [$status, (string)$paymentId, $responsePayload, $external]);
        checkout_mark_enrollment_payment($external, $status, (string)$paymentId, $responsePayload);
    } else {
        Database::exec("INSERT INTO payment_orders (status, payment_id, raw_response) VALUES (?, ?, ?)", [$status, (string)$paymentId, $responsePayload]);
    }
    http_response_code(200);
    echo 'OK';
    exit;
}

if (in_array($path, ['pagamento/sucesso', 'pagamento/pendente', 'pagamento/erro'], true)) {
    checkout_ensure_schema();
    checkout_process_return_payment($_GET);
    $kind = str_ends_with($path, 'sucesso') ? 'success' : (str_ends_with($path, 'pendente') ? 'pending' : 'error');
    $title = $kind === 'success' ? 'Pagamento recebido' : ($kind === 'pending' ? 'Pagamento em análise' : 'Pagamento não concluído');
    $message = $kind === 'success'
        ? 'Recebemos o retorno do Mercado Pago. A matrícula será efetivada em até 24 horas úteis após a confirmação do pagamento.'
        : ($kind === 'pending' ? 'O pagamento ainda está em análise. Assim que houver confirmação, o IBETP dará sequência ao atendimento.' : 'O pagamento não foi concluído. Você pode tentar novamente ou falar com o IBETP pelo WhatsApp.');
    ob_start(); ?><main class="checkout-page"><section class="checkout-result"><p class="eyebrow">IBETP</p><h1><?= e($title) ?></h1><p><?= e($message) ?></p><a class="btn primary" href="<?= e(site_url('/cursos')) ?>">Voltar aos cursos</a><a class="btn outline" href="https://wa.me/5521983177702">Falar com o IBETP</a></section></main><?php
    layout($title . ' | IBETP', $message, ob_get_clean(), null, $kind === 'error');
    exit;
}

function card_summary(array $item, int $limit = 92): string {
    $text = trim((string)($item['excerpt'] ?? $item['short_description'] ?? ''));
    if ($text === '') $text = excerpt((string)($item['content'] ?? $item['description'] ?? ''), $limit + 40);
    return excerpt($text, $limit);
}

function item_label(array $item, string $fallback = 'IBETP'): string {
    $label = trim((string)($item['category'] ?? $item['type'] ?? $fallback));
    return $label === 'glossary' ? 'Glossário' : ($label === 'post' ? 'Blog' : $label);
}

function product_price_label(array $product): string {
    $price = product_effective_price($product);
    return $price > 0 ? 'R$ ' . number_format($price, 2, ',', '.') : 'Consultar';
}

function product_is_competency_certification(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    $normalized = ibetp_slug_key($text);
    return str_contains($text, 'competência') || str_contains($text, 'competencia') || str_contains($normalized, 'competencia');
}

function product_effective_price(array $product): float {
    if (product_is_competency_certification($product)) {
        return 1299.90;
    }
    if (product_is_post_technical($product)) {
        return 799.00;
    }
    if (product_is_sequential($product)) {
        return 699.00;
    }
    if (product_is_postgrad($product)) {
        return 799.00;
    }
    return (float)($product['price'] ?? 0);
}

function product_for_checkout(array $product): array {
    if (product_is_competency_certification($product)) {
        $product['price'] = 1299.90;
    }
    if (product_is_post_technical($product)) {
        $product['price'] = 799.00;
    }
    if (product_is_sequential($product)) {
        $product['price'] = 699.00;
    }
    if (product_is_postgrad($product)) {
        $product['price'] = 799.00;
    }
    return $product;
}

function product_checkout_enabled(array $product): bool {
    return (int)($product['checkout_enabled'] ?? 0) === 1;
}

function checkout_product_from_request(): ?array {
    $id = (int)($_POST['product_id'] ?? ($_GET['product_id'] ?? 0));
    $slug = trim((string)($_POST['product_slug'] ?? ($_GET['produto'] ?? '')));
    if ($id === 900001 || $slug === 'teste-checkout-6-reais') {
        return checkout_test_product();
    }
    if ($id !== 0) {
        $product = Database::one("SELECT * FROM products WHERE id=? AND status='active' AND checkout_enabled=1", [$id]);
        if (!$product && $id < 0) $product = official_technical_product_by_id($id);
        if (!$product && $id < 0) $product = official_technologist_product_by_id($id);
        if (!$product && $id < 0) $product = official_post_technical_product_by_id($id);
        if (!$product && $id < 0) $product = official_sequential_product_by_id($id);
        if (!$product && $id < 0) $product = official_postgrad_product_by_id($id);
        return $product && product_checkout_enabled($product) ? $product : null;
    }
    if ($slug !== '') {
        $product = Database::one("SELECT * FROM products WHERE slug=? AND status='active' AND checkout_enabled=1", [$slug]);
        if (!$product) $product = official_technologist_product_by_slug($slug);
        if (!$product) $product = official_post_technical_product_by_slug($slug);
        if (!$product) $product = official_sequential_product_by_slug($slug);
        if (!$product) $product = official_postgrad_product_by_slug($slug);
        if (!$product) $product = official_technical_product_by_slug($slug);
        return $product && product_checkout_enabled($product) ? $product : null;
    }
    return null;
}

function checkout_test_product(): array {
    return [
        'id' => 900001,
        'slug' => 'teste-checkout-6-reais',
        'title' => 'Produto de Teste — Checkout IBETP',
        'category' => 'Teste interno',
        'short_description' => 'Produto oculto para testar pré-matrícula, pagamento real no Mercado Pago e envio automático de e-mails.',
        'description' => 'Produto técnico interno usado exclusivamente para validação do fluxo de checkout, cadastro e comunicação institucional.',
        'price' => 6.00,
        'currency' => 'BRL',
        'checkout_enabled' => 1,
        'image' => '/assets/hero-industria-profissionais-tecnicos-premium.png',
        'image_path' => '/assets/hero-industria-profissionais-tecnicos-premium.png',
        'status' => 'active',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function checkout_ensure_schema(): void {
    try {
        $driver = config()['db']['driver'] ?? 'sqlite';
        if ($driver === 'sqlite') {
            Database::pdo()->exec("CREATE TABLE IF NOT EXISTS pre_enrollments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER,
                product_slug TEXT,
                product_title TEXT NOT NULL,
                product_category TEXT,
                amount REAL,
                full_name TEXT NOT NULL,
                birth_date TEXT NOT NULL,
                cpf TEXT NOT NULL,
                phone TEXT NOT NULL,
                email TEXT NOT NULL,
                sex TEXT NOT NULL,
                cep TEXT NOT NULL,
                address TEXT NOT NULL,
                number TEXT NOT NULL,
                complement TEXT,
                district TEXT NOT NULL,
                city TEXT NOT NULL,
                state TEXT NOT NULL,
                lgpd_accept INTEGER DEFAULT 0,
                payment_status TEXT DEFAULT 'created',
                external_reference TEXT,
                payment_id TEXT,
                raw_payment TEXT,
                student_email_sent_at TEXT,
                internal_email_sent_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        } else {
            Database::pdo()->exec("CREATE TABLE IF NOT EXISTS pre_enrollments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NULL,
                product_slug VARCHAR(190),
                product_title VARCHAR(255) NOT NULL,
                product_category VARCHAR(190),
                amount DECIMAL(10,2),
                full_name VARCHAR(255) NOT NULL,
                birth_date VARCHAR(20) NOT NULL,
                cpf VARCHAR(30) NOT NULL,
                phone VARCHAR(40) NOT NULL,
                email VARCHAR(190) NOT NULL,
                sex VARCHAR(40) NOT NULL,
                cep VARCHAR(20) NOT NULL,
                address VARCHAR(255) NOT NULL,
                number VARCHAR(50) NOT NULL,
                complement VARCHAR(255),
                district VARCHAR(190) NOT NULL,
                city VARCHAR(190) NOT NULL,
                state VARCHAR(20) NOT NULL,
                lgpd_accept TINYINT DEFAULT 0,
                payment_status VARCHAR(80) DEFAULT 'created',
                external_reference VARCHAR(190),
                payment_id VARCHAR(190),
                raw_payment MEDIUMTEXT,
                student_email_sent_at DATETIME NULL,
                internal_email_sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        }
        foreach ([
            "ALTER TABLE payment_orders ADD COLUMN enrollment_id INTEGER",
            "ALTER TABLE payment_orders ADD COLUMN payment_id TEXT"
        ] as $sql) {
            try { Database::pdo()->exec($sql); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Não foi possível preparar a estrutura de matrícula. ' . $e->getMessage());
    }
}

function checkout_required_fields(): array {
    return [
        'full_name' => 'Nome completo',
        'birth_date' => 'Data de nascimento',
        'cpf' => 'CPF',
        'phone' => 'Telefone celular',
        'email' => 'E-mail',
        'sex' => 'Sexo',
        'cep' => 'CEP',
        'address' => 'Endereço',
        'number' => 'Número',
        'district' => 'Bairro',
        'city' => 'Cidade',
        'state' => 'Estado',
    ];
}

function checkout_validate_pre_enrollment(array $input): array {
    $data = [];
    $missing = [];
    foreach (checkout_required_fields() as $key => $label) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '') $missing[] = $label;
        $data[$key] = $value;
    }
    $data['complement'] = trim((string)($input['complement'] ?? ''));
    $data['lgpd_accept'] = !empty($input['lgpd_accept']) ? 1 : 0;
    if ($missing) {
        throw new RuntimeException('Preencha os campos obrigatórios: ' . implode(', ', $missing) . '.');
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Informe um e-mail válido.');
    }
    if (!$data['lgpd_accept']) {
        throw new RuntimeException('Para seguir, é necessário confirmar a autorização de uso dos dados para pré-matrícula e atendimento educacional.');
    }
    return $data;
}

function checkout_save_pre_enrollment(array $product, array $data): int {
    Database::exec(
        "INSERT INTO pre_enrollments (
            product_id, product_slug, product_title, product_category, amount,
            full_name, birth_date, cpf, phone, email, sex, cep, address, number, complement, district, city, state, lgpd_accept
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int)($product['id'] ?? 0),
            (string)($product['slug'] ?? ''),
            (string)($product['title'] ?? ''),
            product_category_label($product),
            product_effective_price($product),
            $data['full_name'],
            $data['birth_date'],
            $data['cpf'],
            $data['phone'],
            $data['email'],
            $data['sex'],
            $data['cep'],
            $data['address'],
            $data['number'],
            $data['complement'],
            $data['district'],
            $data['city'],
            $data['state'],
            $data['lgpd_accept'],
        ]
    );
    return (int)Database::pdo()->lastInsertId();
}

function checkout_mp_token(): string {
    $cfg = config();
    $env = setting('mp_environment', $cfg['mercado_pago']['environment'] ?? 'test') === 'production' ? 'production' : 'test';
    $tokenKey = $env === 'production' ? 'mp_production_access_token' : 'mp_test_access_token';
    $fallbackKey = $env === 'production' ? 'production_access_token' : 'test_access_token';
    $token = trim(setting($tokenKey, $cfg['mercado_pago'][$fallbackKey] ?? '') ?? '');
    if ($token === '') {
        throw new RuntimeException('Mercado Pago ainda não está configurado no painel administrativo.');
    }
    return $token;
}

function checkout_create_preference(array $product, int $enrollmentId, array $data): array {
    $external = 'ibetp-matricula-' . $enrollmentId . '-' . time();
    [$payerName, $payerSurname] = checkout_split_full_name((string)$data['full_name']);
    [$phoneArea, $phoneNumber] = checkout_split_phone((string)$data['phone']);
    $payload = [
        'items' => [[
            'title' => product_primary_payment_label($product) . ' — ' . $product['title'],
            'description' => excerpt((string)($product['short_description'] ?? $product['description'] ?? $product['title']), 220),
                'picture_url' => product_image_url($product),
            'quantity' => 1,
            'currency_id' => $product['currency'] ?: 'BRL',
            'unit_price' => (float)product_effective_price($product),
        ]],
        'payer' => [
            'name' => $payerName,
            'surname' => $payerSurname,
            'email' => $data['email'],
            'phone' => [
                'area_code' => $phoneArea,
                'number' => $phoneNumber,
            ],
            'identification' => [
                'type' => 'CPF',
                'number' => preg_replace('/\D+/', '', (string)$data['cpf']),
            ],
            'address' => [
                'zip_code' => preg_replace('/\D+/', '', (string)$data['cep']),
                'street_name' => (string)$data['address'],
                'street_number' => (string)$data['number'],
            ],
        ],
        'external_reference' => $external,
        'notification_url' => site_url('/mercado-pago/webhook'),
        'back_urls' => [
            'success' => site_url('/pagamento/sucesso'),
            'failure' => site_url('/pagamento/erro'),
            'pending' => site_url('/pagamento/pendente'),
        ],
        'auto_return' => 'approved',
        'statement_descriptor' => 'IBETP',
        'metadata' => [
            'pre_enrollment_id' => $enrollmentId,
            'product_slug' => (string)($product['slug'] ?? ''),
        ],
    ];
    if (checkout_requires_pix_first_payment($product)) {
        $payload['payment_methods'] = [
            'excluded_payment_types' => [
                ['id' => 'credit_card'],
                ['id' => 'debit_card'],
                ['id' => 'ticket'],
                ['id' => 'atm'],
            ],
            'installments' => 1,
        ];
    }
    $response = checkout_mp_request('POST', 'https://api.mercadopago.com/checkout/preferences', $payload);
    if (empty($response['init_point'])) {
        throw new RuntimeException('Mercado Pago não retornou o link de pagamento.');
    }
    Database::exec(
        'INSERT INTO payment_orders (product_id, enrollment_id, preference_id, external_reference, amount, status, raw_response) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [(int)($product['id'] ?? 0), $enrollmentId, $response['id'] ?? null, $external, product_effective_price($product), 'preference_created', json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    Database::exec("UPDATE pre_enrollments SET external_reference=?, payment_status=?, updated_at=NOW() WHERE id=?", [$external, 'preference_created', $enrollmentId]);
    return $response;
}

function checkout_split_full_name(string $fullName): array {
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
    if ($fullName === '') return ['Aluno', 'IBETP'];
    $parts = explode(' ', $fullName);
    $name = array_shift($parts) ?: $fullName;
    $surname = trim(implode(' ', $parts));
    return [$name, $surname !== '' ? $surname : 'IBETP'];
}

function checkout_split_phone(string $phone): array {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) >= 11) return [substr($digits, 0, 2), substr($digits, 2)];
    if (strlen($digits) >= 10) return [substr($digits, 0, 2), substr($digits, 2)];
    return ['21', $digits !== '' ? $digits : '983177702'];
}

function checkout_requires_pix_first_payment(array $product): bool {
    return product_is_technical_ead($product) || product_is_technologist($product);
}

function checkout_mp_request(string $method, string $url, ?array $payload = null): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . checkout_mp_token(), 'Content-Type: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Falha de comunicação com Mercado Pago: ' . $err);
    }
    curl_close($ch);
    $json = json_decode($response, true);
    if ($status >= 400 || !is_array($json)) {
        throw new RuntimeException('Mercado Pago recusou a solicitação: ' . $response);
    }
    return $json;
}

function checkout_payload_payment_id(array $payload): ?string {
    $data = $payload['data'] ?? [];
    $id = (is_array($data) ? ($data['id'] ?? null) : null)
        ?? $payload['data.id']
        ?? $payload['data_id']
        ?? $payload['id']
        ?? ($_GET['data.id'] ?? null)
        ?? ($_GET['data_id'] ?? null)
        ?? ($_GET['id'] ?? null);
    return $id !== null && $id !== '' ? (string)$id : null;
}

function checkout_fetch_webhook_payment(array $payload): ?array {
    $type = $payload['type'] ?? $payload['topic'] ?? '';
    $id = checkout_payload_payment_id($payload);
    $action = (string)($payload['action'] ?? '');
    if (!$id || ($type && !str_contains((string)$type, 'payment') && !str_contains($action, 'payment'))) return null;
    try {
        return checkout_mp_request('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$id));
    } catch (Throwable $e) {
        return null;
    }
}

function checkout_process_return_payment(array $query): void {
    $paymentId = $query['payment_id'] ?? $query['collection_id'] ?? null;
    if (!$paymentId) return;
    try {
        $payment = checkout_mp_request('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$paymentId));
    } catch (Throwable $e) {
        return;
    }
    $external = $payment['external_reference'] ?? null;
    if (!$external) return;
    $status = (string)($payment['status'] ?? 'returned');
    $raw = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::exec("UPDATE payment_orders SET status=?, payment_id=?, raw_response=?, updated_at=NOW() WHERE external_reference=?", [$status, (string)$paymentId, $raw, $external]);
    checkout_mark_enrollment_payment($external, $status, (string)$paymentId, $raw);
}

function checkout_mark_enrollment_payment(string $external, string $status, string $paymentId, string $raw): void {
    $row = Database::one("SELECT * FROM pre_enrollments WHERE external_reference=?", [$external]);
    if (!$row) return;
    Database::exec("UPDATE pre_enrollments SET payment_status=?, payment_id=?, raw_payment=?, updated_at=NOW() WHERE id=?", [$status, $paymentId, $raw, (int)$row['id']]);
    if ($status === 'approved' && empty($row['student_email_sent_at'])) {
        checkout_send_approved_emails((int)$row['id']);
    }
}

function checkout_smtp_setting(string $key, string $fallback = ''): string {
    return trim((string)setting($key, $fallback));
}

function checkout_smtp_config(): array {
    return [
        'host' => checkout_smtp_setting('smtp_host', 'smtp.hostinger.com'),
        'port' => (int)checkout_smtp_setting('smtp_port', '465'),
        'security' => strtolower(checkout_smtp_setting('smtp_security', 'ssl')),
        'user' => checkout_smtp_setting('smtp_user', 'secretaria@ibetp.com.br'),
        'pass' => checkout_smtp_setting('smtp_pass', ''),
        'from_email' => checkout_smtp_setting('smtp_from_email', 'secretaria@ibetp.com.br'),
        'from_name' => checkout_smtp_setting('smtp_from_name', 'Secretaria IBETP'),
        'internal_to' => checkout_smtp_setting('checkout_internal_email', 'secretaria@ibetp.com.br'),
    ];
}

function checkout_send_approved_emails(int $enrollmentId, bool $force = false): array {
    $row = Database::one("SELECT * FROM pre_enrollments WHERE id=?", [$enrollmentId]);
    if (!$row) return ['student' => false, 'internal' => false];
    $studentSubject = 'IBETP — matrícula recebida e pagamento confirmado';
    $studentHtml = checkout_student_email_html($row);
    $internalSubject = 'Nova matrícula paga — ' . $row['product_title'];
    $internalHtml = checkout_internal_email_html($row);
    $sentStudent = !$force && !empty($row['student_email_sent_at'])
        ? true
        : checkout_send_email($row['email'], $row['full_name'], $studentSubject, $studentHtml);
    $cfg = checkout_smtp_config();
    $sentInternal = !$force && !empty($row['internal_email_sent_at'])
        ? true
        : checkout_send_email($cfg['internal_to'], 'Secretaria IBETP', $internalSubject, $internalHtml);
    if ($sentStudent) Database::exec("UPDATE pre_enrollments SET student_email_sent_at=NOW() WHERE id=?", [$enrollmentId]);
    if ($sentInternal) Database::exec("UPDATE pre_enrollments SET internal_email_sent_at=NOW() WHERE id=?", [$enrollmentId]);
    return ['student' => $sentStudent, 'internal' => $sentInternal];
}

function checkout_student_email_html(array $row): string {
    return '<div style="font-family:Arial,sans-serif;color:#001f54;line-height:1.6;max-width:680px">'
        . '<h2 style="color:#008848">Parabéns, sua matrícula foi recebida pelo IBETP.</h2>'
        . '<p>Olá, ' . e($row['full_name']) . '.</p>'
        . '<p>Recebemos a confirmação de pagamento referente ao curso <strong>' . e($row['product_title']) . '</strong>.</p>'
        . '<p>Sua matrícula será efetivada pela Secretaria IBETP e o acesso à plataforma AVA, ou as orientações iniciais do curso, serão encaminhados em até <strong>24 horas úteis</strong> após a confirmação do pagamento.</p>'
        . '<p>Se houver necessidade de complementar documentos ou confirmar alguma informação, nossa equipe entrará em contato pelos dados informados na ficha de pré-matrícula.</p>'
        . '<div style="background:#f2f8f5;border-left:5px solid #008848;border-radius:12px;padding:14px 16px;margin:18px 0">'
        . '<strong>Resumo da matrícula</strong><br>Curso: ' . e($row['product_title']) . '<br>Aluno(a): ' . e($row['full_name']) . '<br>Status do pagamento: ' . e($row['payment_status']) . '</div>'
        . '<p><strong>IBETP — Instituto Brasileiro de Educação Técnica e Profissional</strong><br>CNPJ: 39.534.189/0001-38</p>'
        . '</div>';
}

function checkout_internal_email_html(array $row): string {
    $fields = [
        'Curso' => $row['product_title'],
        'Modalidade/Categoria' => $row['product_category'],
        'Valor pago/gerado' => 'R$ ' . number_format((float)$row['amount'], 2, ',', '.'),
        'Status do pagamento' => $row['payment_status'],
        'Código Mercado Pago' => $row['payment_id'],
        'Nome completo' => $row['full_name'],
        'Data de nascimento' => $row['birth_date'],
        'CPF' => $row['cpf'],
        'Telefone celular' => $row['phone'],
        'E-mail' => $row['email'],
        'Sexo' => $row['sex'],
        'CEP' => $row['cep'],
        'Endereço' => $row['address'],
        'Número' => $row['number'],
        'Complemento' => $row['complement'],
        'Bairro' => $row['district'],
        'Cidade' => $row['city'],
        'Estado' => $row['state'],
        'Referência interna' => $row['external_reference'],
    ];
    $html = '<div style="font-family:Arial,sans-serif;color:#001f54;line-height:1.55;max-width:760px">'
        . '<h2 style="color:#008848">Nova matrícula paga no site IBETP</h2>'
        . '<p>Pagamento confirmado/retornado pelo Mercado Pago. Abaixo estão os dados completos da ficha preenchida pelo aluno.</p>'
        . '<table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;border-color:#d9e5f5">';
    foreach ($fields as $label => $value) {
        $html .= '<tr><th align="left">' . e($label) . '</th><td>' . e((string)$value) . '</td></tr>';
    }
    return $html . '</table><p><strong>Ação necessária:</strong> conferir documentação, efetivar a matrícula e enviar acesso/orientações da plataforma AVA em até 24 horas úteis após a confirmação do pagamento.</p></div>';
}

function checkout_send_email(string $toEmail, string $toName, string $subject, string $html): bool {
    $cfg = checkout_smtp_config();
    if ($cfg['pass'] === '') {
        checkout_email_last_error('A senha SMTP ainda não foi salva em Configuração do checkout.');
        return false;
    }
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))));
    $boundary = '=_IBETP_' . bin2hex(random_bytes(12));
    $headers = [
        'From: ' . checkout_mime_header($cfg['from_name']) . ' <' . $cfg['from_email'] . '>',
        'To: ' . checkout_mime_header($toName) . ' <' . $toEmail . '>',
        'Subject: ' . checkout_mime_header($subject),
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$plain\r\n"
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$html\r\n--$boundary--\r\n";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    return checkout_smtp_send($cfg, $toEmail, $message);
}

function checkout_email_last_error(?string $message = null): string {
    static $last = '';
    if ($message !== null) $last = $message;
    return $last;
}

function checkout_mime_header(string $text): string {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function checkout_smtp_send(array $cfg, string $toEmail, string $message): bool {
    checkout_email_last_error('');
    $remote = ($cfg['security'] === 'ssl' ? 'ssl://' : '') . $cfg['host'] . ':' . $cfg['port'];
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        checkout_email_last_error('Não foi possível conectar ao SMTP em ' . $remote . '. Erro: ' . $errno . ' ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, 20);
    $read = function () use ($socket): string {
        $data = '';
        while (($line = fgets($socket, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $command) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        return $read();
    };
    $hello = $read();
    if ($hello && !str_starts_with($hello, '220')) {
        checkout_email_last_error('Resposta inicial inesperada do SMTP: ' . trim($hello));
    }
    $ehlo = $cmd('EHLO ibetp.com.br');
    if (!str_starts_with($ehlo, '250')) {
        checkout_email_last_error('SMTP não aceitou EHLO: ' . trim($ehlo));
    }
    if ($cfg['security'] === 'tls') {
        $startTls = $cmd('STARTTLS');
        if (!str_starts_with($startTls, '220')) {
            fclose($socket);
            checkout_email_last_error('SMTP não aceitou STARTTLS: ' . trim($startTls));
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            checkout_email_last_error('Falha ao ativar criptografia TLS no SMTP.');
            return false;
        }
        $cmd('EHLO ibetp.com.br');
    }
    $authLogin = $cmd('AUTH LOGIN');
    if (!str_starts_with($authLogin, '334')) {
        fclose($socket);
        checkout_email_last_error('SMTP não aceitou AUTH LOGIN: ' . trim($authLogin));
        return false;
    }
    $authUser = $cmd(base64_encode($cfg['user']));
    if (!str_starts_with($authUser, '334')) {
        fclose($socket);
        checkout_email_last_error('SMTP não aceitou o usuário informado: ' . trim($authUser));
        return false;
    }
    $auth = $cmd(base64_encode($cfg['pass']));
    if (!str_starts_with($auth, '235')) {
        fclose($socket);
        checkout_email_last_error('SMTP recusou a senha/login: ' . trim($auth));
        return false;
    }
    $mailFrom = $cmd('MAIL FROM:<' . $cfg['from_email'] . '>');
    if (!str_starts_with($mailFrom, '250')) {
        fclose($socket);
        checkout_email_last_error('SMTP recusou o remetente: ' . trim($mailFrom));
        return false;
    }
    $rcptTo = $cmd('RCPT TO:<' . $toEmail . '>');
    if (!str_starts_with($rcptTo, '250') && !str_starts_with($rcptTo, '251')) {
        fclose($socket);
        checkout_email_last_error('SMTP recusou o destinatário: ' . trim($rcptTo));
        return false;
    }
    $data = $cmd('DATA');
    if (!str_starts_with($data, '354')) {
        fclose($socket);
        checkout_email_last_error('SMTP não abriu DATA: ' . trim($data));
        return false;
    }
    fwrite($socket, str_replace("\n.", "\n..", $message) . "\r\n.\r\n");
    $result = $read();
    $cmd('QUIT');
    fclose($socket);
    if (!str_starts_with($result, '250')) {
        checkout_email_last_error('SMTP recebeu a mensagem mas recusou o envio: ' . trim($result));
        return false;
    }
    return true;
}

function render_checkout_form(array $product, array $values = [], array $errors = []): void {
    $value = fn(string $key): string => (string)($values[$key] ?? '');
    $image = product_image_url($product);
    $paymentLabel = product_primary_payment_label($product);
    $investmentLabel = product_investment_label($product);
    $pixOnly = checkout_requires_pix_first_payment($product);
    ob_start(); ?><main class="checkout-page checkout-page-pro">
      <section class="checkout-topline" aria-label="Garantias da pré-matrícula">
        <span>Ambiente seguro</span>
        <span>Pré-matrícula IBETP</span>
        <span>Pagamento processado pelo Mercado Pago</span>
      </section>

      <section class="checkout-shell">
        <div class="checkout-main-card">
          <div class="checkout-heading">
            <p class="eyebrow">Pré-matrícula IBETP</p>
            <h1>Finalize seus dados para seguir ao pagamento</h1>
            <p>Preencha a pré-matrícula com atenção. Após a confirmação do pagamento, a matrícula será efetivada em até <strong>24 horas úteis</strong>, com orientação institucional do IBETP.</p>
          </div>

          <?php if ($errors): ?><div class="checkout-errors"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>

          <form method="post" action="<?= e(site_url('/checkout')) ?>" class="checkout-form checkout-form-pro" novalidate>
            <input type="hidden" name="product_id" value="<?= (int)($product['id'] ?? 0) ?>">
            <input type="hidden" name="product_slug" value="<?= e((string)($product['slug'] ?? '')) ?>">
            <input type="hidden" name="pre_enrollment_submit" value="1">

            <section class="checkout-step-card">
              <div class="checkout-section-title"><span>01</span><strong>Identificação do aluno</strong></div>
              <div class="checkout-grid checkout-grid-3">
                <label class="checkout-field checkout-field-wide">Nome completo<input name="full_name" value="<?= e($value('full_name')) ?>" autocomplete="name" required></label>
                <label class="checkout-field">Data de nascimento<input name="birth_date" type="date" value="<?= e($value('birth_date')) ?>" required></label>
                <label class="checkout-field">CPF<input name="cpf" value="<?= e($value('cpf')) ?>" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required></label>
                <label class="checkout-field">Sexo<select name="sex" required><option value="">Selecione</option><?php foreach (['Feminino','Masculino','Outro','Prefiro não informar'] as $option): ?><option <?= $value('sex') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label>
              </div>
            </section>

            <section class="checkout-step-card">
              <div class="checkout-section-title"><span>02</span><strong>Contato para matrícula</strong></div>
              <div class="checkout-grid">
                <label class="checkout-field">Telefone celular<input name="phone" value="<?= e($value('phone')) ?>" inputmode="tel" autocomplete="tel" placeholder="(21) 99999-9999" required></label>
                <label class="checkout-field">E-mail<input name="email" type="email" value="<?= e($value('email')) ?>" autocomplete="email" placeholder="seuemail@exemplo.com" required></label>
              </div>
            </section>

            <section class="checkout-step-card">
              <div class="checkout-section-title"><span>03</span><strong>Endereço residencial</strong></div>
              <div class="checkout-grid checkout-grid-address">
                <label class="checkout-field">CEP<input name="cep" value="<?= e($value('cep')) ?>" inputmode="numeric" autocomplete="postal-code" required></label>
                <label class="checkout-field checkout-field-wide">Endereço<input name="address" value="<?= e($value('address')) ?>" autocomplete="street-address" required></label>
                <label class="checkout-field">Número<input name="number" value="<?= e($value('number')) ?>" required></label>
                <label class="checkout-field">Complemento<input name="complement" value="<?= e($value('complement')) ?>"></label>
                <label class="checkout-field">Bairro<input name="district" value="<?= e($value('district')) ?>" required></label>
                <label class="checkout-field">Cidade<input name="city" value="<?= e($value('city')) ?>" required></label>
                <label class="checkout-field">Estado<input name="state" value="<?= e($value('state')) ?>" maxlength="2" placeholder="RJ" required></label>
              </div>
            </section>

            <label class="checkout-consent">
              <input type="checkbox" name="lgpd_accept" value="1" <?= !empty($values['lgpd_accept']) ? 'checked' : '' ?> required>
              <span>Declaro que as informações preenchidas são verdadeiras e autorizo o IBETP a utilizar meus dados para fins de pré-matrícula, atendimento educacional e comunicação institucional.</span>
            </label>

            <div class="checkout-actions checkout-actions-pro">
              <button class="btn primary" type="submit"><?= e($paymentLabel) ?></button>
              <a class="btn outline dark" href="<?= e(site_url('/produto/' . $product['slug'])) ?>">Voltar ao curso</a>
            </div>
          </form>
        </div>

        <aside class="checkout-summary-card" aria-label="Resumo do pedido">
          <?= product_visual_art($product, 'checkout') ?>
          <div class="checkout-summary-body">
            <span class="checkout-summary-kicker"><?= e(product_category_label($product)) ?></span>
            <h2><?= e($product['title']) ?></h2>
            <div class="checkout-price-line">
              <small><?= e($paymentLabel) ?></small>
              <strong><?= e($investmentLabel) ?></strong>
            </div>
            <ul class="checkout-safe-list">
              <li>Dados enviados para a Secretaria IBETP após pagamento aprovado.</li>
              <li>Matrícula efetivada em até 24 horas úteis após confirmação.</li>
              <li><?= $pixOnly ? 'Pagamento inicial orientado para Pix pelo Mercado Pago.' : 'Pagamento seguro processado pelo Mercado Pago.' ?></li>
            </ul>
            <div class="checkout-institutional-note">
              <strong>IBETP</strong>
              <span>Instituto Brasileiro de Educação Técnica e Profissional<br>CNPJ: 39.534.189/0001-38</span>
            </div>
          </div>
        </aside>
      </section>
    </main><?php
    layout('Pré-matrícula — ' . $product['title'], 'Formulário de pré-matrícula IBETP antes do pagamento seguro.', ob_get_clean(), premium_product_image($product), true);
}

function render_checkout_admin_config(): void {
    $saved = ($_GET['saved'] ?? '') === '1';
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Checkout IBETP</title><link rel="stylesheet" href="<?= e(site_url('/assets/admin.css')) ?>"><style>
    .checkout-admin-extra{max-width:980px;margin:28px auto;background:#fff;border:1px solid #d9e5f5;border-radius:22px;padding:28px;box-shadow:0 18px 40px rgba(0,31,84,.08);font-family:Arial,sans-serif;color:#001f54}
    .checkout-admin-extra h1{margin:0 0 8px;font-size:32px}.checkout-admin-extra p{font-size:16px;line-height:1.55;color:#4b5d78}.checkout-admin-extra form{display:grid;gap:20px}.checkout-admin-extra fieldset{border:1px solid #d9e5f5;border-radius:18px;padding:20px}.checkout-admin-extra legend{font-weight:800;color:#008848}.checkout-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.checkout-admin-extra label{display:grid;gap:7px;font-weight:700}.checkout-admin-extra input,.checkout-admin-extra select{border:1px solid #c7d7ec;border-radius:12px;padding:12px;font-size:15px}.checkout-admin-extra button{background:#008848;color:#fff;border:0;border-radius:14px;padding:14px 20px;font-weight:900;cursor:pointer}.checkout-admin-ok{background:#e9f8ef;border:1px solid #bce8cb;color:#08783b;border-radius:14px;padding:12px 14px;font-weight:700}@media(max-width:760px){.checkout-admin-grid{grid-template-columns:1fr}}
    </style></head><body><main class="checkout-admin-extra">
      <h1>Configuração do checkout IBETP</h1>
      <p>Use esta tela para salvar Mercado Pago e SMTP no banco privado do site. As senhas não entram no GitHub.</p>
      <p style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="<?= e(site_url('/admin/checkout-email-test')) ?>" style="display:inline-flex;background:#061b45;color:#fff;text-decoration:none;border-radius:12px;padding:12px 16px;font-weight:900">Testar envio de e-mail</a>
        <a href="<?= e(site_url('/admin/pre-matriculas')) ?>" style="display:inline-flex;background:#008848;color:#fff;text-decoration:none;border-radius:12px;padding:12px 16px;font-weight:900">Ver pré-matrículas</a>
      </p>
      <?php if ($saved): ?><div class="checkout-admin-ok">Configurações salvas.</div><?php endif; ?>
      <form method="post">
        <fieldset><legend>Mercado Pago</legend><div class="checkout-admin-grid">
          <label>Ambiente<select name="mp_environment"><option value="test" <?= setting('mp_environment','production') === 'test' ? 'selected' : '' ?>>Teste</option><option value="production" <?= setting('mp_environment','production') === 'production' ? 'selected' : '' ?>>Produção / Real</option></select></label>
          <label>Public Key real<input name="mp_production_public_key" value="<?= e(setting('mp_production_public_key','')) ?>"></label>
          <label>Access Token real<input name="mp_production_access_token" value="<?= e(setting('mp_production_access_token','')) ?>"></label>
          <label>Public Key teste<input name="mp_test_public_key" value="<?= e(setting('mp_test_public_key','')) ?>"></label>
          <label>Access Token teste<input name="mp_test_access_token" value="<?= e(setting('mp_test_access_token','')) ?>"></label>
        </div></fieldset>
        <fieldset><legend>E-mail institucional SMTP</legend><div class="checkout-admin-grid">
          <label>Servidor SMTP<input name="smtp_host" value="<?= e(setting('smtp_host','smtp.hostinger.com')) ?>"></label>
          <label>Porta<input name="smtp_port" value="<?= e(setting('smtp_port','465')) ?>"></label>
          <label>Segurança<select name="smtp_security"><option value="ssl" <?= setting('smtp_security','ssl') === 'ssl' ? 'selected' : '' ?>>SSL</option><option value="tls" <?= setting('smtp_security','ssl') === 'tls' ? 'selected' : '' ?>>TLS/STARTTLS</option></select></label>
          <label>Usuário SMTP<input name="smtp_user" value="<?= e(setting('smtp_user','secretaria@ibetp.com.br')) ?>"></label>
          <label>Senha SMTP<input name="smtp_pass" type="password" placeholder="Deixe vazio para manter a senha salva"></label>
          <label>Remetente<input name="smtp_from_email" value="<?= e(setting('smtp_from_email','secretaria@ibetp.com.br')) ?>"></label>
          <label>Nome do remetente<input name="smtp_from_name" value="<?= e(setting('smtp_from_name','Secretaria IBETP')) ?>"></label>
          <label>E-mail interno da secretaria<input name="checkout_internal_email" value="<?= e(setting('checkout_internal_email','secretaria@ibetp.com.br')) ?>"></label>
        </div></fieldset>
        <button>Salvar configurações de checkout</button>
      </form>
    </main></body></html><?php
}

function render_checkout_email_test(string $to, ?bool $result): void {
    $error = checkout_email_last_error();
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teste de e-mail do checkout IBETP</title><style>
    body{margin:0;background:#f3f7fc;color:#061b45;font-family:Inter,Segoe UI,Arial,sans-serif}.email-test{max-width:820px;margin:48px auto;padding:34px;border:1px solid #d9e4f2;border-radius:28px;background:#fff;box-shadow:0 22px 60px rgba(6,27,69,.08)}.email-test h1{margin:0 0 10px;font-size:38px}.email-test p{font-size:18px;line-height:1.6;color:#52617a}.email-test form{display:grid;gap:14px;margin-top:22px}.email-test label{display:grid;gap:8px;font-weight:900}.email-test input{min-height:54px;border:1px solid #cbd8ea;border-radius:16px;padding:0 15px;font-size:18px}.email-test button,.email-test a{display:inline-flex;width:max-content;align-items:center;justify-content:center;border:0;border-radius:14px;padding:14px 18px;background:#05864b;color:#fff;font-weight:950;text-decoration:none;cursor:pointer}.email-test a{background:#061b45}.email-test .ok{padding:14px 16px;border-radius:16px;background:#e9f8ef;color:#08783b;font-weight:900}.email-test .bad{padding:14px 16px;border-radius:16px;background:#fff2f2;color:#9b1c1c;font-weight:900}.email-test .actions{display:flex;gap:12px;flex-wrap:wrap}
    </style></head><body><main class="email-test">
      <h1>Teste de e-mail do checkout</h1>
      <p>Use esta tela para confirmar se o envio automático pelo e-mail institucional está funcionando antes de fazer um pagamento real.</p>
      <?php if ($result === true): ?><div class="ok">E-mail de teste enviado com sucesso.</div><?php elseif ($result === false): ?><div class="bad">O envio falhou. <?= e($error ?: 'Confira se a senha SMTP foi salva em “Configuração do checkout”.') ?></div><?php endif; ?>
      <form method="post">
        <label>E-mail de destino para o teste<input name="test_email" type="email" value="<?= e($to) ?>" required></label>
        <div class="actions">
          <button>Enviar e-mail de teste</button>
          <a href="<?= e(site_url('/admin/checkout-config')) ?>">Voltar à configuração</a>
        </div>
      </form>
    </main></body></html><?php
}

function render_admin_access_reset(): void {
    $expectedHash = '7e3a0b46460ad54dc7ac18a02a1b78eb0f6c6ee392d29798f21947e493f7935b';
    $token = (string)($_GET['chave'] ?? $_POST['chave'] ?? '');
    $valid = $token !== '' && hash_equals($expectedHash, hash('sha256', $token));
    $used = setting('admin_access_reset_used', '') === '1';
    $message = null;
    $success = false;

    if (!$valid || $used) {
        http_response_code(404);
        layout('Página não encontrada', 'Página não encontrada.', '<main class="checkout-page"><section class="checkout-result"><h1>Página não encontrada</h1></section></main>', null, true);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($_POST['name'] ?? 'Administrador IBETP'));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Informe um e-mail válido para o administrador.';
        } elseif (strlen($password) < 10) {
            $message = 'A senha precisa ter pelo menos 10 caracteres.';
        } else {
            Database::exec(
                "INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role=VALUES(role)",
                [$name ?: 'Administrador IBETP', $email, password_hash($password, PASSWORD_DEFAULT), 'admin']
            );
            save_setting('admin_access_reset_used', '1');
            $success = true;
            $message = 'Acesso administrativo criado/atualizado. Use este e-mail e senha para entrar no painel.';
        }
    }

    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Criar acesso administrativo IBETP</title><style>
    body{margin:0;background:#f3f7fc;color:#061b45;font-family:Inter,Segoe UI,Arial,sans-serif}.reset{max-width:760px;margin:48px auto;padding:34px;background:#fff;border:1px solid #d9e4f2;border-radius:28px;box-shadow:0 22px 60px rgba(6,27,69,.08)}h1{margin:0 0 8px;font-size:34px}p{font-size:17px;line-height:1.55;color:#52617a}.grid{display:grid;gap:14px;margin-top:22px}label{display:grid;gap:8px;font-weight:900}input{min-height:54px;border:1px solid #cbd8ea;border-radius:16px;padding:0 15px;font-size:18px}.btn{display:inline-flex;width:max-content;align-items:center;justify-content:center;border:0;border-radius:14px;padding:14px 18px;background:#05864b;color:#fff;font-weight:950;text-decoration:none;cursor:pointer}.ok{padding:14px 16px;border-radius:16px;background:#e9f8ef;color:#08783b;font-weight:900}.bad{padding:14px 16px;border-radius:16px;background:#fff2f2;color:#9b1c1c;font-weight:900}.actions{display:flex;gap:12px;flex-wrap:wrap}
    </style></head><body><main class="reset">
      <h1>Criar acesso administrativo IBETP</h1>
      <p>Defina o e-mail e a senha que serão usados para acessar as áreas administrativas do site. Este link é de uso único.</p>
      <?php if ($message): ?><div class="<?= $success ? 'ok' : 'bad' ?>"><?= e($message) ?></div><?php endif; ?>
      <?php if (!$success): ?>
      <form class="grid" method="post">
        <input type="hidden" name="chave" value="<?= e($token) ?>">
        <label>Nome do administrador<input name="name" value="Administrador IBETP" required></label>
        <label>E-mail de login<input name="email" type="email" value="secretaria@ibetp.com.br" required></label>
        <label>Nova senha do painel<input name="password" type="password" minlength="10" required></label>
        <button class="btn">Criar/atualizar acesso</button>
      </form>
      <?php else: ?>
      <div class="actions" style="margin-top:18px">
        <a class="btn" href="<?= e(site_url('/admin?action=login')) ?>">Entrar no painel</a>
        <a class="btn" style="background:#061b45" href="<?= e(site_url('/admin/checkout-config')) ?>">Configurar checkout</a>
      </div>
      <?php endif; ?>
    </main></body></html><?php
}

function render_checkout_enrollments_admin(?string $notice = null): void {
    $rows = Database::all("SELECT * FROM pre_enrollments ORDER BY id DESC LIMIT 80");
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pré-matrículas IBETP</title><style>
    body{margin:0;background:#f3f7fc;color:#061b45;font-family:Inter,Segoe UI,Arial,sans-serif}.wrap{max-width:1180px;margin:34px auto;padding:26px;background:#fff;border:1px solid #d9e4f2;border-radius:28px;box-shadow:0 22px 60px rgba(6,27,69,.08)}h1{margin:0 0 8px;font-size:34px}p{font-size:17px;color:#52617a;line-height:1.55}.actions{display:flex;gap:12px;flex-wrap:wrap;margin:18px 0}.btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:13px;padding:12px 15px;background:#061b45;color:#fff;font-weight:950;text-decoration:none;cursor:pointer}.btn.green{background:#008848}.notice{background:#e9f8ef;border:1px solid #bce8cb;color:#08783b;border-radius:14px;padding:12px 14px;font-weight:800;margin:14px 0}.table-wrap{overflow:auto;border:1px solid #d9e4f2;border-radius:18px}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:12px 10px;border-bottom:1px solid #e7eef8;text-align:left;vertical-align:top;font-size:14px}th{background:#f7fbff;color:#001f54;font-size:13px;text-transform:uppercase;letter-spacing:.04em}.status{display:inline-flex;border-radius:999px;padding:5px 9px;font-weight:900;background:#eef5ff}.status.approved{background:#e8f8ef;color:#08783b}.status.preference_created,.status.created{background:#fff7db;color:#8a6100}.muted{color:#6d7b91}.small{font-size:12px}.resend{background:#008848;color:#fff;border:0;border-radius:10px;padding:9px 11px;font-weight:900;cursor:pointer;white-space:nowrap}@media(max-width:760px){.wrap{margin:12px;padding:18px;border-radius:20px}h1{font-size:28px}}
    </style></head><body><main class="wrap">
      <h1>Pré-matrículas e e-mails do checkout</h1>
      <p>Acompanhe pagamentos retornados pelo Mercado Pago, dados preenchidos pelo aluno e status de envio dos e-mails automáticos. Se necessário, use “Reenviar e-mails”.</p>
      <div class="actions">
        <a class="btn" href="<?= e(site_url('/admin/checkout-config')) ?>">Configuração do checkout</a>
        <a class="btn green" href="<?= e(site_url('/admin/checkout-email-test')) ?>">Testar SMTP</a>
        <a class="btn" href="<?= e(site_url('/admin')) ?>">Painel administrativo</a>
      </div>
      <?php if ($notice): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Data</th><th>Curso</th><th>Aluno</th><th>Contato</th><th>Pagamento</th><th>E-mails</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8">Nenhuma pré-matrícula registrada ainda.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><span class="small"><?= e((string)$row['created_at']) ?></span></td>
            <td><strong><?= e((string)$row['product_title']) ?></strong><br><span class="muted small"><?= e((string)$row['product_category']) ?></span></td>
            <td><strong><?= e((string)$row['full_name']) ?></strong><br><span class="muted small">CPF: <?= e((string)$row['cpf']) ?></span></td>
            <td><?= e((string)$row['email']) ?><br><span class="muted small"><?= e((string)$row['phone']) ?></span></td>
            <td><span class="status <?= e(ibetp_slug_key((string)$row['payment_status'])) ?>"><?= e((string)$row['payment_status']) ?></span><br><span class="muted small">MP: <?= e((string)$row['payment_id']) ?></span></td>
            <td><span class="small">Aluno: <?= !empty($row['student_email_sent_at']) ? e((string)$row['student_email_sent_at']) : 'não enviado' ?></span><br><span class="small">Secretaria: <?= !empty($row['internal_email_sent_at']) ? e((string)$row['internal_email_sent_at']) : 'não enviado' ?></span></td>
            <td>
              <form method="post">
                <input type="hidden" name="resend_enrollment_id" value="<?= (int)$row['id'] ?>">
                <button class="resend">Reenviar e-mails</button>
              </form>
              <form method="post" style="margin-top:8px">
                <input type="hidden" name="confirm_paid_enrollment_id" value="<?= (int)$row['id'] ?>">
                <button class="resend" style="background:#061b45">Confirmar pagamento e reenviar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </main></body></html><?php
}

function product_is_technical_ead(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    $normalized = ibetp_slug_key($text);
    if (
        str_contains($text, 'competência') ||
        str_contains($text, 'competencia') ||
        str_contains($normalized, 'competencia') ||
        str_contains($normalized, 'pos-tecnico') ||
        str_contains($normalized, 'superior-sequencial') ||
        str_contains($normalized, 'sequencial') ||
        str_contains($normalized, 'pos-graduacao') ||
        str_contains($normalized, 'mba') ||
        str_contains($normalized, 'especializacao')
    ) {
        return false;
    }
    return str_contains($text, 'técnico') || str_contains($text, 'tecnico');
}

function product_is_sequential(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $slug = mb_strtolower((string)($product['slug'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category . ' ' . $slug;
    $normalized = ibetp_slug_key($text);
    return str_contains($normalized, 'superior-sequencial') || str_contains($normalized, 'sequencial');
}

function product_is_technologist(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    return str_contains($text, 'tecnólogo') || str_contains($text, 'tecnologo') || str_contains($text, 'superior de tecnologia');
}

function product_is_post_technical(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $slug = mb_strtolower((string)($product['slug'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category . ' ' . $slug;
    $normalized = ibetp_slug_key($text);
    return str_contains($normalized, 'pos-tecnico') || str_contains($normalized, 'pos-tecnica') || str_contains($normalized, 'especializacao-tecnica');
}

function product_is_postgrad(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $slug = mb_strtolower((string)($product['slug'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category . ' ' . $slug;
    $normalized = ibetp_slug_key($text);
    if (product_is_post_technical($product)) return false;
    return str_contains($normalized, 'pos-graduacao')
        || str_contains($normalized, 'mba')
        || str_contains($normalized, 'especializacao-em-')
        || str_contains($normalized, 'especializacao-pos-graduacao');
}

function product_category_label(array $product): string {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    if (product_is_sequential($product)) return 'Superior Sequencial';
    if (product_is_technologist($product)) return 'Tecnólogo EAD';
    if (product_is_post_technical($product)) return 'Pós-técnico';
    if (product_is_postgrad($product)) return 'Pós-graduação e MBA';
    if (str_contains($text, 'competência') || str_contains($text, 'competencia')) return 'Certificação Técnica por Competência';
    if (str_contains($text, 'pós-graduação') || str_contains($text, 'pos-graduacao') || str_contains($text, 'mba')) return 'Pós-graduação e MBA';
    if (str_contains($text, 'pós-técnico') || str_contains($text, 'pos-tecnico')) return 'Pós-técnico';
    if (product_is_technical_ead($product)) return 'Cursos Técnicos EAD';
    if (str_contains($text, 'profissionalizante')) return 'Profissionalizante';
    $label = trim((string)($product['category'] ?? 'Formação IBETP'));
    $label = str_ireplace(['UNICORP FAAO', 'UNICORP', 'SEI', 'UNIDADE PARAÍBA', 'UNIDADE PARÁ', 'CENTRO UNIVERSITÁRIO'], '', $label);
    $label = trim(preg_replace('/\s+/', ' ', str_replace(['()', ' - ', ' / '], ' ', $label)) ?: 'Formação IBETP');
    return $label === '' ? 'Formação IBETP' : $label;
}

function product_area_label(array $product): string {
    $explicitArea = trim((string)($product['area'] ?? ''));
    if ($explicitArea !== '') {
        return $explicitArea;
    }
    $key = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? '') . ' ' . (string)($product['category'] ?? ''));
    $areaMap = [
        'Administração e gestão' => ['administracao','logistica','recursos-humanos','contabilidade','financas','seguros','eventos','vendas','marketing','servicos-juridicos','transacoes-imobiliarias','secretaria-escolar','secretariado-escolar'],
        'Serviços' => ['gastronomia','confeitaria','designer-de-interiores','design-de-interiores','guia-de-turismo'],
        'Saúde' => ['saude','gerencia-e-saude','agente-comunitario-de-saude','nutricao','estetica','cosmetologia'],
        'Meio ambiente e agropecuária' => ['meio-ambiente','aquicultura','agroindustria','agropecuaria','agricultura'],
        'Engenharia e manutenção' => ['maquinas-pesadas','mecanica','mecatronica','refrigeracao','climatizacao','soldagem','metalurgia','maquinas-navais','maquinas-industriais','qualidade','petroleo-e-gas','eletromecanica','eletrotecnica','eletronica','eletroeletronica','automacao-industrial'],
        'Construção e infraestrutura' => ['edificacoes','estrada','estradas','saneamento','prevencao-e-combate-ao-incendio','transito','defesa-civil','mineracao','agrimensura'],
        'Tecnologia e informática' => ['informatica','computacao-grafica','desenvolvimento-de-sistemas','programacao-de-jogos','redes-de-computadores','manutencao-e-suporte','geoprocessamento','telecomunicacoes','traducao-e-interpretacao-de-libras','design-grafico','biotecnologia','energia-renovavel'],
        'Educação' => ['educacao','pedagogia','psicopedagogia'],
    ];
    foreach ($areaMap as $label => $needles) {
        foreach ($needles as $needle) {
            if (str_contains($key, $needle)) {
                return $label;
            }
        }
    }
    return 'Área em organização';
}

function product_category_sort_weight(string $label): int {
    $key = ibetp_slug_key($label);
    return match ($key) {
        'cursos-tecnicos-ead' => 10,
        'tecnologo-ead' => 20,
        'certificacao-tecnica-por-competencia' => 30,
        'pos-tecnico' => 40,
        'superior-sequencial' => 50,
        'pos-graduacao-e-mba' => 60,
        'profissionalizante' => 70,
        default => 90,
    };
}

function product_area_sort_weight(string $label): int {
    $key = ibetp_slug_key($label);
    return match ($key) {
        'administracao-e-gestao' => 10,
        'servicos' => 20,
        'saude' => 30,
        'meio-ambiente-e-agropecuaria' => 40,
        'engenharia-e-manutencao' => 50,
        'construcao-e-infraestrutura' => 60,
        'tecnologia-e-informatica' => 70,
        'educacao' => 80,
        'seguranca' => 85,
        default => 90,
    };
}

function product_catalog_search_text(array $product): string {
    return mb_strtolower(
        ($product['title'] ?? '') . ' ' .
        product_category_label($product) . ' ' .
        product_area_label($product) . ' ' .
        ($product['category'] ?? '') . ' ' .
        ($product['short_description'] ?? '') . ' ' .
        ($product['description'] ?? ''),
        'UTF-8'
    );
}

function product_catalog_card_summary(array $product): string {
    $summary = card_summary($product, 82);
    $area = product_area_label($product);
    if ($area !== 'Área em organização' && !str_contains(ibetp_slug_key($summary), ibetp_slug_key($area))) {
        return $area . ' • ' . $summary;
    }
    return $summary;
}

function product_visual_icon_key(array $product): string {
    $key = ibetp_slug_key((string)($product['title'] ?? '') . ' ' . product_category_label($product) . ' ' . product_area_label($product));
    if (product_is_competency_certification($product)) return 'certificate';
    if (product_is_post_technical($product)) return 'specialty';
    if (str_contains($key, 'enfermagem') || str_contains($key, 'saude') || str_contains($key, 'nutricao') || str_contains($key, 'estetica') || str_contains($key, 'agente-comunitario')) return 'health';
    if (str_contains($key, 'informatica') || str_contains($key, 'computacao') || str_contains($key, 'sistemas') || str_contains($key, 'redes') || str_contains($key, 'programacao') || str_contains($key, 'jogos') || str_contains($key, 'internet')) return 'technology';
    if (str_contains($key, 'administracao') || str_contains($key, 'gestao') || str_contains($key, 'recursos-humanos') || str_contains($key, 'marketing') || str_contains($key, 'contabilidade') || str_contains($key, 'secretaria') || str_contains($key, 'servicos-juridicos') || str_contains($key, 'transacoes-imobiliarias')) return 'management';
    if (str_contains($key, 'agrimensura') || str_contains($key, 'edificacoes') || str_contains($key, 'construcao') || str_contains($key, 'estrada') || str_contains($key, 'saneamento') || str_contains($key, 'mineracao')) return 'construction';
    if (str_contains($key, 'mecanica') || str_contains($key, 'mecatronica') || str_contains($key, 'eletro') || str_contains($key, 'automacao') || str_contains($key, 'metalurgia') || str_contains($key, 'soldagem') || str_contains($key, 'petroleo') || str_contains($key, 'gas') || str_contains($key, 'maquinas')) return 'engineering';
    if (str_contains($key, 'agro') || str_contains($key, 'ambiente') || str_contains($key, 'agricultura') || str_contains($key, 'aquicultura') || str_contains($key, 'renovavel')) return 'environment';
    if (str_contains($key, 'seguranca') || str_contains($key, 'defesa-civil') || str_contains($key, 'incendio') || str_contains($key, 'transito')) return 'safety';
    if (str_contains($key, 'turismo') || str_contains($key, 'eventos') || str_contains($key, 'gastronomia') || str_contains($key, 'confeitaria') || str_contains($key, 'interiores')) return 'services';
    if (str_contains($key, 'educacao') || str_contains($key, 'pedagogia') || str_contains($key, 'libras')) return 'education';
    return 'institutional';
}

function product_visual_art(array $product, string $size = 'card'): string {
    $category = product_category_label($product);
    return '<div class="product-payment-panel product-payment-panel-' . e($size) . '">'
        . '<span>' . e($category) . '</span>'
        . '<strong>' . e(product_investment_label($product)) . '</strong>'
        . '<p>' . e(product_payment_condition_label($product)) . '</p>'
        . '</div>';
}

function product_visual_svg(string $icon): string {
    $svg = [
        'health' => '<svg viewBox="0 0 96 96" role="img"><path d="M48 18v60M18 48h60"/><path d="M18 68c12-23 23-23 34-6 8 12 15 6 26-22"/></svg>',
        'technology' => '<svg viewBox="0 0 96 96" role="img"><path d="M28 34 14 48l14 14M68 34l14 14-14 14M56 24 40 72"/><rect x="23" y="20" width="50" height="56" rx="10"/></svg>',
        'management' => '<svg viewBox="0 0 96 96" role="img"><path d="M20 72h56M28 62V44M48 62V28M68 62V36"/><path d="M24 28h50M24 28v46M74 28v46"/></svg>',
        'construction' => '<svg viewBox="0 0 96 96" role="img"><path d="M18 68h60M24 62l8-30 16-10 16 10 8 30"/><path d="M36 62V42h24v20M30 76h36"/></svg>',
        'engineering' => '<svg viewBox="0 0 96 96" role="img"><path d="M48 28v-8M48 76v-8M28 48h-8M76 48h-8M34 34l-6-6M68 68l-6-6M62 34l6-6M28 68l6-6"/><circle cx="48" cy="48" r="18"/><circle cx="48" cy="48" r="6"/></svg>',
        'environment' => '<svg viewBox="0 0 96 96" role="img"><path d="M72 22C44 22 22 38 22 66c30 4 52-12 50-44Z"/><path d="M22 66c18-20 32-28 50-44M44 70c0-20-12-32-28-38"/></svg>',
        'safety' => '<svg viewBox="0 0 96 96" role="img"><path d="M48 14 76 26v20c0 19-11 33-28 42-17-9-28-23-28-42V26l28-12Z"/><path d="m34 48 9 9 20-22"/></svg>',
        'services' => '<svg viewBox="0 0 96 96" role="img"><path d="M48 16 58 38l24 3-18 17 5 24-21-12-21 12 5-24-18-17 24-3 10-22Z"/></svg>',
        'education' => '<svg viewBox="0 0 96 96" role="img"><path d="M18 30c16-6 24-3 30 4 6-7 14-10 30-4v42c-16-6-24-3-30 4-6-7-14-10-30-4V30Z"/><path d="M48 34v42"/></svg>',
        'certificate' => '<svg viewBox="0 0 96 96" role="img"><rect x="20" y="18" width="56" height="48" rx="8"/><path d="M32 34h32M32 46h22M38 66l-8 16 18-8 18 8-8-16"/></svg>',
        'specialty' => '<svg viewBox="0 0 96 96" role="img"><path d="M48 14 60 38l26 4-19 18 5 26-24-13-24 13 5-26-19-18 26-4 12-24Z"/><circle cx="48" cy="51" r="10"/></svg>',
        'institutional' => '<svg viewBox="0 0 96 96" role="img"><path d="M20 74h56M26 66V36l22-14 22 14v30"/><path d="M38 66V46h20v20"/></svg>',
    ];
    return $svg[$icon] ?? $svg['institutional'];
}

function product_payment_condition_label(array $product): string {
    if (product_is_technical_ead($product)) return '1ª mensalidade via Pix; demais mensalidades por link mensal.';
    if (product_is_technologist($product)) return 'Matrícula via Pix; mensalidades de R$ 149,90 no AVA.';
    if (product_is_competency_certification($product)) return 'À vista ou em até 12x com juros no cartão.';
    if (product_is_post_technical($product)) return 'À vista ou parcelado com juros no cartão.';
    if (product_is_sequential($product)) return 'À vista ou parcelado com juros no cartão.';
    if (product_is_postgrad($product)) return 'À vista ou parcelado com juros no cartão.';
    return 'Condições confirmadas com a equipe IBETP.';
}

function product_catalog_filter_groups(array $items, callable $labeler, callable $weight): array {
    $groups = [];
    foreach ($items as $courseItem) {
        $label = $labeler($courseItem);
        $key = ibetp_slug_key($label);
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'count' => 0];
        }
        $groups[$key]['count']++;
    }
    uasort($groups, fn($a, $b) => $weight($a['label']) <=> $weight($b['label']) ?: strcmp($a['label'], $b['label']));
    return $groups;
}

function render_course_filter_nav(array $groups, string $type, string $allLabel, int $total): string {
    ob_start(); ?>
    <nav class="course-category-panel course-category-panel-<?= e($type) ?>" aria-label="<?= e($allLabel) ?>">
      <button class="course-category-button active" type="button" data-course-filter="<?= e($type) ?>" data-course-value="all"><?= e($allLabel) ?> <span><?= $total ?></span></button>
      <?php foreach ($groups as $groupKey => $group): ?>
        <button class="course-category-button" type="button" data-course-filter="<?= e($type) ?>" data-course-value="<?= e($groupKey) ?>"><?= e($group['label']) ?> <span><?= (int)$group['count'] ?></span></button>
      <?php endforeach; ?>
    </nav>
    <?php return ob_get_clean();
}

function product_temporarily_hidden_category(array $product): bool {
    if (product_is_postgrad($product)) {
        return false;
    }
    $categoryLabelKey = ibetp_slug_key(product_category_label($product));
    $areaLabelKey = ibetp_slug_key(product_area_label($product));
    $rawCategoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
    $rawAreaKey = ibetp_slug_key((string)($product['area'] ?? ''));
    return in_array($categoryLabelKey, ['educacao'], true)
        || in_array($areaLabelKey, ['educacao'], true)
        || in_array($rawCategoryKey, ['educacao'], true)
        || in_array($rawAreaKey, ['educacao'], true);
}

function product_publicly_visible(array $product): bool {
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? ''));
    $categoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
    $text = $titleKey . ' ' . $slugKey . ' ' . $categoryKey;
    if (product_temporarily_hidden_category($product)) {
        return false;
    }
    if (product_is_technical_ead($product) && !technical_ead_drive_slug_allowed($product)) {
        return false;
    }
    if (product_is_technologist($product) && !official_technologist_slug_allowed($product)) {
        return false;
    }
    if (product_is_post_technical($product) && !official_post_technical_slug_allowed($product)) {
        return false;
    }
    if (product_is_sequential($product) && !official_sequential_slug_allowed($product)) {
        return false;
    }
    if (product_is_postgrad($product) && !official_postgrad_slug_allowed($product)) {
        return false;
    }
    $isCompetence = str_contains($text, 'competencia');
    if (!$isCompetence) {
        return true;
    }
    foreach (['enfermagem', 'radiologia', 'analises-clinicas', 'quimica'] as $blocked) {
        if (str_contains($text, $blocked)) {
            return false;
        }
    }
    return true;
}

function official_post_technical_products(): array {
    return [
        [
            'id' => -9801,
            'slug' => 'pos-tecnico-em-enfermagem-do-trabalho',
            'title' => 'Especialização Técnica em Enfermagem do Trabalho',
            'category' => 'Pós-técnico',
            'area' => 'Saúde',
            'price' => 799.00,
            'checkout_enabled' => 1,
            'status' => 'active',
            'duration' => '6 meses',
            'workload' => '360h',
            'image' => '/assets/produtos/pos-tecnico-em-enfermagem-do-trabalho.jpg',
            'short_description' => 'Especialização Técnica em Enfermagem do Trabalho. Formação pós-técnica com matriz curricular oficial de 360 horas e duração de 6 meses.',
            'description' => 'Especialização Técnica em Enfermagem do Trabalho, com matriz curricular oficial, investimento de R$ 799,00 e atendimento do IBETP para matrícula, documentação e próximos passos.',
            'seo_title' => 'Especialização Técnica em Enfermagem do Trabalho | IBETP',
            'seo_description' => 'Conheça a Especialização Técnica em Enfermagem do Trabalho do IBETP: matriz curricular oficial, duração, carga horária, investimento e matrícula.',
            'updated_at' => '2026-07-20 00:00:00',
        ],
        [
            'id' => -9802,
            'slug' => 'pos-tecnico-em-agrimensura',
            'title' => 'Especialização Técnica em Agrimensura',
            'category' => 'Pós-técnico',
            'area' => 'Construção e infraestrutura',
            'price' => 799.00,
            'checkout_enabled' => 1,
            'status' => 'active',
            'duration' => '6 meses',
            'workload' => '320h',
            'image' => '/assets/produtos/pos-tecnico-em-agrimensura.webp',
            'short_description' => 'Especialização Técnica em Agrimensura. Formação pós-técnica EAD com matriz curricular oficial de 320 horas e duração de 6 meses.',
            'description' => 'Especialização Técnica em Agrimensura, com matriz curricular oficial, investimento de R$ 799,00 e atendimento do IBETP para matrícula, documentação e próximos passos.',
            'seo_title' => 'Especialização Técnica em Agrimensura | IBETP',
            'seo_description' => 'Conheça a Especialização Técnica em Agrimensura do IBETP: matriz curricular oficial, duração, carga horária, investimento e matrícula.',
            'updated_at' => '2026-07-20 00:00:00',
        ],
    ];
}

function official_post_technical_slug_allowed(array $product): bool {
    if (!product_is_post_technical($product)) return true;
    $textKey = ibetp_slug_key((string)($product['slug'] ?? '') . ' ' . (string)($product['title'] ?? ''));
    foreach (official_post_technical_products() as $official) {
        $officialKey = ibetp_slug_key((string)$official['slug'] . ' ' . (string)$official['title']);
        if ((str_contains($textKey, 'agrimensura') && str_contains($officialKey, 'agrimensura')) || (str_contains($textKey, 'enfermagem-do-trabalho') && str_contains($officialKey, 'enfermagem-do-trabalho'))) {
            return true;
        }
    }
    return false;
}

function official_post_technical_product_by_slug(string $slug): ?array {
    $slugKey = ibetp_slug_key($slug);
    foreach (official_post_technical_products() as $product) {
        if (ibetp_slug_key((string)$product['slug']) === $slugKey) {
            return $product;
        }
        if (str_contains($slugKey, 'agrimensura') && str_contains(ibetp_slug_key((string)$product['slug']), 'agrimensura')) {
            return $product;
        }
        if (str_contains($slugKey, 'enfermagem-do-trabalho') && str_contains(ibetp_slug_key((string)$product['slug']), 'enfermagem-do-trabalho')) {
            return $product;
        }
    }
    return null;
}

function official_post_technical_product_by_id(int $id): ?array {
    foreach (official_post_technical_products() as $product) {
        if ((int)$product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function merge_official_post_technical_products(array $items): array {
    $merged = [];
    foreach ($items as $item) {
        $key = ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''));
        if (product_is_post_technical($item) && !official_post_technical_slug_allowed($item)) {
            continue;
        }
        $official = product_is_post_technical($item) ? official_post_technical_product_by_slug((string)($item['slug'] ?? $item['title'] ?? '')) : null;
        if ($official) {
            $key = ibetp_slug_key((string)$official['slug']);
        }
        $merged[$key] = $official ?: $item;
    }
    foreach (official_post_technical_products() as $product) {
        $merged[ibetp_slug_key((string)$product['slug'])] = $product;
    }
    uasort($merged, fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return array_values($merged);
}

function dedupe_post_technical_products(array $items): array {
    $deduped = [];
    foreach ($items as $item) {
        $key = ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''));
        if (product_is_post_technical($item)) {
            $textKey = ibetp_slug_key((string)($item['slug'] ?? '') . ' ' . (string)($item['title'] ?? ''));
            if (str_contains($textKey, 'agrimensura')) {
                $item = official_post_technical_product_by_slug('pos-tecnico-em-agrimensura') ?: $item;
                $key = 'pos-tecnico-em-agrimensura';
            } elseif (str_contains($textKey, 'enfermagem-do-trabalho') || (str_contains($textKey, 'enfermagem') && str_contains($textKey, 'trabalho'))) {
                $item = official_post_technical_product_by_slug('pos-tecnico-em-enfermagem-do-trabalho') ?: $item;
                $key = 'pos-tecnico-em-enfermagem-do-trabalho';
            }
        }
        $deduped[$key] = $item;
    }
    return array_values($deduped);
}

function official_sequential_products(): array {
    static $products = null;
    if ($products !== null) {
        return $products;
    }
    $path = __DIR__ . '/data-sequential-products.php';
    $loaded = is_file($path) ? require $path : [];
    $products = array_values(array_filter($loaded, fn($product) => is_array($product)));
    return $products;
}

function official_sequential_slug_allowed(array $product): bool {
    if (!product_is_sequential($product)) return true;
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    foreach (official_sequential_products() as $official) {
        if ($slugKey === ibetp_slug_key((string)$official['slug'])) {
            return true;
        }
    }
    return false;
}

function official_sequential_product_by_slug(string $slug): ?array {
    $slugKey = ibetp_slug_key($slug);
    foreach (official_sequential_products() as $product) {
        if (ibetp_slug_key((string)$product['slug']) === $slugKey) {
            return $product;
        }
    }
    return null;
}

function official_sequential_product_by_id(int $id): ?array {
    foreach (official_sequential_products() as $product) {
        if ((int)$product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function merge_official_sequential_products(array $items): array {
    $merged = [];
    foreach ($items as $item) {
        $key = ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''));
        if (product_is_sequential($item) && !official_sequential_slug_allowed($item)) {
            continue;
        }
        $official = product_is_sequential($item) ? official_sequential_product_by_slug((string)($item['slug'] ?? $item['title'] ?? '')) : null;
        if ($official) {
            $key = ibetp_slug_key((string)$official['slug']);
        }
        $merged[$key] = $official ?: $item;
    }
    foreach (official_sequential_products() as $product) {
        $merged[ibetp_slug_key((string)$product['slug'])] = $product;
    }
    uasort($merged, fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return array_values($merged);
}

function official_postgrad_products(): array {
    static $products = null;
    if ($products !== null) {
        return $products;
    }
    $path = __DIR__ . '/data-postgrad-products.php';
    $loaded = is_file($path) ? require $path : [];
    $products = array_values(array_filter($loaded, fn($product) => is_array($product)));
    return $products;
}

function official_postgrad_slug_allowed(array $product): bool {
    if (!product_is_postgrad($product)) return true;
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    foreach (official_postgrad_products() as $official) {
        if ($slugKey === ibetp_slug_key((string)$official['slug'])) {
            return true;
        }
    }
    return false;
}

function official_postgrad_product_by_slug(string $slug): ?array {
    $slugKey = ibetp_slug_key($slug);
    foreach (official_postgrad_products() as $product) {
        if (ibetp_slug_key((string)$product['slug']) === $slugKey) {
            return $product;
        }
    }
    return null;
}

function official_postgrad_product_by_id(int $id): ?array {
    foreach (official_postgrad_products() as $product) {
        if ((int)$product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function merge_official_postgrad_products(array $items): array {
    $merged = [];
    foreach ($items as $item) {
        $key = ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''));
        if (product_is_postgrad($item) && !official_postgrad_slug_allowed($item)) {
            continue;
        }
        $official = product_is_postgrad($item) ? official_postgrad_product_by_slug((string)($item['slug'] ?? $item['title'] ?? '')) : null;
        if ($official) {
            $key = ibetp_slug_key((string)$official['slug']);
        }
        $merged[$key] = $official ?: $item;
    }
    foreach (official_postgrad_products() as $product) {
        $merged[ibetp_slug_key((string)$product['slug'])] = $product;
    }
    uasort($merged, fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return array_values($merged);
}

function official_no_internship_technical_products(): array {
    $base = [
        ['tecnico-em-designer-de-interiores', 'Técnico em Designer de Interiores — EAD', 'Serviços'],
        ['tecnico-em-gastronomia', 'Técnico em Gastronomia — EAD', 'Serviços'],
        ['tecnico-em-confeitaria', 'Técnico em Confeitaria — EAD', 'Serviços'],
        ['tecnico-em-seguros', 'Técnico em Seguros — EAD', 'Administração e gestão'],
        ['tecnico-em-financas', 'Técnico em Finanças — EAD', 'Administração e gestão'],
        ['tecnico-em-eventos', 'Técnico em Eventos — EAD', 'Administração e gestão'],
        ['tecnico-em-gerencia-e-saude', 'Técnico em Gerência e Saúde — EAD', 'Saúde'],
        ['tecnico-em-agente-comunitario-de-saude', 'Técnico em Agente Comunitário de Saúde — EAD', 'Saúde'],
        ['tecnico-em-aquicultura', 'Técnico em Aquicultura — EAD', 'Meio ambiente e agropecuária'],
        ['tecnico-em-agroindustria', 'Técnico em Agroindústria — EAD', 'Meio ambiente e agropecuária'],
        ['tecnico-em-agropecuaria', 'Técnico em Agropecuária — EAD', 'Meio ambiente e agropecuária'],
        ['tecnico-em-agricultura', 'Técnico em Agricultura — EAD', 'Meio ambiente e agropecuária'],
        ['tecnico-em-maquinas-pesadas', 'Técnico em Máquinas Pesadas — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-estrada', 'Técnico em Estradas — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-saneamento', 'Técnico em Saneamento — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-mecatronica', 'Técnico em Mecatrônica — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-refrigeracao-e-climatizacao', 'Técnico em Refrigeração e Climatização — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-soldagem', 'Técnico em Soldagem — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-metalurgia', 'Técnico em Metalurgia — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-manutencao-de-maquinas-navais', 'Técnico em Manutenção de Máquinas Navais — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-manutencao-de-maquinas-industriais', 'Técnico em Manutenção de Máquinas Industriais — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-qualidade', 'Técnico em Qualidade — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-petroleo-e-gas', 'Técnico em Petróleo e Gás — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-eletromecanica', 'Técnico em Eletromecânica — EAD', 'Engenharia e manutenção'],
        ['tecnico-em-prevencao-e-combate-ao-incendio', 'Técnico em Prevenção e Combate ao Incêndio — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-transito', 'Técnico em Trânsito — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-defesa-civil', 'Técnico em Defesa Civil — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-mineracao', 'Técnico em Mineração — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-agrimensura', 'Técnico em Agrimensura — EAD', 'Construção e infraestrutura'],
        ['tecnico-em-geoprocessamento', 'Técnico em Geoprocessamento — EAD', 'Tecnologia e informática'],
        ['tecnico-em-telecomunicacoes', 'Técnico em Telecomunicações — EAD', 'Tecnologia e informática'],
        ['tecnico-em-traducao-e-interpretacao-de-libras', 'Técnico em Tradução e Interpretação de Libras — EAD', 'Tecnologia e informática'],
        ['tecnico-em-design-grafico', 'Técnico em Design Gráfico — EAD', 'Tecnologia e informática'],
        ['tecnico-em-biotecnologia', 'Técnico em Biotecnologia — EAD', 'Tecnologia e informática'],
        ['tecnico-em-sistema-de-energia-renovavel', 'Técnico em Sistema de Energia Renovável — EAD', 'Tecnologia e informática'],
    ];
    $items = [];
    foreach ($base as $i => $row) {
        [$slug, $title, $area] = $row;
        $items[] = [
            'id' => -9000 - $i,
            'slug' => $slug,
            'title' => $title,
            'category' => 'Cursos Técnicos EAD',
            'area' => $area,
            'price' => 99.90,
            'checkout_enabled' => 1,
            'status' => 'active',
            'image' => '/assets/produtos/tecnicos-ead-v2/' . $slug . '.jpg',
            'short_description' => $title . '. Formação técnica EAD com matriz curricular oficial, presencialidade por ATA e início em até 24 horas úteis após a confirmação do pagamento.',
            'description' => $title . '. Curso Técnico EAD com 12 mensalidades de R$ 99,90, grade curricular oficial e atendimento do IBETP para orientação de matrícula, documentação e próximos passos.',
            'seo_title' => $title . ' | IBETP',
            'seo_description' => 'Conheça o ' . $title . ' do IBETP: grade curricular oficial, investimento, presencialidade por ATA, documentos e matrícula.',
            'updated_at' => '2026-07-20 00:00:00',
        ];
    }
    return $items;
}

function official_technical_product_by_slug(string $slug): ?array {
    $slugKey = ibetp_slug_key($slug);
    foreach (official_no_internship_technical_products() as $product) {
        if (ibetp_slug_key((string)$product['slug']) === $slugKey) {
            return $product;
        }
    }
    return null;
}

function official_technical_product_by_id(int $id): ?array {
    foreach (official_no_internship_technical_products() as $product) {
        if ((int)$product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function merge_official_technical_products(array $items): array {
    $merged = [];
    foreach ($items as $item) {
        $merged[ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''))] = $item;
    }
    foreach (official_no_internship_technical_products() as $product) {
        $merged[ibetp_slug_key((string)$product['slug'])] = $product;
    }
    uasort($merged, fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return array_values($merged);
}

function official_technologist_products(): array {
    $base = [
        ['tecnologo-em-gestao-de-recursos-humanos', 'Tecnólogo em Gestão de Recursos Humanos', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-em-negocios-imobiliarios', 'Tecnólogo em Gestão em Negócios Imobiliários', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-do-agronegocio', 'Tecnólogo em Gestão do Agronegócio', 'Meio ambiente e agropecuária', '2.400h', '2 anos'],
        ['tecnologo-em-gestao-ambiental', 'Tecnólogo em Gestão Ambiental', 'Meio ambiente e agropecuária', '1.600h', '1 ano e meio'],
        ['tecnologo-em-marketing', 'Tecnólogo em Marketing', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-logistica', 'Tecnólogo em Logística', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-design-grafico', 'Tecnólogo em Design Gráfico', 'Tecnologia e informática', '1.600h', '1 ano e meio'],
        ['tecnologo-em-seguranca-do-trabalho', 'Tecnólogo em Segurança do Trabalho', 'Segurança', '2.400h', '2 anos'],
        ['tecnologo-em-gestao-hospitalar', 'Tecnólogo em Gestão Hospitalar', 'Saúde', '2.400h', '2 anos'],
        ['tecnologo-em-analise-e-desenvolvimento-de-sistemas', 'Tecnólogo em Análise e Desenvolvimento de Sistemas', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-redes-de-computadores', 'Tecnólogo em Redes de Computadores', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-seguranca-da-informacao', 'Tecnólogo em Segurança da Informação', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-processos-escolares', 'Tecnólogo em Processos Escolares', 'Educação', '2.000h', '2 anos'],
        ['tecnologo-em-secretariado', 'Tecnólogo em Secretariado', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-comercio-exterior', 'Tecnólogo em Comércio Exterior', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-publica', 'Tecnólogo em Gestão Pública', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-jogos-digitais', 'Tecnólogo em Jogos Digitais', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-hotelaria', 'Tecnólogo em Hotelaria', 'Serviços', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-de-eventos', 'Tecnólogo em Gestão de Eventos', 'Serviços', '1.600h', '1 ano e meio'],
        ['tecnologo-em-processos-gerenciais', 'Tecnólogo em Processos Gerenciais', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-comercial', 'Tecnólogo em Gestão Comercial', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-financeira', 'Tecnólogo em Gestão Financeira', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-da-producao-industrial', 'Tecnólogo em Gestão da Produção Industrial', 'Engenharia e manutenção', '2.400h', '2 anos'],
        ['tecnologo-em-gestao-da-qualidade', 'Tecnólogo em Gestão da Qualidade', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-da-tecnologia-da-informacao', 'Tecnólogo em Gestão da Tecnologia da Informação', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-gestao-de-cooperativas', 'Tecnólogo em Gestão de Cooperativas', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-de-seguranca-privada', 'Tecnólogo em Gestão de Segurança Privada', 'Segurança', '1.600h', '1 ano e meio'],
        ['tecnologo-em-redes-de-telecomunicacoes', 'Tecnólogo em Redes de Telecomunicações', 'Tecnologia e informática', '2.400h', '2 anos'],
        ['tecnologo-em-gestao-de-turismo', 'Tecnólogo em Gestão de Turismo', 'Serviços', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-desportiva-e-de-lazer', 'Tecnólogo em Gestão Desportiva e de Lazer', 'Educação', '1.600h', '1 ano e meio'],
        ['tecnologo-em-servicos-penais', 'Tecnólogo em Serviços Penais', 'Segurança', '1.600h', '1 ano e meio'],
        ['tecnologo-em-design-de-animacao', 'Tecnólogo em Design de Animação', 'Tecnologia e informática', '1.600h', '1 ano e meio'],
        ['tecnologo-em-design-de-interiores', 'Tecnólogo em Design de Interiores', 'Serviços', '1.600h', '1 ano e meio'],
        ['tecnologo-em-design-de-moda', 'Tecnólogo em Design de Moda', 'Serviços', '1.600h', '1 ano e meio'],
        ['tecnologo-em-design-de-produtos', 'Tecnólogo em Design de Produtos', 'Engenharia e manutenção', '1.600h', '1 ano e meio'],
        ['tecnologo-em-producao-audiovisual', 'Tecnólogo em Produção Audiovisual', 'Tecnologia e informática', '1.600h', '1 ano e meio'],
        ['tecnologo-em-producao-cultural', 'Tecnólogo em Produção Cultural', 'Educação', '2.400h', '2 anos'],
        ['tecnologo-em-producao-multimidia', 'Tecnólogo em Produção Multimídia', 'Tecnologia e informática', '1.600h', '1 ano e meio'],
        ['tecnologo-em-producao-publicitaria', 'Tecnólogo em Produção Publicitária', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-petroleo-e-gas', 'Tecnólogo em Petróleo e Gás', 'Engenharia e manutenção', '2.400h', '2 anos'],
        ['tecnologo-em-sistemas-eletricos', 'Tecnólogo em Sistemas Elétricos', 'Engenharia e manutenção', '2.400h', '2 anos'],
        ['tecnologo-em-banco-de-dados', 'Tecnólogo em Banco de Dados', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-sistemas-para-internet', 'Tecnólogo em Sistemas para Internet', 'Tecnologia e informática', '2.000h', '2 anos'],
        ['tecnologo-em-gestao-de-servicos-judiciais-e-notariais', 'Tecnólogo em Gestão de Serviços Judiciais e Notariais', 'Administração e gestão', '1.600h', '1 ano e meio'],
        ['tecnologo-em-gestao-de-seguranca-publica', 'Tecnólogo em Gestão de Segurança Pública', 'Segurança', '1.600h', '1 ano e meio'],
    ];
    $items = [];
    foreach ($base as $i => $row) {
        [$slug, $title, $area, $workload, $duration] = $row;
        $items[] = [
            'id' => -9500 - $i,
            'slug' => $slug,
            'title' => $title,
            'category' => 'Tecnólogo EAD',
            'area' => $area,
            'price' => 99.90,
            'checkout_enabled' => 1,
            'status' => 'active',
            'duration' => $duration,
            'workload' => $workload,
            'image' => '/assets/produtos/' . $slug . '.webp',
            'short_description' => $title . '. Matrícula de R$ 99,90 no site do IBETP e mensalidades de R$ 149,90 diretamente no AVA. Curso EAD com atividades presenciais em polo.',
            'description' => $title . '. Graduação tecnológica EAD com grade curricular oficial, atividades presenciais em polo e atendimento do IBETP para matrícula, documentação e próximos passos.',
            'seo_title' => $title . ' | IBETP',
            'seo_description' => 'Conheça o ' . $title . ' do IBETP: matriz curricular oficial, matrícula, mensalidades no AVA, polos presenciais e orientação de matrícula.',
            'updated_at' => '2026-07-20 00:00:00',
        ];
    }
    return $items;
}

function official_technologist_slug_allowed(array $product): bool {
    if (!product_is_technologist($product)) return true;
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    foreach (official_technologist_products() as $official) {
        if (ibetp_slug_key((string)$official['slug']) === $slugKey) return true;
    }
    return false;
}

function official_technologist_product_by_slug(string $slug): ?array {
    $slugKey = ibetp_slug_key($slug);
    foreach (official_technologist_products() as $product) {
        if (ibetp_slug_key((string)$product['slug']) === $slugKey) {
            return $product;
        }
    }
    return null;
}

function official_technologist_product_by_id(int $id): ?array {
    foreach (official_technologist_products() as $product) {
        if ((int)$product['id'] === $id) {
            return $product;
        }
    }
    return null;
}

function merge_official_technologist_products(array $items): array {
    $merged = [];
    foreach ($items as $item) {
        $merged[ibetp_slug_key((string)($item['slug'] ?? $item['title'] ?? ''))] = $item;
    }
    foreach (official_technologist_products() as $product) {
        $merged[ibetp_slug_key((string)$product['slug'])] = $product;
    }
    uasort($merged, fn($a, $b) => strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    return array_values($merged);
}

function technologist_common_profile(string $duration, string $workload, string $eixo, array $modules): array {
    return [
        'duration' => $duration,
        'workload' => $workload,
        'modality_note' => 'Graduação tecnológica EAD com atividades presenciais em polo, matrícula pelo site do IBETP e mensalidades diretamente no AVA.',
        'presence' => 'Este curso possui atividades presenciais vinculadas aos polos informados nesta página. Antes de pagar a matrícula, confirme se você tem disponibilidade real para comparecer a um dos polos. Se você mora muito distante dos polos disponíveis, o aconselhável é não realizar a matrícula sem antes confirmar a viabilidade com o IBETP.',
        'internship' => '',
        'source' => 'Grade oficial extraída do informativo do curso.',
        'tcc' => 'Trabalho de Conclusão de Curso obrigatório.',
        'eixo' => $eixo,
        'modules' => $modules,
    ];
}

function official_post_technical_profile_override(string $slugKey): ?array {
    if (str_contains($slugKey, 'enfermagem-do-trabalho')) {
        return [
            'duration' => '6 meses',
            'workload' => '360h',
            'modality_note' => 'Especialização Técnica com matriz curricular oficial extraída do informativo acadêmico.',
            'presence' => '',
            'internship' => '',
            'tcc' => '',
            'source' => 'Informativo de Curso — Especialização em Enfermagem do Trabalho.',
            'modules' => [
                ['Módulo I', '360h', [
                    ['Práticas de Enfermagem','40h'],
                    ['Epidemiologia e Vigilâncias em Saúde do Trabalhador','60h'],
                    ['Higiene, Segurança do Trabalho e Prevenção de Acidentes — CIPA','40h'],
                    ['Gerenciamento de Perigos e Riscos na Saúde do Trabalhador','40h'],
                    ['Saúde Laboral e Toxicologia','60h'],
                    ['Ergonomia e Medicina do Trabalho','40h'],
                    ['Vigilância Sanitária, Epidemiológica e Ambiental','40h'],
                    ['Sistema de saúde e organização da atenção básica: saúde do homem, do adulto e idoso','40h'],
                ]],
            ],
        ];
    }
    if (str_contains($slugKey, 'agrimensura')) {
        return [
            'duration' => '6 meses',
            'workload' => '320h',
            'modality_note' => 'Especialização Técnica EAD com matriz curricular oficial extraída do informativo acadêmico.',
            'presence' => '',
            'internship' => '',
            'tcc' => '',
            'source' => 'Informativo de Curso — Especialização Técnica em Agrimensura.',
            'modules' => [
                ['Módulo I', '320h', [
                    ['Geodésia','40h'],
                    ['Topografia','40h'],
                    ['Cartografia e Geoprocessamento','40h'],
                    ['Legislação aplicada à agrimensura','40h'],
                    ['Projeto geométrico de estradas','40h'],
                    ['Sistemas de Informação Geográfica','40h'],
                    ['Planejamento Urbano e Cidades Inteligentes','40h'],
                    ['Georreferenciamento de Imóveis Rurais e Geodésia','40h'],
                ]],
            ],
        ];
    }
    return null;
}

function official_technologist_profile_override(string $slugKey): ?array {
    $profiles = [
        'tecnologo-em-sistemas-eletricos' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Controle e Processos Industriais', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Comunicação Corporativa','60h'], ['Cálculo','60h'], ['Segurança em Instalações Elétricas','60h'], ['Interpretação de Desenho Técnico','60h'], ['Empreendedorismo','60h'], ['Comandos Elétricos','60h'], ['Eletricidade Básica','80h']]],
            ['2° Período', '', [['Física Aplicada','60h'], ['Circuitos Elétricos','60h'], ['Legislação e Ética Aplicada ao Setor Elétrico','80h'], ['Mercado de Energia Elétrica','80h'], ['Gestão e Manutenção de Sistemas Elétricos','80h'], ['Instalações Elétricas de Baixa Tensão','80h'], ['Instalações Elétricas de Média e Alta Tensão','80h'], ['Instalações Elétricas Industriais','80h'], ['Máquinas Elétricas','80h']]],
            ['3° Período', '', [['Análise de Sistema de Potência','60h'], ['Programa de Gerência de Riscos','80h'], ['Geração, Transmissão e Distribuição de Energia','80h'], ['Proteção de Sistemas Elétricos','60h'], ['Eletrônica Industrial de Potência','80h'], ['Introdução à Energia Solar Fotovoltaica','60h'], ['Instalações Elétricas em Sistemas de Energia Renovável','80h'], ['Otimização de Sistemas Elétricos de Potência','60h']]],
            ['4° Período', '', [['Despacho Econômico de Energia','60h'], ['Microcontroladores e Microprocessadores','60h'], ['Qualidade e Eficiência Energética','60h'], ['Projetos de Automação Industrial','60h'], ['Gestão de Riscos em Projetos Solares','60h'], ['Projetos Elétricos','60h'], ['Projetos de Instalações de Sistema de Energia Renovável','60h'], ['Educação e Cultura Indígenas','40h'], ['Projetos Integradores Extensionistas','80h'], ['Ética, Cidadania e Meio-Ambiente','60h'], ['Direitos Humanos e Sustentabilidade','60h']]],
        ]),
        'tecnologo-em-jogos-digitais' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Lógica de Programação','80h'], ['Inglês Instrumental','80h'], ['Introdução a Banco de Dados','80h'], ['Criatividade, Storytelling e Design Thinking','80h'], ['Computação Gráfica','80h']]],
            ['2° Período', '', [['Representação e Composição Artística','80h'], ['Física para Jogos Digitais','80h'], ['Algoritmos e Programação de Computadores','80h'], ['Roteirização de Jogos Digitais','80h'], ['Análise e Projeto de Software Orientado a Objetos','80h'], ['Gestão de Times','60h'], ['Programação em Python','80h']]],
            ['3° Período', '', [['Construção e Animação de Cenários e Objetos 2D e 3D','80h'], ['Programação de Jogos Digitais para Consoles','80h'], ['Direção e Edição em Design','80h'], ['Gestão da Qualidade','80h'], ['Modelagem 3D','80h'], ['Gestão de Projetos','60h'], ['Inteligência Artificial para Jogos','60h'], ['Programação — Coding Mobile (Java)','80h']]],
            ['4° Período', '', [['Jogos para Dispositivos Móveis','80h'], ['Programação em Unity','80h'], ['Análise de Mercado: Tendência, Comportamento e Movimento','60h'], ['Empreendedorismo','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Educação Ambiental','40h'], ['Atividades Complementares e Extensionistas','60h']]],
        ]),
        'tecnologo-em-sistemas-para-internet' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Lógica de Programação','60h'], ['Inglês Instrumental','60h'], ['Informática e Ferramentas de Produtividade','60h'], ['Algoritmos e Programação de Computadores','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','60h']]],
            ['2° Período', '', [['Introdução a Banco de Dados','80h'], ['Arquitetura e Programação Front-End','80h'], ['Programação Back-End','80h'], ['Programação — Coding Mobile (Java)','80h'], ['Programação e Integração de Sistemas','80h'], ['Big Data e Ciências dos Dados','80h'], ['Engenharia de Software','80h'], ['Medidores de Performance e Web Analytics','80h']]],
            ['3° Período', '', [['Programação — Coding Web (PHP)','80h'], ['Computação em Nuvem','80h'], ['Modelagem de Sistemas','80h'], ['Gestão de Projetos','60h'], ['Dados Abertos, Segurança da Informação e Privacidade','80h'], ['Qualidade de Software','60h'], ['Lógica de Programação Orientada a Objetos','60h'], ['Empreendedorismo','60h']]],
            ['4° Período', '', [['Teste de Software','60h'], ['Educação Ambiental','40h'], ['Laboratório de Programação Orientada a Objetos','60h'], ['Gestão de Times — Métodos Ágeis','60h'], ['Educação das Relações Étnico-Raciais','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-seguranca-do-trabalho' => technologist_common_profile('2 anos', '2.400h', 'Segurança', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Gestão de Pessoas','60h'], ['Metodologia Científica','60h'], ['Ergonomia e Segurança no Trabalho','80h'], ['Gestão da Qualidade','80h'], ['Psicologia do Trabalho','80h'], ['Combate e Prevenção de Incêndios e Pânicos','80h']]],
            ['2° Período', '', [['Estatística Básica','80h'], ['Direito do Trabalho','80h'], ['Prevenção e Tratamento das Não Conformidades','80h'], ['Gestão Ambiental','80h'], ['Higiene Ocupacional e Prevenção de Riscos Ambientais','80h'], ['Segurança em Instalações Elétricas','60h'], ['Normas Regulamentadoras Básicas','80h'], ['Certificação da Qualidade','80h']]],
            ['3° Período', '', [['Prevenção e Tratamento de Não-Conformidades','80h'], ['Normas Regulamentadoras Básicas','80h'], ['Proteção de Máquinas e Equipamentos','80h'], ['Segurança do Trabalho Aplicada à Radioatividade','90h'], ['Gestão de Custos, Riscos e Perdas — GCRP','80h'], ['Suporte Emergencial à Vida e Atendimento Pré-Hospitalar','90h'], ['Bioética e Biossegurança','80h'], ['Segurança do Trabalho e Saúde Ocupacional','80h']]],
            ['4° Período', '', [['Segurança do Trabalho na Construção Civil NR18','80h'], ['Gestão de Riscos','80h'], ['Segurança, Meio Ambiente, Saúde e Responsabilidade Social','80h'], ['Liderança e Gestão de Equipes','60h'], ['Auditorias, Perícias e Laudos','60h'], ['Educação Ambiental','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Relações Étnico-Raciais e Afrodescendência','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-redes-de-computadores' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução à EAD','40h'], ['Empreendedorismo','60h'], ['Lógica Matemática','60h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','60h'], ['Inglês Instrumental','60h'], ['Algoritmos e Programação de Computadores','80h']]],
            ['2° Período', '', [['Introdução à Ciência da Computação','80h'], ['Introdução a Banco de Dados','80h'], ['Hardware Básico e Manutenção de Computadores','60h'], ['Segurança da Informação','80h'], ['Sistemas Operacionais','80h'], ['Administração de Servidores','80h'], ['Arquitetura e Organização de Computadores','80h'], ['Redes sem Fio','80h']]],
            ['3° Período', '', [['Projeto de Redes','80h'], ['Administração de Sistema Operacional Livre — Linux','80h'], ['Administração de Sistema Operacional Proprietário — Windows Server','80h'], ['Cabeamento Estruturado','80h'], ['Gerenciamento de Redes de Computadores','80h'], ['Programação em Python','80h'], ['Infraestrutura de Computação em Nuvem','80h']]],
            ['4° Período', '', [['Internet das Coisas','80h'], ['Comunicação de Dados','80h'], ['Gestão do Conhecimento e Inteligência Competitiva','60h'], ['Planejamento Estratégico de Tecnologia da Informação','40h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Relações Étnico-Raciais e Afrodescendência','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-redes-de-telecomunicacoes' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Introdução à Eletricidade Básica','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','80h'], ['Fundamentos do Inglês','80h'], ['Informática Aplicada','80h'], ['Empreendedorismo','80h'], ['Planejamento Estratégico de Tecnologia da Informação','80h']]],
            ['2° Período', '', [['Gestão da Tecnologia da Informação e Comunicação','90h'], ['Redes sem Fio','90h'], ['Sistemas de Comunicação e Telecomunicações','80h'], ['Segurança da Informação','80h'], ['Internet das Coisas','80h'], ['Gerenciamento de Redes de Computadores','80h'], ['Projeto de Redes','80h'], ['Cabeamento Estruturado','80h']]],
            ['3° Período', '', [['Eletrônica Analógica','80h'], ['Transformação Digital','80h'], ['Ergonomia e Segurança no Trabalho','80h'], ['Eletrônica Digital','80h'], ['Gestão da Inovação e Competitividade','80h'], ['Conversão Eletromecânica de Energia','80h']]],
            ['4° Período', '', [['Infovia de Comunicação e as Redes de Alto Desempenho em Cidades Inteligentes','80h'], ['Gestão de Projetos','80h'], ['Liderança e Gestão de Equipes','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','80h'], ['Desenvolvimento e Monitoramento de Dashboards em Ciência de Dados','80h'], ['Infraestrutura de Computação em Nuvem','80h'], ['Atividades Complementares/Extensionistas','80h'], ['Educação das Relações Étnico-Raciais','80h'], ['Ética Profissional','80h']]],
        ]),
        'tecnologo-em-producao-cultural' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Comunicação e Liderança','60h'], ['Cultura e Sociedade','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Organização de Eventos Esportivos, Culturais e de Lazer','80h'], ['Patrimônio Histórico-Cultural Brasileiro','80h']]],
            ['2° Período', '', [['Sociologia e Antropologia','80h'], ['Planejamento Estratégico','80h'], ['Produção Multimídia','80h'], ['Estratégias de Marketing','80h'], ['Marketing de Eventos','80h'], ['Geografia Cultural','80h'], ['Gestão de Pessoas','80h'], ['Políticas Sociais','80h'], ['Gestão de Eventos','80h']]],
            ['3° Período', '', [['Tecnologia em Artes Cênicas','80h'], ['Produção Teatral','80h'], ['Gestão Financeira','80h'], ['Economia Criativa e Fontes de Recursos','80h'], ['Análise da Viabilidade Econômica e Financeira de Projetos','80h'], ['Dinâmicas de Grupo e Programas de Desenvolvimento Individual','80h'], ['Dramaturgia','80h']]],
            ['4° Período', '', [['Administração e Planejamento de Projetos de Impacto Social','80h'], ['Voz e Fala na Atuação Teatral','80h'], ['Produção e Gestão em Dança','80h'], ['Criatividade, Storytelling e Design Thinking','60h'], ['Empreendedorismo','60h'], ['Estética e Linguagem Audiovisual','80h'], ['Educação Ambiental','60h'], ['Análise de Dados para Decisões Estratégicas','80h'], ['Educação das Relações Étnico-Raciais','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-petroleo-e-gas' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Produção Industrial', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Comunicação e Liderança','60h'], ['Matemática Básica','60h'], ['Química Geral e Inorgânica','60h'], ['Gestão Ambiental','60h'], ['Estatística Básica','60h'], ['Fundamentos da Geologia','60h'], ['Logística Aplicada à Cadeia Produtiva de Petróleo e Gás','80h'], ['Química dos Materiais','60h']]],
            ['2° Período', '', [['Gestão de Pessoas','80h'], ['Geologia e Mecânica dos Sólidos','80h'], ['Física — Energia, Movimento e Temperatura','80h'], ['Resistência dos Materiais','60h'], ['Produção de Petróleo e Gás','60h'], ['Gestão da Qualidade','80h'], ['Mecânica dos Fluídos e Termodinâmica','80h'], ['Gestão de Operações','80h']]],
            ['3° Período', '', [['Máquinas Mecânicas','80h'], ['Projetos de Automação Industrial','80h'], ['Gestão de Riscos','80h'], ['Controle de Vibrações Mecânicas','80h'], ['Operações e Projetos Portuários','80h'], ['Motores de Combustão Interna','80h'], ['Gerenciamento dos Aspectos e Impactos Ambientais','80h'], ['Organização e Gestão do Trabalho Industrial','80h'], ['Higiene Ocupacional e Prevenção de Riscos Ambientais','80h']]],
            ['4° Período', '', [['Operações de Seguros e Liquidação de Sinistros','80h'], ['Segurança do Trabalho em Portos','80h'], ['Gestão de Projetos','80h'], ['Atividades Complementares e Extensionistas','80h'], ['Empreendedorismo','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','80h'], ['Educação das Relações Étnico-Raciais','60h']]],
        ]),
        'tecnologo-em-gestao-hospitalar' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Ambiente e Saúde', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Metodologia Científica','80h'], ['Gestão do Processo e da Força de Trabalho em Saúde','80h'], ['Empreendedorismo','80h'], ['Gestão da Qualidade','80h'], ['Auditoria Hospitalar','80h'], ['Gestão de Serviços de Tecnologia Hospitalar','80h']]],
            ['2° Período', '', [['Gestão Aplicada à Saúde','80h'], ['Direito Aplicado à Gestão Hospitalar','80h'], ['Comissões Hospitalares','80h'], ['Logística Hospitalar','80h'], ['Gestão de Serviços de Documentação Hospitalar','80h'], ['Gestão Hospitalar e Qualidade no Atendimento','80h'], ['Gestão e Marketing Hospitalar','80h'], ['Farmácia Hospitalar','80h']]],
            ['3° Período', '', [['Administração Financeira e Contábil','80h'], ['Planejamento Estratégico e Qualidade Hospitalar','80h'], ['Ouvidoria Hospitalar','80h'], ['Controle e Prevenção de Infecção Hospitalar','80h'], ['Tecnologias em Equipamentos Hospitalares','80h'], ['Elaboração e Gestão de Contratos','80h'], ['Epidemiologia','80h'], ['Bioética e Biossegurança','80h'], ['Gestão de Riscos','80h']]],
            ['4° Período', '', [['Suporte Emergencial à Vida e Atendimento Pré-Hospitalar','80h'], ['Regulação, Controle, Avaliação e Auditoria em Saúde','80h'], ['Criando e Liderando Organizações Eficazes','80h'], ['Tópicos Contemporâneos em Gestão Hospitalar','60h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Relações Étnico-Raciais e Afrodescendência','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-banco-de-dados' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Lógica Matemática','60h'], ['Inglês Instrumental','60h'], ['Lógica de Programação','60h'], ['Introdução a Banco de Dados','80h'], ['Ciência, Tecnologia e Sociedade','60h']]],
            ['2° Período', '', [['Introdução a Redes de Computadores e Protocolos de Comunicação','60h'], ['Sistemas Operacionais','60h'], ['Planejamento Estratégico de Tecnologia da Informação','60h'], ['Tecnologias e Linguagens de Banco de Dados','80h'], ['Estrutura de Dados','80h'], ['Engenharia de Software','80h'], ['Segurança em Sistemas Operacionais Abertos e Proprietários','80h']]],
            ['3° Período', '', [['Bancos de Dados Não Relacionais','80h'], ['Big Data e Ciências dos Dados','80h'], ['Gestão de Projetos','80h'], ['Dados Abertos, Segurança da Informação e Privacidade','80h'], ['Governança de Dados','80h'], ['Desenvolvimento e Monitoramento de Dashboards em Ciência de Dados','80h'], ['Mineração de Dados','80h']]],
            ['4° Período', '', [['Gestão de Bancos de Dados Locais e em Nuvem','80h'], ['Análise Exploratória de Dados','80h'], ['Modelagem de Sistemas','80h'], ['Gestão de Times — Métodos Ágeis','60h'], ['Empreendedorismo','60h'], ['Educação das Relações Étnico-Raciais','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-analise-e-desenvolvimento-de-sistemas' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução à EAD','40h'], ['Introdução à Ciência da Computação','60h'], ['Lógica Matemática','60h'], ['Empreendedorismo','40h'], ['Inglês Instrumental','60h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','60h'], ['Algoritmos e Programação de Computadores','60h']]],
            ['2° Período', '', [['Arquitetura e Organização de Computadores','60h'], ['Interface Humano-Computador e User Experience','60h'], ['Segurança da Informação','60h'], ['Sistemas Operacionais','60h'], ['Lógica de Programação Orientada a Objetos','80h'], ['Estrutura de Dados','60h'], ['Programação e Integração de Sistemas','60h'], ['Banco de Dados','60h']]],
            ['3° Período', '', [['Computação em Nuvem','60h'], ['Qualidade de Software','60h'], ['Modelagem de Sistemas','60h'], ['Engenharia de Software','80h'], ['Linguagem de Programação para Web','80h'], ['Teste de Software','80h'], ['Big Data e Ciências dos Dados','60h'], ['Sistemas Distribuídos','60h'], ['Bancos de Dados Não Relacionais','60h']]],
            ['4° Período', '', [['Programação em Python','80h'], ['Arquitetura e Programação Front-End','80h'], ['Programação — Coding Mobile (Java)','80h'], ['Governança e Auditoria de Tecnologia da Informação','40h'], ['Gestão de Times — Métodos Ágeis','40h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Relações Étnico-Raciais e Afrodescendência','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-da-tecnologia-da-informacao' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Lógica Matemática','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','80h'], ['Linguagem de Programação','80h'], ['Fundamentos do Inglês','60h'], ['Empreendedorismo','80h']]],
            ['2° Período', '', [['Gestão da Tecnologia da Informação e Comunicação','80h'], ['Direitos Humanos e Sustentabilidade','60h'], ['Estatística','80h'], ['Análise e Modelagem de Processos','80h'], ['Gerenciamento de Redes de Computadores','80h'], ['Administração de Sistemas de Informação','80h']]],
            ['3° Período', '', [['Hardware Básico e Manutenção de Computadores','80h'], ['Engenharia de Software','80h'], ['Planejamento Estratégico de Tecnologia da Informação','80h'], ['Dados Abertos, Segurança da Informação e Privacidade','80h'], ['Programação Orientada a Objetos','80h'], ['Lógica de Programação Avançada','80h'], ['Comunicação Integrada ao Marketing','60h']]],
            ['4° Período', '', [['Governança e Auditoria de Tecnologia da Informação','80h'], ['Big Data e Ciências dos Dados','80h'], ['Requisitos de Sistemas de Informação','80h'], ['Gestão de Bancos de Dados Locais e em Nuvem','80h'], ['Gestão de Projetos','60h'], ['Programação Coding Mobile','60h'], ['Atividades Complementares e Extensionistas','80h'], ['Educação das Relações Étnico-Raciais','40h']]],
        ]),
        'tecnologo-em-processos-escolares' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Desenvolvimento Educacional e Social', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Fundamentos da Administração','60h'], ['Português Instrumental','60h'], ['Administração Escolar','80h'], ['Sociologia e Educação','60h'], ['Relações Institucionais e Humanas no Ambiente Escolar','80h'], ['Gestão de Sistemas Educacionais','80h']]],
            ['2° Período', '', [['Regulação Educacional','80h'], ['Avaliação Diagnóstica Escolar','80h'], ['Comunicação Eficaz','60h'], ['Legislação e Políticas Educacionais','80h'], ['Avaliação Institucional Escolar','80h'], ['Políticas Educacionais e Gestão Escolar','80h'], ['Planejamento e Gestão','60h']]],
            ['3° Período', '', [['Gestão de Equipes e Liderança com Ênfase no Ambiente Escolar','80h'], ['Gestão Administrativa e Financeira da Escola','80h'], ['Escrituração Escolar','60h'], ['Logística de Alimentação Escolar','80h'], ['A Educação Especial e a Inclusão Escolar','60h'], ['Inspeção Escolar','80h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','60h']]],
            ['4° Período', '', [['Gestão de Qualidade e Gerenciamento de Rotina','80h'], ['Direitos Humanos na Educação','60h'], ['Empreendedorismo','60h'], ['Educação e as TICs','80h'], ['Mediação de Conflitos','80h'], ['Ética, Cidadania e Meio-Ambiente','60h'], ['Atividades Complementares/Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-do-agronegocio' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Recursos Naturais', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Fundamentos da Administração','60h'], ['Empreendedorismo','60h'], ['Fundamentos da Agronomia e Agronegócio','60h'], ['Matemática Básica','60h'], ['Economia Rural','80h'], ['Topografia e Geoprocessamento','80h'], ['Libras','60h']]],
            ['2° Período', '', [['Contabilidade Aplicada ao Agronegócio','80h'], ['Tecnologia e Inovação Aplicadas a Agronegócios','80h'], ['Projetos Agropecuários','80h'], ['Comercialização e Certificação Orgânica no Agronegócio','80h'], ['Legislação e Normas Técnicas Aplicadas ao Agronegócio','80h'], ['Direito Agroambiental','80h'], ['Gestão de Qualidade e Produtividade no Agronegócio','80h'], ['Análise de Competitividade das Cadeias Produtivas','80h']]],
            ['3° Período', '', [['Climatologia e Meteorologia Agrícola','80h'], ['Mercado do Agronegócio','80h'], ['Assistência Técnica e Extensão Rural','80h'], ['Fertilidade do Solo e Nutrição de Plantas','80h'], ['Comercialização e Certificação Orgânica no Agronegócio','80h'], ['Gestão de Transportes','60h'], ['Qualidade do Solo e Recuperação de Áreas Degradadas','60h'], ['Agroecologia','60h'], ['Gestão Empresarial Rural','60h']]],
            ['4° Período', '', [['Gestão da Produção','60h'], ['Ecologia e Limnologia','80h'], ['Gestão Financeira Aplicada ao Agronegócio','60h'], ['Agricultura Familiar e Desenvolvimento Sustentável','80h'], ['Georreferenciamento de Imóveis Rurais','60h'], ['Educação e Meio Ambiente','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Relações Étnico-Raciais e Afrodescendência','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-da-producao-industrial' => technologist_common_profile('2 anos (24 meses)', '2.400h', 'Produção Industrial', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Administração Aplicada à Engenharia de Segurança','60h'], ['Organização e Gestão do Trabalho Industrial','80h'], ['Comunicação e Expressão','60h'], ['Estudos em Contabilidade','80h'], ['Metodologia Científica','80h'], ['Gerenciamento de Projetos I','80h'], ['Planejamento Estratégico','80h']]],
            ['2° Período', '', [['Tecnologia da Informação','80h'], ['Gestão da Produção','80h'], ['Estatística','80h'], ['Legislação e Normas Regulamentadoras em Segurança do Trabalho','80h'], ['Meio Ambiente e Sustentabilidade','80h'], ['Ferramentas da Qualidade e Gestão por Processos','80h'], ['Desafios da Gestão Industrial e a Engenharia de Manutenção','80h'], ['Gestão da Qualidade','80h']]],
            ['3° Período', '', [['Gestão Estratégica das Organizações','80h'], ['Engenharia de Sistemas Produtivos','60h'], ['Liderança e Equipe Organizacional','80h'], ['Orçamento e Custos Industriais','80h'], ['Logística Avançada','80h'], ['Legislação Trabalhista e Normas Regulamentadoras','80h'], ['Controladoria e Finanças','80h'], ['Projetos de Automação Industrial','80h']]],
            ['4° Período', '', [['Economia e Mercado','80h'], ['Empreendedorismo','60h'], ['Gerenciamento de Projetos II','80h'], ['Sustentabilidade Ambiental, Social e Governança — ESG','60h'], ['Administração de Materiais','80h'], ['Educação em Direitos Humanos','60h'], ['Educação e Cultura Indígenas','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-design-de-produtos' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Matemática Básica','40h'], ['Comunicação e Expressão','40h'], ['Interpretação de Desenho Técnico','60h'], ['Fundamentos do Design','60h'], ['Desenvolvimento da Criatividade','60h'], ['História da Arte e do Design','60h'], ['Teorias da Cor','60h'], ['Estética e Percepção Visual','40h'], ['Desenvolvimento de Produto Tecnológico até o MVP','60h']]],
            ['2° Período', '', [['Design, Ergonomia, Antropometria e Acessibilidade','60h'], ['Design Thinking','40h'], ['Human Centred Design','40h'], ['Desenvolvimento de Produtos e Engenharia de Valor','60h'], ['Desenho Técnico Mecânico em CAD','60h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','60h'], ['Experiência e Desenho de Produtos e Serviços em Saúde','60h'], ['Gestão Estratégica de Marcas','60h']]],
            ['3° Período', '', [['Ilustração e Tratamento de Imagens 2D — Adobe Photoshop','60h'], ['Semiótica','40h'], ['Inteligência Artificial Aplicada ao Design Gráfico','60h'], ['Comunicação e Linguagem do Design Gráfico','40h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','40h'], ['Marketing','60h'], ['Modelagem 3D','60h'], ['Empreendedorismo','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Educação das Relações Étnico-Raciais','80h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-de-recursos-humanos' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos da Administração','40h'], ['Comunicação Eficaz','40h'], ['Fundamentos da Economia I','40h'], ['Teorias das Organizações','60h'], ['Direito Empresarial','60h'], ['Lógica e Fundamentos da Matemática','60h'], ['Direito do Trabalho','60h']]],
            ['2° Período', '', [['Recursos Humanos','60h'], ['Português Instrumental','60h'], ['Sociologia das Organizações','60h'], ['Psicologia e Comportamento Humano nas Organizações','60h'], ['Logística Organizacional','60h'], ['Contratos','60h'], ['Gerenciamento de Pessoal','60h'], ['Gestão de Conhecimentos e Avaliação de Desempenho','60h'], ['Gestão Estratégica das Organizações','60h'], ['Sistema Comunicativo nas Organizações','60h'], ['Administração Financeira I','60h']]],
            ['3° Período', '', [['Gestão de Qualidade e Gerenciamento de Rotina','60h'], ['Técnicas de Treinamento','60h'], ['Contrato Individual do Trabalho e Cálculo Trabalhista','40h'], ['Organização, Saúde e Segurança no Trabalho','60h'], ['Empreendedorismo','60h'], ['Mediação de Conflitos','60h'], ['Direitos Humanos e Sustentabilidade','40h'], ['Educação e Cultura Indígenas','40h'], ['Ética, Cidadania e Meio-Ambiente','40h'], ['Atividades Complementares/Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-comercial' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Noções Gerais do Direito','60h'], ['Matemática Elementar','60h'], ['Fundamentos da Administração','60h'], ['Estudos em Contabilidade','60h'], ['Comunicação Eficaz','60h'], ['Fundamentos da Economia','60h'], ['Liderança e Equipe Organizacional','60h'], ['Gestão de Pessoas','60h']]],
            ['2° Período', '', [['Aspectos Comerciais e Atendimento ao Cliente','60h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','60h'], ['Estratégias de Vendas','60h'], ['Direito do Consumidor','80h'], ['Planejamento e Gestão','60h'], ['Vendas e Negociação','60h'], ['Gestão de Custos e Formação de Preços','80h'], ['Análise de Mercado','60h']]],
            ['3° Período', '', [['O Papel da Motivação na Produtividade da Equipe','60h'], ['Programas de Qualidade Empresarial','60h'], ['Gestão e Inovação','60h'], ['Marketing Digital','60h'], ['Gestão de Conhecimentos e Avaliação de Desempenho','60h'], ['Inteligência Emocional','60h'], ['Empreendedorismo','40h'], ['Ética e Responsabilidade Social na Gestão','40h'], ['Educação e Cultura Indígenas','40h'], ['Atividades Complementares/Extensionistas','80h'], ['Direitos Humanos e Sustentabilidade','40h']]],
        ]),
        'tecnologo-em-gestao-financeira' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Noções Gerais do Direito','60h'], ['Matemática Elementar','80h'], ['Fundamentos da Administração','60h'], ['Estudos em Contabilidade','80h'], ['Comunicação Eficaz','60h'], ['Fundamentos da Economia','60h'], ['Gestão de Pessoas','60h']]],
            ['2° Período', '', [['Gestão Financeira','80h'], ['Sistema Tributário Nacional — Estrutura e Princípios do Sistema Tributário Brasileiro','60h'], ['Estatística Aplicada','60h'], ['Análise de Custos','60h'], ['Avaliação de Empresas','60h'], ['Administração Financeira I','80h'], ['Liderança e Equipe Organizacional','80h'], ['Administração Financeira II','60h']]],
            ['3° Período', '', [['Programa de Gerência de Riscos','60h'], ['Gestão de Qualidade e Gerenciamento de Rotina','60h'], ['Análise de Demonstrações Financeiras','60h'], ['Análise de Investimentos','60h'], ['Controladoria','60h'], ['Compliance Fiscal e Governança Tributária','60h'], ['Sistema de Banco de Dados','40h'], ['Educação e Cultura Indígenas','40h'], ['Empreendedorismo','40h'], ['Atividades Complementares e Extensionistas','80h'], ['Direitos Humanos e Sustentabilidade','40h']]],
        ]),
        'tecnologo-em-processos-gerenciais' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Noções Gerais do Direito','40h'], ['Matemática Elementar','60h'], ['Fundamentos da Administração','40h'], ['Estudos em Contabilidade','60h'], ['Comunicação Eficaz','40h'], ['Fundamentos da Economia','60h'], ['Liderança e Equipe Organizacional','60h'], ['Gerenciamento de Pessoal','60h'], ['Estatística Aplicada','60h']]],
            ['2° Período', '', [['Direito Empresarial','60h'], ['Análise de Custos','60h'], ['Avaliação de Empresas','60h'], ['Planejamento e Gestão','60h'], ['Gerenciamento da Informação','60h'], ['Gestão de Qualidade e Gerenciamento de Rotina','60h'], ['Liderança e Equipe Organizacional','60h'], ['Recursos Humanos','60h'], ['Gestão de Negócios e Análises Financeiras','60h'], ['Programa de Gerência de Riscos','60h']]],
            ['3° Período', '', [['Gestão e Inovação','40h'], ['Controladoria','40h'], ['Logística Avançada','40h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','40h'], ['Análise de Mercado','40h'], ['Gestão de Produção','40h'], ['Aspectos Comerciais e Atendimento ao Cliente','40h'], ['Empreendedorismo','40h'], ['Educação e Cultura Indígenas','40h'], ['Ética e Responsabilidade Social na Gestão','40h'], ['Atividades Complementares e Extensionistas','80h'], ['Breves Considerações sobre Direitos Humanos','40h']]],
        ]),
        'tecnologo-em-logistica' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gerencial', [
            ['1° Período', '', [['Introdução à EAD','40h'], ['Fundamentos da Administração','40h'], ['Comunicação e Liderança','40h'], ['Tópicos de Economia','60h'], ['Gestão da Cadeia de Suprimentos','60h'], ['Planejamento Estratégico','60h'], ['Administração Financeira e Contábil','60h'], ['Direito Empresarial','60h']]],
            ['2° Período', '', [['Práticas em Logística','60h'], ['Informática e Ferramentas de Produtividade','60h'], ['Logística Empresarial','60h'], ['Gestão de Custos, Riscos e Perdas — GCRP','60h'], ['Logística Reversa','60h'], ['Gestão de Tecnologia e Informação em Logística','60h'], ['Segurança do Trabalho na Indústria e na Logística','80h'], ['Gestão de Compras e Negociação','60h'], ['Logística Internacional','60h']]],
            ['3° Período', '', [['Logística Portuária','60h'], ['Gestão da Produção','60h'], ['Logística de Armazenagem','60h'], ['Logística de Transportes','60h'], ['Gestão de Estoque','60h'], ['Análise de Competitividade das Cadeias Produtivas','60h'], ['Educação Ambiental','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Relações Étnico-Raciais e Afrodescendência','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-marketing' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Comercial', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos da Administração','40h'], ['Gestão de Pessoas','40h'], ['Fundamentos de Marketing','60h'], ['Empreendedorismo','40h'], ['Comunicação Integrada ao Marketing','60h'], ['Gestão de Projetos','40h'], ['Estratégias de Marketing','40h'], ['Comportamento do Consumidor','40h']]],
            ['2° Período', '', [['Liderança e Gestão de Equipes','40h'], ['Ciência de Dados Aplicada à Gestão de Marketing','60h'], ['Publicidade e Propaganda','60h'], ['Métricas de Marketing','60h'], ['Gestão de Custos e Finanças','60h'], ['Geomarketing','60h'], ['Marketing de Eventos','60h'], ['Marketing de Produtos','60h'], ['Estratégias de Growth Marketing','60h'], ['Visual Merchandising','60h']]],
            ['3° Período', '', [['Neuromarketing e UX','60h'], ['Marketing de Influência e o Social Media','60h'], ['Trade Marketing','60h'], ['Marketing Político','60h'], ['Marketing de Serviços e do Varejo','60h'], ['Business Intelligence','60h'], ['Inteligência Artificial Aplicada ao Marketing Digital','60h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Relações Étnico-Raciais e Afrodescendência','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-da-qualidade' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Comunicação e Expressão','60h'], ['Ferramentas da Qualidade e Gestão por Processos','80h'], ['Metodologia Científica','60h'], ['Economia e Mercado','60h'], ['Fundamentos Contábeis','80h'], ['Fundamentos da Administração','60h'], ['Gestão de Riscos','80h']]],
            ['2° Período', '', [['Gestão da Qualidade','80h'], ['Processos e Projetos de Melhoria Contínua','60h'], ['Auditoria da Qualidade','80h'], ['Gestão da Inovação e Competitividade','60h'], ['Análise e Modelagem de Processos','60h'], ['Certificação da Qualidade','60h'], ['Gestão de Projetos','60h'], ['Planejamento Estratégico','60h']]],
            ['3° Período', '', [['Gestão da Produção','60h'], ['Gerenciamento dos Aspectos e Impactos Ambientais','60h'], ['Controle de Qualidade Industrial','60h'], ['Metodologia da Manutenção','60h'], ['Liderança e Gestão de Equipes','60h'], ['Empreendedorismo','60h'], ['Atividades Complementares e Extensionistas','80h'], ['Educação das Relações Étnico-Raciais','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h']]],
        ]),
        'tecnologo-em-gestao-publica' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Estatística','60h'], ['Metodologia Científica','60h'], ['Empreendedorismo','60h'], ['Ciência Política e Teoria Geral do Estado','60h'], ['Administração Pública','60h'], ['Direito Administrativo e Direito Constitucional','60h']]],
            ['2° Período', '', [['Administração e Contabilidade Pública','80h'], ['Introdução e Fundamentos das Relações Institucionais e Governamentais','60h'], ['Serviço Público','80h'], ['Planejamento Governamental','80h'], ['Governança Pública','80h'], ['Compliance Fiscal e Governança Tributária','80h'], ['Gestão de Projetos','60h'], ['Legislação e Políticas Públicas','80h']]],
            ['3° Período', '', [['Licitações e Contratos Administrativos — Normas e Procedimentos para a Contratação Pública','80h'], ['Análise de Cenários Políticos e Econômicos e Finanças Públicas e Orçamento Governamental','80h'], ['Serviços Públicos e Improbidade Administrativa','80h'], ['Assessoria de Comunicação Pública','60h'], ['Auditoria e Responsabilidade Fiscal','80h'], ['Educação e Cultura Indígenas','40h'], ['Direitos Humanos e Sustentabilidade','40h'], ['Ética e Responsabilidade Social na Gestão','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-ambiental' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Ambiente e Saúde', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Fundamentos da Administração','60h'], ['Comunicação e Linguagem','60h'], ['Tópicos de Economia','60h'], ['Gestão Ambiental','80h'], ['Meio Ambiente e Qualidade de Vida','80h'], ['Direito Ambiental','80h'], ['Estatística Básica','60h']]],
            ['2° Período', '', [['Físico-Química Ambiental','80h'], ['Direito Agroambiental','70h'], ['Microbiologia Aplicada ao Meio Ambiente','70h'], ['Hidrologia','80h'], ['Ecologia e Limnologia','60h'], ['Monitoramento e Controle da Poluição Ambiental','60h'], ['Sistema de Gestão, Auditoria e Perícia Ambiental','60h'], ['Climatologia e Meteorologia Agrícola','60h']]],
            ['3° Período', '', [['Gerenciamento dos Aspectos e Impactos Ambientais','80h'], ['Tratamento de Resíduos Sólidos','60h'], ['Energias Renováveis','60h'], ['Sustentabilidade Ambiental, Social e Governança — ESG','60h'], ['Educação Ambiental','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Relações Étnico-Raciais e Afrodescendência','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-secretariado' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução à EAD','40h'], ['Português Instrumental','60h'], ['Empreendedorismo','60h'], ['Tópicos de Economia','60h'], ['Fundamentos da Administração','60h'], ['Práticas em Secretariado','60h'], ['Comunicação e Linguagem','60h']]],
            ['2° Período', '', [['Inglês Instrumental','60h'], ['Sistemas de Informações Gerenciais','60h'], ['Direito Empresarial','60h'], ['Práticas Administrativas','80h'], ['Gestão do Conhecimento e Aprendizagem Organizacional','80h'], ['Mediação de Conflitos nas Organizações','60h'], ['Organização, Sistemas e Métodos','80h'], ['Lei Geral de Proteção de Dados Pessoais','80h'], ['Gerenciamento Eletrônico de Documentos (GED)','80h']]],
            ['3° Período', '', [['Elaboração e Gestão de Contratos','60h'], ['Gestão de Custos e Finanças','60h'], ['Análise e Modelagem de Processos','60h'], ['Gestão de Eventos','60h'], ['Gestão da Qualidade','60h'], ['Técnicas de Atendimento e Teleatendimento ao Cliente','60h'], ['Educação e Meio Ambiente','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Projetos Integradores Extensionistas','80h']]],
        ]),
        'tecnologo-em-comercio-exterior' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Comércio Exterior','60h'], ['Fundamentos da Administração','60h'], ['Economia e Mercado Global','60h'], ['Ciência Política','60h'], ['Empreendedorismo','60h'], ['Inglês Instrumental','60h']]],
            ['2° Período', '', [['Logística Internacional','60h'], ['Direito Empresarial','60h'], ['Direito Internacional e Comércio Exterior','80h'], ['Fundamentos de Espanhol','60h'], ['Gestão Tributária','60h'], ['Gestão de Compras e Negociação','60h'], ['Estatística Básica','60h'], ['Gestão de Tecnologia e Informação em Logística','60h'], ['Educação e Meio Ambiente',''], ['Marketing Eletrônico e Internacional','60h']]],
            ['3° Período', '', [['Despachos Documentalistas em Relações Exteriores','60h'], ['Formação de Preços','60h'], ['Contabilidade Internacional','60h'], ['Políticas Comerciais e Mercado Global','60h'], ['Inteligência Artificial e a Engenharia de Prompt para Negócios','60h'], ['Gestão da Cadeia de Suprimentos','60h'], ['Educação das Relações Étnico-Raciais','60h'], ['Empreendedorismo','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-hotelaria' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Turismo, Hospedagem e Lazer', [
            ['1° Período', '', [['Introdução à EAD','40h'], ['Fundamentos da Administração','60h'], ['Pensamento Científico','60h'], ['Comunicação e Linguagem','60h'], ['Gestão de Serviços Turísticos e de Hospitalidade','60h'], ['Inglês Instrumental Aplicado ao Turismo e Hospitalidade','60h'], ['Fundamentos do Turismo e da Hospitalidade','60h'], ['Estatística Básica','60h']]],
            ['2° Período', '', [['Estratégias de Marketing','60h'], ['Cadeia Produtiva e Logística do Turismo e Hospitalidade','60h'], ['Gestão de Eventos','60h'], ['Governança e Serviços de Lavanderia em Hotelaria','60h'], ['Orçamento Empresarial e Fluxo de Caixa','60h'], ['Organização, Sistemas e Métodos','60h'], ['Espanhol Instrumental Aplicado ao Turismo e Hospitalidade','60h'], ['Gestão de Projetos','60h'], ['Educação Ambiental','40h'], ['Gestão de Alimentos e Bebidas','60h']]],
            ['3° Período', '', [['Legislação e Ética Aplicada ao Turismo e Hospitalidade','60h'], ['Gestão de Reservas e do Atendimento Receptivo','60h'], ['Planejamento e Controladoria Financeira','60h'], ['Gestão de Operações','60h'], ['Higiene e Segurança do Trabalho em Turismo e Hotelaria','60h'], ['Técnicas de Atendimento e Teleatendimento ao Cliente','60h'], ['Educação das Relações Étnico-Raciais','40h'], ['Empreendedorismo','40h'], ['Atividades Complementares e Extensionistas','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h']]],
        ]),
        'tecnologo-em-gestao-de-eventos' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Turismo, Hospedagem e Lazer', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Fundamentos do Direito','60h'], ['Fundamentos da Administração','60h'], ['Gestão de Eventos','80h'], ['Marketing Digital','60h'], ['Pensamento Científico','60h'], ['Logística Empresarial','60h']]],
            ['2° Período', '', [['Marketing de Eventos','60h'], ['Gestão Financeira','60h'], ['Fundamentos do Turismo e da Hospitalidade','60h'], ['Economia Criativa e Fontes de Recursos','60h'], ['Assessoria de Comunicação','60h'], ['Análise e Pesquisa de Mercado','60h'], ['Orçamento Empresarial e Fluxo de Caixa','80h'], ['Organização de Eventos Esportivos, Culturais e de Lazer','80h'], ['Liderança e Desenvolvimento de Equipes','60h']]],
            ['3° Período', '', [['Gestão de Projetos','60h'], ['Formação de Preços','60h'], ['Marketing de Relacionamento, Pós-Venda e Fidelização de Clientes','60h'], ['Gestão de Riscos','60h'], ['Gestão Estratégica de Marcas','40h'], ['Elaboração e Gestão de Contratos','60h'], ['Segurança, Meio Ambiente, Saúde e Responsabilidade Social','40h'], ['Empreendedorismo','40h'], ['Educação das Relações Étnico-Raciais','40h'], ['Atividades Complementares e Extensionistas','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h']]],
        ]),
        'tecnologo-em-gestao-em-negocios-imobiliarios' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos da Administração','40h'], ['Organização, Sistemas e Métodos','40h'], ['Fundamentos da Economia I','40h'], ['Ética Profissional e Cidadania','40h'], ['Empreendedorismo','60h'], ['Matemática Básica','40h'], ['Comportamento Consumidor','60h'], ['Marketing Digital','60h'], ['Atendimento Eficiente ao Cliente: Introdução','60h']]],
            ['2° Período', '', [['Comunicação e Marketing Pessoal','40h'], ['Planejamento Estratégico','60h'], ['Aspectos Constitucionais do Direito Notarial e Registral Imobiliário','60h'], ['Direito das Transações Imobiliárias — Locação de Imóveis e Ações Locatícias','40h'], ['Direito Imobiliário','60h'], ['Direito Imobiliário — Relação com a Pessoa Física','60h'], ['Direito Imobiliário — Relação com a Pessoa Jurídica','60h'], ['Estratégias de Vendas','40h'], ['Processos Imobiliários','60h'], ['Economia e Mercado','60h']]],
            ['3° Período', '', [['Administração de Condomínios','40h'], ['Processos de Compra, Venda, Permuta e Locação de Imóveis','60h'], ['Gestão de Custos e Formação de Preços','60h'], ['Aspectos Comerciais e Atendimento ao Cliente','60h'], ['Processos de Venda de Imóveis','60h'], ['Contratos','60h'], ['Empreendedorismo','40h'], ['Direitos Humanos e Sustentabilidade','40h'], ['Educação e Cultura Indígenas','40h'], ['Ética, Cidadania e Meio-Ambiente','40h'], ['Atividades Complementares/Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-desportiva-e-de-lazer' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Turismo, Hospitalidade e Lazer', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Fundamentos da Administração','60h'], ['Comunicação e Expressão','60h'], ['Fundamentos Pedagógicos dos Esportes e das Atividades Físicas','60h'], ['Matemática Básica','60h'], ['Metodologia do Trabalho Científico','60h'], ['Fundamentos Teóricos e Metodológicos dos Esportes Coletivos','80h']]],
            ['2° Período', '', [['Gestão de Pessoas','60h'], ['Jogos e Recreação','60h'], ['Práticas Corporais Adaptadas para Grupos Especiais','80h'], ['Gestão Financeira','60h'], ['Educação Física Adaptada e Inclusiva','60h'], ['Multiculturalismo','60h'], ['Gerenciamento de Projetos II','80h'], ['Liderança e Equipe Organizacional','80h']]],
            ['3° Período', '', [['Captação de Recursos para Entidades Esportivas, Clubes e Escolas','80h'], ['Proteção do Meio Ambiente e Sustentabilidade','60h'], ['Psicologia do Esporte e Alto Rendimento','80h'], ['Marketing','60h'], ['Saúde Coletiva','80h'], ['Ética e Cidadania','60h'], ['Primeiros Socorros','60h'], ['Direitos Humanos e Sustentabilidade','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-gestao-de-turismo' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Turismo, Hospitalidade e Lazer', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos da Administração','60h'], ['Comunicação e Expressão','60h'], ['Metodologia do Trabalho Científico','60h'], ['Matemática Básica','60h'], ['Inglês Instrumental Aplicado ao Turismo e Hospitalidade','60h'], ['Gestão de Eventos','60h'], ['Marketing Turístico','60h']]],
            ['2° Período', '', [['Administração Financeira e Contábil','60h'], ['Geografia Aplicada ao Turismo','60h'], ['Fundamentos do Turismo e da Hospitalidade','60h'], ['Cadeia Produtiva e Logística do Turismo e Hospitalidade','60h'], ['Organização de Eventos Esportivos, Culturais e de Lazer','60h'], ['Suporte Emergencial à Vida e Atendimento Pré-Hospitalar','60h'], ['Legislação e Ética Aplicada ao Turismo e Hospitalidade','60h'], ['Espanhol Instrumental Aplicado ao Turismo e Hospitalidade','40h'], ['História Aplicada ao Turismo','60h'], ['Rotas Turísticas Brasileiras','60h']]],
            ['3° Período', '', [['Meio Ambiente, Desenvolvimento e Sustentabilidade','40h'], ['Agenciamento e Roteirização Turística','60h'], ['Entretenimento Turístico','60h'], ['Patrimônio Histórico-Cultural Brasileiro','60h'], ['Educação das Relações Étnico-Raciais','40h'], ['Gestão de Reservas e do Atendimento Receptivo','60h'], ['Atividades Complementares e Extensionistas','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Higiene e Segurança do Trabalho em Turismo e Hotelaria','60h'], ['Estudos Avançados de Marketing Sustentável','60h']]],
        ]),
        'tecnologo-em-gestao-de-seguranca-privada' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Segurança', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Introdução ao Direito','60h'], ['Fundamentos da Administração','60h'], ['Comunicação e Expressão','60h'], ['Metodologia Científica','60h'], ['Matemática Básica','60h'], ['Direito Empresarial','60h']]],
            ['2° Período', '', [['Gerenciamento de Pessoal','60h'], ['Psicologia na Engenharia de Segurança, Comunicação e Treinamento','60h'], ['Segurança no Trabalho','60h'], ['Gestão de Sistemas de Informação','60h'], ['Informática','60h'], ['Agentes de Risco e EPI','60h'], ['Estratégia e Planejamento em Segurança Privada','60h'], ['Gerenciamento de Riscos e Crises','60h'], ['Tecnologia e Gestão em Segurança Privada','60h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','60h']]],
            ['3° Período', '', [['Primeiros Socorros','40h'], ['Planos de Emergência','60h'], ['Organização de Empresas Públicas e Privadas','60h'], ['Introdução à Engenharia de Segurança Contra Incêndio e Pânico','60h'], ['Segurança, Saúde e Higiene do Trabalho','40h'], ['Fundamentos de Inteligência Policial','60h'], ['Ética e Cidadania','40h'], ['Gestão de Projetos','40h'], ['Direitos Humanos e Segurança Pública','40h'], ['Educação e Cultura Indígenas','40h'], ['Atividades Complementares e Extensionistas','80h'], ['SMS — Segurança, Meio Ambiente e Saúde','40h']]],
        ]),
        'tecnologo-em-gestao-de-cooperativas' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Gestão da Qualidade','60h'], ['Administração aplicada à Gestão Empresarial','80h'], ['Fundamentos da Economia','80h'], ['Metodologia Científica','60h'], ['Matemática Aplicada','60h'], ['Fundamentos de Marketing','60h']]],
            ['2° Período', '', [['Gestão de Cooperativas','80h'], ['Administração de Pessoal','80h'], ['Análise de Custos','60h'], ['Empreendedorismo','60h'], ['Estatística','80h'], ['Gestão e Inovação','80h'], ['Marketing e Endomarketing','80h'], ['Gestão de Custos','60h']]],
            ['3° Período', '', [['Gestão Financeira','80h'], ['Políticas Públicas para Sustentabilidade','80h'], ['Planejamento em Agronegócio','80h'], ['Cooperativismo, Associativismo e Extensão Rural','60h'], ['Administração e Planejamento de Projetos de Impacto Social','60h'], ['Educação em Direitos Humanos','60h'], ['Atividades Complementares e Extensionistas','80h'], ['Sustentabilidade Ambiental, Social e Governança — ESG','60h']]],
        ]),
        'tecnologo-em-design-grafico' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Metodologia do Design Gráfico','40h'], ['Matemática para Computação Gráfica','40h'], ['Teoria e Prática da Cor','40h'], ['Lógica de Programação','40h'], ['História da Arte e do Design','40h'], ['Marketing Digital','40h'], ['Computação Gráfica','60h'], ['Fundamentos do Inglês','40h']]],
            ['2° Período', '', [['Criatividade, Storytelling e Design Thinking','40h'], ['Ilustração','60h'], ['Estética e Percepção Visual','60h'], ['Tipografia Digital','60h'], ['Representação e Composição Artística','60h'], ['Tecnologias de Impressão','40h'], ['Sistemas de Identidade Visual','60h'], ['Planejamento Gráfico e Editorial','40h'], ['Comunicação e Linguagem do Design Gráfico','40h'], ['Human Centred Design','40h']]],
            ['3° Período', '', [['Ilustração e Tratamento de Imagens 2D — Adobe Photoshop','60h'], ['Direção e Edição em Design','60h'], ['Fotografia e Imagem','60h'], ['Ilustração e Criação de Imagens Vetoriais — Adobe Illustrator','60h'], ['Design, Ergonomia, Antropometria e Acessibilidade','60h'], ['Design Web e Mídias Móveis','60h'], ['Web Design em WordPress','60h'], ['Gestão de Projetos','40h'], ['Inteligência Artificial Aplicada ao Design Gráfico','60h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Relações Étnico-Raciais e Afrodescendência','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-design-de-moda' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Semiótica','60h'], ['Estratégias de Marketing','40h'], ['História da Arte e do Design','40h'], ['Fundamentos de Inglês','40h'], ['Desenvolvimento da Criatividade','40h'], ['Comunicação e Expressão','40h'], ['Criação de Portfólio de Moda','60h']]],
            ['2° Período', '', [['História da Moda e da Indumentária','60h'], ['Modelagem Criativa para Moda','40h'], ['Direção Criativa para Moda','60h'], ['Modelagem 3D','60h'], ['Produção de Moda','60h'], ['Ilustração de Moda','60h'], ['Representação Gráfica Digital para o Design de Moda','60h'], ['Teorias da Cor','40h'], ['Mercado da Moda','60h'], ['Confecção e Vestuário','60h']]],
            ['3° Período', '', [['Planejamento e Desenvolvimento de Coleções','60h'], ['Ergonomia e Design','40h'], ['Técnicas de Acabamento e Estrutura na Produção de Moda','60h'], ['Desenho de Moda','60h'], ['Visual Merchandising','40h'], ['Modelagem Bidimensional Artesanal para Moda','60h'], ['Inteligência Artificial Aplicada ao Design Gráfico','60h'], ['Design Thinking','40h'], ['Programação Visual de Coleções de Moda','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Educação Ambiental','40h'], ['Educação das Relações Étnico-Raciais','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-design-de-interiores' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Matemática Básica','60h'], ['Comunicação Oral e Escrita','60h'], ['Metodologia do Trabalho Científico','60h'], ['Desenho Técnico','60h'], ['Fundamentos do Design','60h'], ['Arquitetura de Interiores','80h'], ['Teorias da Cor','60h']]],
            ['2° Período', '', [['Desenho Técnico de Plantas Arquitetônicas em CAD','80h'], ['Design, Ergonomia, Antropometria e Acessibilidade','80h'], ['Ambientação e Decoração em CAD 3D','80h'], ['Arte, Estética e Sensibilidade','60h'], ['Design de Ambientes Comerciais','60h'], ['Design de Paisagismo','60h'], ['Teoria e História da Arquitetura, Urbanismo e Paisagismo Contemporâneo','60h'], ['Estética e Percepção Visual','60h'], ['Desenho de Móveis','60h']]],
            ['3° Período', '', [['Conforto Ambiental','60h'], ['Inteligência Artificial Aplicada ao Design Gráfico','60h'], ['Criatividade, Storytelling e Design Thinking','60h'], ['Direção e Edição em Design','60h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','60h'], ['Empreendedorismo','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-design-de-animacao' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos do Design','40h'], ['Lógica de Programação','40h'], ['Fundamentos do Inglês','40h'], ['História da Arte e do Design','40h'], ['Teorias da Cor','60h'], ['Desenho Artístico à Mão Livre','60h'], ['Ilustração','60h']]],
            ['2° Período', '', [['Ilustração e Tratamento de Imagens 2D — Adobe Photoshop','60h'], ['Modelagem 3D','60h'], ['Estética e Percepção Visual','60h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','60h'], ['Comunicação e Linguagem do Design Gráfico','60h'], ['Empreendedorismo Digital','60h'], ['Direção e Edição em Design','60h'], ['Ilustração e Criação de Imagens Vetoriais — Adobe Illustrator','60h'], ['Storytelling','60h']]],
            ['3° Período', '', [['Tipografia Digital','60h'], ['Human Centred Design','60h'], ['Roteiro e Produção de Áudio','60h'], ['Produção Audiovisual para o Metaverso','60h'], ['Design Web e Mídias Móveis','60h'], ['Inteligência Artificial Aplicada ao Design Gráfico','60h'], ['Criatividade e Solução de Problemas','60h'], ['Sociologia e Ética Profissional','60h'], ['Educação das Relações Étnico-Raciais','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-servicos-penais' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Segurança', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Introdução ao Direito','80h'], ['Sociologia do Crime — Estudo do Crime como Fenômeno Social e suas Implicações','80h'], ['Comunicação Oral e Escrita','60h'], ['Introdução ao Direito Penal','80h'], ['Legislação Penal','80h']]],
            ['2° Período', '', [['Laudos e Pareceres','80h'], ['Ressocialização','60h'], ['Direito Penal Avançado — Análise Aprofundada dos Princípios e Normas do Direito Penal','80h'], ['Perfis Criminais e Comportamentais','80h'], ['Psicologia Criminal — Estudo do Comportamento Criminoso a partir de uma Perspectiva Psicológica','80h'], ['Gerenciamento de Pessoal','60h'], ['Criminologia e Sociedade — Estudo das Causas e Consequências do Crime na Sociedade','80h'], ['Juventude e Criminalidade','60h']]],
            ['3° Período', '', [['Ética e Responsabilidade Social na Gestão','60h'], ['Direitos Humanos e Sistema Penal — Relação entre Direitos Humanos e o Sistema de Justiça Criminal','60h'], ['Criminologia e Segurança Pública','80h'], ['Organização dos Serviços de Segurança e Saúde do Trabalho','60h'], ['Mediação de Conflitos','60h'], ['Sustentabilidade Ambiental, Social e Governança — ESG','40h'], ['Educação e Cultura Indígenas','40h'], ['Empreendedorismo','40h'], ['Políticas Públicas na Área da Segurança','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-servicos-judiciais-e-notariais' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Gestão e Negócios', [
            ['1° Período', '', [['Introdução ao EAD','60h'], ['Fundamentos do Direito','60h'], ['Arquivologia e Sistemas de Informação','60h'], ['Fundamentos da Administração','60h'], ['Introdução a Banco de Dados','80h'], ['Processo Decisório','60h'], ['Gestão de Escritórios de Advocacia','60h'], ['Análise e Modelagem de Processos','60h']]],
            ['2° Período', '', [['Despachos Documentalistas de Registro Empresarial','60h'], ['Práticas Administrativas','60h'], ['Elaboração e Gestão de Contratos','60h'], ['Direito Notarial e Registral','80h'], ['Direito Civil','60h'], ['Direito Processual Civil','60h'], ['Arbitragem e Mediação de Conflitos','60h'], ['Informática e Ferramentas de Produtividade','60h'], ['Ética Jurídica','40h']]],
            ['3° Período', '', [['Gestão de Serviços','40h'], ['Direito Público e Processo Legislativo','60h'], ['Direito do Trabalho e da Previdência','60h'], ['Corpo e Reconhecimento Jurídico das Diversidades','60h'], ['Gestão de Riscos','60h'], ['Técnicas de Atendimento e Teleatendimento ao Cliente','40h'], ['Educação Ambiental','40h'], ['Atividades Complementares e Extensionistas','80h'], ['Educação das Relações Étnico-Raciais','60h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h']]],
        ]),
        'tecnologo-em-seguranca-publica' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Segurança', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Legislação Aplicada à Segurança Pública','40h'], ['Fundamentos da Administração','60h'], ['Direito Constitucional','60h'], ['Criminologia e Segurança Pública','60h'], ['Planejamento Estratégico','40h'], ['Direito Processual Penal','60h'], ['Suporte Emergencial à Vida e Atendimento Pré-Hospitalar','40h'], ['Administração Pública','60h']]],
            ['2° Período', '', [['Fundamentos de Inteligência Policial — Princípios e Técnicas de Inteligência Aplicadas à Segurança Pública','60h'], ['Governo, Gestão e a Estrutura do Poder Público','60h'], ['Gestão da Tecnologia da Informação e Comunicação','60h'], ['Organização Judiciária da Justiça Militar e do Ministério Público Militar','60h'], ['Inteligência de Segurança','60h'], ['Planos de Emergência','60h'], ['Medicina do Trabalho e Biossegurança','60h'], ['Legislação e Políticas Públicas','60h'], ['Sociologia do Crime — Estudo do Crime como Fenômeno Social e suas Implicações','60h']]],
            ['3° Período', '', [['Introdução à Engenharia de Segurança Contra Incêndio e Pânico','60h'], ['Agentes de Risco e EPI','60h'], ['Violência e Direitos Humanos — Análise das Diferentes Formas de Violência e suas Implicações para os Direitos Humanos','40h'], ['Inspeção e Manutenção de Equipamentos de Prevenção e Combate a Incêndios','60h'], ['Policiamento Comunitário e Participação Social','60h'], ['Gestão de Riscos','60h'], ['Gestão de Projetos','60h'], ['Ética, Cidadania e Meio Ambiente','40h'], ['Educação e Cultura Indígenas','40h'], ['SMS — Segurança, Meio Ambiente e Saúde','40h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
        'tecnologo-em-producao-publicitaria' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Teorias da Comunicação','40h'], ['Semiótica','40h'], ['Comunicação e Liderança','40h'], ['Criatividade, Storytelling e Design Thinking','40h'], ['Cultura e Sociedade','40h'], ['Publicidade e Propaganda','60h'], ['Comportamento do Consumidor','60h'], ['Teorias da Cor','40h'], ['Gestão de Custos e Finanças','60h']]],
            ['2° Período', '', [['Direção e Edição em Design','60h'], ['Tipografia','60h'], ['Gestão de Agências de Publicidade e Propaganda','60h'], ['Análise e Pesquisa de Mercado','40h'], ['Planejamento Estratégico','40h'], ['Design Web e Mídias Móveis','60h'], ['Produção Audiovisual','60h'], ['Copywriting no Marketing Digital','60h'], ['Roteiro e Direção de Mídias Digitais','60h'], ['Ilustração e Criação de Imagens Vetoriais — Adobe Illustrator','40h'], ['Gestão Estratégica de Marcas','40h'], ['Roteiro e Produção de Áudio','40h']]],
            ['3° Período', '', [['Inteligência Artificial Aplicada ao Marketing Digital','60h'], ['Análise Exploratória de Dados','60h'], ['Visual Merchandising','60h'], ['Marketing de Eventos','40h'], ['Montagem e Edição de Audiovisuais','60h'], ['Planejamento Gráfico e Editorial','40h'], ['Empreendedorismo','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Educação Ambiental','40h'], ['Atividades Complementares/Extensionistas','80h']]],
        ]),
        'tecnologo-em-producao-multimidia' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Marketing e Propaganda Digital','40h'], ['Comunicação Oral e Escrita','40h'], ['Criatividade, Storytelling e Design Thinking','40h'], ['Matemática para Computação Gráfica','60h'], ['Produção Multimídia','60h'], ['Lógica de Programação','60h'], ['Fotografia e Imagem','40h'], ['Tipografia Digital','40h'], ['Imagens Digitais','60h']]],
            ['2° Período', '', [['Teorias da Cor','40h'], ['Banco de Dados','40h'], ['Ilustração e Criação de Imagens Vetoriais — Adobe Illustrator','60h'], ['Teorias da Comunicação','40h'], ['Produção Audiovisual','60h'], ['Planejamento Gráfico e Editorial','60h'], ['Estética e Linguagem Audiovisual','40h'], ['Design Web e Mídias Móveis','60h'], ['Direção e Edição em Design','60h'], ['Ilustração e Tratamento de Imagens 2D — Adobe Photoshop','60h'], ['Mercado Editorial','40h']]],
            ['3° Período', '', [['Roteiro e Direção de Mídias Digitais','60h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','60h'], ['Copywriting no Marketing Digital','40h'], ['Desenvolvimento de Produto Tecnológico até o MVP','60h'], ['Produção Audiovisual para o Metaverso','60h'], ['Gestão de Projetos','40h'], ['Empreendedorismo','40h'], ['Educação das Relações Étnico-Raciais','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Atividades Complementares/Extensionistas','80h']]],
        ]),
        'tecnologo-em-producao-audiovisual' => technologist_common_profile('1 ano e meio (18 meses)', '1.600h', 'Produção Cultural e Design', [
            ['1° Período', '', [['Introdução ao EAD','40h'], ['Fundamentos do Inglês','60h'], ['Marketing Digital','60h'], ['Criatividade, Storytelling e Design Thinking','60h'], ['Cultura e Sociedade','60h'], ['Produção Audiovisual','60h'], ['Estética e Linguagem Audiovisual','60h']]],
            ['2° Período', '', [['História do Cinema','60h'], ['Cinema Brasileiro','60h'], ['Argumento e Roteiro Audiovisual','60h'], ['Comunicação e Liderança','40h'], ['Fotografia e Imagem','60h'], ['Gestão de Projetos','60h'], ['Tecnologias e Equipamentos Audiovisuais','60h'], ['Direção e Edição em Design','60h'], ['Roteiro e Produção de Áudio','60h'], ['Roteiro e Direção de Mídias Digitais','60h']]],
            ['3° Período', '', [['Tendências e Tecnologias Emergentes em Audiovisual','60h'], ['Arte, Estética e Sensibilidade','40h'], ['Documentários Audiovisuais','60h'], ['Produção Multimídia','40h'], ['Mercado Audiovisual','60h'], ['Montagem e Edição de Audiovisuais','60h'], ['Estúdio de Gravação e Acústica','40h'], ['Produção Audiovisual para o Metaverso','60h'], ['Atividades Complementares/Extensionistas','80h'], ['Educação Ambiental','40h'], ['Direitos Humanos, Multiculturalismo e Cidadania','40h'], ['Educação das Relações Étnico-Raciais','40h']]],
        ]),
        'tecnologo-em-seguranca-da-informacao' => technologist_common_profile('2 anos (24 meses)', '2.000h', 'Informação e Comunicação', [
            ['1° Período', '', [['Introdução à EAD','60h'], ['Fundamentos da Administração','80h'], ['Empreendedorismo','80h'], ['Fundamentos da Economia I','80h'], ['Sociologia das Organizações','80h'], ['Fundamentos da Contabilidade','80h']]],
            ['2° Período', '', [['Técnicas de Secretariado','80h'], ['Organização de Empresas Públicas e Privadas','80h'], ['Inglês Instrumental','80h'], ['Teorias das Organizações','80h'], ['Sistema Comunicativo nas Organizações','80h'], ['Liderança e Equipe Organizacional','80h']]],
            ['3° Período', '', [['Contratos','80h'], ['Matemática Financeira e Cidadania','80h'], ['Gestão Estratégica das Organizações','80h'], ['Gestão de Pessoas','80h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','80h'], ['Comunicação Eficaz','80h']]],
            ['4° Período', '', [['Direito Empresarial','80h'], ['Mediação de Conflitos','80h'], ['Ética','60h'], ['Gestão de Qualidade e Gerenciamento de Rotina','80h'], ['Educação das Relações Étnico-Raciais','60h'], ['Empreendedorismo','80h'], ['Direitos Humanos, Multiculturalismo e Cidadania','60h'], ['Atividades Complementares e Extensionistas','80h']]],
        ]),
    ];
    if (isset($profiles['tecnologo-em-seguranca-publica'])) {
        $profiles['tecnologo-em-gestao-de-seguranca-publica'] = $profiles['tecnologo-em-seguranca-publica'];
    }
    if (isset($profiles['tecnologo-em-servicos-judiciais-e-notariais'])) {
        $profiles['tecnologo-em-gestao-de-servicos-judiciais-e-notariais'] = $profiles['tecnologo-em-servicos-judiciais-e-notariais'];
    }
    return $profiles[$slugKey] ?? null;
}

function technical_ead_drive_slug_allowed(array $product): bool {
    if (!product_is_technical_ead($product)) {
        return true;
    }
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $matchText = $slugKey . ' ' . $titleKey;
    if (str_contains($matchText, 'pos-tecnico') || str_contains($matchText, 'especializacao')) {
        return false;
    }
    $allowed = [
        'tecnico-em-administracao',
        'tecnico-em-automacao-industrial',
        'tecnico-em-computacao-grafica',
        'tecnico-em-contabilidade',
        'tecnico-em-desenvolvimento-de-sistemas',
        'tecnico-em-edificacoes',
        'tecnico-em-eletronica',
        'tecnico-em-eletroeletronica',
        'tecnico-em-eletrotecnica',
        'tecnico-em-estetica-e-cosmetologia',
        'tecnico-em-gerencia-e-saude',
        'tecnico-em-agente-comunitario-de-saude',
        'tecnico-em-guia-de-turismo',
        'tecnico-em-informatica',
        'tecnico-em-informatica-para-internet',
        'tecnico-em-logistica',
        'tecnico-em-manutencao-e-suporte-para-informatica',
        'tecnico-em-marketing-e-comunicacao',
        'tecnico-em-mecanica',
        'tecnico-em-nutricao-e-dietetica',
        'tecnico-em-petroleo-e-gas',
        'tecnico-em-programacao-de-jogos-digitais',
        'tecnico-em-qualidade',
        'tecnico-em-recursos-humanos',
        'tecnico-em-redes-de-computadores',
        'tecnico-em-refrigeracao-e-climatizacao',
        'tecnico-em-secretaria-escolar',
        'tecnico-em-seguranca-do-trabalho',
        'tecnico-em-seguros',
        'tecnico-em-servicos-juridicos',
        'tecnico-em-soldagem',
        'tecnico-em-transacoes-imobiliarias',
        'tecnico-em-vendas',
        'tecnico-em-eventos',
        'tecnico-em-financas',
        'tecnico-em-gastronomia',
        'tecnico-em-confeitaria',
        'tecnico-em-aquicultura',
        'tecnico-em-agroindustria',
        'tecnico-em-agropecuaria',
        'tecnico-em-agricultura',
        'tecnico-em-manutencao-de-maquinas-pesadas',
        'tecnico-em-maquinas-pesadas',
        'tecnico-em-estrada',
        'tecnico-em-saneamento',
        'tecnico-em-mecatronica',
        'tecnico-em-metalurgia',
        'tecnico-em-manutencao-de-maquinas-navais',
        'tecnico-em-manutencao-de-maquinas-industriais',
        'tecnico-em-prevencao-e-combate-ao-incendio',
        'tecnico-em-transito',
        'tecnico-em-defesa-civil',
        'tecnico-em-mineracao',
        'tecnico-em-agrimensura',
        'tecnico-em-eletromecanica',
        'tecnico-em-designer-de-interiores',
        'tecnico-em-design-de-interiores',
        'tecnico-em-geoprocessamento',
        'tecnico-em-telecomunicacoes',
        'tecnico-em-traducao-e-interpretacao-de-libras',
        'tecnico-em-design-grafico',
        'tecnico-em-biotecnologia',
        'tecnico-em-sistema-de-energia-renovavel',
        'tecnico-em-sistemas-de-energia-renovavel',
    ];
    foreach ($allowed as $needle) {
        if (str_contains($matchText, $needle)) {
            return true;
        }
    }
    return false;
}

function product_primary_payment_label(array $product): string {
    if (product_is_technical_ead($product)) {
        return 'Pagar 1ª Mensalidade via Pix';
    }
    if (product_is_technologist($product)) {
        return 'Pagar Matrícula via Pix';
    }
    if (product_is_post_technical($product)) {
        return 'Comprar Pós-técnico';
    }
    if (product_is_sequential($product)) {
        return 'Comprar Superior Sequencial';
    }
    if (product_is_postgrad($product)) {
        return 'Comprar Pós-Graduação';
    }
    if (product_is_competency_certification($product)) {
        return 'Comprar Certificação';
    }
    return 'Comprar com Mercado Pago';
}

function whatsapp_course_url(array $product): string {
    $title = trim((string)($product['title'] ?? ''));
    $category = product_category_label($product);
    $context = $title !== '' ? 'o curso "' . $title . '"' : 'um curso do IBETP';
    if ($category !== '') {
        $context .= ' da área/modalidade ' . $category;
    }
    $message = 'Olá, IBETP! Tenho interesse em ' . $context . '. Pode me passar detalhes sobre matrícula, valores, requisitos, documentação e próximas turmas?';
    return 'https://wa.me/5521983177702?text=' . rawurlencode($message);
}

function premium_post_image(array $post): string {
    $category = strtolower((string)($post['category'] ?? ''));
    $slug = strtolower((string)($post['slug'] ?? ''));
    if (str_contains($category, 'educação infantil') || str_contains($category, 'educacao infantil')) {
        return '/assets/artigo-educacao-brasil-diversidade-premium.png';
    }
    if (str_contains($category, 'educação superior') || str_contains($category, 'educacao superior')) {
        return '/assets/artigo-educacao-brasil-diversidade-premium.png';
    }
    if (str_contains($category, 'educação física') || str_contains($category, 'educacao fisica') || str_contains($slug, 'educacao-fisica')) {
        return '/assets/artigo-educacao-fisica-inclusiva-premium.png';
    }
    if (str_contains($slug, 'educacao') || str_contains($slug, 'educação')) {
        return '/assets/artigo-educacao-brasil-diversidade-premium.png';
    }
    return (string)($post['featured_image'] ?? '/assets/default-article.webp');
}

function premium_product_image(array $product): string {
    return '';
    $category = strtolower((string)($product['category'] ?? ''));
    $title = strtolower((string)($product['title'] ?? ''));
    $slug = strtolower((string)($product['slug'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $isTechnicalEadImage = product_is_technical_ead($product)
        || str_contains($slugKey, 'tecnico-ead-')
        || str_contains($slugKey, 'tecnico-em-')
        || str_contains(ibetp_slug_key((string)($product['category'] ?? '')), 'cursos-tecnicos-ead');
    if ($isTechnicalEadImage) {
        $aliases = array_filter([$slugKey, $titleKey]);
        foreach ([$slugKey, $titleKey] as $key) {
            if ($key === '') {
                continue;
            }
            if (str_starts_with($key, 'tecnico-ead-')) {
                $aliases[] = 'tecnico-em-' . substr($key, strlen('tecnico-ead-'));
            }
            if (str_starts_with($key, 'tecnico-em-')) {
                $aliases[] = 'tecnico-ead-' . substr($key, strlen('tecnico-em-'));
            }
            $specialAliases = [
                'tecnico-ead-secretariado-escolar' => 'tecnico-em-secretaria-escolar',
                'tecnico-em-secretaria-escolar' => 'tecnico-ead-secretariado-escolar',
                'tecnico-em-maquinas-pesadas' => 'tecnico-em-manutencao-de-maquinas-pesadas',
                'tecnico-em-manutencao-de-maquinas-pesadas' => 'tecnico-em-maquinas-pesadas',
            ];
            if (isset($specialAliases[$key])) {
                $aliases[] = $specialAliases[$key];
            }
        }
        foreach (array_unique($aliases) as $key) {
            foreach (['webp', 'jpg', 'jpeg', 'png'] as $ext) {
                $candidate = '/assets/produtos/tecnicos-ead-v2/' . $key . '.' . $ext;
                if (file_exists(__DIR__ . $candidate)) {
                    return $candidate;
                }
            }
        }
        return '';
    }
    $explicit = trim((string)($product['image_path'] ?? $product['image'] ?? ''));
    if ($explicit !== '' && str_starts_with($explicit, '/assets/') && file_exists(__DIR__ . $explicit)) {
        return $explicit;
    }
    $slugBase = '/assets/produtos/' . ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? ''));
    $titleBase = '/assets/produtos/' . ibetp_slug_key((string)($product['title'] ?? ''));
    foreach (['webp', 'png', 'jpg', 'jpeg'] as $ext) {
        $slugSpecific = $slugBase . '.' . $ext;
        if (file_exists(__DIR__ . $slugSpecific)) {
            return $slugSpecific;
        }
        $titleSpecific = $titleBase . '.' . $ext;
        if (file_exists(__DIR__ . $titleSpecific)) {
            return $titleSpecific;
        }
    }
    return '';
}

function product_image_url(array $product): string {
    return '';
}

function product_investment_text(array $product): string {
    if (product_is_technical_ead($product)) {
        return 'Curso Técnico EAD em 12 mensalidades de R$ 99,90. A 1ª mensalidade é paga via Pix no site do IBETP, no ato da matrícula; o início ocorre em até 24 horas úteis após a confirmação do pagamento. As demais mensalidades são enviadas mensalmente por e-mail, WhatsApp ou SMS com link de pagamento e opções de boleto, cartão de crédito e Pix, em até 5 dias antes do vencimento.';
    }
    if (product_is_technologist($product)) {
        return 'Curso Tecnólogo com matrícula de R$ 99,90 paga via Pix no site do IBETP. O início ocorre em até 24 horas úteis após a confirmação do pagamento. As mensalidades de R$ 149,90 são pagas diretamente no AVA, conforme o vencimento e as opções disponíveis na plataforma.';
    }
    if (product_is_competency_certification($product)) {
        return 'Certificação Técnica por Competência por R$ 1.299,90 à vista, com possibilidade de parcelamento em até 12 vezes com juros no cartão. O diploma técnico é emitido em até 20 dias úteis após aprovação na prova e análise documental.';
    }
    if (product_is_post_technical($product)) {
        return 'Pós-técnico por R$ 799,00 à vista, com possibilidade de parcelamento com juros no cartão. A página apresenta a matriz curricular oficial, duração e carga horária conforme informativo acadêmico.';
    }
    if (product_is_sequential($product)) {
        return 'Curso Superior Sequencial por R$ 699,00 à vista, com possibilidade de parcelamento com juros no cartão de crédito. Modalidade 100% EAD, com matriz curricular oficial de 560 horas.';
    }
    if (product_is_postgrad($product)) {
        return 'Pós-graduação/MBA EAD por R$ 799,00 à vista, com possibilidade de parcelamento com juros no cartão. Curso lato sensu EAD, com titulação de Especialista e duração de 4 a 12 meses.';
    }
    return 'Condições e disponibilidade podem ser confirmadas com a equipe IBETP.';
}

function product_investment_label(array $product): string {
    if (product_is_technical_ead($product)) {
        return '12x de R$ 99,90';
    }
    if (product_is_technologist($product)) {
        return 'Matrícula R$ 99,90';
    }
    if (product_is_sequential($product)) {
        return 'R$ 699,00';
    }
    if (product_is_postgrad($product)) {
        return 'R$ 799,00';
    }
    return product_price_label($product);
}

function ibetp_slug_key(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $from = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç'];
    $to = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'];
    $text = str_replace($from, $to, $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function product_is_official_drive_technical_ead(array $product): bool {
    return product_is_technical_ead($product) && technical_ead_drive_slug_allowed($product);
}

function normalize_official_drive_technical_profile(array $profile): array {
    $profile['modality_note'] = 'Curso Técnico EAD em 12 mensalidades de R$ 99,90. A 1ª mensalidade é paga via Pix no site do IBETP, no ato da matrícula; o início ocorre em até 24 horas úteis após a confirmação do pagamento. As demais mensalidades são enviadas mensalmente por e-mail, WhatsApp ou SMS com link de pagamento e opções de boleto, cartão de crédito e Pix, em até 5 dias antes do vencimento.';
    $profile['source'] = 'Grade oficial extraída do informativo do curso disponível ao IBETP.';
    return $profile;
}

function official_drive_technical_profile_override(string $slugKey, string $titleKey): ?array {
    $matchText = $slugKey . ' ' . $titleKey;
    $profile = function (string $duration, string $workload, string $internship, array $modules): array {
        return normalize_official_drive_technical_profile([
            'duration' => $duration,
            'workload' => $workload,
            'modality_note' => '',
            'presence' => '',
            'internship' => $internship,
            'source' => 'Grade oficial extraída do informativo do curso disponível ao IBETP.',
            'modules' => $modules,
        ]);
    };
    $has = fn(string $needle): bool => str_contains($matchText, $needle);

    if ($has('administracao')) {
        return $profile('12 meses', '1.200h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '460h', [
                ['Introdução ao EAD','20h'],
                ['Gestão de Recursos Humanos','40h'],
                ['Fundamentos da Administração','20h'],
                ['Organização, Sistemas e Métodos','20h'],
                ['Empreendedorismo','20h'],
                ['Princípios de Marketing','20h'],
                ['Marketing Digital','40h'],
                ['Atendimento ao Cliente','20h'],
                ['Gestão Estratégica de Pessoas','60h'],
                ['Departamento Pessoal','40h'],
                ['Matemática Financeira','40h'],
                ['Gestão Financeira e Orçamentária','80h'],
                ['Gestão de Custos','40h'],
            ]],
            ['Módulo II', '740h', [
                ['Logística Empresarial','80h'],
                ['Supply Chain Management - SCM','60h'],
                ['Administração de Sistemas de Informação','40h'],
                ['Gestão de Projetos','40h'],
                ['Técnicas de Vendas','20h'],
                ['Processo Decisório','40h'],
                ['Liderança e Desenvolvimento de Equipes','60h'],
                ['Contabilidade Básica','40h'],
                ['Gestão de Estoques e Suprimentos','40h'],
                ['Gestão de transporte e Distribuição','80h'],
                ['Gestão de Inovação Tecnológica','40h'],
                ['Estágio Supervisionado','200h'],
            ]],
        ]);
    }
    if ($has('mecanica')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '800h', [['Introdução ao EAD','40h'], ['Gestão de Recursos Humanos','40h'], ['Introdução à Libras','40h'], ['Comportamento Organizacional','40h'], ['Segurança do Trabalho','80h'], ['Gestão de Projetos','40h'], ['Metrologia','40h'], ['Empreendedorismo','60h'], ['Lógica de Programação','40h'], ['Matemática aplicada','60h'], ['Desenho Técnico','60h'], ['Projetos mecânicos','80h'], ['Desenho Mecânico','60h'], ['Resistência de Materiais','40h'], ['Gestão Empresarial','80h']]],
            ['Módulo II', '640h', [['Controle e acionamento de máquinas','20h'], ['Máquinas mecânicas','20h'], ['Equipamentos e instalações industriais','20h'], ['Ferramental de mecânica','20h'], ['Eletricidade básica','20h'], ['Hidropneumática','80h'], ['Metodologia da manutenção','60h'], ['Ensaios de máquinas elétricas','60h'], ['Proteção de máquinas e equipamentos','40h'], ['Tecnologia da soldagem mecânica','20h'], ['Mecânica técnica','20h'], ['Usinagem e conformação mecânica','20h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('eletroeletronica')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '880h', [['Introdução ao EAD','40h'], ['Gestão de Recursos Humanos','60h'], ['Critérios e Modalidades EAD','40h'], ['Introdução à Libras','40h'], ['Eletricidade I','60h'], ['Lógica de Programação','40h'], ['Matemática Aplicada','60h'], ['Física Aplicada','60h'], ['Proteção de Sistemas Elétricos','40h'], ['Projetos de Automação Industrial','80h'], ['Medidas Elétricas','60h'], ['Eletrônica Digital','80h'], ['NR 10 - Segurança em Instalações Elétricas','80h'], ['Análise de Circuitos Eletroeletrônicos','80h']]],
            ['Módulo II', '560h', [['Ensaios de Máquinas Elétricas','60h'], ['Metodologia da Manutenção','40h'], ['Controle e Acionamento de Máquinas','60h'], ['Técnicas em Programação','40h'], ['Programação CLP','60h'], ['Sistemas Microcontrolados','40h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('eletronica')) {
        return $profile('12 meses', '1.450h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '530h', [['Introdução ao EAD','60h'], ['Gestão de Recursos Humanos','80h'], ['Introdução a Libras','30h'], ['Comportamento Organizacional','40h'], ['Noções de Segurança do Trabalho','40h'], ['Interpretação de Desenho Técnico','30h'], ['Empreendedorismo','30h'], ['Eletricidade I','40h'], ['Lógica de Programação','60h'], ['Ética nas Organizações','30h'], ['Matemática aplicada','30h'], ['Física Aplicada','30h'], ['Comandos Eletroeletrônicos','30h'], ['Eletrônica Industrial','30h'], ['Eletrônica Digital','40h'], ['Projetos de automação industrial','40h']]],
            ['Módulo II', '920h', [['Segurança em Instalações Elétricas','40h'], ['Princípios da Eletrônica Analógica','60h'], ['Análise de Circuitos Eletroeletrônicos','40h'], ['Medidas Elétricas','40h'], ['Eletrônica Analógica','40h'], ['Lógica Matemática','60h'], ['Controle e Acionamento de Máquinas','60h'], ['APH – Atendimento pré-hospitalar','30h'], ['Sistemas Digitais','60h'], ['Gestão integrada da qualidade, segurança e meio ambiente','60h'], ['Elementos de Máquinas','40h'], ['Ensaios de Máquinas Elétricas','40h'], ['Metodologia da Manutenção','30h'], ['Instalações elétricas de baixa tensão','40h'], ['Instalações elétricas de média e alta tensão','40h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('computacao-grafica')) {
        return $profile('12 meses', '1.200h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '260h', [['Introdução ao EAD','60h'], ['Português Instrumental','40h'], ['Organização Empresarial','40h'], ['Human Centred Design','60h'], ['Análise de Mercado: Tendência, comportamento e movimento','60h']]],
            ['Módulo II', '380h', [['Empreendedorismo','80h'], ['Ilustração e Criação de Imagens Vetoriais - Adobe Illustrator','60h'], ['Ilustração e Tratamento de Imagens 2D - Adobe Photoshop','80h'], ['Modelagem Matemática','80h'], ['Segurança, Meio Ambiente, Saúde e Responsabilidade Social','80h']]],
            ['Módulo III', '560h', [['Ferramentas CAD','80h'], ['Tecnologias de Impressão','80h'], ['Diagramação e Documentos - Adobe InDesign','80h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','80h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('transacoes-imobiliarias')) {
        return $profile('12 meses', '1.200h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '200h', [['Introdução ao EAD','40h'], ['Práticas em Negócios Imobiliários','40h'], ['Organização, Sistemas e Métodos','20h'], ['Empreendedorismo','60h'], ['Marketing Digital','40h']]],
            ['Módulo II', '200h', [['Atendimento ao Cliente','40h'], ['Desinibição, Dicção e Oratória','60h'], ['Avaliação Imobiliária','20h'], ['Incorporações Imobiliárias e Loteamentos','20h'], ['Gestão de Projetos','60h']]],
            ['Módulo III', '180h', [['Publicidade e propaganda','60h'], ['Contratos e Documentação Imobiliária','20h'], ['Noções de Direito Imobiliário','20h'], ['Gestão Comercial','40h'], ['Marketing de Relacionamento','40h']]],
            ['Módulo IV', '220h', [['Fundamentos da Arquitetura e Urbanismo','40h'], ['Modelagem 3D','80h'], ['Financiamento Imobiliário','60h'], ['Mercado Imobiliário','40h']]],
            ['Módulo V', '400h', [['Estratégias de Vendas e Negociação','20h'], ['Operações Imobiliárias','60h'], ['Estratégias de gestão e organização empresarial','40h'], ['Legislação Imobiliária Urbana e Territorial','80h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('vendas')) {
        return $profile('12 meses', '1.000h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '200h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','20h'], ['Fundamentos da Administração','20h'], ['Organização, Sistemas e Métodos','20h'], ['Empreendedorismo','20h'], ['Marketing Digital','20h'], ['Atendimento ao Cliente','40h'], ['Gestão Estratégica de Pessoas','40h']]],
            ['Módulo II', '260h', [['Comunicação Empresarial','60h'], ['Oratória','60h'], ['Logística empresarial','40h'], ['Gestão de Custos','40h'], ['Técnicas de Negociação','20h'], ['Gestão de Projetos','20h'], ['Administração de Sistemas de Informação','20h']]],
            ['Módulo III', '200h', [['Negociação Comercial e Comércio Eletrônico','20h'], ['Marketing de serviços e do varejo','20h'], ['Gestão de Compras','20h'], ['Técnicas de vendas','80h'], ['Técnicas de atendimento em help desk','60h']]],
            ['Módulo IV', '340h', [['Telemarketing','20h'], ['Análise de Mercado','20h'], ['Métricas do marketing','60h'], ['Gestão de Inovação Tecnológica','40h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('marketing') || $has('mktcom')) {
        return $profile('12 meses', '1.000h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '180h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','20h'], ['Marketing Digital','40h'], ['Empreendedorismo','20h'], ['Comunicação Empresarial','40h'], ['Gestão de Projetos','40h']]],
            ['Módulo II', '80h', [['Inbound Marketing','20h'], ['Marketing Eletrônico e Internacional','20h'], ['Organização Empresarial','20h'], ['Marketing de Relacionamento / Métricas de Marketing','20h']]],
            ['Módulo III', '120h', [['Marketing de eventos','40h'], ['Segurança, meio ambiente, saúde e responsabilidade social','40h'], ['Análise e pesquisa de mercado','40h']]],
            ['Módulo IV', '180h', [['Estratégias de marketing','20h'], ['Fundamentos de marketing','60h'], ['Marketing pessoal e gestão de carreira','60h'], ['Marketing de serviços e do varejo','40h']]],
            ['Módulo V', '120h', [['Publicidade e propaganda','20h'], ['Business intelligence','40h'], ['Marketing e propaganda digital','40h'], ['Estudos avançados de marketing sustentável','20h']]],
            ['Módulo VI', '320h', [['Negociação Comercial e Comércio Eletrônico','20h'], ['Técnicas de atendimento em help desk','40h'], ['Visual Merchandising','60h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('redes-de-computadores')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '400h', [['Introdução ao EAD','80h'], ['Introdução à Banco de Dados','80h'], ['Lógica de Programação','80h'], ['Português Instrumental','80h'], ['Segurança da Informação','80h']]],
            ['Módulo II', '480h', [['Ambiente de Desenvolvimento para WEB','80h'], ['Empreendedorismo','80h'], ['Modelagem Matemática','80h'], ['Programação – Coding WEB (PHP)','80h'], ['Tecnologia e Linguagens de Banco de Dados','80h']]],
            ['Módulo III', '560h', [['Análise de Mercado: Tendência, Comportamento e Movimento','80h'], ['Análise e Projeto de Software Orientado a Objetos','80h'], ['Gestão de Times – Métodos Ágeis','80h'], ['Organização Empresarial','80h'], ['Programação Coding Mobile (Java)','80h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('recursos-humanos')) {
        return $profile('12 meses', '1.000h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '320h', [['Introdução ao EAD','60h'], ['Gestão Estratégica de Recursos Humanos','20h'], ['Introdução à Libras','20h'], ['Psicologia Organizacional','40h'], ['Organização e Técnicas Administrativas','60h'], ['Fundamentos da administração','60h'], ['Noções de Direito do Trabalho','20h'], ['Sistema de Informações Gerenciais','20h'], ['Sistemas de informações Gerenciais em RH','20h']]],
            ['Módulo II', '200h', [['Gestão de departamento pessoal','40h'], ['Ética nas Organizações','20h'], ['Matemática Aplicada','20h'], ['Planejamento estratégico','20h'], ['Estratégias de gestão e organização empresarial','60h'], ['Recrutamento, seleção e socialização','40h']]],
            ['Módulo III', '480h', [['Segurança do trabalho e saúde ocupacional','20h'], ['Divisão e modelagem de cargos e salários','80h'], ['Sociologia e ética profissional','60h'], ['Gestão de times - métodos ágeis','40h'], ['Marketing pessoal e gestão de carreira','20h'], ['Gestão de equipes','40h'], ['Liderança e desenvolvimento de equipes','20h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('manutencao-e-suporte-para-informatica')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '480h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Inglês Instrumental','40h'], ['Empreendedorismo','60h'], ['Eletricidade','60h']]],
            ['Módulo II', '400h', [['Lógica de Programação','60h'], ['Ética e Cidadania Organizacional','40h'], ['Gestão de Sistemas Operacionais I','80h'], ['Técnicas e Linguagens de Banco de Dados','80h'], ['Testes de Software','80h']]],
            ['Módulo III', '560h', [['Administração de Sistemas Operacionais Proprietário - Windows Server','80h'], ['Hardware Básico e Manutenção de Computadores','80h'], ['Programação Coding Mobile (Java)','80h'], ['Eletrônica Digital','80h'], ['Segurança da Informação','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','80h'], ['Metodologia da Manutenção','80h'], ['Introdução a Banco de Dados','80h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('informatica-para-internet')) {
        return $profile('12 meses', '1.240h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '440h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','20h'], ['Português Instrumental','20h'], ['Empreendedorismo','20h'], ['Lógica de Programação','40h'], ['Banco de Dados','60h'], ['Segurança do Trabalho','40h'], ['Matemática aplicada','40h'], ['Inglês instrumental I','20h'], ['Inglês instrumental II','20h'], ['Redes de computadores','60h'], ['Ética, cidadania e sustentabilidade','60h'], ['Qualidade de Software','20h']]],
            ['Módulo II', '180h', [['Análise e projeto de software orientado a objetos','60h'], ['Desenvolvimento Web I','40h'], ['Desenvolvimento Web II','40h'], ['Aplicação de tecnologias emergentes','40h']]],
            ['Módulo III', '620h', [['Interface humano-computador e user experience','60h'], ['Técnicas em Programação','60h'], ['Gestão da tecnologia da informação e comunicação','80h'], ['Gestão de projetos','60h'], ['Teste de software','60h'], ['Segurança da informação','60h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('guia-de-turismo')) {
        return $profile('12 meses', '1.000h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I', '280h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Atendimento ao cliente','80h'], ['Primeiros Socorros no Turismo','40h'], ['Inglês Instrumental','40h'], ['Espanhol Instrumental','20h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','40h']]],
            ['Módulo II', '120h', [['Marketing de Eventos','20h'], ['Comunicação Oficial','40h'], ['Marketing Pessoal e Gestão de Carreira','60h']]],
            ['Módulo III', '120h', [['História da Arte Aplicada ao Turismo','40h'], ['Legislação Aplicada ao Turismo','60h'], ['Gestão de Serviços Turísticos','20h']]],
            ['Módulo IV', '140h', [['Manifestação da Cultura Popular Regional e Nacional','40h'], ['Técnicas de Comunicação','20h'], ['Relações Interpessoais','80h']]],
            ['Módulo V', '340h', [['Elaboração de Roteiros e Hospedagem','40h'], ['Geografia Aplicada ao Turismo Regional e Nacional','40h'], ['Fundamentos do Turismo e da Hospitalidade','60h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('estetica') || $has('cosmetologia')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '580h', [['Introdução ao EAD','60h'], ['Gestão de Recursos Humanos','40h'], ['Biossegurança','20h'], ['Ética profissional, bioética e legislação','40h'], ['Empreendedorismo e gestão','20h'], ['Nutrição e dietética','80h'], ['Microbiologia e imunologia','60h'], ['Química geral','80h'], ['Cosmetologia aplicada a maquiagem','20h'], ['Anatomia e fisiologia','40h'], ['Citopatologia','40h'], ['Psicologia da personalidade','20h'], ['Suporte emergencial à vida e atendimento pré-hospitalar','20h'], ['Gestão comercial','20h'], ['Atendimento ao cliente','20h']]],
            ['Módulo II', '300h', [['Fundamentos e técnicas aplicadas à estética capilar','20h'], ['Biologia Celular','20h'], ['Cosmetologia','40h'], ['Fundamentos e técnicas aplicadas de estética corporal','40h'], ['Técnicas de depilação','80h'], ['Técnicas aplicadas de estética facial','40h'], ['Cosmetologia aplicada a maquiagem','60h']]],
            ['Módulo III', '560h', [['Fundamentos da estética humana','40h'], ['Fundamentos da dermatologia','80h'], ['Princípios da avaliação estética','80h'], ['Massoterapia','20h'], ['Terapias alternativas aplicadas à estética','40h'], ['Fundamentos e técnicas de drenagem linfática facial','20h'], ['Psicologia aplicada à estética','40h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    if ($has('contabilidade')) {
        return $profile('12 meses', '1.200h', 'Estágio supervisionado obrigatório de 200h.', [
            ['Módulo I — Ambientação Organizacional e Empreendedorismo', '280h', [['Introdução ao EAD','60h'], ['Gestão de Recursos Humanos','60h'], ['Fundamentos da Administração','40h'], ['Organização, Sistemas e Métodos','40h'], ['Empreendedorismo','40h'], ['Legislação Social e Trabalhista','40h']]],
            ['Módulo II — Contabilidade Básica', '260h', [['Relações Humanas no Trabalho','40h'], ['Departamento Pessoal','60h'], ['Análise das Demonstrações Contábeis','40h'], ['Gestão Financeira e Orçamentária','60h'], ['Gestão de Custos','60h']]],
            ['Módulo III — Auxiliar em Recursos Humanos', '300h', [['Fundamentos Contábeis','40h'], ['Matemática Financeira','60h'], ['Controladoria','40h'], ['Práticas Bancárias','80h'], ['Contabilidade Intermediária','80h']]],
            ['Módulo IV — Auxiliar em Finanças', '360h', [['Teorias Contábeis','20h'], ['Contabilidade Pública','80h'], ['Escrita Fiscal e Legislação Tributária','60h'], ['Estágio Supervisionado','200h']]],
        ]);
    }
    if ($has('automacao-industrial')) {
        return $profile('12 meses', '1.440h', 'Estágio supervisionado obrigatório de 240h.', [
            ['Módulo I', '200h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Introdução à Libras','20h'], ['Comportamento Organizacional','40h'], ['Segurança do Trabalho','60h'], ['Empreendedorismo','20h']]],
            ['Módulo II', '580h', [['Lógica de Programação','40h'], ['Ética nas Organizações','20h'], ['Meio ambiente, desenvolvimento e sustentabilidade','80h'], ['Mecânica Técnica','40h'], ['Interpretação do desenho técnico','60h'], ['Eletricidade I','40h'], ['Eletrônica digital','60h'], ['Resistência de materiais','40h'], ['Metodologia da manutenção','60h'], ['Comandos eletroeletrônicos','80h'], ['Projetos elétricos','20h'], ['Metrologia e Normatização','40h']]],
            ['Módulo III', '660h', [['Projetos de automação industrial','80h'], ['Sistemas Digitais','20h'], ['Eletrônica analógica','60h'], ['Instalações Elétricas','40h'], ['Ensaios de máquinas elétricas','20h'], ['Hidropneumática','40h'], ['Controle e acionamento de máquinas','60h'], ['Controle de qualidade industrial','40h'], ['Eletrônica industrial','20h'], ['NR 10 - Segurança em instalações elétricas','40h'], ['Estágio Supervisionado','240h']]],
        ]);
    }
    return null;
}

function product_academic_profile(array $product): ?array {
    if (isset($product['academic']) && is_array($product['academic'])) {
        return $product['academic'];
    }
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? ''));
    $categoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
    if ($slugKey === 'tecnico-ead-seguranca-trabalho') {
        $slugKey = 'tecnico-em-seguranca-do-trabalho';
    }
    if ($slugKey === 'tecnico-ead-secretariado-escolar' || str_contains($slugKey, 'secretariado-escolar')) {
        $slugKey = 'tecnico-em-secretaria-escolar';
    }
    if (str_contains($slugKey, 'sistemas-de-energia-renovavel')) {
        $slugKey = 'tecnico-em-sistema-de-energia-renovavel';
    }
    if (str_contains($slugKey, 'design-de-interiores')) {
        $slugKey = 'tecnico-em-designer-de-interiores';
    }
    if (str_contains($slugKey, 'estradas')) {
        $slugKey = 'tecnico-em-estrada';
    }
    if ($slugKey === 'tecnico-em-maquinas-pesadas') {
        $slugKey = 'tecnico-em-manutencao-de-maquinas-pesadas';
    }
    $postTechnicalOverride = official_post_technical_profile_override($slugKey);
    if ($postTechnicalOverride !== null && product_is_post_technical($product)) {
        return $postTechnicalOverride;
    }
    $technologistOverride = official_technologist_profile_override($slugKey);
    if ($technologistOverride !== null && product_is_technologist($product)) {
        return $technologistOverride;
    }
    $officialOverride = official_drive_technical_profile_override($slugKey, $titleKey);
    if ($officialOverride !== null && product_is_official_drive_technical_ead($product)) {
        return $officialOverride;
    }
    $profiles = [
        'tecnico-em-administracao' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP.',
            'internship' => 'Estágio supervisionado obrigatório de 200h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '460h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Fundamentos da Administração','20h'], ['Organização, Sistemas e Métodos','20h'], ['Empreendedorismo','20h'], ['Princípios de Marketing','20h'], ['Marketing Digital','40h'], ['Atendimento ao Cliente','20h'], ['Gestão Estratégica de Pessoas','60h'], ['Departamento Pessoal','40h'], ['Matemática Financeira','40h'], ['Gestão Financeira e Orçamentária','80h'], ['Gestão de Custos','40h']]],
                ['Módulo II', '740h', [['Logística Empresarial','80h'], ['Supply Chain Management — SCM','60h'], ['Administração de Sistemas de Informação','40h'], ['Gestão de Projetos','40h'], ['Técnicas de Vendas','20h'], ['Processo Decisório','40h'], ['Liderança e Desenvolvimento de Equipes','60h'], ['Contabilidade Básica','40h'], ['Gestão de Estoques e Suprimentos','40h'], ['Gestão de Transporte e Distribuição','80h'], ['Gestão de Inovação Tecnológica','40h'], ['Estágio Supervisionado','200h']]],
            ],
        ],
        'tecnico-em-seguranca-do-trabalho' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz curricular oficial voltada à prevenção, legislação, gestão de riscos e saúde ocupacional. Início em até 24 horas úteis após a confirmação do pagamento.',
            'presence' => '',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I — Educação para a Saúde e Segurança do Trabalho', '380h', [['Introdução ao EAD','80h'], ['Gestão de Recursos Humanos','80h'], ['Introdução à Segurança do Trabalho','60h'], ['Psicologia do Trabalho','40h'], ['Higiene do Trabalho','80h'], ['Empreendedorismo','40h']]],
                ['Módulo II — Prevenção e Segurança em Processos Industriais', '220h', [['Prevenção e Combate a Incêndio','80h'], ['Ergonomia','60h'], ['Medicina do Trabalho','40h'], ['Gestão Ambiental','40h'], ['Gestão da Qualidade','40h']]],
                ['Módulo III — Gestão e Programas de Saúde e Segurança do Trabalho', '300h', [['Legislação e Normas Técnicas','80h'], ['Certificação da Qualidade','60h'], ['Segurança, meio ambiente, saúde e responsabilidade social','80h'], ['Segurança do trabalho na construção civil — NR18','80h']]],
                ['Módulo IV', '540h', [['Gestão de Riscos','80h'], ['Planejamento Estratégico','80h'], ['Auditorias, Perícias e Laudos','60h'], ['Ética e Cidadania','80h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-logistica' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP.',
            'internship' => 'Estágio supervisionado obrigatório de 200h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I — Ambientação Organizacional e Empreendedorismo', '260h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Fundamentos da Administração','20h'], ['Organização, Sistemas e Métodos','60h'], ['Empreendedorismo','20h'], ['Marketing Digital','20h'], ['Atendimento ao Cliente','20h'], ['Logística Empresarial','20h'], ['Supply Chain Management — SCM','40h']]],
                ['Módulo II', '240h', [['Gestão de Pessoas','20h'], ['Gestão da Qualidade','60h'], ['Gestão da Produção','40h'], ['Matemática Financeira','20h'], ['Gestão de Custos','20h'], ['Economia Aplicada','40h'], ['Administração de Sistemas de Informação','20h'], ['Gestão de Projetos','20h']]],
                ['Módulo III', '120h', [['Gestão de Marketing','40h'], ['Técnicas de Vendas','20h'], ['Gestão de Estoques e Suprimentos','40h'], ['Gestão de Transporte e Distribuição','20h']]],
                ['Módulo IV — Gestão Integrada', '380h', [['Logística Reversa','60h'], ['Logística de Armazenagem','80h'], ['Gestão de Inovação Tecnológica','40h'], ['Estágio Supervisionado','200h']]],
            ],
        ],
        'tecnico-em-desenvolvimento-de-sistemas' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada a desenvolvimento, testes, documentação e manutenção de sistemas.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP.',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '400h', [['Introdução ao EAD','80h'], ['Introdução a Banco de Dados','80h'], ['Lógica de Programação','80h'], ['Português Instrumental','80h'], ['Segurança da Informação','80h']]],
                ['Módulo II', '480h', [['Ambiente de Desenvolvimento para Web','80h'], ['Empreendedorismo','80h'], ['Modelagem Matemática','80h'], ['Programação — Coding Web (PHP)','80h'], ['Tecnologia e Linguagens de Banco de Dados','80h'], ['Análise de Mercado: Tendência, Comportamento e Movimento','80h']]],
                ['Módulo III', '560h', [['Análise e Projeto de Software Orientado a Objetos','80h'], ['Gestão de Times — Métodos Ágeis','80h'], ['Organização Empresarial','80h'], ['Programação — Coding Mobile (Java)','80h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-edificacoes' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada a projetos, obras, instalações, orçamento e sustentabilidade na construção civil. Início em até 24 horas úteis após a confirmação do pagamento.',
            'presence' => '',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '180h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','20h'], ['Português Instrumental','20h'], ['Higiene e Segurança do Trabalho','40h'], ['Gestão de Pessoas','20h'], ['Introdução à Libras','20h'], ['Comportamento Organizacional','20h'], ['Matemática Básica','20h']]],
                ['Módulo II', '120h', [['Física Aplicada','20h'], ['Técnicas da Construção Civil','40h'], ['Lógica de Programação','40h'], ['Ferramental da Construção Civil','20h']]],
                ['Módulo III', '120h', [['Interpretação do Desenho Técnico','40h'], ['Desenho e Cálculo Estrutural de Fundações','40h'], ['Comunicação e Redação Técnica','20h'], ['Segurança do Trabalho na Construção Civil — NR18','20h']]],
                ['Módulo IV', '160h', [['Mecânica dos Solos','20h'], ['Desenho Técnico de Plantas Arquitetônicas em CAD','20h'], ['Modelagem de Projetos de Construção com Tecnologia BIM','20h'], ['Desenho Técnico Topográfico em CAD','40h'], ['Desenho e Cálculo Estrutural de Edificações','40h'], ['Acabamento de Edificações','20h']]],
                ['Módulo V', '120h', [['Topografia','20h'], ['Projeto Arquitetônico','20h'], ['Fundamentos da Arquitetura e Urbanismo','40h'], ['Instalações Elétricas','20h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','20h']]],
                ['Módulo VI', '120h', [['Orçamento e Planejamento','20h'], ['Empreendedorismo, Ética e Responsabilidade Social','40h'], ['NR 10 — Segurança em Instalações Elétricas','40h'], ['Logística de Canteiro e Gestão Ambiental','20h']]],
                ['Módulo VII', '100h', [['Sustentabilidade da Construção Civil','20h'], ['Planejamento, Orçamento e Controle de Obras','20h'], ['Ética nas Organizações','40h'], ['Meio Ambiente na Ética Empresarial','20h']]],
                ['Módulo VIII', '120h', [['Instalações Hidrossanitárias','60h'], ['Ergonomia e Medicina do Trabalho','20h'], ['Instalações, Normas Técnicas e Segurança','40h']]],
                ['Módulo IX', '100h', [['Resistência de Materiais','20h'], ['Sistemas e Processos Construtivos','40h'], ['Tratamento de Resíduos Sólidos','40h']]],
                ['Módulo X', '300h', [['Projeto de Edificações','40h'], ['Gerenciamento dos Aspectos e Impactos Ambientais','20h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-eletrotecnica' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada a instalações, projetos, automação, máquinas e sistemas elétricos. Início em até 24 horas úteis após a confirmação do pagamento.',
            'presence' => '',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '220h', [['Introdução ao EAD','20h'], ['Gestão de Recursos Humanos','40h'], ['Introdução à Libras','20h'], ['Eletricidade I','20h'], ['Introdução ao AutoCAD 2D','20h'], ['Matemática Aplicada','60h'], ['Física Aplicada','40h']]],
                ['Módulo II', '180h', [['Empreendedorismo','20h'], ['Lógica de Programação','20h'], ['Gestão Empresarial','20h'], ['NR 10 — Segurança em Instalações Elétricas','20h'], ['Projetos Elétricos','40h'], ['Projetos de Automação Industrial','60h'], ['Interpretação do Desenho Técnico','40h'], ['Eletrônica Digital','20h']]],
                ['Módulo III', '480h', [['Instalações Elétricas de Baixa Tensão','80h'], ['Eletrônica Industrial','20h'], ['Energias Renováveis','60h'], ['Geração, Conservação de Eficiência Energética','40h'], ['Tecnologia Mecânica','20h'], ['Princípios de Máquinas Elétricas','40h'], ['Ensaios de Máquinas Elétricas','60h'], ['Comandos Eletroeletrônicos','40h'], ['Segurança do Trabalho na Indústria e na Logística','20h'], ['Sistemas de Comunicação e Telecomunicações','40h']]],
                ['Módulo IV', '160h', [['Análise de Circuitos Eletroeletrônicos','40h'], ['Ergonomia e Medicina do Trabalho','40h'], ['Programação CLP','20h'], ['Metodologia da Manutenção','60h']]],
                ['Módulo V', '400h', [['Instalações Elétricas de Média e Alta Tensão','40h'], ['Elementos de Máquinas','20h'], ['Controle e Acionamento de Máquinas','20h'], ['Proteção de Sistemas Elétricos','20h'], ['Transferência de Calor','60h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-informatica' => [
            'duration' => '12 meses',
            'workload' => '1.260h',
            'modality_note' => 'Curso Técnico EAD com organização por módulos e acompanhamento acadêmico.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '320h', [['Introdução ao EAD','60h'], ['Introdução à Banco de Dados','40h'], ['Lógica de Programação','60h'], ['Português Instrumental','80h'], ['Segurança da Informação','80h']]],
                ['Módulo II', '360h', [['Ambiente de Desenvolvimento para WEB','80h'], ['Empreendedorismo','80h'], ['Introdução a redes de computadores e protocolos de comunicação','40h'], ['Programação — Coding Web (PHP)','80h'], ['Tecnologia e Linguagens de Banco de Dados','80h']]],
                ['Módulo III', '580h', [['Administração de sistema operacional proprietário — Windows Server','80h'], ['Cabeamento estruturado','60h'], ['Hardware básico e manutenção de computadores','40h'], ['Modelagem matemática','80h'], ['Organização empresarial','80h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-nutricao-e-dietetica' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz curricular oficial e estágio supervisionado.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '620h', [['Introdução ao EAD','80h'], ['Gestão de Recursos Humanos','60h'], ['Segurança do Trabalho','60h'], ['Avaliação Nutricional','40h'], ['Introdução à Nutrição','80h'], ['Anatomia e fisiologia aplicadas à nutrição','80h'], ['Bioquímica humana aplicada à nutrição','60h'], ['Microbiologia dos alimentos','80h'], ['Sociologia e ética profissional','80h']]],
                ['Módulo II', '180h', [['Bromatologia','20h'], ['Bioquímica dos alimentos','60h'], ['Gestão de serviços em nutrição e dietética','40h'], ['Técnica dietética básica','60h']]],
                ['Módulo III', '240h', [['Nutrição e Dietética','80h'], ['Nutrição materno-infantil','80h'], ['Nutrição e o Idoso','80h']]],
                ['Módulo IV', '400h', [['Microbiologia e Imunologia','20h'], ['Processamento industrial de alimentos','20h'], ['Nutrição, marketing e empreendedorismo','40h'], ['Técnica dietética avançada','80h'], ['Estágio supervisionado','240h']]],
            ],
        ],
        'tecnico-em-programacao-de-jogos-digitais' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com matriz curricular oficial voltada a desenvolvimento de jogos.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '400h', [['Introdução ao EAD','80h'], ['Introdução à Banco de Dados','80h'], ['Lógica de Programação','80h'], ['Português Instrumental','80h'], ['Segurança da Informação','80h']]],
                ['Módulo II', '480h', [['Ambiente de Desenvolvimento para WEB','80h'], ['Empreendedorismo','80h'], ['Análise de Software Orientado a Objetos','80h'], ['Gestão de Times — Métodos Ágeis','80h'], ['Programação — Coding Mobile (Java)','80h'], ['Construção e Animação de Cenários e Objetos 2D e 3D','80h']]],
                ['Módulo III', '560h', [['Organização Empresarial','80h'], ['Roteirização de Jogos Digitais','80h'], ['Análise de Mercado: Tendência, Comportamento e Movimento','80h'], ['Programação em Unity','80h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-qualidade' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com grade oficial organizada por semestres.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Sem estágio obrigatório informado na grade oficial.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '', [['Gestão de Qualidade e Gerenciamento de Rotina','65h'], ['Gestão por Competências','65h'], ['Gerenciamento de Projetos I','65h'], ['Relacionamento Interpessoal','65h'], ['Programa de Gerência de Riscos','65h']]],
                ['2º semestre', '', [['Programas de Qualidade Empresarial','65h'], ['Gerenciamento de Projetos II','65h'], ['Análise de Custos','65h'], ['Planejamento e Gestão','65h']]],
                ['3º semestre', '', [['Auditoria da Qualidade','65h'], ['Qualidade de Serviços de Saúde ISO 9001','65h'], ['Controladoria na Gestão','65h'], ['Auditoria de Custos','20h']]],
            ],
        ],
        'tecnico-em-seguros' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com grade oficial organizada por semestres.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Sem estágio obrigatório informado na grade oficial.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '', [['Informática Essencial e Avançada','40h'], ['Sistema de Informações Gerenciais e Marketing Estratégico','60h'], ['Atuária e Precificação de Seguros','60h'], ['Economia e Mercado','60h'], ['Contabilidade de Seguros','60h']]],
                ['2º semestre', '', [['Contratos','60h'], ['Matemática Financeira e Cidadania','60h'], ['Mercado de Seguros Privados','60h'], ['Governança Corporativa e Gestão de Riscos','60h'], ['Comunicação Interpessoal','40h']]],
                ['3º semestre', '', [['Vendas e Negociação','60h'], ['Gestão da Segurança da Informação','60h'], ['Regulação e Compliance em Seguros','60h'], ['Tendências e Desafios no Setor de Seguros','60h']]],
            ],
        ],
        'tecnico-em-servicos-juridicos' => [
            'duration' => '12 meses',
            'workload' => '1.220h',
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada à atuação em rotinas jurídicas.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso e podem envolver atas, atividades documentais e procedimentos administrativos orientados ao aluno.',
            'internship' => 'Estágio supervisionado obrigatório de 200h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I — Introdução aos Estudos Jurídicos', '320h', [['Introdução ao EAD','60h'], ['Introdução ao Estudo do Direito','60h'], ['Gestão de Pessoas','40h'], ['Direito Empresarial: Contratos Empresariais e Títulos de Crédito','40h'], ['Noções de Direito do Consumidor','40h'], ['Fundamentos do Direito do Trabalho','40h'], ['Fundamentos do Direito Tributário','40h']]],
                ['Módulo II — Noções de Direito I', '320h', [['Gestão de escritório de advocacia','40h'], ['Práticas jurídicas e carreira advocatícia','40h'], ['Fundamentos do Direito Civil I: Introdução e Direito das Obrigações','80h'], ['Direito Sucessório','40h'], ['Fundamentos do Direito Constitucional','80h'], ['Direito Processual Civil: Teoria Geral','40h']]],
                ['Módulo III', '180h', [['Noções de Direito Penal','40h'], ['Direito processual penal','60h'], ['Direito Imobiliário, Registral e Cartorário','40h'], ['Direito processual civil','40h']]],
                ['Módulo IV — Direito dos Negócios', '140h', [['Direito digital','40h'], ['Fundamentos do Direito Previdenciário','40h'], ['Direito de Família','60h']]],
                ['Módulo V — Prática Jurídica', '60h + estágio', [['Mediação, Conciliação e Arbitragem','20h'], ['Atuação Jurídica Preventiva','40h'], ['Estágio Supervisionado','200h']]],
            ],
        ],
        'tecnico-em-secretaria-escolar' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada às rotinas de secretaria escolar.',
            'presence' => '',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '440h', [['Introdução ao EAD','80h'], ['Organização Empresarial','40h'], ['Português Instrumental','60h'], ['Empreendedorismo e Gestão','80h'], ['Introdução à Segurança da Informação','20h'], ['Atendimento ao Cliente','40h'], ['Comportamento Organizacional','60h'], ['Comunicação e Expressão','60h']]],
                ['Módulo II', '560h', [['Currículos e Projetos pedagógicos','80h'], ['Fundamentos da Educação','80h'], ['Gestão Educacional','60h'], ['Organização e Legislação em Educação','60h'], ['Práticas de Secretaria Escolar','40h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-meio-ambiente' => [
            'duration' => '12 meses',
            'workload' => '1.440h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à gestão ambiental, resíduos, água, efluentes e responsabilidade socioambiental.',
            'presence' => '',
            'internship' => 'Estágio supervisionado obrigatório de 240h.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['Módulo I', '320h', [['Introdução ao EAD','60h'], ['Introdução a Libras','80h'], ['Química Orgânica','80h'], ['Direito Ambiental','60h'], ['Cartografia e Geoprocessamento','40h']]],
                ['Módulo II', '320h', [['Gestão Ambiental','60h'], ['Microbiologia Aplicada ao meio ambiente','80h'], ['Empreendedorismo e Gestão','40h'], ['Gestão de Pessoas','80h'], ['Gerenciamento dos Aspectos e Impactos Ambientais','60h']]],
                ['Módulo III', '320h', [['Análise Química','80h'], ['Higiene Ocupacional e Prevenção de Riscos Ambientais','80h'], ['Logística Reversa','60h'], ['Tratamento da Água e Efluentes','40h'], ['Físico-química ambiental','60h']]],
                ['Módulo IV', '480h', [['Energias Renováveis','80h'], ['Meio Ambiente e Qualidade de Vida','40h'], ['Segurança, Meio Ambiente, Saúde e Responsabilidade Social','60h'], ['Tratamento de Resíduos Sólidos','60h'], ['Estágio Supervisionado','240h']]],
            ],
        ],
        'tecnico-em-gastronomia' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à atuação em gastronomia, gestão de alimentos, cozinha e segurança alimentar.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '', [['História da alimentação','60h'], ['Nutrição e Dietética','60h'], ['Gestão de alimentos e bebidas','60h'], ['Cozinha brasileira e internacional','60h'], ['Comunicação eficaz','40h']]],
                ['2º semestre', '', [['Auxiliar de cozinha','60h'], ['Culturas alimentares e regionais','60h'], ['Análise de custos','60h'], ['Tecnologia de alimentos','60h'], ['Gestão de estoque e armazenagem','40h']]],
                ['3º semestre', '', [['Liderança e equipe organizacional','40h'], ['Imunologia e microbiologia','60h'], ['Higiene ocupacional e gestão de riscos: agentes ambientais e ergonômicos','40h'], ['Bases legais e ações de vigilância sobre alimentos','60h'], ['Meio ambiente, desenvolvimento e sustentabilidade','40h']]],
            ],
        ],
        'tecnico-em-confeitaria' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à confeitaria, segurança alimentar, criatividade e gestão de alimentos.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '240h', [['Fundamentos em Administração e Empreendedorismo','60h'], ['Liderança e Equipe Organizacional','60h'], ['História da Alimentação','60h'], ['Bases Legais e Ações de Vigilância sobre Alimentos','60h']]],
                ['2º semestre', '280h', [['Nutrição e Dietética','60h'], ['Confeitaria','80h'], ['Culturas Alimentares e Regionais','80h'], ['Imunologia e Microbiologia','80h']]],
                ['3º semestre', '260h', [['Inovação e Criatividade','60h'], ['Gestão de Alimentos e Bebidas','60h'], ['Tecnologia de Alimentos','60h'], ['Gestão de Custos e Formação de Preços','80h']]],
            ],
        ],
        'tecnico-em-financas' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à administração financeira, análise de mercado, investimentos e controladoria.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '325h', [['Administração Financeira I','65h'], ['Gestão de Negócios e Análises Financeiras','65h'], ['Gestão Financeira','65h'], ['Matemática Financeira e Cidadania','65h'], ['Análise Financeira','65h']]],
                ['2º semestre', '260h', [['O Papel das Instituições Financeiras no Mercado Financeiro','65h'], ['Administração Financeira II','65h'], ['Administração e Finanças Públicas','65h'], ['Análise de Mercado','65h']]],
                ['3º semestre', '215h', [['Análise de Investimentos','65h'], ['Análise de Demonstrações Financeiras','65h'], ['Teoria da Administração','65h'], ['Controladoria e Finanças','20h']]],
            ],
        ],
        'tecnico-em-eventos' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à organização, comunicação, logística e gestão de eventos.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '200h', [['Introdução à EAD','40h'], ['Empreendedorismo','40h'], ['Ética Profissional','40h'], ['Gestão de Pessoas','40h'], ['Gestão Estratégica das Organizações','40h']]],
                ['2º semestre', '300h', [['Marketing','60h'], ['Assessoria e Comunicação Pública','60h'], ['Logística Organizacional','60h'], ['Assessoria de Comunicação Pública','60h'], ['Controle e Gerência Participativa','60h']]],
                ['3º semestre', '300h', [['Gerenciamento de Produtos','60h'], ['Fundamentos da Gestão e do Planejamento Estratégico','60h'], ['Licitações e Contratos Administrativos','60h'], ['Análise de Custos','60h'], ['Análise Financeira','60h']]],
            ],
        ],
        'tecnico-em-gerencia-e-saude' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à gestão de serviços de saúde, auditoria, legislação e informação em saúde.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '380h', [['Informática Aplicada à Saúde','60h'], ['Legislação e Políticas de Saúde','80h'], ['Fundamentos em Administração e Empreendedorismo','60h'], ['Sistema de Saúde','60h'], ['Gerenciamento de Pessoal','60h'], ['Gestão da Informação em Serviços de Saúde','60h']]],
                ['2º semestre', '380h', [['Planejamento e Gestão','60h'], ['Saúde Coletiva','60h'], ['Auditoria em Saúde','80h'], ['Gestão de Estoques e Armazenagem','60h'], ['Comunicação Eficaz','60h'], ['Gestão Financeira','60h']]],
                ['3º semestre', '440h', [['Contabilidade para Negócios na Área da Saúde','60h'], ['Auditoria de Contratos, Convênios e Protocolos Clínicos','80h'], ['Epidemiologia, Vigilância e Educação em Saúde Pública','60h'], ['Biossegurança nas Ações de Saúde','60h'], ['SMS — Segurança, Meio Ambiente e Saúde','60h'], ['Ética na Saúde','60h']]],
            ],
        ],
        'tecnico-em-agente-comunitario-de-saude' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à atenção básica, educação em saúde, políticas públicas e cuidado comunitário.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Sistema de Saúde','80h'], ['Introdução à Gerontologia','80h'], ['Ética Profissional','80h']]],
                ['2º semestre', '400h', [['Diretrizes para a Educação em Saúde','80h'], ['Legislação e Políticas de Saúde','80h'], ['Manejo e Cuidado de Estomias e Fístulas','80h'], ['Oncologia','80h'], ['Cuidados de Enfermagem ao Paciente Renal Transplantado','80h']]],
                ['3º semestre', '400h', [['Assistência em Enfermagem a Paciente Renal e Home Care','80h'], ['Atenção Básica à Saúde I','80h'], ['Atenção Básica à Saúde II','80h'], ['Estratégia Saúde da Família (ESF)','80h'], ['Gestão Pública com Ênfase em Saúde da Família','80h']]],
            ],
        ],
        'tecnico-em-petroleo-e-gas' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à operação, segurança, logística e gestão em petróleo, gás e fontes alternativas de energia.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '440h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Ética Profissional','100h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','100h'], ['Agentes de Risco e EPI','80h']]],
                ['2º semestre', '360h', [['Planejamento de Gestão em Petróleo, Gás e Fontes Alternativas de Energia','80h'], ['Inspeção e Manutenção de Equipamentos de Prevenção e Combate a Incêndios','100h'], ['Básico de Produtos Químicos Perigosos','100h'], ['Máquinas Mecânicas','80h']]],
                ['3º semestre', '400h', [['Procedimentos de Emergência em Incêndios','100h'], ['Tópicos Especiais de Segurança contra Incêndio e Pânico','100h'], ['Noções Básicas de Logística','100h'], ['Gestão de Logística e Cadeia de Suprimentos','100h']]],
            ],
        ],
        'tecnico-em-manutencao-de-maquinas-pesadas' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à manutenção, inspeção, prevenção de riscos e operação técnica de máquinas pesadas.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '420h', [['Física Aplicada','60h'], ['Eletricidade Básica','80h'], ['Ferramental de Mecânica','60h'], ['Máquinas Mecânicas','80h'], ['Empreendedorismo','60h'], ['Indicadores de Manutenção','80h']]],
                ['2º semestre', '420h', [['Eletrônica Industrial de Potência','80h'], ['Prevenção e Controle de Riscos em Máquinas, Equipamentos e Instalações','60h'], ['Manutenção Preventiva de Plantadeiras Agrícolas','80h'], ['Projetos Mecânicos','60h'], ['Máquinas e Mecanização Agrícola','80h'], ['Proteção de Máquinas e Equipamentos','60h']]],
                ['3º semestre', '440h', [['Usinagem e Conformação Mecânica','80h'], ['Segurança do Trabalho e Saúde Ocupacional','60h'], ['Inspeção e Manutenção de Equipamentos de Prevenção e Combate a Incêndios','80h'], ['Liderança e Equipe Organizacional','60h'], ['Português Instrumental I','80h'], ['Controle da Qualidade Industrial em Mecânica','80h']]],
            ],
        ],
        'tecnico-em-soldagem' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a processos de soldagem, segurança, metalurgia e manutenção industrial.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '440h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Ética Profissional','100h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','100h'], ['Legislação e Normas Regulamentadoras em Segurança do Trabalho','80h']]],
                ['2º semestre', '360h', [['Proteção de Máquinas e Equipamentos','80h'], ['Agentes de Risco e EPI','100h'], ['Processos de Soldagem','100h'], ['Soldagem e Processos de União','80h']]],
                ['3º semestre', '400h', [['Processo de Soldagem por Eletrodo Revestido (SMAW)','100h'], ['Metalurgia da Soldagem','100h'], ['Brasagem e Soldagem Branda','100h'], ['Indicadores de Manutenção','100h']]],
            ],
        ],
        'tecnico-em-metalurgia' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à metalurgia, processos mecânicos, segurança e soldagem industrial.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '440h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Ética Profissional','100h'], ['Meio Ambiente, Desenvolvimento e Sustentabilidade','100h'], ['Legislação e Normas Regulamentadoras em Segurança do Trabalho','80h']]],
                ['2º semestre', '360h', [['Proteção de Máquinas e Equipamentos','80h'], ['Agentes de Risco e EPI','100h'], ['Usinagem e Conformação Mecânica','100h'], ['Desenho Técnico Mecânico em CAD','80h']]],
                ['3º semestre', '400h', [['Metalurgia Extrativa','100h'], ['Metalurgia dos Aços e Ferros Fundidos','100h'], ['Metalurgia da Soldagem','100h'], ['Soldagem e Processos de União','100h']]],
            ],
        ],
        'tecnico-em-aquicultura' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à produção aquícola, sustentabilidade, manejo ambiental e tecnologias aplicadas à água.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '300h', [['Introdução à EAD','60h'], ['Empreendedorismo','60h'], ['Gerenciamento Ambiental','60h'], ['Higiene ocupacional e gestão de riscos: agentes ambientais e ergonômicos','60h'], ['Recursos ambientais','60h']]],
                ['2º semestre', '380h', [['Poluição das águas','60h'], ['Águas superficiais e subterrâneas','80h'], ['Proteção do meio ambiente e sustentabilidade','80h'], ['Piscicultura','80h'], ['Carcinicultura','80h']]],
                ['3º semestre', '320h', [['Maricultura','80h'], ['Ranicultura','80h'], ['Despoluição de corpos hídricos','80h'], ['Meio ambiente, desenvolvimento e sustentabilidade','80h']]],
            ],
        ],
        'tecnico-em-agroindustria' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada ao agronegócio, beneficiamento de alimentos, sustentabilidade e processos agroindustriais.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Ética profissional','80h'], ['Cadeias produtivas do agronegócio','80h'], ['Agronegócios','80h']]],
                ['2º semestre', '400h', [['Políticas econômicas aplicadas ao agronegócio','80h'], ['Fundamentos do agronegócio','80h'], ['Planejamento em agronegócios','80h'], ['Tecnologia no beneficiamento de farinha','80h'], ['Tecnologia no processamento de carnes e derivados','80h']]],
                ['3º semestre', '400h', [['Proteção do meio ambiente e sustentabilidade','80h'], ['Máquinas e mecanização agrícola','80h'], ['Tratamento e reciclagem de águas residuárias e lixo','80h'], ['Física industrial I','80h'], ['Física industrial II','80h']]],
            ],
        ],
        'tecnico-em-agropecuaria' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à integração lavoura-pecuária-floresta, gestão agropecuária, solo e sustentabilidade.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Integração Lavoura, Pecuária e Floresta','80h'], ['Gestão da Agroindústria','80h'], ['Fertilidade do Solo e Nutrição das plantas','80h']]],
                ['2º semestre', '400h', [['Gestão de Cooperativas','80h'], ['Tratamento e reciclagem de águas residuárias e lixo','80h'], ['Proteção do meio ambiente e sustentabilidade','80h'], ['Legislação em Agrimensura','80h'], ['Zoologia Agrícola','80h']]],
                ['3º semestre', '400h', [['Mecanização agrícola','80h'], ['Climatologia e Meteorologia Agrícola','80h'], ['Gestão de Alimentos e Bebidas','80h'], ['Criação e Manejo na Agricultura','80h'], ['Sustentabilidade Ambiental, Social e Governança — ESG','80h']]],
            ],
        ],
        'tecnico-em-agricultura' => [
            'duration' => '12 meses',
            'workload' => '1.280h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à agricultura, cadeias produtivas, manejo do solo, mecanização e sustentabilidade.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Cadeias Produtivas do Agronegócio','80h'], ['Integração Lavoura, Pecuária e Floresta','80h'], ['Gestão da Agroindústria','80h']]],
                ['2º semestre', '400h', [['Fertilidade do Solo e Nutrição das plantas','80h'], ['Gestão de Cooperativas','80h'], ['Tratamento e reciclagem de águas residuárias','80h'], ['Proteção do meio ambiente e sustentabilidade','80h'], ['Legislação em Agrimensura','80h']]],
                ['3º semestre', '480h', [['Agricultura Familiar e Desenvolvimento Sustentável','80h'], ['Mecanização agrícola','80h'], ['Climatologia e Meteorologia Agrícola','80h'], ['História da Alimentação','80h'], ['Logística de Alimentação Escolar','80h'], ['Floricultura, Jardinagem e Paisagismo','80h']]],
            ],
        ],
        'tecnico-em-estrada' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a estradas, topografia, geoprocessamento, drenagem, obras e segurança.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '380h', [['Lógica e Fundamentos da Matemática','60h'], ['Interpretação de Desenho Técnico','60h'], ['Legislação e Normatização de Trânsito e Transporte','60h'], ['Topografia e Geoprocessamento Aplicados','80h'], ['Erosão e Conservação do Solo','60h'], ['Ferramental da Construção Civil','60h']]],
                ['2º semestre', '440h', [['Planejamento Urbano e Meio Ambiente','60h'], ['Mecânica dos Solos','80h'], ['Hidrologia','60h'], ['Estruturas em Concreto Protendido','80h'], ['Sistema de Drenagem','80h'], ['Modelagem de Projetos de Construção com Tecnologia BIM','80h']]],
                ['3º semestre', '380h', [['Planejamento, Orçamento e Controle de Obras','80h'], ['Visitas Técnicas','60h'], ['Legislação e Normas Regulamentadoras em Segurança do Trabalho','60h'], ['Segurança do Trabalho e Saúde Ocupacional','60h'], ['Legislação Ambiental e Licenciamento Ambiental','60h'], ['Proteção do Meio Ambiente e Sustentabilidade','60h']]],
            ],
        ],
        'tecnico-em-saneamento' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a saneamento, abastecimento de água, resíduos, drenagem e recuperação ambiental.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º período', '360h', [['Introdução à EAD','60h'], ['Poluição das Águas','60h'], ['Estatística Aplicada','60h'], ['Higiene Ocupacional','60h'], ['Instalações Hidrossanitárias','60h'], ['Topografia e Geoprocessamento Aplicados','60h']]],
                ['2º período', '420h', [['Hidrologia','60h'], ['Sistema de Abastecimento de Água e ETA','60h'], ['Gerenciamento de Resíduos Sólidos','60h'], ['Sistema de Drenagem','60h'], ['Despoluição de Corpos Hídricos','60h'], ['Águas Superficiais e Subterrâneas','60h'], ['Geologia Ambiental','60h']]],
                ['3º período', '420h', [['Controle Ambiental','60h'], ['Planejamento, Orçamento e Controle de Obras','60h'], ['Qualidade do Solo e Recuperação de Áreas Degradadas','60h'], ['Legislação Ambiental e Licenciamento Ambiental','60h'], ['Tratamento e Reciclagem de Águas Residuárias e Lixo','60h'], ['Responsabilidade Civil, Responsabilidade Ambiental e o Empresário','60h'], ['Educação em Direitos Humanos','60h']]],
            ],
        ],
        'tecnico-em-mecatronica' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à integração entre mecânica, eletrônica, automação, programação e instalações industriais.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Ética Profissional','80h'], ['Sistema de Gestão de Segurança e Saúde no Trabalho','80h'], ['Informática Essencial e Avançada','80h'], ['Segurança, Meio Ambiente e Responsabilidade Social','80h']]],
                ['2º semestre', '400h', [['Microcontroladores e Microprocessadores','80h'], ['Inglês Instrumental','80h'], ['Análise de Circuitos Eletroeletrônicos','80h'], ['Eletrônica Industrial de Potência','80h'], ['Lógica de Programação','80h']]],
                ['3º semestre', '400h', [['Equipamentos e Instalações Industriais','80h'], ['Conceitos Fundamentais da Eletricidade','80h'], ['Eletrônica Digital','80h'], ['Eletrônica Analógica','80h'], ['Projetos de Automação Industrial','80h']]],
            ],
        ],
        'tecnico-em-refrigeracao-e-climatizacao' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à refrigeração, climatização, eletricidade, obras, projetos e custos.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução ao EAD','80h'], ['Empreendedorismo','80h'], ['Eletricidade Básica','80h'], ['Segurança em Instalações Elétricas','80h'], ['Planejamento, Orçamento e Controle de Obras','80h']]],
                ['2º semestre', '400h', [['Ferramental de Mecânica','80h'], ['Fundamentos da Construção Civil','80h'], ['Noções Gerais do Direito','80h'], ['Refrigeração e Climatização','80h'], ['Gestão de Custos e Formação de Preços','80h']]],
                ['3º semestre', '400h', [['Gerenciamento de Projetos','100h'], ['Projetos Elétricos','100h'], ['Projetos de Instalações de Sistema de Energia Renovável','100h'], ['Projetos de Edificações','100h']]],
            ],
        ],
        'tecnico-em-eletromecanica' => [
            'duration' => '12 meses',
            'workload' => '1.360h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à mecânica, eletroeletrônica, CAD, qualidade e proteção de sistemas.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '480h', [['Introdução à EAD','80h'], ['Fundamentos da Mecânica Clássica e Quântica','80h'], ['Eletricidade Básica','80h'], ['Noções de Lógica Matemática','80h'], ['Eletrônica Analógica','80h'], ['Eletrônica Digital','80h']]],
                ['2º semestre', '480h', [['Eletrônica Industrial','80h'], ['Legislação e Ética aplicada ao setor elétrico','80h'], ['Mecânica Automotiva','80h'], ['Eletroeletrônica Automotiva','80h'], ['Desenho Técnico Mecânico em CAD','80h'], ['Ferramental de Mecânica','80h']]],
                ['3º semestre', '400h', [['Ferramentas da Qualidade','80h'], ['Lógica de Programação','80h'], ['Projetos Elétricos',''], ['Projetos Mecânicos','80h'], ['Proteção de máquinas e equipamentos','80h'], ['Proteção de Sistemas elétricos','80h']]],
            ],
        ],
        'tecnico-em-designer-de-interiores' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a ambientes, estética, projetos, paisagismo e comunicação visual.',
            'presence' => 'Metodologia oficial com atividades EAD e presencialidade acadêmica/documental conforme orientação recebida no AVA. O IBETP orienta o aluno sobre registros, ATAs e procedimentos aplicáveis antes e durante o curso.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '300h', [['Introdução à EAD','60h'], ['Empreendedorismo','60h'], ['Ética Profissional','60h'], ['Os Grandes Arquitetos e Suas Obras','60h'], ['Estética e Filosofia da Arte','60h']]],
                ['2º semestre', '360h', [['Gerenciamento de Projetos I','60h'], ['Gerenciamento de Projetos II','80h'], ['Projeto (Design) de Software','80h'], ['Marketing Digital','80h'], ['Marketing Visual','80h']]],
                ['3º semestre', '320h', [['Paisagismo e Plantas Ornamentais','80h'], ['Design de Interface','80h'], ['Design Editorial','80h'], ['Floricultura, Jardinagem e Paisagismo','80h']]],
            ],
        ],
        'tecnico-em-geoprocessamento' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a cartografia, topografia, banco de dados geográficos, sensoriamento remoto e planejamento urbano.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '420h', [['Empreendedorismo','60h'], ['Planejamento Estratégico e Tomada de Decisão','60h'], ['Categorias e Conceitos da Geografia','80h'], ['Cartografia','80h'], ['Interpretação de Desenho Técnico','80h'], ['Português Instrumental','60h']]],
                ['2º semestre', '400h', [['Topografia e Geoprocessamento Aplicados','80h'], ['Bancos de Dados Geográficos','80h'], ['Redes Geográficas','80h'], ['Georreferenciamento e Sensoriamento Remoto','80h'], ['Sensoriamento Remoto e VANTs','80h']]],
                ['3º semestre', '380h', [['Liderança e Equipe Organizacional','60h'], ['Sistema de Informação','80h'], ['Controle Ambiental','80h'], ['Planejamento Urbano e Meio Ambiente','80h'], ['Legislação Ambiental e Licenciamento Ambiental','80h']]],
            ],
        ],
        'tecnico-em-telecomunicacoes' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a comunicação de dados, redes, eletrônica, nuvem e infraestrutura de telecomunicações.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '320h', [['Introdução ao EAD','20h'], ['Empreendedorismo','60h'], ['Sistemas de Comunicação e Telecomunicações','80h'], ['Comunicação de Dados','80h'], ['Gestão da Tecnologia da Informação e Comunicação','80h']]],
                ['2º semestre', '400h', [['Eletrônica Analógica','80h'], ['Eletrônica Digital','80h'], ['Gestão da Segurança da Informação','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','80h'], ['Tecnologias e Equipamentos Audiovisuais','80h']]],
                ['3º semestre', '480h', [['Projetos de Redes de Computadores','80h'], ['Infraestrutura de Computação em Nuvem','80h'], ['Microcontroladores e Microprocessadores','80h'], ['Segurança do Trabalho e Saúde Ocupacional','80h'], ['Psicologia Aplicada à Comunicação','80h'], ['Tecnologias Digitais de Informação e Comunicação','80h']]],
            ],
        ],
        'tecnico-em-manutencao-de-maquinas-navais' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à manutenção de sistemas, equipamentos e máquinas navais.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '440h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Ética Profissional','100h'], ['Prevenção e controle de riscos em máquinas, equipamentos e instalações','100h'], ['Proteção de máquinas e equipamentos','80h']]],
                ['2º semestre', '440h', [['Gestão e manutenção de sistemas elétricos','80h'], ['Meio ambiente, desenvolvimento e sustentabilidade','100h'], ['Legislação e normas regulamentadoras em segurança do trabalho','100h'], ['Agentes de risco e EPI','80h']]],
                ['3º semestre', '500h', [['Automação de sistemas','100h'], ['Refrigeração e climatização','100h'], ['Mecânica e climatização','100h'], ['Mecânica técnica','100h'], ['Inspeção e manutenção de equipamentos de prevenção e combate a incêndios','100h']]],
            ],
        ],
        'tecnico-em-manutencao-de-maquinas-industriais' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à manutenção, confiabilidade, máquinas elétricas e mecânicas industriais.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '200h', [['Introdução à EAD','40h'], ['Empreendedorismo','40h'], ['Ética Profissional','40h'], ['Gestão de Pessoas','40h'], ['Ferramental de Mecânica','40h']]],
                ['2º semestre', '300h', [['Máquinas Mecânicas','60h'], ['Mecânica Técnica','60h'], ['Máquinas Elétricas','60h'], ['Prevenção e controle de riscos em máquinas, equipamentos e instalações','60h'], ['Equipamentos e Instalações Industriais','60h']]],
                ['3º semestre', '300h', [['Práticas de manutenção','60h'], ['Operação e manutenção de usinas solares','60h'], ['Manutenção centrada em confiabilidade','60h'], ['Manutenção preventiva de plantadeiras agrícolas','60h'], ['Indicadores de manutenção','60h']]],
            ],
        ],
        'tecnico-em-prevencao-e-combate-ao-incendio' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à prevenção, inspeção, emergência e combate a incêndios.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Ética Profissional','80h'], ['Informática Essencial e Avançada','80h'], ['Lógica e Fundamentos da Matemática','80h'], ['Português Instrumental I','80h'], ['Primeiros Socorros','80h']]],
                ['2º semestre', '440h', [['Proteção e Combate a Incêndios','80h'], ['Procedimentos de Emergência','80h'], ['Inspeção e manutenção de equipamentos de prevenção e combate a incêndios','60h'], ['Básico de Produtos Químicos Perigosos','80h'], ['Legislação e normas regulamentadoras em segurança do trabalho','60h'], ['Tópicos em engenharia de segurança do trabalho','80h']]],
                ['3º semestre', '360h', [['Proteção do meio ambiente e sustentabilidade','60h'], ['Suporte básico de vida e socorro de emergência','60h'], ['Plano de escape','60h'], ['Sistema de Comando de Incidentes — ICS','60h'], ['Técnicas de treinamento','60h'], ['Visitas técnicas','60h']]],
            ],
        ],
        'tecnico-em-transito' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à mobilidade, legislação, educação e segurança no trânsito.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Psicologia do Trânsito e Prevenção de Acidentes','80h'], ['Direito de Trânsito','80h'], ['Avaliação e Perícia em Psicologia de Trânsito','80h'], ['Trânsito e Mobilidade Humana','80h'], ['Direitos e Deveres do Cidadão','80h']]],
                ['2º semestre', '280h', [['Mobilidade e Segurança no Trânsito','80h'], ['Educação para o Trânsito e Transporte','80h'], ['Proteção do Meio Ambiente e Sustentabilidade','80h'], ['Educação em Direitos Humanos','40h']]],
                ['3º semestre', '320h', [['Legislação e Normatização de Trânsito e Transporte','80h'], ['Políticas Públicas para o Trânsito e Legislação Aplicada','80h'], ['Direito Administrativo e Direito Constitucional','80h'], ['Primeiros Socorros','80h']]],
            ],
        ],
        'tecnico-em-defesa-civil' => [
            'duration' => '12 meses',
            'workload' => '800h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à defesa civil, emergência, riscos ambientais e planejamento.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '200h', [['Introdução ao EAD','40h'], ['Empreendedorismo','40h'], ['Ética profissional','40h'], ['Proteção e combate a incêndios','40h'], ['Gerenciamento ambiental','40h']]],
                ['2º semestre', '300h', [['Proteção do meio ambiente e sustentabilidade','60h'], ['O meio ambiente e os recursos naturais','60h'], ['Recursos ambientais','60h'], ['Programa de gerência de riscos','60h'], ['Primeiros socorros','60h']]],
                ['3º semestre', '300h', [['Segurança, Meio Ambiente e Responsabilidade Social','60h'], ['Gerenciamento de aspectos e Impactos Ambientais','60h'], ['Suporte básico de vida e socorro de emergência','60h'], ['Direito Ambiental','60h'], ['Planejamento e gestão','60h']]],
            ],
        ],
        'tecnico-em-mineracao' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à mineração, geologia, logística, legislação e recuperação ambiental.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Fundamentos da Geologia','80h'], ['Geologia ambiental','80h'], ['Geologia e Mecânica dos Sólidos','80h'], ['Técnicas de Mineração','80h']]],
                ['2º semestre', '440h', [['Logística Aplicada a Mineração','80h'], ['Legislação e Normas Técnicas à Mineração','80h'], ['Segurança, Meio Ambiente e Responsabilidade Social','80h'], ['Gerenciamento Ambiental','80h'], ['Erosão e conservação do solo','60h'], ['Gerenciamento de projetos I','60h']]],
                ['3º semestre', '360h', [['Gestão de materiais industriais','60h'], ['Programa de gerência de riscos','60h'], ['Sistema de Gestão, Auditoria e Perícia Ambiental','60h'], ['Gerenciamento de aspectos e Impactos Ambientais','60h'], ['Mecânica dos Solos','60h'], ['Qualidade do Solo e Recuperação de Áreas Degradadas','60h']]],
            ],
        ],
        'tecnico-em-agrimensura' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à agrimensura, topografia, solo, georreferenciamento e sensoriamento remoto.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Ética Profissional','80h'], ['Programa de gerência de riscos','80h'], ['Liderança e equipe organizacional','80h'], ['Empreendedorismo','80h']]],
                ['2º semestre', '400h', [['Topografia e geoprocessamento aplicados','80h'], ['Desenho técnico topográfico em CAD','80h'], ['Ciência do solo','80h'], ['Erosão e conservação do solo','80h'], ['Geologia e mecânica dos sólidos','80h']]],
                ['3º semestre', '400h', [['Qualidade do solo e recuperação de áreas degradadas','80h'], ['Georreferenciamento e sensoriamento remoto','80h'], ['Legislação em agrimensura','80h'], ['Tecnologia e agricultura de precisão','80h'], ['Sensoriamento remoto e VANTs','80h']]],
            ],
        ],
        'tecnico-em-traducao-e-interpretacao-de-libras' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à Libras, educação inclusiva, acessibilidade e atuação do intérprete.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '400h', [['Introdução à EAD','80h'], ['Empreendedorismo','80h'], ['Legislação e políticas públicas para a educação inclusiva e especial','80h'], ['Formação continuada de professores','80h'], ['Didática de a formação decente','80h']]],
                ['2º semestre', '400h', [['Deficiência auditiva e visual','80h'], ['Historicidade da língua brasileira de sinais (LIBRAS)','80h'], ['Escola e comunidade surda','80h'], ['Surdez e educação para surdos','80h'], ['Acessibilidade escolar','80h']]],
                ['3º semestre', '400h', [['Língua brasileira de sinais (LIBRAS)','80h'], ['Intérprete de LIBRAS em sala de aula','80h'], ['Sintaxe da língua de sinais','80h'], ['Formação e atuação do intérprete de LIBRAS','80h'], ['Inclusão Socioeducacional','80h']]],
            ],
        ],
        'tecnico-em-design-grafico' => [
            'duration' => '12 meses',
            'workload' => '1.000h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a design gráfico, marketing, interface, editorial e projetos digitais.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '300h', [['Introdução à EAD','60h'], ['Empreendedorismo','60h'], ['Ética profissional','60h'], ['Introdução a redes de computadores e protocolos de comunicação','60h'], ['Infográfico','60h']]],
                ['2º semestre', '380h', [['Gerenciamento de projetos I','60h'], ['Gerenciamento de projetos II','80h'], ['Marketing','80h'], ['Marketing digital','80h'], ['Marketing visual','80h']]],
                ['3º semestre', '320h', [['Geometria analítica e álgebra linear','80h'], ['Design de interface','80h'], ['Design editorial','80h'], ['Gestão de projetos na informática','80h']]],
            ],
        ],
        'tecnico-em-biotecnologia' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada à biotecnologia, genética, microbiologia, bioquímica e fundamentos científicos aplicados.',
            'presence' => 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à escola; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '320h', [['Introdução ao EAD','20h'], ['Empreendedorismo','60h'], ['Ética profissional','80h'], ['Química orgânica','80h'], ['Fisiopatologia e farmacologia','80h']]],
                ['2º semestre', '400h', [['Citogenética e mutagênese','80h'], ['Genética animal','80h'], ['Evolução, sistemática e filogenética','80h'], ['Embriologia e histologia animal','80h'], ['Imunologia e microbiologia','80h']]],
                ['3º semestre', '480h', [['Bioquímica e bioenergética','80h'], ['Microbiologia veterinária','80h'], ['Parasitologia veterinária','80h'], ['Física quântica e relatividade','80h'], ['Fundamentos da mecânica clássica e quântica','80h'], ['Estatística aplicada','80h']]],
            ],
        ],
        'tecnico-em-sistema-de-energia-renovavel' => [
            'duration' => '12 meses',
            'workload' => '1.200h',
            'modality_note' => 'Curso Técnico com início em até 24 horas úteis após a confirmação do pagamento e matriz curricular oficial voltada a sistemas de energia renovável, energia solar, fotovoltaica, eólica, hidráulica e manutenção.',
            'presence' => 'Metodologia oficial com atividades online e presencialidade acadêmica/documental conforme orientação recebida no AVA. O IBETP orienta o aluno sobre registros, ATAs e procedimentos aplicáveis antes e durante o curso.',
            'internship' => '',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º período', '480h', [['Legislação Aplicada','80h'], ['Informática','80h'], ['Matemática','80h'], ['Física','80h'], ['Eletricidade','80h'], ['Legislação Aplicada','80h']]],
                ['2º período', '400h', [['Química','60h'], ['Eletrônica','70h'], ['Energias Renováveis','80h'], ['Inglês Instrumental','50h'], ['Sustentabilidade Ambiental','70h'], ['Desenho Assistido por Computador','70h']]],
                ['3º período', '400h', [['Energia Solar e Térmica','80h'], ['Energia Fotovoltaica','80h'], ['Manutenção em Sistemas de Energia','80h'], ['Gestão do Projeto de Instalação','80h'], ['Energia Eólica e Hidráulica','80h']]],
            ],
        ],
    ];
    foreach ($profiles as $profileKey => $profile) {
        if ($titleKey === $profileKey || $slugKey === $profileKey || str_contains($titleKey, $profileKey) || str_ends_with($slugKey, '-' . $profileKey)) {
            if (product_is_official_drive_technical_ead($product)) {
                return normalize_official_drive_technical_profile($profile);
            }
            return $profile;
        }
    }
    if (product_is_technical_ead($product)) {
        $pbTechnicalSlugs = ['administracao','automacao-industrial','computacao-grafica','contabilidade','desenvolvimento-de-sistemas','eletronica','estetica-e-cosmetologia','guia-de-turismo','informatica','informatica-para-internet','logistica','manutencao-e-suporte-para-informatica','marketing-e-comunicacao','mecanica-industrial','nutricao-e-dietetica','programacao-de-jogos-digitais','recursos-humanos','redes-de-computadores','transacoes-imobiliarias','vendas','servicos-juridicos'];
        $ataWithInternshipSlugs = ['seguranca-do-trabalho','secretaria-escolar','eletrotecnica','edificacoes','meio-ambiente'];
        $ataNoInternshipSlugs = ['gerencia-em-saude','agente-comunitario-de-saude','eventos','financas','seguros','biotecnologia','sistemas-de-energia-renovavel','telecomunicacoes','design-grafico','traducao-e-interpretacao-de-libras','geoprocessamento','eletromecanica','refrigeracao-e-climatizacao','soldagem','petroleo-e-gas','qualidade','manutencao-de-maquinas-industriais','manutencao-de-maquinas-navais','metalurgia','maquinas-pesadas','estrada','mecatronica','agrimensura','mineracao','prevencao-e-combate-ao-incendio','defesa-civil','transito','saneamento','agricultura','agropecuaria','aquicultura','agroindustria','designer-de-interiores','design-de-interiores','gastronomia','confeitaria'];
        $hasInternship = str_contains($categoryKey, 'com-estagio');
        $isAtaNoInternship = false;
        $isAtaWithInternship = false;
        $isPbTechnical = false;
        foreach ($pbTechnicalSlugs as $pbSlug) {
            if (str_contains($slugKey, $pbSlug)) {
                $isPbTechnical = true;
                break;
            }
        }
        foreach ($ataWithInternshipSlugs as $internshipSlug) {
            if (str_contains($slugKey, $internshipSlug)) {
                $hasInternship = true;
                $isAtaWithInternship = true;
                break;
            }
        }
        foreach ($ataNoInternshipSlugs as $noInternshipSlug) {
            if (str_contains($slugKey, $noInternshipSlug)) {
                $isAtaNoInternship = true;
                break;
            }
        }
        if ($isAtaNoInternship) {
            $hasInternship = false;
        }
        $catalogInternship = trim((string)($product['internship'] ?? ''));
        $catalogDuration = trim((string)($product['duration'] ?? ''));
        $catalogWorkload = trim((string)($product['workload'] ?? ''));
        $presence = '';
        if ($isAtaNoInternship || $isAtaWithInternship) {
            $presence = 'Metodologia oficial: 80% online e 20% de presencialidade cumprida exclusivamente por assinatura de ATAs. O aluno não precisa comparecer presencialmente à instituição; as ATAs são enviadas por e-mail, assinadas pelo aluno e devolvidas conforme orientação acadêmica.';
        } elseif ($isPbTechnical) {
            $presence = 'Metodologia oficial dos Cursos Técnicos EAD: acompanhamento pelo AVA, orientação documental pelo IBETP e procedimentos acadêmicos formais conforme matriz do curso.';
        }
        $internshipText = $catalogInternship !== '' ? $catalogInternship : '';
        if ($isAtaNoInternship) {
            $internshipText = '';
        } elseif ($isAtaWithInternship) {
            $internshipText = 'Estágio supervisionado obrigatório de 240h, conforme matriz curricular oficial.';
        } elseif ($isPbTechnical) {
            $internshipText = 'Estágio supervisionado obrigatório de 240h, conforme matriz curricular oficial.';
        }
        return [
            'duration' => $catalogDuration !== '' ? $catalogDuration : '8 a 14 meses',
            'workload' => $catalogWorkload !== '' ? $catalogWorkload : 'Carga horária conforme informativo oficial do curso',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento. A 1ª mensalidade é paga via Pix no site no ato da matrícula; as demais mensalidades são enviadas mensalmente por link de pagamento, com opções de boleto, cartão de crédito e Pix.',
            'presence' => product_is_official_drive_technical_ead($product) ? '' : $presence,
            'internship' => $internshipText,
            'source' => 'Informações acadêmicas extraídas da planilha oficial de cursos e dos informativos acadêmicos disponíveis ao IBETP. As disciplinas detalhadas são exibidas quando a grade individual do curso já está vinculada.',
            'modules' => [],
        ];
    }
    if (product_is_technologist($product)) {
        $catalogInternship = trim((string)($product['internship'] ?? ''));
        $catalogDuration = trim((string)($product['duration'] ?? ''));
        $catalogWorkload = trim((string)($product['workload'] ?? ''));
        return [
            'duration' => $catalogDuration !== '' ? $catalogDuration : (str_contains($slugKey, 'analise-e-desenvolvimento-de-sistemas') || str_contains($slugKey, 'seguranca-do-trabalho') || str_contains($slugKey, 'gestao-hospitalar') ? '24 meses' : '18 a 24 meses'),
            'workload' => $catalogWorkload !== '' ? $catalogWorkload : 'Carga horária conforme informativo oficial do curso',
            'modality_note' => 'Curso Tecnólogo EAD com início em até 24 horas úteis após a confirmação do pagamento da matrícula. O aluno paga a matrícula via Pix no site e as mensalidades diretamente no AVA.',
            'presence' => 'Este curso possui atividades presenciais vinculadas aos polos informados nesta página. Antes de pagar a matrícula, confirme se você tem disponibilidade real para comparecer a um dos polos. Se você mora muito distante dos polos disponíveis, o aconselhável é não realizar a matrícula sem antes confirmar a viabilidade com o IBETP.',
            'internship' => $catalogInternship !== '' ? $catalogInternship : '',
            'source' => 'Informações acadêmicas extraídas da lista oficial de cursos e dos informativos acadêmicos disponíveis ao IBETP. As disciplinas detalhadas são exibidas quando a grade individual do curso já está vinculada.',
            'modules' => [],
        ];
    }
    return null;
}

function layout(string $title, string $description, string $body, ?string $image = null, bool $noindex = false, array $schemas = []): void {
    $robots = $noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';
    $og = absolute_asset($image ?: setting('seo_default_image', '/assets/og-ibetp.webp'));
    $canonical = current_url_clean();
    $schemas = array_merge([organization_schema(), website_schema()], $schemas);
    $gaId = trim(setting('google_analytics_id', setting('seo_google_analytics_id', 'G-S1JXRMV06F')));
    ?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($description) ?>">
  <meta name="robots" content="<?= e($robots) ?>">
  <meta name="author" content="IBETP - Instituto Brasileiro de Educação Técnica e Profissional">
  <meta name="theme-color" content="#061b45">
  <?php if (setting('seo_google_verification','')): ?><meta name="google-site-verification" content="<?= e(setting('seo_google_verification','')) ?>"><?php endif; ?>
  <?php if (setting('seo_bing_verification','')): ?><meta name="msvalidate.01" content="<?= e(setting('seo_bing_verification','')) ?>"><?php endif; ?>
  <link rel="canonical" href="<?= e($canonical) ?>">
  <link rel="alternate" type="application/rss+xml" title="Blog IBETP" href="<?= e(site_url('/feed.xml')) ?>">
  <link rel="icon" href="<?= e(site_url('/assets/favicon-ibetp.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(site_url('/assets/favicon-ibetp.png')) ?>">
  <link rel="preload" href="<?= e(site_url('/assets/site.css?v=premium-20260720-checkout-test-v1')) ?>" as="style">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($description) ?>">
  <meta property="og:image" content="<?= e($og) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta property="og:site_name" content="IBETP">
  <meta property="og:locale" content="pt_BR">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($title) ?>">
  <meta name="twitter:description" content="<?= e($description) ?>">
  <meta name="twitter:image" content="<?= e($og) ?>">
  <?php foreach ($schemas as $schema) echo json_ld($schema), "\n"; ?>
  <?php if ($gaId !== ''): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($gaId) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', <?= json_encode($gaId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
  </script>
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(site_url('/assets/site.css?v=premium-20260720-checkout-test-v1')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand brand-original" href="<?= e(site_url('/')) ?>"><img src="<?= e(site_url('/assets/logo-ibetp-original.png')) ?>" alt="IBETP - Instituto Brasileiro de Educação Técnica e Profissional"><span>Instituto Brasileiro de Educação Técnica e Profissional</span></a>
  <nav><a href="<?= e(site_url('/cursos')) ?>">Cursos</a><a href="<?= e(site_url('/quem-somos')) ?>">Quem somos</a><a href="<?= e(site_url('/glossario')) ?>">Glossário</a><a href="<?= e(site_url('/blog')) ?>">Blog</a><a class="btn" href="https://wa.me/5521983177702">Fale conosco</a></nav>
</header>
<?= $body ?>
<a class="whatsapp" href="https://wa.me/5521983177702">WhatsApp</a>
<section class="instagram-sitewide" aria-label="Convite para seguir o IBETP no Instagram">
  <div>
    <h2>Acompanhe oportunidades, carreira e educação profissional no Instagram</h2>
    <p>Siga o IBETP para receber conteúdos rápidos sobre cursos, mercado de trabalho, documentação e escolhas profissionais.</p>
  </div>
  <a class="btn" href="https://www.instagram.com/_ibetp/" target="_blank" rel="noopener">Seguir @_ibetp</a>
</section>
<footer><strong>IBETP</strong><p>Educação técnica e profissional com orientação humana, clareza documental e foco no futuro do trabalho.</p></footer>
</body>
</html><?php
}

if ($path === '' || $path === 'index.php') {
    $recent = Database::all("SELECT * FROM posts WHERE status='published' ORDER BY published_at DESC LIMIT 3");
    $products = Database::all("SELECT * FROM products WHERE status='active' ORDER BY updated_at DESC LIMIT 8");
    $products = array_values(array_filter($products, 'product_publicly_visible'));
    $products = merge_official_technical_products($products);
    $products = merge_official_technologist_products($products);
    $products = merge_official_post_technical_products($products);
    $products = merge_official_sequential_products($products);
    $products = merge_official_postgrad_products($products);
    $products = dedupe_post_technical_products($products);
    $products = array_slice($products, 0, 8);
    $featuredCourses = [
        ['/assets/curso-seguranca-trabalho-v3.webp', 'Técnico EAD', 'Segurança do Trabalho', 'Atuação preventiva em indústrias, obras e grandes operações.'],
        ['/assets/curso-administracao-v3.webp', 'Técnico EAD', 'Administração', 'Formação versátil para empresas de todos os setores.'],
        ['/assets/curso-edificacoes-v3.webp', 'Técnico EAD', 'Edificações', 'Planejamento, execução e controle de obras e projetos.'],
        ['/assets/curso-analises-clinicas-v3.webp', 'Certificação', 'Análises Clínicas', 'Reconhecimento formal para profissionais experientes da saúde.'],
        ['/assets/curso-ads-v3.webp', 'Tecnólogo', 'Análise e Desenvolvimento de Sistemas', 'Formação superior prática para o setor de tecnologia.'],
        ['/assets/curso-gestao-ti-v3.webp', 'Tecnólogo', 'Gestão da Tecnologia da Informação', 'Tecnologia, processos e liderança de equipes.'],
        ['/assets/curso-redes-v3.webp', 'Tecnólogo', 'Redes de Computadores', 'Infraestrutura digital para organizações conectadas.'],
        ['/assets/curso-rh-v3.webp', 'Tecnólogo', 'Gestão de Recursos Humanos', 'Pessoas, cultura e estratégia organizacional.'],
    ];
    ob_start(); ?>
    <main>
      <section class="home-hero">
        <img class="home-hero-media" src="<?= e(site_url('/assets/hero-industria-profissionais-tecnicos-premium.png')) ?>" alt="Profissionais técnicos brasileiros atuando em ambiente industrial moderno com equipamentos de segurança">
        <div class="wrap home-hero-content">
          <p class="eyebrow">Educação que move o Brasil</p>
          <h1>Formação técnica e superior para quem constrói o futuro.</h1>
          <p>O IBETP conecta profissionais a formações reconhecidas, com orientação humana, segurança documental e foco nas carreiras mais demandadas do mercado.</p>
          <div class="home-actions">
            <a class="btn primary" href="<?= e(site_url('/cursos')) ?>">Encontre seu curso</a>
            <a class="btn ghost" href="<?= e(site_url('/quem-somos')) ?>">Conheça o IBETP</a>
          </div>
        </div>
      </section>
      <section class="home-stats" aria-label="Indicadores IBETP">
        <div class="wrap stats-grid">
          <div><strong>13 mil+</strong><span>alunos atendidos</span></div>
          <div><strong>5+ anos</strong><span>de atuação educacional</span></div>
          <div><strong>100%</strong><span>validade nacional</span></div>
          <div><strong>Brasil</strong><span>atendimento em todo o país</span></div>
        </div>
      </section>
      <section class="home-section">
        <div class="wrap home-about">
          <div>
            <p class="eyebrow">Institucional</p>
            <h2>O elo entre a sua experiência e uma formação reconhecida.</h2>
            <p>Fundado em 2020, o IBETP é uma agência educacional independente sediada em Nova Iguaçu/RJ. Intermediamos matrículas e certificações junto a instituições credenciadas, acompanhando cada etapa com clareza e responsabilidade.</p>
            <a class="btn primary" href="<?= e(site_url('/quem-somos')) ?>">Nossa história</a>
          </div>
          <aside class="home-dark-card">
            <h3>Orientação que começa antes da matrícula.</h3>
            <p>Nosso papel é simplificar o processo e ajudar cada aluno a escolher uma trajetória compatível com seus objetivos.</p>
            <ol>
              <li>Verificação documental prévia</li>
              <li>Atendimento humano e personalizado</li>
              <li>Instituições parceiras autorizadas</li>
              <li>Acompanhamento do processo de matrícula</li>
            </ol>
          </aside>
        </div>
      </section>
      <section class="home-section home-soft">
        <div class="wrap">
          <div class="home-heading">
            <p class="eyebrow">Onde a formação acontece</p>
            <h2>Profissionais preparados para ambientes que exigem excelência.</h2>
            <p>Do chão de fábrica ao cuidado hospitalar, conectamos educação, tecnologia e trabalho.</p>
          </div>
          <div class="sector-grid">
            <article class="sector-card"><img src="<?= e(site_url('/assets/setor-metalurgica-caldeiraria-premium.png')) ?>" alt="Metalúrgica com caldeiraria, soldagem, fumaça industrial controlada e operação pesada"><div><small>Indústria pesada</small><h3>Metalurgia e caldeiraria</h3><p>Aço, soldagem, fabricação e manutenção industrial.</p></div></article>
            <article class="sector-card"><img src="<?= e(site_url('/assets/setor-petroleo-gas-plataforma-offshore-premium.png')) ?>" alt="Plataforma de petróleo completa com profissionais em uniformes offshore laranja"><div><small>Energia</small><h3>Petróleo e gás</h3><p>Competência técnica em operações críticas.</p></div></article>
            <article class="sector-card"><img src="<?= e(site_url('/assets/setor-saude-hospital-profissionais-premium.png')) ?>" alt="Profissionais de saúde atuando em ambiente hospitalar moderno"><div><small>Saúde</small><h3>Ambientes hospitalares</h3><p>Tecnologia, cuidado e precisão profissional.</p></div></article>
          </div>
        </div>
      </section>
      <section class="home-section">
        <div class="wrap">
          <div class="home-heading">
            <p class="eyebrow">Formações em destaque</p>
            <h2>Cursos para as áreas que mais contratam.</h2>
            <p>Uma seleção de formações técnicas e tecnológicas com aplicação direta no mercado.</p>
          </div>
          <div class="course-carousel" aria-label="Formações em destaque">
            <?php foreach ($featuredCourses as [$image, $type, $title, $description]) : ?>
              <a class="course-card" href="<?= e(site_url('/cursos')) ?>">
                <img src="<?= e(absolute_asset($image)) ?>" alt="<?= e($title) ?>" decoding="async">
                <div><span><?= e($type) ?></span><h3><?= e($title) ?></h3><p><?= e($description) ?></p><b>Ver curso ?</b></div>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="carousel-hint">Arraste para o lado para conhecer as formações.</p>
          <a class="btn primary" href="<?= e(site_url('/cursos')) ?>">Ver todos os cursos</a>
        </div>
      </section>
      <section class="home-section home-soft">
        <div class="wrap">
          <div class="home-heading">
            <p class="eyebrow">Conhecimento em movimento</p>
            <h2>Conteúdos para orientar sua carreira.</h2>
          </div>
          <div class="cards cards-premium article-cards"><?php foreach ($recent as $p): $prefix = $p['type']==='glossary' ? 'glossario' : 'blog'; ?><a class="card compact-card" href="<?= e(site_url('/' . $prefix . '/' . $p['slug'])) ?>"><img src="<?= e(absolute_asset(premium_post_image($p))) ?>" alt="<?= e($p['featured_alt'] ?: $p['title']) ?>"><div class="card-body"><em><?= e($prefix === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($p['title']) ?></strong><span><?= e(card_summary($p, 78)) ?></span><b>Ler conteúdo ?</b></div></a><?php endforeach; ?></div>
        </div>
      </section>
      <section class="home-section">
        <div class="wrap home-end">
          <div>
            <p class="eyebrow">Orientação personalizada</p>
            <h2>Seu próximo passo profissional começa com uma escolha segura.</h2>
            <p>Converse com a equipe do IBETP e encontre uma formação compatível com sua experiência, seus objetivos e o mercado de trabalho.</p>
          </div>
          <a class="btn primary" href="https://wa.me/5521983177702">Conversar no WhatsApp</a>
        </div>
      </section>
    </main>
    <?php
    $schemas = [[
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => site_url('/#webpage'),
        'url' => site_url('/'),
        'name' => config()['site_name'],
        'isPartOf' => ['@id' => site_url('/#website')],
        'about' => ['@id' => site_url('/#organization')]
    ]];
    layout(config()['site_name'], 'Instituto Brasileiro de Educação Técnica e Profissional.', ob_get_clean(), null, false, $schemas); exit;
    ?>
    <main>
      <section class="hero">
        <div>
          <p class="eyebrow">Educação que move o Brasil</p>
          <h1>Formação técnica e profissional para quem constrói o futuro.</h1>
          <p>O IBETP conecta pessoas, cursos e oportunidades com orientação clara, tecnologia e foco real no mercado de trabalho.</p>
          <a class="btn primary" href="<?= e(site_url('/cursos')) ?>">Encontrar meu curso</a>
          <a class="btn ghost" href="<?= e(site_url('/quem-somos')) ?>">Conheça o IBETP</a>
        </div>
      </section>
      <section class="grid-section">
        <h2>Cursos e produtos em destaque</h2>
        <div class="cards"><?php foreach ($products as $p): $productCardImage = product_image_url($p); ?><a class="card" href="<?= e(site_url('/produto/' . $p['slug'])) ?>"><?php if ($productCardImage !== ''): ?><img src="<?= e($productCardImage) ?>" alt="<?= e($p['title']) ?>"><?php endif; ?><strong><?= e($p['title']) ?></strong><span><?= e($p['category']) ?></span></a><?php endforeach; ?></div>
      </section>
      <section class="grid-section">
        <h2>Conteúdos recentes</h2>
        <div class="cards"><?php foreach ($recent as $p): $prefix = $p['type']==='glossary' ? 'glossario' : 'blog'; ?><a class="card" href="<?= e(site_url('/' . $prefix . '/' . $p['slug'])) ?>"><img src="<?= e(absolute_asset(premium_post_image($p))) ?>" alt="<?= e($p['featured_alt'] ?: $p['title']) ?>"><strong><?= e($p['title']) ?></strong><span><?= e($p['excerpt'] ?: excerpt($p['content'])) ?></span></a><?php endforeach; ?></div>
      </section>
    </main>
    <?php
    $schemas = [[
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => site_url('/#webpage'),
        'url' => site_url('/'),
        'name' => config()['site_name'],
        'isPartOf' => ['@id' => site_url('/#website')],
        'about' => ['@id' => site_url('/#organization')]
    ]];
    layout(config()['site_name'], 'Instituto Brasileiro de Educação Técnica e Profissional.', ob_get_clean(), null, false, $schemas); exit;
}

if ($path === 'busca') {
    $q = trim($_GET['q'] ?? '');
    $items = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $items = Database::all("SELECT title, slug, type, excerpt, content, featured_image FROM posts WHERE status='published' AND noindex=0 AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?) ORDER BY published_at DESC LIMIT 30", [$like, $like, $like]);
    }
    ob_start(); ?><main><section class="page-hero"><p class="eyebrow">Busca IBETP</p><h1>Resultados para <?= e($q ?: 'sua pesquisa') ?></h1><form action="<?= e(site_url('/busca')) ?>"><input name="q" value="<?= e($q) ?>" placeholder="Buscar no site"><button class="btn primary">Buscar</button></form></section><section class="cards archive article-cards"><?php foreach ($items as $item): $prefix = $item['type']==='glossary'?'glossario':'blog'; ?><a class="card compact-card" href="<?= e(site_url('/' . $prefix . '/' . $item['slug'])) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['title']) ?>"><div class="card-body"><em><?= e($prefix === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo ?</b></div></a><?php endforeach; ?></section></main><?php
    layout('Busca IBETP', 'Pesquise conteúdos, cursos e temas profissionais no site do IBETP.', ob_get_clean(), null, true); exit;
}

if ($path === 'blog' || $path === 'glossario' || $path === 'cursos') {
    $type = $path === 'glossario' ? 'glossary' : 'post';
    if ($path === 'cursos') {
        $items = Database::all("SELECT * FROM products WHERE status='active' ORDER BY title");
        $items = merge_official_technical_products($items);
        $items = merge_official_technologist_products($items);
        $items = merge_official_post_technical_products($items);
        $items = merge_official_sequential_products($items);
        $items = merge_official_postgrad_products($items);
        $items = array_values(array_filter($items, 'product_publicly_visible'));
        $items = array_values(array_filter($items, 'technical_ead_drive_slug_allowed'));
        $items = dedupe_post_technical_products($items);
        $heading = 'Cursos e produtos IBETP';
    } else {
        $items = Database::all("SELECT * FROM posts WHERE type=? AND status='published' ORDER BY published_at DESC", [$type]);
        $heading = $path === 'glossario' ? 'Glossário profissional' : 'Blog IBETP';
    }
    $courseModalities = [];
    $courseAreas = [];
    if ($path === 'cursos') {
        $courseModalities = product_catalog_filter_groups($items, 'product_category_label', 'product_category_sort_weight');
        $courseAreas = product_catalog_filter_groups($items, 'product_area_label', 'product_area_sort_weight');
    }
    ob_start(); ?><main><section class="page-hero <?= $path === 'cursos' ? 'courses-hero' : '' ?>"><p class="eyebrow"><?= $path === 'cursos' ? 'Vitrine IBETP' : 'IBETP' ?></p><h1><?= e($heading) ?></h1><p><?= $path === 'cursos' ? 'Escolha sua formação com clareza: catálogo organizado por modalidade e área profissional, atendimento consultivo e caminhos de matrícula para avançar com segurança.' : 'Conteúdos organizados, claros e orientados para decisão.' ?></p></section><?php if ($path === 'cursos'): ?><section class="course-search-panel" aria-label="Pesquisar cursos"><div><span>Busca inteligente</span><h2>Encontre o curso certo por nome, área ou modalidade.</h2><p>Use a pesquisa ou combine os filtros. O catálogo mostra apenas cursos ativos e organizados com atendimento do IBETP.</p></div><div class="course-search-controls"><label for="course-search">Pesquisar curso</label><div class="course-search-input-wrap"><input id="course-search" type="search" placeholder="Ex.: Administração, Mecatrônica, Saúde, Tecnologia..." autocomplete="off"><button type="button" id="course-search-submit">Pesquisar cursos</button><button type="button" id="course-search-clear">Limpar</button></div><small id="course-search-count"><?= count($items) ?> cursos disponíveis</small></div></section><div class="course-filter-block"><p class="course-filter-heading">Filtrar por modalidade</p><?= render_course_filter_nav($courseModalities, 'modalidade', 'Todas as modalidades', count($items)) ?><p class="course-filter-heading">Filtrar por área profissional</p><?= render_course_filter_nav($courseAreas, 'area', 'Todas as áreas', count($items)) ?></div><?php endif; ?><section id="<?= $path === 'cursos' ? 'course-results' : '' ?>" class="cards archive <?= $path === 'cursos' ? 'course-archive' : 'article-cards' ?>">
    <?php foreach ($items as $item): $url = $path === 'cursos' ? '/produto/' . $item['slug'] : '/' . $path . '/' . $item['slug']; ?>
      <?php if ($path === 'cursos'): ?>
        <a class="card course-list-card" href="<?= e(site_url($url)) ?>" data-course-card data-modalidade="<?= e(ibetp_slug_key(product_category_label($item))) ?>" data-area="<?= e(ibetp_slug_key(product_area_label($item))) ?>" data-search="<?= e(product_catalog_search_text($item)) ?>"><div class="card-body"><em><?= e(product_category_label($item)) ?></em><small class="course-area-pill"><?= e(product_area_label($item)) ?></small><strong><?= e($item['title']) ?></strong><span><?= e(product_catalog_card_summary($item)) ?></span><div class="course-meta course-meta-payment"><small><?= e(product_investment_label($item)) ?></small><div><span><?= e(product_payment_condition_label($item)) ?></span><b>Ver detalhes ?</b></div></div></div></a>
      <?php else: ?>
        <a class="card compact-card" href="<?= e(site_url($url)) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['featured_alt'] ?? $item['title']) ?>"><div class="card-body"><em><?= e($path === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo ?</b></div></a>
      <?php endif; ?>
    <?php endforeach; ?></section><?php if ($path === 'cursos'): ?><p id="course-empty-state" class="course-empty-state" hidden>Nenhum curso encontrado com esse termo. Tente pesquisar por área, profissão ou fale com o IBETP pelo WhatsApp.</p><script>
      (() => {
        const input = document.getElementById('course-search');
        const cards = [...document.querySelectorAll('[data-course-card]')];
        const count = document.getElementById('course-search-count');
        const empty = document.getElementById('course-empty-state');
        const clear = document.getElementById('course-search-clear');
        const submit = document.getElementById('course-search-submit');
        const filterButtons = [...document.querySelectorAll('[data-course-filter]')];
        const activeFilters = { modalidade: 'all', area: 'all' };
        const normalize = value => (value || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        const update = () => {
          const term = normalize(input.value);
          let visible = 0;
          cards.forEach(card => {
            const haystack = normalize(card.dataset.search);
            const modalityMatch = activeFilters.modalidade === 'all' || card.dataset.modalidade === activeFilters.modalidade;
            const areaMatch = activeFilters.area === 'all' || card.dataset.area === activeFilters.area;
            const show = modalityMatch && areaMatch && (term === '' || haystack.includes(term));
            card.hidden = !show;
            if (show) visible++;
          });
          count.textContent = visible + (visible === 1 ? ' curso encontrado' : ' cursos encontrados');
          empty.hidden = visible !== 0;
        };
        input.addEventListener('input', update);
        submit?.addEventListener('click', () => { input.focus(); update(); });
        clear?.addEventListener('click', () => { input.value = ''; input.focus(); update(); });
        filterButtons.forEach(button => button.addEventListener('click', () => {
          const group = button.dataset.courseFilter || 'modalidade';
          activeFilters[group] = button.dataset.courseValue || 'all';
          filterButtons
            .filter(item => (item.dataset.courseFilter || 'modalidade') === group)
            .forEach(item => item.classList.toggle('active', item === button));
          update();
        }));
        update();
      })();
    </script><?php endif; ?></main><?php
    $schemas = [[
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => $heading,
        'url' => current_url_clean(),
        'isPartOf' => ['@id' => site_url('/#website')]
    ], breadcrumb_schema(['Início' => site_url('/'), $heading => current_url_clean()])];
    layout($heading, 'Arquivo de conteúdos e cursos do IBETP.', ob_get_clean(), null, false, $schemas); exit;
}

if (preg_match('#^(blog|glossario)/([^/]+)$#', $path, $m)) {
    $type = $m[1] === 'glossario' ? 'glossary' : 'post';
    $post = Database::one("SELECT * FROM posts WHERE slug=? AND type=? AND status='published'", [$m[2], $type]);
    if (!$post) { http_response_code(404); layout('Página não encontrada', 'Conteúdo não encontrado.', '<main><h1>404</h1></main>', null, true); exit; }
    $content = add_heading_ids($post['content']);
    ob_start(); ?><main class="article">
      <section class="article-hero">
        <p class="eyebrow"><?= $type === 'glossary' ? 'Glossário' : 'Blog' ?></p>
        <h1><?= e($post['title']) ?></h1>
        <p><?= e($post['excerpt'] ?: excerpt($post['content'], 240)) ?></p>
        <?php if (premium_post_image($post)): ?><img src="<?= e(absolute_asset(premium_post_image($post))) ?>" alt="<?= e($post['featured_alt'] ?: $post['title']) ?>"><?php endif; ?>
      </section>
      <?= render_toc($content) ?>
      <article class="article-body"><?= $content ?></article>
    </main><?php
    $prefix = $type === 'glossary' ? 'glossario' : 'blog';
    $articleSchema = [
        '@context' => 'https://schema.org',
        '@type' => $type === 'glossary' ? 'DefinedTerm' : 'Article',
        '@id' => site_url($prefix . '/' . $post['slug'] . '#article'),
        'headline' => $post['title'],
        'description' => $post['seo_description'] ?: excerpt($post['content'], 155),
        'url' => site_url($prefix . '/' . $post['slug']),
        'image' => absolute_asset(premium_post_image($post)),
        'datePublished' => $post['published_at'] ?: $post['created_at'],
        'dateModified' => $post['updated_at'],
        'author' => ['@type' => 'Organization', '@id' => site_url('/#organization')],
        'publisher' => ['@id' => site_url('/#organization')],
        'mainEntityOfPage' => site_url($prefix . '/' . $post['slug'])
    ];
    if ($type === 'glossary') {
        $articleSchema['name'] = $post['title'];
        $articleSchema['termCode'] = $post['focus_keyword'] ?: $post['slug'];
    }
    $archiveLabel = $type === 'glossary' ? 'Glossário' : 'Blog';
    $schemas = [$articleSchema, breadcrumb_schema(['Início' => site_url('/'), $archiveLabel => site_url('/' . $prefix), $post['title'] => site_url($prefix . '/' . $post['slug'])])];
    $faqSchema = extract_faq_schema($content, site_url($prefix . '/' . $post['slug']));
    if ($faqSchema) $schemas[] = $faqSchema;
    layout($post['seo_title'] ?: $post['title'], $post['seo_description'] ?: excerpt($post['content'], 155), ob_get_clean(), premium_post_image($post), (bool)$post['noindex'], $schemas); exit;
}

if (preg_match('#^produto/([^/]+)$#', $path, $m)) {
    $isCheckoutTestProduct = $m[1] === 'teste-checkout-6-reais';
    $product = $isCheckoutTestProduct ? checkout_test_product() : Database::one("SELECT * FROM products WHERE slug=? AND status='active'", [$m[1]]);
    $officialTechnologist = official_technologist_product_by_slug($m[1]);
    if (!$isCheckoutTestProduct && $officialTechnologist) $product = $officialTechnologist;
    $officialPostTechnical = official_post_technical_product_by_slug($m[1]);
    if (!$isCheckoutTestProduct && $officialPostTechnical) $product = $officialPostTechnical;
    $officialSequential = official_sequential_product_by_slug($m[1]);
    if (!$isCheckoutTestProduct && $officialSequential) $product = $officialSequential;
    $officialPostgrad = official_postgrad_product_by_slug($m[1]);
    if (!$isCheckoutTestProduct && $officialPostgrad) $product = $officialPostgrad;
    if (!$product && !$isCheckoutTestProduct) $product = official_technical_product_by_slug($m[1]);
    if (!$product || !product_publicly_visible($product)) { http_response_code(404); layout('Produto não encontrado', 'Produto não encontrado.', '<main><h1>404</h1></main>', null, true); exit; }
    $isCompetencyCertification = product_is_competency_certification($product);
    $isPostTechnical = product_is_post_technical($product);
    $isSequential = product_is_sequential($product);
    $isPostgrad = product_is_postgrad($product);
    $academic = product_academic_profile($product);
    $internshipText = $academic ? trim((string)($academic['internship'] ?? '')) : '';
    $tccText = $academic ? trim((string)($academic['tcc'] ?? '')) : '';
    $hasMandatoryInternship = $internshipText !== '' && str_contains(ibetp_slug_key($internshipText), 'estagio-supervisionado-obrigatorio');
    $internshipLabel = $internshipText !== '' ? $internshipText : 'Estágio supervisionado obrigatório conforme matriz oficial.';
    ob_start(); ?><main class="product">
      <section class="product-hero">
        <div class="product-copy">
          <p class="eyebrow"><?= e(product_category_label($product)) ?></p>
          <h1><?= e($product['title']) ?></h1>
          <p class="lead"><?= e($product['short_description'] ?: 'Formação organizada para quem busca avançar com segurança profissional.') ?></p>
          <div class="product-badges"><span>Atendimento consultivo</span><span>Orientação documental</span><span>Compra segura</span></div>
          <div class="product-actions">
            <?php if (product_checkout_enabled($product)): ?>
              <form method="post" action="<?= e(site_url('/checkout')) ?>"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><button class="btn primary"><?= e(product_primary_payment_label($product)) ?></button></form>
            <?php endif; ?>
            <a class="btn <?= product_checkout_enabled($product) ? 'outline' : 'primary' ?>" href="<?= e(whatsapp_course_url($product)) ?>" target="_blank" rel="noopener">Falar no WhatsApp sobre este curso</a>
            <a class="btn outline" href="<?= e(site_url('/cursos')) ?>">Ver catálogo</a>
          </div>
        </div>
        <aside class="product-visual-card">
          <?= product_visual_art($product, 'hero') ?>
        </aside>
      </section>
      <section class="product-trust wrap">
        <?php if ($isCompetencyCertification): ?>
        <div><strong>01</strong><span>Análise da experiência profissional comprovada na área.</span></div>
        <div><strong>02</strong><span>Prova EAD com 10 questões, até 3 tentativas e média mínima 7,0.</span></div>
        <div><strong>03</strong><span>Diploma técnico em até 20 dias úteis após aprovação e análise documental.</span></div>
        <?php elseif ($isPostTechnical): ?>
        <div><strong>01</strong><span>Matriz curricular oficial apresentada na página.</span></div>
        <div><strong>02</strong><span>Investimento de R$ 799,00 à vista ou parcelado com juros no cartão.</span></div>
        <div><strong>03</strong><span>Duração e carga horária conforme informativo acadêmico.</span></div>
        <?php elseif ($isSequential): ?>
        <div><strong>01</strong><span>Matriz curricular oficial de 560 horas apresentada na página.</span></div>
        <div><strong>02</strong><span>Modalidade 100% EAD, conforme regras do curso Superior Sequencial.</span></div>
        <div><strong>03</strong><span>Investimento de R$ 699,00 à vista ou parcelado com juros no cartão.</span></div>
        <?php elseif ($isPostgrad): ?>
        <div><strong>01</strong><span>Pós-graduação lato sensu EAD com titulação de Especialista.</span></div>
        <div><strong>02</strong><span>Investimento de R$ 799,00 à vista ou parcelado com juros no cartão.</span></div>
        <div><strong>03</strong><span>Duração de 4 a 12 meses, com trilhas, videoaulas e avaliações.</span></div>
        <?php else: ?>
        <div><strong>01</strong><span>Início em até 24 horas úteis após a confirmação do pagamento.</span></div>
        <div><strong>02</strong><span>Receba orientação sobre matrícula e próximos passos.</span></div>
        <div><strong>03</strong><span>Escolha com apoio humano e foco profissional.</span></div>
        <?php endif; ?>
      </section>
      <section class="product-conversion wrap">
        <div class="conversion-copy">
          <p class="section-kicker">Decisão com clareza</p>
          <h2>Uma página feita para você entender o curso antes de pagar.</h2>
          <p><?= e($isCompetencyCertification ? 'O IBETP organiza as informações essenciais — investimento, requisitos, documentação, prova, solicitação de diploma e base legal — para que a certificação aconteça com segurança e sem surpresa.' : ($isPostTechnical ? 'O IBETP organiza as informações essenciais — investimento, duração, carga horária, matriz curricular oficial e atendimento — para que a matrícula aconteça com segurança e sem surpresa.' : ($isSequential ? 'O IBETP organiza as informações essenciais — investimento, requisito de Ensino Médio, modalidade 100% EAD, matriz curricular oficial e documentação — para que a matrícula aconteça com segurança e sem surpresa.' : ($isPostgrad ? 'O IBETP organiza as informações essenciais — investimento, modalidade EAD, titulação, documentos e funcionamento acadêmico — para que a pós-graduação aconteça com segurança e sem surpresa.' : 'O IBETP organiza as informações essenciais — investimento, início, documentação, grade, estágio e atendimento — para que a matrícula aconteça com segurança e sem surpresa.')))) ?></p>
        </div>
        <div class="conversion-points">
          <div><strong>O que você confirma aqui</strong><span>Valor, formato de pagamento, carga horária, duração e caminho de atendimento.</span></div>
          <div><strong>O que você confere antes da matrícula</strong><span><?= e($isCompetencyCertification ? 'Experiência mínima, documentação necessária, prova e fluxo até a emissão do diploma.' : ($isPostTechnical ? 'Matriz curricular oficial, duração, carga horária e escopo da especialização técnica.' : ($isSequential ? 'Módulo básico, disciplinas seletivas, carga horária total, requisito de Ensino Médio e documentos de matrícula.' : ($isPostgrad ? 'Área do curso, investimento, titulação de Especialista, duração, carga horária e documentação necessária.' : 'Grade curricular, estágio quando obrigatório e documentos acadêmicos relevantes.')))) ?></span></div>
          <div><strong>Como seguir com segurança</strong><span><?= e($isCompetencyCertification ? 'Confirme sua experiência, envie a documentação e fale com o IBETP para tirar dúvidas antes de avançar.' : ($isPostTechnical ? 'Compre com segurança pelo site ou fale com o IBETP para confirmar documentação e próximos passos.' : ($isSequential ? 'Compre pelo site ou fale com o IBETP para confirmar documentação, matrícula e percurso acadêmico.' : ($isPostgrad ? 'Compre pelo site ou fale com o IBETP para confirmar documentação, acesso e percurso acadêmico.' : 'Pague a etapa inicial pelo site ou fale com o IBETP para tirar dúvidas antes de avançar.')))) ?></span></div>
        </div>
      </section>
      <article class="article-body product-detail">
        <div class="premium-product-layout">
          <section class="premium-section">
            <div class="section-kicker">Por que essa formação importa</div>
            <h2>Formação para quem busca atuar com segurança profissional.</h2>
            <p><?= e($product['short_description'] ?: excerpt(strip_tags($product['description']), 260)) ?></p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Antes da matrícula</strong><span><?= e($isCompetencyCertification ? 'Você entende valores, experiência mínima, documentos e critérios da prova antes de avançar.' : ($isPostTechnical ? 'Você entende investimento, matriz curricular, duração e carga horária antes de avançar.' : ($isSequential ? 'Você confere investimento, requisito de Ensino Médio e matriz curricular oficial antes de avançar.' : ($isPostgrad ? 'Você confere investimento, documentos de graduação, área do curso e regras gerais antes de avançar.' : 'Você entende valores, requisitos e próximos passos antes de avançar.')))) ?></span></div>
              <div class="premium-card"><strong>Durante o processo</strong><span><?= e($isCompetencyCertification ? 'O atendimento do IBETP orienta documentação, acesso ao Portal do Aluno e solicitação do diploma.' : ($isPostTechnical ? 'O atendimento do IBETP orienta matrícula, documentação e encaminhamento acadêmico.' : ($isSequential ? 'O atendimento do IBETP orienta documentação, matrícula e percurso acadêmico 100% EAD.' : ($isPostgrad ? 'O atendimento do IBETP orienta matrícula, documentação e acesso acadêmico conforme o curso escolhido.' : 'O atendimento do IBETP orienta documentação, acesso e etapas acadêmicas.')))) ?></span></div>
              <div class="premium-card"><strong>Depois da confirmação</strong><span><?= e($isCompetencyCertification ? 'O diploma técnico é emitido em até 20 dias úteis após aprovação da prova e análise documental.' : ($isPostTechnical ? 'A equipe orienta os próximos passos conforme o curso escolhido e a documentação necessária.' : ($isSequential ? 'Você segue o percurso acadêmico conforme as regras do curso Superior Sequencial.' : ($isPostgrad ? 'Você segue os módulos, unidades e avaliações no ambiente EAD, com acompanhamento acadêmico.' : 'O início ocorre em até 24 horas úteis após a confirmação do pagamento.')))) ?></span></div>
            </div>
          </section>
          <section class="premium-price">
            <div><small>Investimento</small><strong><?= e(product_investment_label($product)) ?></strong><span><?= e(product_investment_text($product)) ?></span></div>
          </section>
          <?php if ($isCompetencyCertification): ?>
          <section class="premium-section competency-official">
            <div class="section-kicker">Certificação Técnica por Competência</div>
            <h2>Reconhecimento da experiência profissional comprovada.</h2>
            <p>A Certificação Técnica por Competência é destinada ao profissional que já atua no mercado de trabalho e possui, no mínimo, 2 anos de experiência comprovada na área em que deseja obter o diploma técnico. As certificações são reconhecidas pelo SISTEC.</p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Público-alvo</strong><span>Profissional com experiência prática comprovada de 2 anos ou mais na área pretendida.</span></div>
              <div class="premium-card"><strong>Modalidade</strong><span>EAD, com prova realizada pelo Portal do Aluno.</span></div>
              <div class="premium-card"><strong>Duração</strong><span>Diploma técnico em até 20 dias úteis após aprovação da prova e análise documental.</span></div>
              <div class="premium-card"><strong>Reconhecimento</strong><span>Processo reconhecido pelo SISTEC, conforme regras da educação profissional técnica.</span></div>
            </div>
          </section>
          <section class="premium-section competency-flow">
            <div class="section-kicker">Fluxo da certificação</div>
            <h2>Etapas do processo</h2>
            <div class="premium-steps">
              <div><span>Análise da experiência profissional comprovada na área.</span></div>
              <div><span>Matrícula no SGU com envio da documentação exigida.</span></div>
              <div><span>Liberação da prova no Portal do Aluno.</span></div>
              <div><span>Após aprovação, solicitação do diploma em: Solicitações → Certificações → Solicitação de Diploma (1ª via PDF).</span></div>
              <div><span>Emissão do diploma em até 20 dias úteis após aprovação da prova e da documentação.</span></div>
            </div>
          </section>
          <section class="premium-section competency-exam">
            <div class="section-kicker">Como será a prova?</div>
            <h2>Prova objetiva, com critérios claros de aprovação.</h2>
            <div class="premium-grid">
              <div class="premium-card"><strong>10 questões</strong><span>A avaliação é composta por 10 questões objetivas.</span></div>
              <div class="premium-card"><strong>Até 3 tentativas</strong><span>O aluno possui até 3 tentativas para alcançar o desempenho necessário.</span></div>
              <div class="premium-card"><strong>Média mínima 7,0</strong><span>A aprovação exige média mínima de 7,0 pontos.</span></div>
            </div>
          </section>
          <section class="premium-section competency-docs">
            <div class="section-kicker">Documentação</div>
            <h2>Documentos necessários para análise</h2>
            <div class="document-list">
              <div>Certidão de Nascimento ou Casamento.</div>
              <div>Documento com foto: CNH ou RG e CPF.</div>
              <div>Comprovante de residência.</div>
              <div>Título de Eleitor.</div>
              <div>Certificado de Reservista para homens de 18 a 45 anos.</div>
              <div>Histórico e Certificado do Ensino Médio.</div>
              <div>Carteira de trabalho digital e/ou declaração de experiência profissional emitida pela empresa da área, com funções desempenhadas e período de 2 anos ou mais de experiência comprovada. Caso o contrato ainda esteja em aberto, providenciar declaração de vínculo junto à empresa.</div>
              <div>Contrato Social ou MEI, caso seja sócio ou dono de empresa na área, com envio do CNPJ. A data da situação cadastral precisa comprovar 2 anos ou mais de CNPJ ativo.</div>
            </div>
          </section>
          <section class="premium-section competency-law">
            <div class="section-kicker">Base legal</div>
            <h2>Reconhecimento legal da experiência profissional</h2>
            <p>A Certificação por Competência é um processo legal, amparado pela Lei Federal 9.394/96, artigo 41, que reconhece a experiência profissional prática de trabalhadores para obtenção de diploma técnico oficial, sem necessidade de cursar aulas tradicionais.</p>
          </section>
          <?php elseif ($academic): ?>
          <section class="premium-section academic-official">
            <div class="section-kicker">Grade curricular oficial</div>
            <h2>Grade curricular</h2>
            <div class="premium-grid academic-summary">
              <div class="premium-card"><strong>Duração</strong><span><?= e($academic['duration']) ?></span></div>
              <div class="premium-card"><strong>Carga horária</strong><span><?= e($academic['workload']) ?></span></div>
              <?php if ($internshipText !== ''): ?><div class="premium-card"><strong>Estágio</strong><span><?= e($internshipText) ?></span></div><?php endif; ?>
              <?php if ($tccText !== ''): ?><div class="premium-card"><strong>TCC</strong><span><?= e($tccText) ?></span></div><?php endif; ?>
            </div>
            <?php if (!$hasMandatoryInternship && trim((string)($academic['presence'] ?? '')) !== ''): ?>
            <div class="info-card official-presence"><strong>Presencialidade</strong><p><?= e($academic['presence']) ?></p></div>
            <?php endif; ?>
            <?php if (empty($academic['modules']) && trim((string)($academic['curriculum_note'] ?? '')) !== ''): ?>
              <div class="info-card academic-pending"><strong>Grade curricular oficial</strong><p><?= e($academic['curriculum_note']) ?></p></div>
            <?php elseif (empty($academic['modules'])): ?>
              <div class="info-card academic-pending"><strong>Grade curricular indisponível nesta página</strong><p>Este curso ainda não tem matriz individual vinculada ao catálogo público. O IBETP informa os dados acadêmicos oficiais antes da matrícula.</p></div>
            <?php endif; ?>
            <?php foreach ($academic['modules'] as $module): ?>
              <div class="curriculum-module">
                <h3><?= e($module[0]) ?><?= $module[1] !== '' ? ' — ' . e($module[1]) : '' ?></h3>
                <table class="curriculum-table">
                  <thead><tr><th>Disciplina</th><th>Carga horária</th></tr></thead>
                  <tbody>
                    <?php foreach ($module[2] as $subject): ?>
                      <tr><td><?= e($subject[0]) ?></td><td><?= e($subject[1] ?: 'Conforme matriz') ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          </section>
          <?php if ($isSequential): ?>
          <section class="premium-section sequential-official">
            <div class="section-kicker">Regras do Superior Sequencial</div>
            <h2>Curso Superior Sequencial de Complementação de Estudos.</h2>
            <div class="premium-grid">
              <div class="premium-card"><strong>Modalidade</strong><span>100% EAD, conforme informativo oficial do curso.</span></div>
              <div class="premium-card"><strong>Requisito</strong><span>Certificado de conclusão do Ensino Médio para matrícula.</span></div>
              <div class="premium-card"><strong>Módulo I</strong><span>Quatro disciplinas obrigatórias comuns a todos os cursos superiores sequenciais.</span></div>
              <div class="premium-card"><strong>Módulo II</strong><span>Escolha de três disciplinas seletivas relacionadas ao curso, totalizando 240 horas. Disciplinas extras podem gerar taxa adicional.</span></div>
            </div>
          </section>
          <section class="premium-section sequential-docs">
            <div class="section-kicker">Documentos para matrícula</div>
            <h2>Documentação necessária</h2>
            <div class="document-list">
              <div>Formulário de Inscrição — Superior Sequencial.</div>
              <div>RG e CPF.</div>
              <div>Certidão de Nascimento ou Casamento.</div>
              <div>Comprovante de residência.</div>
              <div>Foto recente.</div>
              <div>Certificado de conclusão do Ensino Médio.</div>
            </div>
          </section>
          <section class="premium-section sequential-law">
            <div class="section-kicker">Base legal</div>
            <h2>Fundamentação acadêmica informada no material do curso</h2>
            <div class="premium-grid">
              <div class="premium-card"><strong>LDB 9.394/96</strong><span>Artigo 44.</span></div>
              <div class="premium-card"><strong>Resolução nº 1</strong><span>22 de maio de 2017.</span></div>
              <div class="premium-card"><strong>Lei nº 11.632</strong><span>27 de dezembro de 2007.</span></div>
              <div class="premium-card"><strong>Resolução CNE/CES nº 1</strong><span>27 de janeiro de 1999.</span></div>
              <div class="premium-card"><strong>Parecer CNE/CES nº 968/98</strong><span>Diretrizes relacionadas aos cursos sequenciais.</span></div>
            </div>
          </section>
          <?php endif; ?>
          <?php if ($isPostgrad): ?>
          <section class="premium-section postgrad-official">
            <div class="section-kicker">Pós-Graduação EAD</div>
            <h2>Especialização lato sensu com foco profissional.</h2>
            <div class="premium-grid">
              <div class="premium-card"><strong>Titulação</strong><span>Especialista.</span></div>
              <div class="premium-card"><strong>Modalidade</strong><span>EAD, com videoaulas gravadas e materiais digitais.</span></div>
              <div class="premium-card"><strong>Duração</strong><span>4 a 12 meses.</span></div>
              <div class="premium-card"><strong>Carga horária</strong><span>400 a 800 horas, conforme o curso.</span></div>
            </div>
          </section>
          <section class="premium-section postgrad-method">
            <div class="section-kicker">Metodologia</div>
            <h2>Como funciona a formação</h2>
            <p>Os cursos são organizados por módulos e unidades. O aluno estuda por trilhas de aprendizagem, videoaulas gravadas, e-books ou apostilas em PDF, slides, mapas conceituais, simulados e atividades avaliativas, conforme o ambiente acadêmico do curso.</p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Materiais digitais</strong><span>Conteúdos organizados para estudo online, com apoio de materiais de fixação.</span></div>
              <div class="premium-card"><strong>Avaliações</strong><span>Atividades avaliativas por unidade, com até 3 tentativas.</span></div>
              <div class="premium-card"><strong>Média mínima</strong><span>A aprovação exige desempenho mínimo de 7,0 pontos.</span></div>
            </div>
          </section>
          <section class="premium-section postgrad-docs">
            <div class="section-kicker">Documentos para matrícula</div>
            <h2>Documentação necessária</h2>
            <div class="document-list">
              <div>CPF.</div>
              <div>Cédula de Identidade (RG).</div>
              <div>Certidão de Nascimento ou Casamento.</div>
              <div>Comprovante de residência.</div>
              <div>Histórico escolar da graduação.</div>
              <div>Diploma da graduação, frente e verso.</div>
              <div>Declaração de conclusão do curso de graduação, quando o aluno ainda não possuir o diploma no ato da matrícula.</div>
            </div>
          </section>
          <?php endif; ?>
          <?php endif; ?>
          <?php if (!$academic && !$isCompetencyCertification && (product_is_technical_ead($product) || product_is_technologist($product))): ?>
          <section class="premium-section academic-pending">
            <div class="section-kicker">Dados acadêmicos em validação</div>
            <h2>Grade curricular oficial em conferência</h2>
            <p>Este produto está preparado para exibir grade curricular exata, estágio obrigatório ou dispensa de estágio e regras de presencialidade assim que a matriz oficial correspondente for vinculada ao cadastro. O IBETP não publica grade sugerida como se fosse oficial.</p>
          </section>
          <?php endif; ?>
          <?php if (product_is_technical_ead($product)): ?>
          <?php if ($hasMandatoryInternship): ?>
          <section class="premium-section internship-official">
            <div class="section-kicker">Estágio supervisionado obrigatório</div>
            <h2>Como funciona o estágio deste Curso Técnico EAD</h2>
            <p>Este curso possui <?= e(mb_strtolower($internshipLabel, 'UTF-8')) ?>, conforme a matriz oficial. O estágio deve ser realizado em local relacionado à área de formação, com supervisão de profissional formado na mesma área do curso. A documentação fica disponível na disciplina de estágio dentro da plataforma acadêmica.</p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Responsabilidade do aluno</strong><span>O aluno busca o local de estágio compatível com sua formação e segue a orientação documental recebida no ambiente acadêmico.</span></div>
              <div class="premium-card"><strong>Documentação</strong><span>Termo de convênio, termo de compromisso, plano de atividade e relatório final devem ser preenchidos, assinados e carimbados quando aplicável.</span></div>
              <div class="premium-card"><strong>Análise acadêmica</strong><span>Após o envio correto dos documentos pela plataforma, a análise pode levar até 5 dias úteis.</span></div>
            </div>
            <div class="info-card official-presence"><strong>Já trabalha na área?</strong><p>Quando o aluno já atua na área do curso, pode solicitar convalidação de estágio mediante documentação formal. A regra informada no material de estágio permite aproveitar até 6 horas por dia trabalhado; fins de semana e feriados não são contabilizados.</p></div>
          </section>
          <?php endif; ?>
          <section class="premium-section">
            <div class="section-kicker">Pagamento do Curso Técnico EAD</div>
            <h2>1ª mensalidade no site, demais por link de pagamento</h2>
            <p>Para iniciar sua matrícula, você paga a 1ª mensalidade de R$ 99,90 via Pix diretamente no site do IBETP, no ato da matrícula. O acesso/orientação inicial acontece em até 24 horas úteis após a confirmação do pagamento. As demais mensalidades são enviadas mensalmente por e-mail, WhatsApp ou SMS com link de pagamento e opções de boleto, cartão de crédito e Pix, em até 5 dias antes do vencimento.</p>
            <div class="premium-steps">
              <div><span>1ª mensalidade: pagamento via Pix no site do IBETP.</span></div>
              <div><span>12 mensalidades de R$ 99,90, com a primeira paga no ato da matrícula.</span></div>
              <div><span>Demais mensalidades: link enviado mensalmente por e-mail, WhatsApp ou SMS, com boleto, cartão de crédito e Pix.</span></div>
            </div>
          </section>
          <?php endif; ?>
          <?php if (product_is_technologist($product)): ?>
          <section class="premium-section technologist-poles">
            <div class="section-kicker">Atividades presenciais</div>
            <h2>Confirme sua disponibilidade para comparecer a um dos polos.</h2>
            <p>Os cursos de Graduação Tecnológica EAD possuem atividades presenciais. Antes de tentar se matricular, confirme se você consegue comparecer a um dos polos abaixo. Se você mora muito distante de todos eles, o aconselhável é não realizar a matrícula sem antes falar com o IBETP e confirmar a viabilidade.</p>
            <div class="premium-grid pole-grid">
              <div class="premium-card"><strong>SP — Hortolândia</strong><span>R. Zacarias Costa Camargo, 310 — Centro</span></div>
              <div class="premium-card"><strong>PB — João Pessoa</strong><span>R. Jobson de Almeida Sá, 16 — Mangabeira</span></div>
              <div class="premium-card"><strong>MG — Itaúna</strong><span>Av. Jove Soares, 1.367 — Centro</span></div>
              <div class="premium-card"><strong>AC — Rio Branco</strong><span>R. Alvorada, 271 — Bosque</span></div>
              <div class="premium-card"><strong>RS — Centro Histórico</strong><span>Tv. Acilino de Carvalho, 10</span></div>
              <div class="premium-card"><strong>RJ — Duque de Caxias</strong><span>R. José Veríssimo, 173 — Centro</span></div>
              <div class="premium-card"><strong>DF — Taguatinga</strong><span>QNJ Área Especial 08, Lote 01 — Parte B</span></div>
              <div class="premium-card"><strong>PA — Tucuruí</strong><span>Travessa W Um, Quadra 03, Lotes 20 e 24 — Bairro COHAB</span></div>
            </div>
          </section>
          <section class="premium-section">
            <div class="section-kicker">Pagamento do Tecnólogo</div>
            <h2>Matrícula no site, mensalidades pelo AVA</h2>
            <p>Para iniciar sua matrícula no Tecnólogo, você paga apenas a matrícula de R$ 99,90 via Pix diretamente no site do IBETP. O acesso/orientação inicial acontece em até 24 horas úteis após a confirmação do pagamento. As mensalidades de R$ 149,90 são pagas posteriormente no AVA, dentro dos vencimentos informados pela plataforma.</p>
            <div class="premium-steps">
              <div><span>Matrícula: pagamento único de R$ 99,90 via Pix no site do IBETP.</span></div>
              <div><span>Mensalidades: R$ 149,90 pagas diretamente no AVA.</span></div>
              <div><span>O aluno acompanha vencimentos e opções de pagamento dentro da própria plataforma acadêmica.</span></div>
            </div>
          </section>
          <?php endif; ?>
          <?php if ($isSequential): ?>
          <section class="premium-section">
            <div class="section-kicker">Pagamento do Superior Sequencial</div>
            <h2>R$ 699,00 à vista ou parcelado com juros no cartão</h2>
            <p>O Curso Superior Sequencial possui investimento de R$ 699,00 à vista, com possibilidade de parcelamento com juros no cartão de crédito. Antes de avançar, confira a matriz curricular, a documentação necessária e fale com o IBETP se tiver qualquer dúvida sobre matrícula.</p>
            <div class="premium-steps">
              <div><span>Pagamento à vista de R$ 699,00.</span></div>
              <div><span>Parcelamento disponível com juros no cartão de crédito.</span></div>
              <div><span>Atendimento do IBETP para orientação documental e próximos passos.</span></div>
            </div>
          </section>
          <?php endif; ?>
        </div>
      </article>
      <aside class="offer-box product-final-cta">
        <div><small>Pronto para decidir?</small><strong><?= e($product['title']) ?></strong><p><?= e($isCompetencyCertification ? 'Fale com o IBETP para confirmar experiência profissional, documentação, prova, valores e próximos passos da certificação.' : ($isPostTechnical ? 'Fale com o IBETP para confirmar matriz curricular, documentação, investimento, forma de pagamento e próximos passos deste pós-técnico.' : ($isSequential ? 'Fale com o IBETP para confirmar matriz curricular, documentação, investimento, forma de pagamento e próximos passos deste Superior Sequencial.' : 'Fale com o IBETP para confirmar matrícula, documentação, estágio, valores e próximos passos deste curso.'))) ?></p></div>
        <div class="product-final-actions">
          <?php if (product_checkout_enabled($product)): ?>
            <form method="post" action="<?= e(site_url('/checkout')) ?>"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><button class="btn primary"><?= e(product_primary_payment_label($product)) ?></button></form>
          <?php endif; ?>
          <a class="btn primary" href="<?= e(whatsapp_course_url($product)) ?>" target="_blank" rel="noopener">Falar no WhatsApp</a>
          <a class="btn ghost" href="<?= e(site_url('/cursos')) ?>">Ver catálogo</a>
        </div>
      </aside>
    </main><?php
    $productSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Course',
        '@id' => site_url('/produto/' . $product['slug'] . '#course'),
        'name' => $product['title'],
        'description' => excerpt($product['short_description'] ?: $product['description'], 240),
        'url' => site_url('/produto/' . $product['slug']),
        'provider' => ['@id' => site_url('/#organization')],
        'offers' => [
            '@type' => 'Offer',
            'price' => (string)product_effective_price($product),
            'priceCurrency' => $product['currency'] ?: 'BRL',
            'availability' => 'https://schema.org/InStock',
            'url' => site_url('/produto/' . $product['slug'])
        ]
    ];
    $schemaImage = product_image_url($product);
    if ($schemaImage !== '') {
        $productSchema['image'] = $schemaImage;
    }
    $schemas = [$productSchema, breadcrumb_schema(['Início' => site_url('/'), 'Cursos' => site_url('/cursos'), $product['title'] => site_url('/produto/' . $product['slug'])])];
    layout($product['title'] . ' | IBETP', excerpt($product['short_description'] ?: $product['description']), ob_get_clean(), premium_product_image($product), $isCheckoutTestProduct, $schemas); exit;
}

$page = Database::one("SELECT * FROM posts WHERE slug=? AND type='page' AND status='published'", [$path]);
if ($page) {
    $schemas = [[
        '@context' => 'https://schema.org',
        '@type' => 'AboutPage',
        'name' => $page['title'],
        'url' => current_url_clean(),
        'isPartOf' => ['@id' => site_url('/#website')],
        'about' => ['@id' => site_url('/#organization')]
    ], breadcrumb_schema(['Início' => site_url('/'), $page['title'] => current_url_clean()])];
    layout($page['seo_title'] ?: $page['title'], $page['seo_description'] ?: excerpt($page['content']), '<main class="article"><article class="article-body">' . $page['content'] . '</article></main>', $page['featured_image'], (bool)$page['noindex'], $schemas);
    exit;
}

http_response_code(404);
layout('Página não encontrada', 'Página não encontrada.', '<main class="page-hero"><h1>404</h1><p>Esta página não foi encontrada.</p></main>', null, true);









