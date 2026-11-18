<?php
declare(strict_types=1);

/**
 * Prepara o banco LOCAL de teste para experimentar o módulo do líder à mão. Só roda contra 127.0.0.1.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\preparar-local.php [preparar|tirar|devolver|semear|limpar]
 *
 *   preparar (padrão): roda a migração do líder e cria (se não existirem) a "EQUIPE LÍDER TESTE" com dois closers de teste, cada um com
 *                      conta ativa no portal (senha de teste abaixo), e deixa o primeiro como líder da equipe.
 *   tirar:             remove a liderança da equipe (para ver o acesso do líder cair na hora).
 *   devolver:          devolve a liderança ao líder de teste.
 *   semear:            grava ações (marcadas com observacao='e2e-lider-l2') e contratos de hoje/ontem para os dois closers de teste, com
 *                      totais conhecidos, para os testes de tela conferirem números exatos. Devolve os totais esperados.
 *   contratos N Q:     (fase L4) troca os contratos: o closer de teste vende Q contratos há N dias (0 = hoje) e o líder fica com 2 hoje.
 *   limpar:            apaga o que o 'semear' gravou (ações marcadas e os contratos dos dois closers de teste, que só existem aqui).
 * Imprime um JSON com os dados. O banco de teste é recriado por reset-local-db.ps1, que apaga tudo isto.
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Database;
use App\Services\MigracaoLiderService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');

$modo = $argv[1] ?? 'preparar';
$db = new Database();
$db->testar();   // carrega o config.secrets.php (define DB_HOST)
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: este script só roda contra o MySQL local de teste.\n");
    exit(2);
}

const SENHA = 'senha1234';   // senha de TESTE, só existe neste banco local
const EQUIPE = 'EQUIPE LÍDER TESTE';
$pessoas = [
    ['nome' => 'LÍDER TESTE', 'email' => 'lider.teste@teste.com'],
    ['nome' => 'CLOSER TESTE', 'email' => 'closer.teste@teste.com'],
];

$equipeId = (int) $db->valor('SELECT id FROM equipes WHERE nome = ?', [EQUIPE]);
if ($modo === 'preparar') {
    $migracao = (new MigracaoLiderService($db))->executar();
    if ($equipeId === 0) {
        $db->executar('INSERT INTO equipes (nome, cor) VALUES (?, ?)', [EQUIPE, '#d4af37']);
        $equipeId = (int) $db->idInserido();
    }
    foreach ($pessoas as $i => $p) {
        $vid = (int) $db->valor('SELECT id FROM vendedores WHERE nome = ? AND equipe_id = ?', [$p['nome'], $equipeId]);
        if ($vid === 0) {
            $db->executar('INSERT INTO vendedores (equipe_id, nome, metas, meta_mensal, cor) VALUES (?, ?, 10, 0, ?)', [$equipeId, $p['nome'], '#d4af37']);
            $vid = (int) $db->idInserido();
        }
        $pessoas[$i]['vendedor_id'] = $vid;
        if ((int) $db->valor('SELECT COUNT(*) FROM closer_usuarios WHERE email = ?', [$p['email']]) === 0) {
            $db->executar(
                "INSERT INTO closer_usuarios (vendedor_id, equipe_id, nome, email, senha_hash, status, status_em) VALUES (?, ?, ?, ?, ?, 'ativo', NOW())",
                [$vid, $equipeId, $p['nome'], $p['email'], password_hash(SENHA, PASSWORD_DEFAULT)]
            );
        }
    }
    $db->executar('UPDATE equipes SET lider_id = ? WHERE id = ?', [$pessoas[0]['vendedor_id'], $equipeId]);
} else {
    if ($equipeId === 0) {
        fwrite(STDERR, "A equipe de teste não existe: rode primeiro o modo 'preparar'.\n");
        exit(2);
    }
    foreach ($pessoas as $i => $p) {
        $pessoas[$i]['vendedor_id'] = (int) $db->valor('SELECT id FROM vendedores WHERE nome = ? AND equipe_id = ?', [$p['nome'], $equipeId]);
    }
    if ($modo === 'tirar') {
        $db->executar('UPDATE equipes SET lider_id = NULL WHERE id = ?', [$equipeId]);
    } elseif ($modo === 'devolver') {
        $db->executar('UPDATE equipes SET lider_id = ? WHERE id = ?', [$pessoas[0]['vendedor_id'], $equipeId]);
    } elseif ($modo === 'semear' || $modo === 'limpar') {
        $ids = [$pessoas[0]['vendedor_id'], $pessoas[1]['vendedor_id']];
        $db->executar("DELETE FROM acoes_closer WHERE observacao = 'e2e-lider-l2' OR vendedor_id IN (?, ?)", $ids);   // (fase L4: também o que os testes registram pela tela; os dois closers só existem aqui)
        $db->executar('DELETE FROM contratos_historico WHERE vendedor_id IN (?, ?)', $ids);
        // registros da liderança (fase L3) da equipe de teste: a equipe só existe neste banco local
        // fase L4: participantes, notas da agenda e preferências do líder (tabelas criadas pela migração; sem ela não há o que limpar)
        foreach ([
            ['DELETE FROM reuniao_participantes WHERE reuniao_id IN (SELECT id FROM reunioes WHERE equipe_id = ?)', [$equipeId]],
            ['DELETE FROM evento_notas WHERE equipe_id = ?', [$equipeId]],
            ['DELETE FROM lider_preferencias WHERE vendedor_id IN (?, ?)', $ids],
        ] as [$sql, $par]) {
            try { $db->executar($sql, $par); } catch (Throwable $e) { /* tabela ainda não migrada */ }
        }
        foreach (['reunioes', 'feedbacks', 'cobrancas_cadastro', 'revisoes_atendimento'] as $tabela) {
            $db->executar("DELETE FROM $tabela WHERE equipe_id = ?", [$equipeId]);
        }
        $db->executar('DELETE FROM treinamento_presencas WHERE treinamento_id IN (SELECT id FROM treinamentos WHERE equipe_id = ?)', [$equipeId]);
        $db->executar('DELETE FROM treinamentos WHERE equipe_id = ?', [$equipeId]);
        if ($modo === 'semear') {
            $hoje = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
            $ontem = $hoje->modify('-1 day');
            $acao = static function (int $vid, string $tipo, string $fone, DateTimeImmutable $dia, string $hora) use ($db): void {
                $db->executar("INSERT INTO acoes_closer (vendedor_id, tipo, telefone, observacao, registrado_em) VALUES (?, ?, ?, 'e2e-lider-l2', ?)", [$vid, $tipo, $fone, $dia->format('Y-m-d') . ' ' . $hora]);
            };
            // líder: 5 ligações, 3 follow-ups e 2 leads quentes hoje (6 telefones diferentes); closer: 3 ligações e 1 follow-up hoje (3 telefones)
            foreach (['11981110001', '11981110002', '11981110003', '11981110004', '11981110005'] as $i => $fone) {
                $acao($ids[0], 'ligacao', $fone, $hoje, sprintf('09:%02d:00', $i));
            }
            foreach (['11981110001', '11981110002', '11981110003'] as $i => $fone) {
                $acao($ids[0], 'followup', $fone, $hoje, sprintf('10:%02d:00', $i));
            }
            foreach (['11981110001', '11981110006'] as $i => $fone) {
                $acao($ids[0], 'lead_quente', $fone, $hoje, sprintf('11:%02d:00', $i));
            }
            foreach (['11982220001', '11982220002', '11982220003'] as $i => $fone) {
                $acao($ids[1], 'ligacao', $fone, $hoje, sprintf('09:%02d:00', 10 + $i));
            }
            $acao($ids[1], 'followup', '11982220001', $hoje, '10:30:00');
            // dia anterior (entra na comparação "antes" do período Hoje): 2 ligações do closer
            $acao($ids[1], 'ligacao', '11982220009', $ontem, '09:00:00');
            $acao($ids[1], 'ligacao', '11982220008', $ontem, '09:05:00');
            $contrato = static function (int $vid, DateTimeImmutable $dia, int $q) use ($db): void {
                $db->executar('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade)', [$vid, $dia->format('Y-m-d'), $q]);
            };
            $contrato($ids[0], $hoje, 2);
            $contrato($ids[1], $hoje, 1);
            $contrato($ids[1], $ontem, 1);
            // registros da liderança (fase L3) para testar as telas: uma reunião agendada e uma realizada, um feedback, uma cobrança,
            // uma revisão e um treinamento com presença. Marcados com a mesma observação para o 'limpar' apagar.
            $agora = $hoje->format('Y-m-d') . ' ' . date('H:i:s');
            $reu = static function (string $titulo, string $marcada, ?string $realizada, string $status, string $criado) use ($db, $equipeId, $ids): int {
                $db->executar('INSERT INTO reunioes (equipe_id, titulo, data_hora_marcada, data_hora_realizada, status, criado_por, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)', [$equipeId, $titulo, $marcada, $realizada, $status, $ids[0], $criado]);
                return (int) $db->idInserido();
            };
            $reu('Alinhamento da semana (teste)', $hoje->format('Y-m-d') . ' 15:00:00', null, 'agendada', $agora);
            $reu('Revisão de metas (teste)', $ontem->format('Y-m-d') . ' 10:00:00', $ontem->format('Y-m-d') . ' 10:20:00', 'realizada', $ontem->format('Y-m-d') . ' 08:00:00');
            $db->executar("INSERT INTO feedbacks (equipe_id, closer_vendedor_id, lider_vendedor_id, data, assunto, texto, criado_em) VALUES (?, ?, ?, ?, 'Ritmo de ligações (teste)', 'Aumentar as ligações pela manhã e registrar o follow-up à tarde.', ?)", [$equipeId, $ids[1], $ids[0], $hoje->format('Y-m-d'), $agora]);
            $db->executar("INSERT INTO cobrancas_cadastro (equipe_id, closer_vendedor_id, lider_vendedor_id, data, observacao, criado_em) VALUES (?, ?, ?, ?, 'Cobrar o cadastro do cliente do dia (teste)', ?)", [$equipeId, $ids[1], $ids[0], $hoje->format('Y-m-d'), $agora]);
            $db->executar("INSERT INTO revisoes_atendimento (equipe_id, closer_vendedor_id, telefone_cliente, observacao, lider_vendedor_id, data, criado_em) VALUES (?, ?, '11987654321', 'Cliente pediu retorno (teste)', ?, ?, ?)", [$equipeId, $ids[1], $ids[0], $hoje->format('Y-m-d'), $agora]);
            $db->executar("INSERT INTO treinamentos (equipe_id, data_hora, assunto, criado_por, criado_em) VALUES (?, ?, 'Objeções de preço (teste)', ?, ?)", [$equipeId, $hoje->format('Y-m-d') . ' 14:00:00', $ids[0], $agora]);
            $treino = (int) $db->valor("SELECT id FROM treinamentos WHERE assunto = 'Objeções de preço (teste)' AND equipe_id = ?", [$equipeId]);
            $db->executar('INSERT IGNORE INTO treinamento_presencas (treinamento_id, vendedor_id) VALUES (?, ?)', [$treino, $ids[0]]);
        }
    } elseif ($modo === 'contratos') {
        // contratos <dias atrás> <quantidade>: troca os contratos dos dois closers de teste para os testes de alerta. O closer vende <quantidade>
        // contratos há <dias atrás> dias (0 = hoje) e mais nada; o líder (que não gera alerta) fica com 2 hoje.
        $ids = [$pessoas[0]['vendedor_id'], $pessoas[1]['vendedor_id']];
        $atras = max(0, (int) ($argv[2] ?? 0)); $qtd = max(0, (int) ($argv[3] ?? 1));
        $hoje = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $db->executar('DELETE FROM contratos_historico WHERE vendedor_id IN (?, ?)', $ids);
        $db->executar('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?, ?, 2)', [$ids[0], $hoje->format('Y-m-d')]);
        if ($qtd > 0) {
            $db->executar('INSERT INTO contratos_historico (vendedor_id, data_registro, quantidade) VALUES (?, ?, ?)', [$ids[1], $hoje->modify("-$atras day")->format('Y-m-d'), $qtd]);
        }
    } else {
        fwrite(STDERR, "Modo desconhecido: $modo (use preparar, tirar, devolver, semear, contratos ou limpar).\n");
        exit(2);
    }
}

$liderAtual = $db->valor('SELECT lider_id FROM equipes WHERE id = ?', [$equipeId]);
echo json_encode([
    'modo' => $modo,
    'equipe_id' => $equipeId,
    'lider_atual_vendedor_id' => $liderAtual === null ? null : (int) $liderAtual,
    'senha_de_teste' => SENHA,
    'lider' => $pessoas[0],
    'closer' => $pessoas[1],
    'migracao' => $migracao ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
