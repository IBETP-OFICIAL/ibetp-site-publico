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

if ($path === 'admin' || str_starts_with($path, 'admin/')) {
    require __DIR__ . '/../app/admin/panel.php';
    exit;
}

if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $rows = Database::all("SELECT slug, type, updated_at, featured_image AS image, title FROM posts WHERE status='published' AND noindex=0 UNION SELECT slug, 'product' type, updated_at, image, title FROM products WHERE status='active'");
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    echo '<url><loc>' . e(site_url('/')) . '</loc><changefreq>daily</changefreq><priority>1.0</priority></url>';
    foreach ($rows as $row) {
        $prefix = $row['type'] === 'glossary' ? 'glossario' : ($row['type'] === 'product' ? 'produto' : 'blog');
        if ($row['type'] === 'page') $prefix = '';
        $priority = $row['type'] === 'product' ? '0.9' : ($row['type'] === 'page' ? '0.8' : '0.7');
        echo '<url><loc>' . e(site_url($prefix . '/' . $row['slug'])) . '</loc><lastmod>' . e(substr($row['updated_at'], 0, 10)) . '</lastmod><changefreq>weekly</changefreq><priority>' . $priority . '</priority>';
        if (!empty($row['image'])) {
            echo '<image:image><image:loc>' . e(absolute_asset($row['image'])) . '</image:loc><image:title>' . e($row['title']) . '</image:title></image:image>';
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

if ($path === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['product_id'] ?? 0);
    $product = Database::one("SELECT * FROM products WHERE id=? AND status='active' AND checkout_enabled=1", [$id]);
    if (!$product) { http_response_code(404); echo 'Produto indisponível.'; exit; }
    try {
        $preference = MercadoPago::createPreference($product);
        header('Location: ' . $preference['init_point']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Não foi possível iniciar o pagamento. ' . e($e->getMessage());
    }
    exit;
}

if ($path === 'mercado-pago/webhook' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: [];
    $external = $payload['external_reference'] ?? ($_GET['external_reference'] ?? null);
    $status = $payload['status'] ?? ($payload['action'] ?? 'received');
    if ($external) {
        Database::exec("UPDATE payment_orders SET status=?, raw_response=?, updated_at=NOW() WHERE external_reference=?", [$status, $raw, $external]);
    } else {
        Database::exec("INSERT INTO payment_orders (status, raw_response) VALUES (?, ?)", [$status, $raw]);
    }
    http_response_code(200);
    echo 'OK';
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
    $price = (float)($product['price'] ?? 0);
    return $price > 0 ? 'R$ ' . number_format($price, 2, ',', '.') : 'Consultar';
}

function product_checkout_enabled(array $product): bool {
    return (int)($product['checkout_enabled'] ?? 0) === 1;
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
        str_contains($normalized, 'pos-graduacao') ||
        str_contains($normalized, 'mba') ||
        str_contains($normalized, 'especializacao')
    ) {
        return false;
    }
    return str_contains($text, 'técnico') || str_contains($text, 'tecnico');
}

function product_is_technologist(array $product): bool {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    return str_contains($text, 'tecnólogo') || str_contains($text, 'tecnologo') || str_contains($text, 'superior de tecnologia');
}

function product_category_label(array $product): string {
    $title = mb_strtolower((string)($product['title'] ?? ''), 'UTF-8');
    $category = mb_strtolower((string)($product['category'] ?? ''), 'UTF-8');
    $text = $title . ' ' . $category;
    if (product_is_technologist($product)) return 'Tecnólogo EAD';
    if (str_contains($text, 'competência') || str_contains($text, 'competencia')) return 'Certificação Técnica por Competência';
    if (str_contains($text, 'pós-graduação') || str_contains($text, 'pos-graduacao') || str_contains($text, 'mba')) return 'Pós-graduação e MBA';
    if (str_contains($text, 'pós-técnico') || str_contains($text, 'pos-tecnico')) return 'Pós-técnico';
    if (product_is_technical_ead($product)) return 'Cursos Técnicos EAD';
    if (str_contains($text, 'sequencial')) return 'Superior Sequencial';
    if (str_contains($text, 'profissionalizante')) return 'Profissionalizante';
    $label = trim((string)($product['category'] ?? 'Formação IBETP'));
    $label = str_ireplace(['UNICORP FAAO', 'UNICORP', 'SEI', 'UNIDADE PARAÍBA', 'UNIDADE PARÁ', 'CENTRO UNIVERSITÁRIO'], '', $label);
    $label = trim(preg_replace('/\s+/', ' ', str_replace(['()', ' - ', ' / '], ' ', $label)) ?: 'Formação IBETP');
    return $label === '' ? 'Formação IBETP' : $label;
}

function product_area_label(array $product): string {
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


function product_publicly_visible(array $product): bool {
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? ''));
    $categoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
    $text = $titleKey . ' ' . $slugKey . ' ' . $categoryKey;
    if (product_is_technical_ead($product) && !technical_ead_drive_slug_allowed($product)) {
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
        'tecnico-em-eletromecanica',
        'tecnico-em-geoprocessamento',
        'tecnico-em-telecomunicacoes',
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
    $category = strtolower((string)($product['category'] ?? ''));
    $title = strtolower((string)($product['title'] ?? ''));
    $slug = strtolower((string)($product['slug'] ?? ''));
    $explicit = trim((string)($product['image_path'] ?? $product['image'] ?? ''));
    if ($explicit !== '' && str_starts_with($explicit, '/assets/') && file_exists(__DIR__ . $explicit)) {
        return $explicit;
    }
    $slugSpecific = '/assets/produtos/' . ibetp_slug_key((string)($product['slug'] ?? $product['title'] ?? '')) . '.webp';
    if (file_exists(__DIR__ . $slugSpecific)) {
        return $slugSpecific;
    }
    $titleSpecific = '/assets/produtos/' . ibetp_slug_key((string)($product['title'] ?? '')) . '.webp';
    if (file_exists(__DIR__ . $titleSpecific)) {
        return $titleSpecific;
    }
    if ((str_contains($slug, 'secretariado-escolar') || str_contains($title, 'secretaria escolar')) && file_exists(__DIR__ . '/assets/produtos/tecnico-em-secretaria-escolar.webp')) {
        return '/assets/produtos/tecnico-em-secretaria-escolar.webp';
    }
    if ($explicit !== '' && str_starts_with($explicit, '/assets/')) {
        return $explicit;
    }
    if (str_contains($title, 'enfermagem') || str_contains($title, 'saúde') || str_contains($title, 'saude') || str_contains($category, 'saúde') || str_contains($category, 'saude')) {
        return '/assets/setor-saude-hospital-profissionais-premium.png';
    }
    if (str_contains($title, 'petróleo') || str_contains($title, 'petroleo') || str_contains($title, 'gás') || str_contains($title, 'gas') || str_contains($slug, 'petroleo') || str_contains($slug, 'gas')) {
        return '/assets/setor-petroleo-gas-plataforma-offshore-premium.png';
    }
    if (str_contains($title, 'metal') || str_contains($title, 'solda') || str_contains($title, 'caldeir') || str_contains($title, 'mecânica') || str_contains($title, 'mecanica')) {
        return '/assets/setor-metalurgica-caldeiraria-premium.png';
    }
    if (str_contains($title, 'administra') || str_contains($title, 'gestão') || str_contains($title, 'gestao') || str_contains($title, 'logística') || str_contains($title, 'logistica') || str_contains($category, 'gestão') || str_contains($category, 'gestao')) {
        return '/assets/curso-gestao-administracao-premium.png';
    }
    if (str_contains($title, 'edifica') || str_contains($title, 'agrimens') || str_contains($title, 'constru') || str_contains($title, 'defesa civil') || str_contains($category, 'constru')) {
        return '/assets/curso-construcao-agrimensura-edificacoes-premium.png';
    }
    if (str_contains($title, 'agro') || str_contains($title, 'agric') || str_contains($category, 'meio ambiente') || str_contains($category, 'ambient')) {
        return '/assets/curso-agro-meio-ambiente-premium.png';
    }
    if (str_contains($title, 'educação física') || str_contains($title, 'educacao fisica') || str_contains($category, 'educação física') || str_contains($category, 'educacao fisica')) {
        return '/assets/artigo-educacao-fisica-inclusiva-premium.png';
    }
    if (str_contains($category, 'indústria') || str_contains($category, 'industria')) {
        return '/assets/hero-industria-profissionais-tecnicos-premium.png';
    }
    return (string)($product['image'] ?? '/assets/default-course.webp');
}

function product_investment_text(array $product): string {
    if (product_is_technical_ead($product)) {
        return 'Curso Técnico EAD em 12 mensalidades de R$ 99,90. A 1ª mensalidade é paga via Pix no site do IBETP, no ato da matrícula; o início ocorre em até 24 horas úteis após a confirmação do pagamento. As demais mensalidades são enviadas mensalmente por e-mail, WhatsApp ou SMS com link de pagamento e opções de boleto, cartão de crédito e Pix, em até 5 dias antes do vencimento.';
    }
    if (product_is_technologist($product)) {
        return 'Curso Tecnólogo com matrícula de R$ 99,90 paga via Pix no site do IBETP. O início ocorre em até 24 horas úteis após a confirmação do pagamento. As mensalidades de R$ 149,90 são pagas diretamente no AVA, conforme o vencimento e as opções disponíveis na plataforma.';
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
    if (empty($profile['internship'])) {
        $profile['internship'] = 'Estágio supervisionado obrigatório conforme a carga horária indicada na matriz curricular oficial deste curso.';
    }
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
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? ''));
    $categoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
    if ($slugKey === 'tecnico-ead-seguranca-trabalho') {
        $slugKey = 'tecnico-em-seguranca-do-trabalho';
    }
    if ($slugKey === 'tecnico-ead-secretariado-escolar' || str_contains($slugKey, 'secretariado-escolar')) {
        $slugKey = 'tecnico-em-secretaria-escolar';
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
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP. Quando houver exigência de presencialidade documental, ela é organizada por registros acadêmicos e ATAs, conforme orientação recebida no AVA.',
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
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP. Quando houver exigência de presencialidade documental, ela é organizada por registros acadêmicos e ATAs, conforme orientação recebida no AVA.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
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
            'internship' => 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.',
            'source' => 'Grade oficial extraída do informativo do curso.',
            'modules' => [
                ['1º semestre', '320h', [['Introdução ao EAD','20h'], ['Empreendedorismo','60h'], ['Sistemas de Comunicação e Telecomunicações','80h'], ['Comunicação de Dados','80h'], ['Gestão da Tecnologia da Informação e Comunicação','80h']]],
                ['2º semestre', '400h', [['Eletrônica Analógica','80h'], ['Eletrônica Digital','80h'], ['Gestão da Segurança da Informação','80h'], ['Introdução a Redes de Computadores e Protocolos de Comunicação','80h'], ['Tecnologias e Equipamentos Audiovisuais','80h']]],
                ['3º semestre', '480h', [['Projetos de Redes de Computadores','80h'], ['Infraestrutura de Computação em Nuvem','80h'], ['Microcontroladores e Microprocessadores','80h'], ['Segurança do Trabalho e Saúde Ocupacional','80h'], ['Psicologia Aplicada à Comunicação','80h'], ['Tecnologias Digitais de Informação e Comunicação','80h']]],
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
            $presence = 'Metodologia oficial dos Cursos Técnicos EAD: acompanhamento pelo AVA, orientação documental pelo IBETP, sem TCC e com procedimentos acadêmicos formais conforme matriz do curso.';
        }
        $internshipText = $catalogInternship !== '' ? $catalogInternship : ($hasInternship ? 'Estágio supervisionado obrigatório de 240h conforme metodologia oficial da categoria.' : 'Não possui estágio obrigatório conforme metodologia oficial da categoria.');
        if ($isAtaNoInternship) {
            $internshipText = 'Não possui estágio obrigatório e não possui TCC, conforme metodologia oficial da categoria.';
        } elseif ($isAtaWithInternship) {
            $internshipText = 'Possui estágio supervisionado obrigatório de 240h e não possui TCC, conforme metodologia oficial da categoria.';
        } elseif ($isPbTechnical) {
            $internshipText = 'Possui estágio supervisionado obrigatório de 240h e não possui TCC, conforme metodologia oficial dos Cursos Técnicos EAD.';
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
            'presence' => 'Metodologia oficial dos Tecnólogos EAD: 80% EAD e 20% de presencialidade acadêmica, com atividades síncronas mediadas, calendário de avaliações, encontros presenciais no polo credenciado quando previstos e acompanhamento pelo AVA.',
            'internship' => $catalogInternship !== '' ? $catalogInternship : 'Estágio e práticas acadêmicas seguem a matriz oficial do curso. Quando houver exigência específica, o IBETP orienta o aluno antes da matrícula e durante o acompanhamento acadêmico.',
            'source' => 'Informações acadêmicas extraídas da planilha oficial de cursos e dos informativos acadêmicos disponíveis ao IBETP. As disciplinas detalhadas são exibidas quando a grade individual do curso já está vinculada.',
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
  <link rel="preload" href="<?= e(site_url('/assets/site.css?v=premium-20260719-produto-hero-final')) ?>" as="style">
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
  <link rel="stylesheet" href="<?= e(site_url('/assets/site.css?v=premium-20260719-produto-hero-final')) ?>">
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
                <div><span><?= e($type) ?></span><h3><?= e($title) ?></h3><p><?= e($description) ?></p><b>Ver curso →</b></div>
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
          <div class="cards cards-premium article-cards"><?php foreach ($recent as $p): $prefix = $p['type']==='glossary' ? 'glossario' : 'blog'; ?><a class="card compact-card" href="<?= e(site_url('/' . $prefix . '/' . $p['slug'])) ?>"><img src="<?= e(absolute_asset(premium_post_image($p))) ?>" alt="<?= e($p['featured_alt'] ?: $p['title']) ?>"><div class="card-body"><em><?= e($prefix === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($p['title']) ?></strong><span><?= e(card_summary($p, 78)) ?></span><b>Ler conteúdo →</b></div></a><?php endforeach; ?></div>
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
        <div class="cards"><?php foreach ($products as $p): ?><a class="card" href="<?= e(site_url('/produto/' . $p['slug'])) ?>"><img src="<?= e(absolute_asset(premium_product_image($p))) ?>" alt="<?= e($p['title']) ?>"><strong><?= e($p['title']) ?></strong><span><?= e($p['category']) ?></span></a><?php endforeach; ?></div>
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
    ob_start(); ?><main><section class="page-hero"><p class="eyebrow">Busca IBETP</p><h1>Resultados para <?= e($q ?: 'sua pesquisa') ?></h1><form action="<?= e(site_url('/busca')) ?>"><input name="q" value="<?= e($q) ?>" placeholder="Buscar no site"><button class="btn primary">Buscar</button></form></section><section class="cards archive article-cards"><?php foreach ($items as $item): $prefix = $item['type']==='glossary'?'glossario':'blog'; ?><a class="card compact-card" href="<?= e(site_url('/' . $prefix . '/' . $item['slug'])) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['title']) ?>"><div class="card-body"><em><?= e($prefix === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo →</b></div></a><?php endforeach; ?></section></main><?php
    layout('Busca IBETP', 'Pesquise conteúdos, cursos e temas profissionais no site do IBETP.', ob_get_clean(), null, true); exit;
}

if ($path === 'blog' || $path === 'glossario' || $path === 'cursos') {
    $type = $path === 'glossario' ? 'glossary' : 'post';
    if ($path === 'cursos') {
        $items = Database::all("SELECT * FROM products WHERE status='active' ORDER BY title");
        $items = array_values(array_filter($items, 'product_publicly_visible'));
        $items = array_values(array_filter($items, 'technical_ead_drive_slug_allowed'));
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
        <a class="card course-list-card" href="<?= e(site_url($url)) ?>" data-course-card data-modalidade="<?= e(ibetp_slug_key(product_category_label($item))) ?>" data-area="<?= e(ibetp_slug_key(product_area_label($item))) ?>" data-search="<?= e(product_catalog_search_text($item)) ?>"><img src="<?= e(absolute_asset(premium_product_image($item))) ?>" alt="<?= e($item['title']) ?>"><div class="card-body"><em><?= e(product_category_label($item)) ?></em><small class="course-area-pill"><?= e(product_area_label($item)) ?></small><strong><?= e($item['title']) ?></strong><span><?= e(product_catalog_card_summary($item)) ?></span><div class="course-meta"><small><?= e(product_investment_label($item)) ?></small><b>Ver detalhes →</b></div></div></a>
      <?php else: ?>
        <a class="card compact-card" href="<?= e(site_url($url)) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['featured_alt'] ?? $item['title']) ?>"><div class="card-body"><em><?= e($path === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo →</b></div></a>
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
    $product = Database::one("SELECT * FROM products WHERE slug=? AND status='active'", [$m[1]]);
    if (!$product || !product_publicly_visible($product)) { http_response_code(404); layout('Produto não encontrado', 'Produto não encontrado.', '<main><h1>404</h1></main>', null, true); exit; }
    $academic = product_academic_profile($product);
    $hasMandatoryInternship = $academic && str_contains(ibetp_slug_key((string)($academic['internship'] ?? '')), 'estagio-supervisionado-obrigatorio');
    $internshipLabel = $academic ? (string)($academic['internship'] ?? 'Estágio supervisionado obrigatório conforme matriz oficial.') : 'Estágio supervisionado obrigatório conforme matriz oficial.';
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
          <?php if (premium_product_image($product)): ?><img src="<?= e(absolute_asset(premium_product_image($product))) ?>" alt="<?= e($product['title']) ?>"><?php endif; ?>
        </aside>
      </section>
      <section class="product-trust wrap">
        <div><strong>01</strong><span>Início em até 24 horas úteis após a confirmação do pagamento.</span></div>
        <div><strong>02</strong><span>Receba orientação sobre matrícula e próximos passos.</span></div>
        <div><strong>03</strong><span>Escolha com apoio humano e foco profissional.</span></div>
      </section>
      <section class="product-conversion wrap">
        <div class="conversion-copy">
          <p class="section-kicker">Decisão com clareza</p>
          <h2>Uma página feita para você entender o curso antes de pagar.</h2>
          <p>O IBETP organiza as informações essenciais — investimento, início, documentação, grade, estágio e atendimento — para que a matrícula aconteça com segurança e sem surpresa.</p>
        </div>
        <div class="conversion-points">
          <div><strong>O que você confirma aqui</strong><span>Valor, formato de pagamento, carga horária, duração e caminho de atendimento.</span></div>
          <div><strong>O que você confere antes da matrícula</strong><span>Grade curricular, estágio quando obrigatório e documentos acadêmicos relevantes.</span></div>
          <div><strong>Como seguir com segurança</strong><span>Pague a etapa inicial pelo site ou fale com o IBETP para tirar dúvidas antes de avançar.</span></div>
        </div>
      </section>
      <article class="article-body product-detail">
        <div class="premium-product-layout">
          <section class="premium-section">
            <div class="section-kicker">Por que essa formação importa</div>
            <h2>Formação para quem busca atuar com segurança profissional.</h2>
            <p><?= e($product['short_description'] ?: excerpt(strip_tags($product['description']), 260)) ?></p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Antes da matrícula</strong><span>Você entende valores, requisitos e próximos passos antes de avançar.</span></div>
              <div class="premium-card"><strong>Durante o processo</strong><span>O atendimento do IBETP orienta documentação, acesso e etapas acadêmicas.</span></div>
              <div class="premium-card"><strong>Depois da confirmação</strong><span>O início ocorre em até 24 horas úteis após a confirmação do pagamento.</span></div>
            </div>
          </section>
          <section class="premium-price">
            <div><small>Investimento</small><strong><?= e(product_investment_label($product)) ?></strong><span><?= e(product_investment_text($product)) ?></span></div>
          </section>
          <?php if ($academic): ?>
          <section class="premium-section academic-official">
            <div class="section-kicker">Grade curricular oficial</div>
            <h2>Grade curricular</h2>
            <div class="premium-grid academic-summary">
              <div class="premium-card"><strong>Duração</strong><span><?= e($academic['duration']) ?></span></div>
              <div class="premium-card"><strong>Carga horária</strong><span><?= e($academic['workload']) ?></span></div>
              <div class="premium-card"><strong>Estágio</strong><span><?= e($academic['internship']) ?></span></div>
            </div>
            <?php if (!$hasMandatoryInternship && trim((string)($academic['presence'] ?? '')) !== ''): ?>
            <div class="info-card official-presence"><strong>Presencialidade</strong><p><?= e($academic['presence']) ?></p></div>
            <?php endif; ?>
            <?php if (empty($academic['modules'])): ?>
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
          <?php elseif (product_is_technical_ead($product) || product_is_technologist($product)): ?>
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
        </div>
      </article>
      <aside class="offer-box product-final-cta">
        <div><small>Pronto para decidir?</small><strong><?= e($product['title']) ?></strong><p>Fale com o IBETP para confirmar matrícula, documentação, estágio, valores e próximos passos deste curso.</p></div>
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
        'image' => absolute_asset(premium_product_image($product)),
        'provider' => ['@id' => site_url('/#organization')],
        'offers' => [
            '@type' => 'Offer',
            'price' => (string)$product['price'],
            'priceCurrency' => $product['currency'] ?: 'BRL',
            'availability' => 'https://schema.org/InStock',
            'url' => site_url('/produto/' . $product['slug'])
        ]
    ];
    $schemas = [$productSchema, breadcrumb_schema(['Início' => site_url('/'), 'Cursos' => site_url('/cursos'), $product['title'] => site_url('/produto/' . $product['slug'])])];
    layout($product['title'] . ' | IBETP', excerpt($product['short_description'] ?: $product['description']), ob_get_clean(), premium_product_image($product), false, $schemas); exit;
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



