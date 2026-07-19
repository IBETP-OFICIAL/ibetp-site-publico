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
    if (str_contains($text, 'competência') || str_contains($text, 'competencia') || str_contains($text, 'pós técnico') || str_contains($text, 'pos tecnico')) {
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
    if (product_is_technical_ead($product)) return 'Cursos Técnicos EAD';
    if (str_contains($text, 'competência') || str_contains($text, 'competencia')) return 'Certificação Técnica por Competência';
    if (str_contains($text, 'pós-graduação') || str_contains($text, 'pos-graduacao') || str_contains($text, 'mba')) return 'Pós-graduação e MBA';
    if (str_contains($text, 'pós-técnico') || str_contains($text, 'pos-tecnico')) return 'Pós-técnico';
    if (str_contains($text, 'profissionalizante')) return 'Profissionalizante';
    $label = trim((string)($product['category'] ?? 'Formação IBETP'));
    $label = str_ireplace(['UNICORP FAAO', 'UNICORP', 'SEI', 'UNIDADE PARAÍBA', 'UNIDADE PARÁ', 'CENTRO UNIVERSITÁRIO'], '', $label);
    $label = trim(preg_replace('/\s+/', ' ', str_replace(['()', ' - ', ' / '], ' ', $label)) ?: 'Formação IBETP');
    return $label === '' ? 'Formação IBETP' : $label;
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
        return 'Curso Técnico EAD em 10 parcelas de R$ 99,90. Você paga a 1ª mensalidade via Pix no site do IBETP; o início ocorre em até 24 horas úteis após a confirmação do pagamento. Da 2ª à 10ª parcela, o pagamento acontece diretamente na plataforma AVA, com opções de Pix, cartão ou boleto, sempre dentro do vencimento.';
    }
    if (product_is_technologist($product)) {
        return 'Curso Tecnólogo com matrícula de R$ 99,90 paga via Pix no site do IBETP. O início ocorre em até 24 horas úteis após a confirmação do pagamento. As mensalidades de R$ 149,90 são pagas diretamente no AVA, conforme o vencimento e as opções disponíveis na plataforma.';
    }
    return 'Condições e disponibilidade podem ser confirmadas com a equipe IBETP.';
}

function product_investment_label(array $product): string {
    if (product_is_technical_ead($product)) {
        return '10x de R$ 99,90';
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

function product_academic_profile(array $product): ?array {
    $titleKey = ibetp_slug_key((string)($product['title'] ?? ''));
    $slugKey = ibetp_slug_key((string)($product['slug'] ?? ''));
    $categoryKey = ibetp_slug_key((string)($product['category'] ?? ''));
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
            'modality_note' => 'Curso Técnico EAD com matriz curricular oficial voltada à prevenção, legislação, gestão de riscos e saúde ocupacional.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com 80% online e 20% de atividades presenciais/documentais conforme metodologia aplicável.',
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
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada a projetos, obras, instalações, orçamento e sustentabilidade na construção civil.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP.',
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
            'modality_note' => 'Curso Técnico EAD com matriz oficial voltada a instalações, projetos, automação, máquinas e sistemas elétricos.',
            'presence' => 'Presencialidade e registros acadêmicos seguem as orientações formais do curso, com atividades, documentos acadêmicos e procedimentos administrativos orientados ao aluno pelo IBETP.',
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
    ];
    foreach ($profiles as $profileKey => $profile) {
        if ($titleKey === $profileKey || $slugKey === $profileKey || str_ends_with($slugKey, '-' . $profileKey)) {
            return $profile;
        }
    }
    if (product_is_technical_ead($product)) {
        $withInternshipSlugs = ['seguranca-do-trabalho','secretaria-escolar','eletrotecnica','edificacoes','meio-ambiente','administracao','automacao-industrial','computacao-grafica','contabilidade','desenvolvimento-de-sistemas','eletronica','estetica-e-cosmetologia','guia-de-turismo','informatica','informatica-para-internet','logistica','manutencao-e-suporte-para-informatica','marketing-e-comunicacao','mecanica-industrial','nutricao-e-dietetica','programacao-de-jogos-digitais','recursos-humanos','redes-de-computadores','transacoes-imobiliarias','vendas'];
        $hasInternship = str_contains($categoryKey, 'com-estagio');
        foreach ($withInternshipSlugs as $internshipSlug) {
            if (str_contains($slugKey, $internshipSlug)) {
                $hasInternship = true;
                break;
            }
        }
        $catalogInternship = trim((string)($product['internship'] ?? ''));
        $catalogDuration = trim((string)($product['duration'] ?? ''));
        $catalogWorkload = trim((string)($product['workload'] ?? ''));
        return [
            'duration' => $catalogDuration !== '' ? $catalogDuration : ($hasInternship ? '8 a 14 meses ou conforme informativo oficial do curso' : '8 a 14 meses'),
            'workload' => $catalogWorkload !== '' ? $catalogWorkload : 'Carga horária conforme informativo oficial do curso',
            'modality_note' => 'Curso Técnico EAD com início em até 24 horas úteis após a confirmação do pagamento. A 1ª mensalidade é paga via Pix no site; as demais mensalidades são pagas no AVA, conforme vencimento.',
            'presence' => 'Presencialidade acadêmica conforme metodologia oficial do cadastro: 80% online e 20% de presencialidade quando prevista, cumprida por ATAs, registros acadêmicos e orientações documentais no AVA, sem exposição de contatos de instituições parceiras.',
            'internship' => $catalogInternship !== '' ? $catalogInternship : ($hasInternship ? 'Estágio supervisionado obrigatório conforme matriz oficial da modalidade.' : 'Não possui estágio obrigatório conforme metodologia oficial da categoria.'),
            'source' => 'Informações acadêmicas extraídas do cadastro oficial de cursos disponível ao IBETP. Quando a matriz individual já estiver vinculada, disciplinas e cargas horárias aparecem nesta página.',
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
            'presence' => 'Metodologia acadêmica conforme cadastro oficial do curso: aulas, materiais, avaliações, atividades e presencialidade — quando prevista — são acompanhados pelo AVA e orientados pelo atendimento do IBETP.',
            'internship' => $catalogInternship !== '' ? $catalogInternship : 'Estágio e práticas acadêmicas seguem a matriz oficial do curso e serão confirmados pelo atendimento do IBETP antes da matrícula.',
            'source' => 'Informações acadêmicas extraídas do cadastro oficial de cursos disponível ao IBETP. Quando a matriz individual já estiver vinculada, disciplinas e cargas horárias aparecem nesta página.',
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
        $heading = 'Cursos e produtos IBETP';
    } else {
        $items = Database::all("SELECT * FROM posts WHERE type=? AND status='published' ORDER BY published_at DESC", [$type]);
        $heading = $path === 'glossario' ? 'Glossário profissional' : 'Blog IBETP';
    }
    ob_start(); ?><main><section class="page-hero <?= $path === 'cursos' ? 'courses-hero' : '' ?>"><p class="eyebrow"><?= $path === 'cursos' ? 'Vitrine IBETP' : 'IBETP' ?></p><h1><?= e($heading) ?></h1><p><?= $path === 'cursos' ? 'Escolha sua formação com clareza: catálogo organizado, atendimento consultivo e caminhos de matrícula para avançar com segurança.' : 'Conteúdos organizados, claros e orientados para decisão.' ?></p></section><?php if ($path === 'cursos'): ?><section class="course-search-panel" aria-label="Pesquisar cursos"><div><span>Busca rápida</span><h2>Encontre sua formação pelo nome do curso.</h2><p>Digite uma área, profissão ou modalidade. Os cursos aparecem automaticamente conforme sua pesquisa.</p></div><label for="course-search">Pesquisar curso</label><input id="course-search" type="search" placeholder="Ex.: Administração, Segurança do Trabalho, Logística, Tecnólogo..." autocomplete="off"><small id="course-search-count"><?= count($items) ?> cursos disponíveis</small></section><?php endif; ?><section id="<?= $path === 'cursos' ? 'course-results' : '' ?>" class="cards archive <?= $path === 'cursos' ? 'course-archive' : 'article-cards' ?>">
    <?php foreach ($items as $item): $url = $path === 'cursos' ? '/produto/' . $item['slug'] : '/' . $path . '/' . $item['slug']; ?>
      <?php if ($path === 'cursos'): ?>
        <a class="card course-list-card" href="<?= e(site_url($url)) ?>" data-course-card data-search="<?= e(mb_strtolower($item['title'] . ' ' . product_category_label($item) . ' ' . ($item['category'] ?? '') . ' ' . ($item['short_description'] ?? '') . ' ' . ($item['description'] ?? ''), 'UTF-8')) ?>"><img src="<?= e(absolute_asset(premium_product_image($item))) ?>" alt="<?= e($item['title']) ?>"><div class="card-body"><em><?= e(product_category_label($item)) ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 82)) ?></span><div class="course-meta"><small><?= e(product_investment_label($item)) ?></small><b>Ver detalhes →</b></div></div></a>
      <?php else: ?>
        <a class="card compact-card" href="<?= e(site_url($url)) ?>"><img src="<?= e(absolute_asset(premium_post_image($item))) ?>" alt="<?= e($item['featured_alt'] ?? $item['title']) ?>"><div class="card-body"><em><?= e($path === 'glossario' ? 'Glossário' : 'Blog') ?></em><strong><?= e($item['title']) ?></strong><span><?= e(card_summary($item, 78)) ?></span><b>Ler conteúdo →</b></div></a>
      <?php endif; ?>
    <?php endforeach; ?></section><?php if ($path === 'cursos'): ?><p id="course-empty-state" class="course-empty-state" hidden>Nenhum curso encontrado com esse termo. Tente pesquisar por área, profissão ou fale com o IBETP pelo WhatsApp.</p><script>
      (() => {
        const input = document.getElementById('course-search');
        const cards = [...document.querySelectorAll('[data-course-card]')];
        const count = document.getElementById('course-search-count');
        const empty = document.getElementById('course-empty-state');
        const normalize = value => (value || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        const update = () => {
          const term = normalize(input.value);
          let visible = 0;
          cards.forEach(card => {
            const haystack = normalize(card.dataset.search);
            const show = term === '' || haystack.includes(term);
            card.hidden = !show;
            if (show) visible++;
          });
          count.textContent = visible + (visible === 1 ? ' curso encontrado' : ' cursos encontrados');
          empty.hidden = visible !== 0;
        };
        input.addEventListener('input', update);
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
    if (!$product) { http_response_code(404); layout('Produto não encontrado', 'Produto não encontrado.', '<main><h1>404</h1></main>', null, true); exit; }
    $academic = product_academic_profile($product);
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
        <aside class="product-panel">
          <?php if (premium_product_image($product)): ?><img src="<?= e(absolute_asset(premium_product_image($product))) ?>" alt="<?= e($product['title']) ?>"><?php endif; ?>
          <div class="price-box"><small>Investimento</small><strong><?= e(product_investment_label($product)) ?></strong><span><?= e(product_investment_text($product)) ?></span></div>
        </aside>
      </section>
      <section class="product-trust wrap">
        <div><strong>01</strong><span>Início em até 24 horas úteis após a confirmação do pagamento.</span></div>
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
            <div><small>Investimento</small><strong><?= e(product_investment_label($product)) ?></strong><span><?= e(product_investment_text($product)) ?></span></div>
          </section>
          <?php if ($academic): ?>
          <section class="premium-section academic-official">
            <div class="section-kicker">Grade curricular oficial</div>
            <h2>Matriz curricular, estágio e presencialidade</h2>
            <p><?= e($academic['source']) ?> <?= empty($academic['modules']) ? 'A página informa apenas dados oficiais já presentes no cadastro do curso; grade curricular sugerida não é publicada como se fosse matriz oficial.' : 'A página abaixo apresenta as disciplinas e cargas horárias informadas no documento acadêmico disponível ao IBETP.' ?></p>
            <div class="premium-grid academic-summary">
              <div class="premium-card"><strong>Duração</strong><span><?= e($academic['duration']) ?></span></div>
              <div class="premium-card"><strong>Carga horária</strong><span><?= e($academic['workload']) ?></span></div>
              <div class="premium-card"><strong>Estágio</strong><span><?= e($academic['internship']) ?></span></div>
            </div>
            <div class="info-card official-presence"><strong>Presencialidade e registros</strong><p><?= e($academic['presence']) ?></p></div>
            <?php if (empty($academic['modules'])): ?>
              <div class="info-card academic-pending"><strong>Grade individual em conferência</strong><p>A matriz com disciplinas e cargas horárias será publicada somente após conferência documental do curso específico. Até lá, o atendimento do IBETP confirma os detalhes acadêmicos antes da matrícula.</p></div>
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
          <section class="premium-section">
            <div class="section-kicker">Pagamento do Curso Técnico EAD</div>
            <h2>Primeira mensalidade no site, continuidade pelo AVA</h2>
            <p>Para iniciar sua matrícula, você paga a 1ª mensalidade de R$ 99,90 via Pix diretamente no site do IBETP. O acesso/orientação inicial acontece em até 24 horas úteis após a confirmação do pagamento. As demais 9 mensalidades de R$ 99,90 serão pagas na plataforma AVA, onde o aluno acompanha os vencimentos e escolhe entre Pix, cartão ou boleto.</p>
            <div class="premium-steps">
              <div><span>1ª mensalidade: pagamento via Pix no site do IBETP.</span></div>
              <div><span>Da 2ª à 10ª mensalidade: pagamento diretamente no AVA.</span></div>
              <div><span>Opções no AVA: Pix, cartão ou boleto, sempre dentro do vencimento correspondente.</span></div>
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



