<?php

declare(strict_types=1);

// =============================================================================
// Segurança HTTP e pipeline de logs de requisição.
//
// Todos os valores são ajustáveis por .env — NUNCA hardcodar no código
// (segredos só no .env).
// =============================================================================

return [

    // --- Headers HTTP de segurança (OWASP Secure Headers) ---------------------
    'headers' => [
        'enabled' => env('SECURITY_HEADERS_ENABLED', true),

        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=()'),

        // CSP básica. Endurecer em produção (remover 'unsafe-inline' com nonces)
        // quando o frontend estiver pronto para isso.
        'content_security_policy' => env(
            'SECURITY_CSP',
            "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; "
            ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ),

        // CSP do super admin (/admin — Filament): o Filament 5 usa expressões
        // Alpine incompatíveis com o build CSP-safe (sem eval) — os modais e
        // ações não abrem sem 'unsafe-eval'. DECISÃO DOCUMENTADA: 'unsafe-eval'
        // é adicionado SOMENTE às rotas /admin* (painel interno, restrito por
        // is_admin + IP allowlist em produção). O painel do usuário e a API
        // seguem com a CSP estrita acima (Livewire em modo csp_safe).
        // Vazio = CSP padrão + 'unsafe-eval' automático no script-src.
        'content_security_policy_admin' => env('SECURITY_CSP_ADMIN', ''),

        // CSP do Horizon (/horizon): a SPA Vue do dashboard usa
        // template in-DOM ('unsafe-eval') e fontes do fonts.bunny.net.
        // Vazio = CSP base + derivações automáticas (ver SecurityHeaders).
        // Aplica-se SOMENTE às rotas do Horizon (is_admin + IP allowlist).
        'content_security_policy_horizon' => env('SECURITY_CSP_HORIZON', ''),

        // CSP da landing alternativa (/v2): a única diferença para a CSP
        // base é o connect-src — essa página lê a contagem de estrelas
        // do repositório direto na API pública do GitHub (client-side, com
        // fallback silencioso). Nenhum script de CDN, nenhum 'unsafe-eval',
        // nenhuma exceção de style/font: só o host de dados, e só nessas
        // rotas. Vazio = CSP base + esse host no connect-src.
        'content_security_policy_landing_alt' => env('SECURITY_CSP_LANDING_ALT', ''),

        // Hosts liberados no connect-src da landing alternativa (/v2).
        'landing_alt_connect_src' => array_filter(explode(',', (string) env('SECURITY_LANDING_ALT_CONNECT_SRC', 'https://api.github.com'))),

        // SUPERFÍCIES com CSP própria: padrões de caminho (a sintaxe de
        // `Request::is()`) em que cada CSP acima vale no lugar da base. O
        // middleware de cabeçalhos não conhece os caminhos do painel, do
        // Horizon nem da landing — eles moram aqui, com os valores de sempre.
        //   admin       → content_security_policy_admin (Filament em /admin).
        //   horizon     → content_security_policy_horizon. Segue o mesmo
        //                 HORIZON_PATH do config/horizon.php; caminho vazio =
        //                 nenhuma rota.
        //   landing_alt → content_security_policy_landing_alt. O produto não
        //                 tem página nessa superfície; quem a usa declara o
        //                 caminho no próprio register (a demonstração do kit
        //                 declara a landing alternativa, /v2).
        'surfaces' => [
            'admin' => ['admin*'],
            'horizon' => (static fn (string $path): array => $path === '' ? [] : [$path.'*'])(trim((string) env('HORIZON_PATH', 'horizon'), '/')),
            'landing_alt' => [],
        ],

        // HSTS: só enviado sob HTTPS e quando habilitado (padrão: produção).
        'hsts_enabled' => env('SECURITY_HSTS_ENABLED', env('APP_ENV') === 'production'),
    ],

    // --- Quem pode dizer QUEM É O CLIENTE (proxies confiáveis) -----------------
    // Atrás de CDN/load balancer, o endereço da conexão é o do proxy: sem esta
    // declaração a allowlist do /admin compara o IP errado, o rate limiting
    // agrupa o mundo num balde só, a trilha de auditoria grava sempre o mesmo
    // `ip` e o HTTPS deixa de ser detectado. A regra inteira e a justificativa
    // das decisões estão em Twstec\Kit\Foundation\Http\TrustedProxies.
    'proxies' => [
        // Proxies em que a aplicação confia para reescrever origem e esquema.
        // Lista separada por vírgula em TRUSTED_PROXIES, aceitando IP exato,
        // faixa CIDR IPv4/IPv6 e três palavras:
        //   `private`     → faixas privadas (RFC 1918/4193) + loopback. É o caso
        //                   do compose do kit: o nginx conversa com o php-fpm
        //                   pela rede interna, com IP que o Docker atribui.
        //   `REMOTE_ADDR` → confia em quem estiver conectando (mais estreito que
        //                   `private` quando o único caminho é o proxy).
        //   `*`           → confia em QUALQUER origem. Opt-out declarado: sem
        //                   valor padrão, ausente de todo arquivo de exemplo, e
        //                   com aviso no log a cada boot em produção.
        //
        // VAZIO (padrão) = nenhum proxy confiável: os headers de encaminhamento
        // são IGNORADOS e `ip()` é o endereço da conexão TCP. Pode estar errado
        // atrás de proxy, mas não é inseguro — ninguém consegue se declarar
        // outra pessoa. Por isso aqui o silêncio NÃO recusa o boot: ao contrário
        // da allowlist do admin, ele já cai para o lado estreito.
        //
        // ATENÇÃO: declarar proxy confiável exige que a BORDA acrescente o
        // endereço real ao X-Forwarded-For (no nginx,
        // `$proxy_add_x_forwarded_for` — já é o que os dois arquivos de nginx do
        // kit fazem). Proxy que só repassa o header do cliente, declarado
        // confiável, permite forjar IP.
        'trusted' => array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))),

        // Obedecer também `X-Forwarded-Host`? Padrão NÃO, ao contrário do padrão
        // do Laravel: esse header reescreve o host da aplicação, e o host monta
        // toda URL absoluta gerada (redirect, link de e-mail, URL assinada).
        // Ligue só quando a aplicação é servida num host interno e publicada em
        // outro — e nesse caso a lista de `hosts` abaixo é a barreira que
        // continua valendo.
        'trust_forwarded_host' => (bool) env('TRUSTED_PROXY_TRUST_FORWARDED_HOST', false),
    ],

    // --- Quais valores de `Host` a aplicação aceita ----------------------------
    // O Laravel monta toda URL absoluta a partir do header `Host`, que é dado do
    // cliente: sem esta validação, `Host: evil.example.com` sai refletido no
    // `Location` de qualquer redirect. A regra inteira está em
    // Twstec\Kit\Foundation\Http\TrustedHosts.
    'hosts' => [
        // Hosts aceitos ALÉM do host da APP_URL (que já entra sozinho, com seus
        // subdomínios). Lista separada por vírgula, host exato
        // (`app.exemplo.com`) ou `*.exemplo.com` para o domínio e seus
        // subdomínios. Sem porta: a comparação é só do host. `localhost`,
        // `127.0.0.1` e `[::1]` são sempre aceitos (sondas e healthchecks
        // internos batem no /up por dentro).
        //
        // VAZIO (padrão) NÃO significa "qualquer host": significa o host da
        // APP_URL. Use esta lista para domínio com e sem `www`, domínio de
        // staging na mesma instalação, host interno do load balancer — ou, em
        // desenvolvimento, o IP do WSL / um `*.test` pelo qual se acesse.
        //
        // A validação vale em TODO ambiente, sem chave para desligar: ver o
        // bloco "vale em todo ambiente" em Twstec\Kit\Foundation\Http\TrustedHosts.
        'trusted' => array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', '')))),
    ],

    // --- Guardas de produção da base do kit ----------------------------------
    // Em APP_ENV=production, o pacote twstec/kit-foundation aplica sozinho, no
    // boot: recusa de subir sem APP_KEY utilizável (processos que servem
    // tráfego ou processam trabalho), HTTPS em toda URL gerada, APP_DEBUG
    // forçado para false e os avisos dos opt-outs de segurança. Ver
    // Twstec\Kit\Foundation\Support\ProductionHardening.
    //
    // Desligar (SECURITY_PRODUCTION_GUARDS=false) é opt-out de segurança: só
    // faz sentido quando a aplicação aplica as mesmas guardas por conta
    // própria, e em produção fica um aviso no log a cada boot.
    'production_guards' => [
        'enabled' => (bool) env('SECURITY_PRODUCTION_GUARDS', true),
    ],

    // --- Segredos que não podem ser inventados nem ter padrão ------------------
    // Em produção, a aplicação RECUSA subir sem uma chave de aplicação
    // utilizável e AVISA no log quando um segredo de infraestrutura está com
    // valor de fachada. A regra e a justificativa das duas respostas diferentes
    // estão em Twstec\Kit\Foundation\Support\CriticalSecrets.
    'secrets' => [
        // Valores reconhecidos como "depois eu troco". A lista existe porque
        // cada instalação tem o seu histórico de placeholder; o padrão já traz
        // os que o próprio kit ofereceu algum dia. A comparação ignora
        // maiúsculas e o prefixo `base64:`, e vale para o valor INTEIRO —
        // `troque-esta-senha` é placeholder, `troque-esta-senha-7f3a` não é.
        // NÃO existe variável para DESLIGAR a recusa da chave: não há
        // instalação de produção legítima cuja chave de criptografia seja um
        // valor público.
        'placeholders' => array_filter(array_map('trim', explode(',', (string) env(
            'SECURITY_SECRETS_PLACEHOLDERS',
            'troque-esta-senha,troque-esta-chave,mude-esta-senha,change-me,changeme,change-this,'
            .'senha,password,secret,segredo,example,exemplo,placeholder,todo,tbd',
        )))),

        // Comandos que fazem o processo PROCESSAR TRABALHO — ler e gravar dado
        // real, como o HTTP faz. Só nestes (e em qualquer processo que atenda
        // requisição) a chave inutilizável RECUSA o boot; nos demais comandos
        // ela apenas avisa, para que `composer install`, `package:discover`,
        // `config:cache`, `vendor:publish` e `migrate` não sejam derrubados —
        // sem `.env`, o Laravel resolve APP_ENV como `production`, então uma
        // recusa larga quebraria o CI, o build da imagem e o primeiro clone.
        //
        // A lista é de quem PROCESSA, e não de quem é liberado, porque uma
        // lista de liberados tem o padrão errado: todo comando de manutenção
        // novo (do Laravel, do Filament, do Horizon) voltaria a quebrar build
        // até alguém lembrar de incluí-lo. Esta lista é definida pelo DEPLOY —
        // são os nomes escritos no docker-compose.prod.yml — e por isso é
        // estável. Aceita `*` no fim para worker próprio (`meu-worker:*`).
        'processing_commands' => array_filter(array_map('trim', explode(',', (string) env(
            'SECURITY_SECRETS_PROCESSING_COMMANDS',
            'queue:work,queue:listen,horizon,horizon:work,horizon:supervisor,'
            .'schedule:run,schedule:work',
        )))),
    ],

    // --- Backup sem criptografia -------------------------------------------------
    // Em produção, o `backup:run` (comando e agendamento) RECUSA rodar quando o
    // zip sairia sem criptografia: BACKUP_ARCHIVE_PASSWORD vazia, com valor de
    // placeholder (o vocabulário de `secrets.placeholders`, acima) ou cifra
    // desligada. Fora de produção ele avisa e segue. A regra e a justificativa
    // estão em Twstec\Kit\Foundation\Backup\BackupEncryption.
    'backup' => [
        // Opt-out consciente: a instalação garante a confidencialidade do
        // backup por outra camada (bucket com criptografia do lado do servidor
        // E acesso restrito). Cada execução grava aviso no log.
        'allow_unencrypted_in_production' => (bool) env('BACKUP_ALLOW_UNENCRYPTED_IN_PRODUCTION', false),
    ],

    // --- E-mail que não é entregue -----------------------------------------------
    // Em produção, os transportes `log` (grava a mensagem inteira — código 2FA,
    // link de redefinição de senha, dados pessoais — no arquivo de log) e
    // `array` (descarta em silêncio) RECUSAM o envio: o job de e-mail falha com
    // a instrução do que configurar. O boot e a instalação não são afetados.
    // A regra e a justificativa estão em Twstec\Kit\Foundation\Mail\NonDeliveringMailers.
    'mail' => [
        // Opt-out consciente, para instalação descartável com APP_ENV=production
        // e sem servidor de e-mail (demo hospedada, ensaio de deploy). Cada boot
        // grava aviso no log enquanto estiver ligado.
        'allow_non_delivering_in_production' => (bool) env('MAIL_ALLOW_NON_DELIVERING_IN_PRODUCTION', false),
    ],

    // --- Redirecionamento "de volta" (open redirect) ---------------------------
    // Toda rota que devolve o usuário ao endereço anterior (alternador de
    // idioma, `back()`, parâmetros `?redirect=`) só pode redirecionar para
    // DENTRO da aplicação — ver Twstec\Kit\Foundation\Http\SafeRedirect.
    'redirects' => [
        // Destino usado quando o endereço pedido é externo, ausente ou
        // malformado. Caminho relativo à raiz ou URL de mesma origem.
        'fallback' => (string) env('SECURITY_REDIRECT_FALLBACK', '/'),

        // Origens ACEITAS além da própria APP_URL (esquema://host[:porta]),
        // separadas por vírgula. Use quando a aplicação atende por mais de um
        // endereço legítimo — domínio com e sem `www`, domínio de staging,
        // APP_URL em http enquanto a borda entrega em https. A origem é
        // comparada de forma estruturada (esquema + host + porta) e NUNCA é
        // lida do header `Host` da requisição, que é dado do cliente.
        'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('SECURITY_REDIRECT_ALLOWED_ORIGINS', '')))),
    ],

    // --- Rate limiting — requisições por minuto -------------------------------
    // Aplicado por usuário autenticado ou, na ausência, por IP.
    'rate_limit' => [
        // Os limitadores nomeados `api` (por chave de API/tenant, com balde
        // próprio para falha de autenticação por IP — ver
        // Twstec\Kit\Foundation\Security\ApiRateLimit) e `sensitive` (login,
        // códigos, recuperação de senha) são registrados pelo pacote. Um
        // RateLimiter::for de mesmo nome na aplicação substitui o do pacote.
        // Desligar (RATE_LIMIT_DEFINE_LIMITERS=false) deixa `throttle:api` e
        // `throttle:sensitive` sem limitador — em produção fica um aviso no log
        // a cada boot.
        'define_limiters' => (bool) env('RATE_LIMIT_DEFINE_LIMITERS', true),

        // Limite da API por minuto (grupo api inteiro). Requisição AUTENTICADA
        // conta pela chave de API (ou pelo tenant — abaixo); rota da API sem
        // autenticação (/api/health) conta por IP. Duas integrações atrás do
        // mesmo NAT têm orçamentos independentes. Ver Twstec\Kit\Foundation\Security\ApiRateLimit.
        'api' => (int) env('RATE_LIMIT_API', 60),

        // Quem é contado no limite autenticado: `key` (cada chave de API tem o
        // seu orçamento — padrão) ou `tenant` (todas as chaves do mesmo dono
        // somam, e criar chaves novas deixa de multiplicar o limite).
        'api_by' => (string) env('RATE_LIMIT_API_BY', 'key'),

        // Falhas de autenticação da API (credencial ausente, inválida, chave
        // revogada/expirada) — dois baldes, ver Twstec\Kit\Foundation\Security\ApiRateLimit.
        //
        // Por IP + CHAVE PÚBLICA apresentada: acima disso, aquela credencial,
        // daquele IP, recebe 429 ANTES de ser verificada — mesmo que a secreta
        // venha certa depois, até a janela passar (é o que impede intercalar a
        // secreta certa para zerar o balde). As outras chaves que saem do
        // mesmo IP não são afetadas: o erro de um vizinho de NAT não derruba
        // a integração de ninguém.
        'api_auth_failures' => (int) env('RATE_LIMIT_API_AUTH_FAILURES', 20),

        // TETO por IP (IPv6 por prefixo), somando todas as chaves públicas —
        // sem ele, inventar uma chave pública por tentativa daria um balde
        // novo a cada requisição. Acima dele, o IP só autentica com chave que
        // JÁ autenticou com sucesso a partir dele (marca abaixo); as demais
        // recebem 429. Bem mais alto que o balde por credencial, e abaixo do
        // teto da borda (RATE_LIMIT_WEB).
        'api_auth_failures_per_ip' => (int) env('RATE_LIMIT_API_AUTH_FAILURES_PER_IP', 100),

        // Janela dos dois limites acima, em segundos.
        'api_auth_failures_decay_seconds' => (int) env('RATE_LIMIT_API_AUTH_FAILURES_DECAY_SECONDS', 60),

        // Por quanto tempo uma chave que autenticou com sucesso a partir de um
        // IP continua "conhecida" dele (passa pelo teto por IP). Renovada na
        // primeira autenticação depois de expirar. Padrão: 7 dias.
        'api_auth_known_client_ttl_seconds' => (int) env('RATE_LIMIT_API_AUTH_KNOWN_CLIENT_TTL_SECONDS', 604800),

        // Rotas sensíveis (login, códigos 2FA/verificação, recuperação de senha):
        // middleware throttle:sensitive.
        'sensitive' => (int) env('RATE_LIMIT_SENSITIVE', 5),

        // TETO POR CLIENTE da borda (Twstec\Kit\Foundation\Security\Middleware\EdgeRateLimit):
        // vale para TODA requisição que chega ao PHP — páginas, updates do
        // Livewire, /admin, /up, API e rotas inexistentes (o flood de 404 de
        // varredura) —, ANTES da varredura de ataque e da trilha em banco, que
        // são o trabalho caro que ele protege. Contado por IP (ou prefixo IPv6,
        // abaixo), porque roda antes da sessão: ainda não se sabe quem é o
        // usuário. Assets servidos pelo nginx (build/, css/, js/, vendor/) não
        // chegam ao PHP e não contam.
        //
        // O padrão foi MEDIDO: a suíte E2E inteira (8 navegadores em paralelo,
        // do mesmo IP, navegando landing, painel e /admin) faz ~140 requisições
        // ao PHP em ~40 s; uma pessoa navegando o painel faz poucas dezenas por
        // minuto. 300/min dá folga de sobra para isso e para algumas abas
        // abertas atrás do mesmo NAT, e corta um flood em poucos segundos.
        // Aumente para NAT grande (empresa, escola, CGNAT de operadora) — não há
        // chave para desligar. Atrás de proxy/CDN, só é por cliente com
        // TRUSTED_PROXIES correto; sem ele, todos caem no balde do proxy.
        'web' => (int) env('RATE_LIMIT_WEB', 300),

        // Janela do teto acima, em segundos.
        'web_decay_seconds' => (int) env('RATE_LIMIT_WEB_DECAY_SECONDS', 60),

        // IPv6: o limite conta por PREFIXO, não por endereço — um único host
        // costuma receber um /64 inteiro e trocaria de endereço a cada
        // requisição para ganhar orçamento novo. Ver Twstec\Kit\Foundation\Security\ClientBucket.
        'ipv6_prefix' => (int) env('RATE_LIMIT_IPV6_PREFIX', 64),
    ],

    // --- Superfícies administrativas (/admin — Filament, /horizon) -------------
    // A barreira de ORIGEM das duas. A regra inteira e a justificativa das
    // decisões estão em Twstec\Kit\Foundation\Security\AdminIpAllowlist; aqui ficam apenas
    // os valores que a operação ajusta.
    'admin' => [
        // Origens permitidas no /admin e no /horizon (allowlist de IP). Lista separada por vírgula em ADMIN_ALLOWED_IPS, aceitando IP
        // exato, faixa CIDR IPv4 (`198.51.100.0/24`) e IPv6 com ou sem prefixo
        // (`2001:db8::1`, `2001:db8::/32`). Espaços em volta de cada item são
        // aparados: `10.0.0.1, 10.0.0.2` é como uma pessoa escreve uma lista, e
        // antes o segundo valor chegava com espaço e era rejeitado em silêncio.
        //
        // EM PRODUÇÃO, LISTA VAZIA NÃO SIGNIFICA MAIS "SEM RESTRIÇÃO": o
        // /admin e o /horizon RECUSAM (403) enquanto a origem permitida for
        // desconhecida. Antes, vazia liberava geral — e vazia era o padrão do
        // docker-compose.prod.yml, então a barreira que este arquivo prometia
        // não existia em nenhuma instalação que não a tivesse preenchido à mão.
        // Fora de produção, vazia continua liberando (conveniência de
        // desenvolvimento: o IP de quem desenvolve é o que o Docker der).
        'allowed_ips' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_ALLOWED_IPS', '')))),

        // ESCAPE HATCH da linha acima: assume a ausência de allowlist NA
        // APLICAÇÃO como decisão declarada, em vez de recusa. Legítimo para
        // quem administra de IP dinâmico e para quem já tem a segunda barreira
        // FORA da aplicação (rede só por VPN, Cloudflare Access, WAF com regra
        // de origem). Não tem valor padrão verdadeiro, não aparece
        // descomentado em nenhum arquivo de exemplo, e enquanto estiver valendo
        // o AppServiceProvider grava aviso no log a cada boot — opt-out de
        // segurança que ninguém vê volta a ser esquecimento. Quando a lista
        // acima tem conteúdo, ela VENCE: esta variável responde só "o que
        // significa uma lista vazia em produção".
        'allow_any_ip' => (bool) env('ADMIN_ALLOW_ANY_IP', false),

        // Configuração trocada SÓ nas páginas do painel administrativo, pelo
        // middleware UseEvalBundleForAdmin (que o painel aplica). Padrão: o
        // Livewire serve o bundle JavaScript normal em vez do CSP-safe, porque
        // o Filament 5 usa expressões Alpine que o build sem eval não executa
        // (a CSP dessas rotas ganha 'unsafe-eval' — ver `headers.surfaces`).
        'runtime_config' => [
            'livewire.csp_safe' => false,
        ],
    ],

    // --- Filtro de ataques (SecurityValidation + AttackDetector) --------------
    // A defesa PRIMÁRIA contra injeção e XSS é o framework: Eloquent/Query
    // Builder com bindings, Blade escapando a saída ({{ }}), validação de cada
    // formulário. O filtro é defesa em profundidade e TELEMETRIA — ele detecta
    // padrões de ataque em query, corpo (formulário/JSON, chaves inclusive),
    // metadados de upload, cabeçalhos e caminho. Ver Twstec\Kit\Foundation\Security\
    // ValidationMode e docs/seguranca.md ("Filtro de ataques").
    //
    // Delegação (vitrine de segurança do /ui): caminhos/componentes cuja
    // detecção é feita pela PRÓPRIA camada da aplicação, que segue em qualquer
    // modo (a linha da trilha sai marcada e neutralizada).
    // A camada delegada roda o MESMO AttackDetector e registra a tentativa
    // (form_submissions com blocked_at + payload inerte — vitrine exibida
    // no super admin). NUNCA adicionar rotas de produção aqui sem
    // implementar a detecção local correspondente.
    'validation' => [
        // MODO do filtro:
        //   `observe` (PADRÃO) — detecta, grava a tentativa na trilha
        //                (request_logs: `attack_type` + payload neutralizado;
        //                evento `security.observed` no log de arquivo) e DEIXA
        //                a requisição seguir. Um filtro por padrão de texto
        //                sempre terá falso positivo; recusar texto legítimo
        //                quebra o produto, e quem impede a injeção é o framework.
        //   `block`    — recusa com 422 genérico e grava a linha BLOQUEADA.
        //                Ligue quando houver superfície fora das defesas do
        //                framework (SQL cru, saída {!! !!}, sistema legado),
        //                durante incidente ativo, ou depois que a telemetria do
        //                `observe` mostrou zero falso positivo no seu tráfego.
        // Valor desconhecido é tratado como `block` (o lado estreito); vazio é
        // `observe`. O teto de inspeção abaixo (413) vale nos DOIS modos.
        'mode' => (string) env('SECURITY_VALIDATION_MODE', 'observe'),

        // Cabeçalhos inspecionados além do corpo, separados por vírgula. O
        // padrão cobre os que a aplicação GRAVA (User-Agent vai para a trilha
        // e para a tabela de sessões) ou USA (Referer decide o destino do
        // `back()`). Os demais cabeçalhos do kit já têm validação própria
        // (Host → TrustHosts; X-Correlation-Id → lista branca; chaves de API →
        // busca por hash). Vazio = só corpo, query e caminho.
        'inspected_headers' => array_filter(array_map('trim', explode(',', (string) env('SECURITY_VALIDATION_INSPECTED_HEADERS', 'User-Agent,Referer')))),

        // POST clássico do form demo (Blade).
        'delegated_paths' => array_filter(explode(',', (string) env('SECURITY_VALIDATION_DELEGATED_PATHS', 'ui/form-demo'))),

        // Componentes Livewire "autodefendidos": quando TODOS os componentes
        // de um /livewire/update estão nesta lista, a detecção é delegada a
        // eles (o form demo Livewire roda o AttackDetector no send()).
        'delegated_components' => array_filter(explode(',', (string) env('SECURITY_VALIDATION_DELEGATED_COMPONENTS', 'contact-form'))),

        // Caminhos (sintaxe de `Request::is()`) do endpoint de atualização de
        // componentes — onde vale a delegação por componente acima. O Livewire
        // 4 ofusca o path do update (`livewire-<hash>/update`), e o painel
        // /admin tem o seu próprio.
        'livewire_paths' => ['livewire/*', 'livewire-*', 'admin/livewire/*'],

        // TETO de bytes que a detecção de ataque inspeciona por requisição
        // (soma de chaves e valores de texto de query + corpo, mais um custo
        // fixo de 8 bytes por item — senão milhões de itens vazios passariam
        // de graça; arquivos enviados contam só pelos metadados — o conteúdo
        // nunca é lido; cabeçalhos e caminho não contam, o servidor web já os
        // limita). A
        // detecção roda ANTES da autenticação, então sem teto qualquer anônimo
        // compraria regex sobre 25 MB de corpo a cada requisição.
        //
        // O excedente NÃO passa sem inspeção (inspecionar só o começo deixaria
        // o ataque no fim do corpo): a requisição é RECUSADA com 413 e gravada
        // como BLOQUEADA (`attack_type` = `payload_too_large`, payload com o
        // tamanho, nunca o conteúdo). 1 MiB cobre com folga formulário, JSON de
        // API e snapshot de Livewire; upload de arquivo não conta. Ver
        // Twstec\Kit\Foundation\Security\Middleware\SecurityValidation.
        'max_inspected_bytes' => (int) env('SECURITY_VALIDATION_MAX_INSPECTED_BYTES', 1048576),
    ],

    // --- Pipeline de logs de requisição ----------------------------------------
    'request_logging' => [
        // Rotas excluídas do log pesado em banco: health checks barulhentos e
        // assets estáticos (em produção o nginx serve direto; em dev local o
        // servidor embutido as deixa chegar ao Laravel). Continuam passando
        // pela validação de segurança, headers e rate limit, e ficam no access
        // log do nginx. Preflights OPTIONS também são excluídos (middleware).
        'excluded_paths' => array_filter(explode(',', (string) env('REQUEST_LOG_EXCLUDED_PATHS', 'up,api/health,favicon.ico,build/*,storage/*,vendor/*'))),

        // Rotas registradas com payload RESUMIDO: os updates genéricos do
        // Livewire (/livewire/update — usado pelo painel e pelo /admin)
        // carregam snapshots serializados enormes e repetitivos; o log guarda
        // apenas os nomes dos componentes envolvidos. Continuam auditadas
        // (método, endpoint, duração, status), só sem o payload bruto.
        'summarized_paths' => array_filter(explode(',', (string) env('REQUEST_LOG_SUMMARIZED_PATHS', 'livewire/*,livewire-*,admin/livewire/*'))),

        // Marcador gravado na coluna `endpoint` quando NENHUMA rota casa com
        // a requisição (404, varredura, método não permitido). O caminho real
        // NUNCA é gravado: ele é dado do usuário e pode carregar segredo
        // posicional (ver Twstec\Kit\Foundation\Logging\EndpointSignature). O marcador
        // recebe a profundidade do caminho pedido (ex.: `[unmatched]:3`), que
        // separa sondagem de raiz de traversal profundo sem revelar conteúdo.
        'unmatched_endpoint' => (string) env('REQUEST_LOG_UNMATCHED_ENDPOINT', '[unmatched]'),

        // Tamanho máximo da correlação informada pelo CLIENTE (header de
        // entrada X-Correlation-Id), gravada em `client_correlation_id`. É
        // dado hostil: além deste limite, o valor é cortado, e fora da lista
        // branca de caracteres nada sobrevive (ver
        // Twstec\Kit\Foundation\Logging\CorrelationId). O teto da coluna é 255 — valores
        // maiores na configuração são limitados a ele.
        'client_correlation_max_length' => (int) env('REQUEST_LOG_CLIENT_CORRELATION_MAX_LENGTH', 128),

        // Contenção do tráfego de VARREDURA na trilha em banco: de
        // cada cliente (IP ou prefixo IPv6), só a PRIMEIRA requisição a rota
        // inexistente (404/405) e a PRIMEIRA recusa do limite da borda (429)
        // por janela vão para `request_logs`; as demais 404/405 ficam só no
        // log de arquivo (`request.unmatched.sampled_out`) e as demais 429 não
        // escrevem nada (o contador do limiter e o access log têm o volume).
        // Rota que EXISTE — autenticada ou não — e tentativa BLOQUEADA pelo
        // SecurityValidation continuam gravadas SEMPRE: nada disso é amostrado.
        // 0 = sem amostragem (toda 404 volta a gerar linha — só com a borda
        // protegida por outro meio). Ver Twstec\Kit\Foundation\Logging\ScanTrafficSampler.
        'scan_sample_window_seconds' => (int) env('REQUEST_LOG_SCAN_SAMPLE_WINDOW_SECONDS', 60),
    ],

];
