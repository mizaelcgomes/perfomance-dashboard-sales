# Golden tests

Respostas congeladas da `api.php` e da `closer-api.php`, para comparar depois de cada fase da migração MVC e garantir que as regras de negócio não mudaram.

> **Só no ambiente local de teste.** O executor recusa qualquer endereço que não seja `127.0.0.1` ou `localhost`. As chamadas de escrita alteram o banco, por isso o `golden.ps1` recria o banco antes e depois.
> **Não subir a pasta `tests/` para a HostGator.**

## Como usar

```powershell
.\tests\golden\golden.ps1 capture        # grava os snapshots em tests\golden\snapshots\
.\tests\golden\golden.ps1 compare        # compara o código atual com os snapshots
.\tests\golden\golden.ps1 estabilidade   # captura, recria o banco e compara: precisa dar 0 diferenças
.\tests\golden\golden.ps1 cobertura      # toda ação do api.php tem chamada no catálogo?
```

Cada comando recria o banco de teste (`reset-local-db.ps1`), roda e deixa o banco no estado habitual (`-Mix`). Códigos de saída: `0` tudo igual, `1` diferenças ou ação sem cobertura, `2` ambiente fora do ar, `3` snapshot de outro dia.

## O que é gravado

| Grupo | Arquivos | O que mostra |
|---|---|---|
| `semlogin__<ação>` | uma por ação do `api.php` (exceto `login`) | Sem sessão, toda ação de admin responde "Não autenticado" e as públicas devolvem seus dados. É o contrato de segurança. |
| `leitura__<ação>` | todas as leituras, com parâmetros reais do banco | A resposta completa, com a sessão de admin de teste. |
| `escrita_vazia__<ação>` | uma por ação de escrita | O contrato de ERRO: o que a ação responde a um pedido vazio (validação). |
| `escrita__csrf_ausente` | 1 | POST de admin sem token CSRF: responde 419 e não grava. |
| `escrita__login_invalido` | 1 | Login com senha errada. |
| `closer__<passo>` | fluxo completo de um closer de teste | `register`, `login`, `forgot`, `reset`, `resumo`, `acoes`, `acao_criar/get/editar/excluir`, `evolucao`, `contratos`, `logout`. |

Os campos que mudam sozinhos (hora, token) saem da comparação, e textos com relógio viram um valor fixo (ver `IGNORAR_CHAVES` e `NORMALIZAR_TEXTO` no `golden.js`).

## Sucesso das escritas, variantes de estado e comparação ao vivo (fases 3 em diante)

Além do catálogo acima, o `golden.js` tem três recursos que a fase 3 acrescentou:

| Recurso | Como usar | O que faz |
|---|---|---|
| `--sucesso` (já ligado no `golden.ps1`) | `capture` / `compare` | Cenários **em sequência** que congelam o caminho de **sucesso** das escritas migradas (`sucesso__*`): propaganda, rotação, pop-up, cerimônia e música de fundo, com envio de arquivo (multipart) de verdade. Apaga os MP3 de teste no fim. Cada fase que migra escritas acrescenta o cenário delas aqui, **antes** de mexer no código. |
| `diferencial` | `node tests\golden\golden.js diferencial --base http://127.0.0.1:8787/ --base-antiga http://127.0.0.1:8790/ --escritas [--sucesso] [--variantes-tv] [--so "<regex>"]` | Roda as mesmas chamadas no código **antigo** (porta 8790) e no **novo** (8787), cada um num banco recém-criado, e compara resposta a resposta. Não depende do dia do snapshot. |
| `--variantes-tv` | junto do `diferencial` com `--escritas` | 9 **estados do banco** (metas por período, semanas configuradas, férias, ex-closers, desempate por grupo e conversão, "outros" com descrição, investimento, sem Calendário), com ~95 leituras da TV e do Painel refeitas em cada um (`vartv__*`). |

O servidor da linha de base é criado por `.\tests\golden\base-antiga.ps1 criar` (copia o projeto **como está agora**, fora da pasta do projeto, e sobe em `127.0.0.1:8790`). Rode `criar` **antes** de mexer no código da fase; `parar` e `apagar` desfazem.

## Cobertura e limites (leia antes de confiar)

- **Toda ação do `api.php` tem pelo menos uma chamada** (a "sem login"). O `capture` e o `compare` falham se alguém criar uma ação nova e esquecer de cobri-la.
- **Leituras:** cobertura completa, com resposta real.
- **Escritas:** o golden grava o **contrato de erro** (corpo vazio). **O caminho de sucesso de cada escrita ainda não está congelado.** Quem protege esse caminho hoje são as suítes de `run-all.ps1`. Cada fase da migração (2 a 6) deve acrescentar o golden de sucesso das ações que migra, antes de mexer nelas.
- **Sem chamada com login, de propósito** (só a "sem login"):

| Ação | Motivo |
|---|---|
| `upload_bg_music`, `delete_bg_music`, `set_bg_next`, `save_ceremony_settings` | gravam ou apagam arquivos de música no disco |
| `get_csrf` | devolve um token aleatório |
| `login` | tem chamada própria (`escrita__login_invalido`) |

## Comparar em outro dia

O banco de teste usa datas **relativas a hoje** (semana e mês correntes). Um snapshot de ontem não bate com a resposta de hoje, e o `compare` recusa rodar (código de saída 3). Para comparar a migração com o comportamento antigo em outro dia:

1. Restaure o código da linha de base numa pasta à parte (por exemplo, copiando de `backup_archives\`) e suba um servidor para ela no mesmo banco: `php -S 127.0.0.1:8788 -t <pasta-da-linha-de-base>` (com `pdo_mysql`, como no `start-test-env.ps1`).
2. Gere o snapshot de hoje a partir dele: `.\tests\golden\golden.ps1 capture -Dir snapshots-baseline -Base http://127.0.0.1:8788/`
3. Compare o código novo com esse snapshot: `.\tests\golden\golden.ps1 compare -Dir snapshots-baseline`

## Quando uma diferença aparece

1. Leia a linha `DIFF`: ela mostra o primeiro caminho que mudou (`$.data[0].score: 70 ≠ 71`).
2. Se a mudança foi **intencional**, regenere o snapshot com `capture`, no mesmo commit da mudança, e explique o motivo.
3. Se **não foi**, é uma regressão: corrija o código, não o snapshot.
4. Se o campo muda sozinho a cada rodada (hora, aleatório), ele é volátil: confirme com `estabilidade` e acrescente em `IGNORAR_CHAVES`.
