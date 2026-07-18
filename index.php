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

function whatsapp_course_url(array $product): string {
    $title = trim((string)($product['title'] ?? ''));
    $category = trim((string)($product['category'] ?? ''));
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
  <link rel="preload" href="<?= e(site_url('/assets/site.css?v=premium-20260717-1408')) ?>" as="style">
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
  <link rel="stylesheet" href="<?= e(site_url('/assets/site.css?v=premium-20260717-1408')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(site_url('/')) ?>"><img src="<?= e(site_url('/assets/logo-ibetp.webp')) ?>" alt="IBETP - Instituto Brasileiro de Educação Técnica e Profissional"><span>Instituto Brasileiro de Educação Técnica e Profissional</span></a>
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
        $heading = 'Cursos e produtos IBETP';
    } else {
        $items = Database::all("SELECT * FROM posts WHERE type=? AND status='published' ORDER BY published_at DESC", [$type]);
        $heading = $path === 'glossario' ? 'Glossário profissional' : 'Blog IBETP';
    }
    ob_start(); ?><main><section class="page-hero <?= $path === 'cursos' ? 'courses-hero' : '' ?>"><p class="eyebrow"><?= $path === 'cursos' ? 'Vitrine IBETP' : 'IBETP' ?></p><h1><?= e($heading) ?></h1><p><?= $path === 'cursos' ? 'Escolha sua formação com clareza: catálogo organizado, atendimento consultivo e caminhos de matrícula para avançar com segurança.' : 'Conteúdos organizados, claros e orientados para decisão.' ?></p></section><section class="cards archive <?= $path === 'cursos' ? 'course-archive' : 'article-cards' ?>">
    <?php foreach ($items as $item): $url = $path === 'cursos' ? '/produto/' . $item['slug'] : '/' . $path . '/' . $item['slug']; ?>
      <?php if ($path === 'cursos'): ?>
        <a class="card course-list-card" href="<?= e(site_url($url)) ?>"><img src="<?= e(absolute_asset(premium_product_image($item))) ?>" alt="<?= e($item['title']) ?>"><div class="card-body"><em><?= e($item['category'] ?: 'Formação IBETP') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 82)) ?></span><div class="course-meta"><small><?= e(product_price_label($item)) ?></small><b>Ver detalhes →</b></div></div></a>
      <?php else: ?>
        <a class="card compact-card" href="<?= e(site_url($url)) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['featured_alt'] ?? $item['title']) ?>"><div class="card-body"><em><?= e($path === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo →</b></div></a>
      <?php endif; ?>
    <?php endforeach; ?></section></main><?php
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
    if (!$product) { http_response_code(404); layout('Produto não encontrado', 'Produto não encontrado.', '<main><h1>404</h1></main>', null, true); exit; }
    ob_start(); ?><main class="product">
      <section class="product-hero">
        <div class="product-copy">
          <p class="eyebrow"><?= e($product['category'] ?: 'Formação IBETP') ?></p>
          <h1><?= e($product['title']) ?></h1>
          <p class="lead"><?= e($product['short_description'] ?: 'Formação organizada para quem busca avançar com segurança profissional.') ?></p>
          <div class="product-badges"><span>Atendimento consultivo</span><span>Orientação documental</span><span>Compra segura</span></div>
          <div class="product-actions">
            <?php if (product_checkout_enabled($product)): ?>
              <form method="post" action="<?= e(site_url('/checkout')) ?>"><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"><button class="btn primary">Comprar com Mercado Pago</button></form>
            <?php endif; ?>
            <a class="btn <?= product_checkout_enabled($product) ? 'outline' : 'primary' ?>" href="<?= e(whatsapp_course_url($product)) ?>" target="_blank" rel="noopener">Falar no WhatsApp sobre este curso</a>
            <a class="btn outline" href="<?= e(site_url('/cursos')) ?>">Ver catálogo</a>
          </div>
        </div>
        <aside class="product-panel">
          <?php if (premium_product_image($product)): ?><img src="<?= e(absolute_asset(premium_product_image($product))) ?>" alt="<?= e($product['title']) ?>"><?php endif; ?>
          <div class="price-box"><small>Investimento</small><strong><?= e(product_price_label($product)) ?></strong><span>Condições e disponibilidade podem ser confirmadas com a equipe IBETP.</span></div>
        </aside>
      </section>
      <section class="product-trust wrap">
        <div><strong>01</strong><span>Entenda requisitos e documentos antes de iniciar.</span></div>
        <div><strong>02</strong><span>Receba orientação sobre matrícula e próximos passos.</span></div>
        <div><strong>03</strong><span>Escolha com apoio humano e foco profissional.</span></div>
      </section>
      <article class="article-body product-detail">
        <div class="premium-product-layout">
          <section class="premium-section">
            <div class="section-kicker">Detalhes da formação</div>
            <h2><?= e($product['title']) ?></h2>
            <p><?= e($product['short_description'] ?: excerpt(strip_tags($product['description']), 260)) ?></p>
            <div class="premium-grid">
              <div class="premium-card"><strong>Orientação humana</strong><span>Atendimento consultivo para entender sua necessidade antes da matrícula.</span></div>
              <div class="premium-card"><strong>Segurança documental</strong><span>Informações claras sobre requisitos, etapas e condições da formação.</span></div>
              <div class="premium-card"><strong>Foco profissional</strong><span>Formação apresentada com linguagem objetiva para apoiar sua decisão.</span></div>
            </div>
          </section>
          <section class="premium-price">
            <div><small>Investimento</small><strong><?= e(product_price_label($product)) ?></strong><span>Condições e disponibilidade devem ser confirmadas com a equipe IBETP.</span></div>
          </section>
          <section class="premium-section">
            <h2>Como funciona o atendimento</h2>
            <p>O IBETP organiza o processo para que você avance com clareza, evitando decisões apressadas e dúvidas sobre documentação, modalidade e próximos passos.</p>
            <div class="premium-steps">
              <div><span>Você informa seu objetivo profissional e a formação desejada.</span></div>
              <div><span>A equipe orienta sobre requisitos, matrícula, certificação e condições disponíveis.</span></div>
              <div><span>Você segue para a compra ou atendimento pelo canal mais adequado, com segurança.</span></div>
            </div>
          </section>
        </div>
      </article>
      <aside class="offer-box product-final-cta"><div><small>Pronto para decidir?</small><strong><?= e($product['title']) ?></strong><p>Use os botões no topo desta página para comprar com segurança, falar no WhatsApp sobre este curso ou voltar ao catálogo.</p></div></aside>
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



