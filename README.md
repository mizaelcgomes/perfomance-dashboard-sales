# Top Closers — Sales Performance Dashboard

Sistema web para acompanhar a produção de uma equipe comercial: contratos por closer, dia e tipo de benefício, metas em três níveis, rankings com desempate configurável, painel de TV em tempo real, relatórios em PDF e portais separados para closers e líderes.

Construído em **PHP 8 puro, sem framework e sem Composer**, com um mini-framework MVC próprio (`app/Core`) e MySQL. Em produção no projeto original; esta é uma versão **anonimizada** para portfólio (dados, marca, fotos, músicas e credenciais foram removidos).

> **Em PT-BR / in English:** a documentação técnica está em português; the code identifiers are in Portuguese by design (domain language).

## Destaques de engenharia

- **MVC em camadas:** Controller → Action/Service → Repository (único lugar com SQL). 33 controllers, 53 actions, 62 services, 26 repositories.
- **Mini-framework próprio:** autoloader PSR-4, router com middlewares em pilha, container com *autowiring* por reflexão, view com escape, sessão, CSRF.
- **Segurança:** PDO com prepared statements reais, CSRF com `hash_equals`, sessão `HttpOnly/SameSite`, limite de tentativas com `flock`, login resistente a enumeração, upload validado por bytes e re-codificado para WebP, escopo de líder decidido no servidor a cada requisição.
- **Regras de negócio testáveis:** pontuação, desempate em cascata, templates de meta diária (soma exata), CAC, semanas quarta→terça, fuso fixo `America/Sao_Paulo`.
- **Migração incremental real:** de um `api.php` procedural (~4 mil linhas) para MVC em 9 fases, provada por uma suíte de testes de contrato ("golden").
- **PDF sem biblioteca:** gerador próprio (A4 paisagem, Helvetica, Flate).
- **TV de alto desempenho:** palco único, animações baratas (transform/opacity, `steps`).

## Funcionalidades

| Área | O que faz |
|---|---|
| TV (pública) | Rotação com pódio, cerimônia de meta batida, propagandas, pop-up, retrospectiva (Hall da Fama), música de fundo |
| Admin | Painel, Rankings, Contratos (feed + matriz de auditoria), Equipes, Produtividade, Relatórios, Metas, Calendário, Propaganda |
| Portal do closer | Ações do dia (ligação, follow-up, lead quente), contratos, ranking, feedbacks recebidos |
| Portal do líder | Dashboard com alertas, equipe, agenda, registros (reuniões, feedbacks, cobranças, revisões, treinamentos), busca Ctrl+K |

## Como rodar localmente

Requisitos: PHP 8.1+ (extensões `pdo_mysql`, `gd`, `mbstring`) e MySQL 8.

```bash
# 1. banco vazio
mysql -u root -e "CREATE DATABASE top_closers_demo CHARACTER SET utf8mb4"

# 2. credenciais (arquivo ignorado pelo Git)
cp config.secrets.exemplo.php config.secrets.php
php -r "echo password_hash('demo1234', PASSWORD_BCRYPT), PHP_EOL;"   # cole o hash em ADMIN_PASS_HASH

# 3. esquema + dados FICTÍCIOS
php database/seed-demo.php

# 4. servidor de desenvolvimento
php -S 127.0.0.1:8787 -t public
```

Abra `http://127.0.0.1:8787/` (TV) e `http://127.0.0.1:8787/login` (admin: usuário `admin`, senha escolhida acima).

## Testes

`php tests/nucleo/unit.php` roda sem banco, mas os testes de middleware, banco e rotas de teste só passam com `config.secrets.php` configurado e um `config/local.php` com `['app' => ['rotas_teste' => true, 'chave_teste' => '<chave hex longa>']]` (veja `config/local.exemplo.php`). As demais suítes (HTTP, E2E com Puppeteer, golden) esperam um ambiente local de teste e estão documentadas nos próprios arquivos.

## Estrutura

```
app/        Core, Http (middlewares e mapa de ações), Controllers, Actions, Services, Repositories, Views
public/     index.php (front controller), .htaccess, assets
config/     app.php, database.php, local.exemplo.php
database/   seed-demo.php (dados fictícios)
tests/      unidade, HTTP, líder, TV, golden
```

## Aviso

Todos os nomes, equipes e números são fictícios. O projeto original atendia a um cliente real; nenhum dado dele está neste repositório.

## Licença e Direitos Autorais

Copyright © 2026 MIZAEL GOMES. Todos os direitos reservados.

Este código e os arquivos contidos neste repositório são de propriedade exclusiva do autor. Não é permitida a cópia, modificação, distribuição, sublicenciamento ou uso comercial ou privado deste material sem autorização prévia e expressa por escrito do titular dos direitos autorais.

