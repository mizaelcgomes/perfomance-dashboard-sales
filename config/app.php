<?php
/**
 * Configuração do núcleo MVC. Para um ambiente específico (o servidor), crie config/local.php com só o que muda
 * (veja config/local.exemplo.php): ele sobrepõe estes valores e não precisa ir junto com os pacotes de atualização.
 * Variáveis disponíveis aqui: $raiz (pasta que contém app/ e config/) e $publico (pasta com o index.php do núcleo).
 */
return [
    'nome' => 'TOP CLOSERS',

    // true mostra a mensagem do erro interno na resposta (só para depurar). Em produção deixe false: o detalhe vai para o log.
    'debug' => false,

    // Defina uma chave longa e aleatória em config/local.php para ligar. Rotas temporárias de teste da fase 1 (/health e /_teste/*). Só respondem com ?k=<chave_teste>.
    // Para desligar tudo: 'rotas_teste' => false.
    'rotas_teste' => false,
    'chave_teste' => '',

    // Para onde o middleware auth manda quem não está logado (caminhos a partir da raiz do site).
    'login_admin' => '/login',
    'login_closer' => '/closer/login',

    // Pasta de gravação (logs, limite de tentativas). null = <raiz>/storage, fora da pasta pública.
    'storage' => null,
];
