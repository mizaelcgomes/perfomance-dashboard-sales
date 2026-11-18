<?php
declare(strict_types=1);

/**
 * Prova de equivalência da Produtividade: o ProdutividadeService (núcleo) tem que devolver EXATAMENTE o mesmo que o get_produtividade
 * do api.php (código antigo, ainda em uso no admin), para o mesmo escopo e período. Só local: lê o banco de teste e chama o servidor
 * do sistema atual (127.0.0.1:8787) com a sessão de admin de teste.
 *   php -d extension_dir=C:\php\ext -d extension=pdo_mysql tests\lider\equivalencia.php
 */

use App\Core\Autoloader;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database;
use App\Services\ProdutividadeService;

$RAIZ = str_replace('\\', '/', dirname(__DIR__, 2));
require $RAIZ . '/app/Core/Autoloader.php';
Autoloader::registrar($RAIZ . '/app');
Config::carregar($RAIZ, $RAIZ . '/public');

$db = new Database();
$db->testar();
if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Recusado: só roda contra o MySQL local de teste.\n");
    exit(2);
}
$container = new Container();
$container->instancia(Database::class, $db);
$servico = $container->obter(ProdutividadeService::class);

$antigo = static function (array $q): array {
    $ctx = stream_context_create(['http' => ['header' => "Cookie: PHPSESSID=testadmin0001\r\n", 'timeout' => 30]]);
    $bruto = @file_get_contents('http://127.0.0.1:8787/api.php?' . http_build_query(['action' => 'get_produtividade'] + $q), false, $ctx);
    if ($bruto === false) {
        fwrite(STDERR, "O servidor do sistema atual (8787) não respondeu.\n");
        exit(2);
    }
    return json_decode($bruto, true);
};

$hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$inicioMes = substr($hoje, 0, 8) . '01';
$periodos = [
    ['periodo' => 'hoje'], ['periodo' => 'semana'], ['periodo' => 'mes'],
    ['periodo' => 'custom', 'from' => $inicioMes, 'to' => $hoje],
    ['periodo' => 'custom', 'from' => $hoje, 'to' => $inicioMes],                                   // invertido
    ['periodo' => 'custom', 'from' => '2026-01-01', 'to' => '2027-12-31'],                          // passa de 365 dias
    ['periodo' => 'custom', 'from' => '2099-01-01', 'to' => '2099-01-10'],                          // no futuro
    ['periodo' => 'custom', 'from' => 'lixo', 'to' => $hoje],                                       // inválido: vira "hoje"
    ['periodo' => 'qualquer-coisa'],
];
$equipes = array_map('intval', array_column($db->consultar('SELECT id FROM equipes ORDER BY id'), 'id'));
$total = 0;
$ruins = 0;

// O banco de teste costuma estar sem ações registradas: semeia ações CONTROLADAS (marcadas e apagadas no fim) para exercitar ligações,
// follow-ups, leads quentes, leads únicos, variação e alertas, em dias do período e do período anterior.
$MARCA = 'equivalencia-lider';
register_shutdown_function(static function () use ($db, $MARCA): void {
    $db->executar('DELETE FROM acoes_closer WHERE observacao = ?', [$MARCA]);
});
$db->executar('DELETE FROM acoes_closer WHERE observacao = ?', [$MARCA]);
$candidatos = array_map('intval', array_column($db->consultar('SELECT v.id FROM vendedores v WHERE v.deleted_at IS NULL AND v.equipe_id IS NOT NULL ORDER BY v.id LIMIT 8'), 'id'));
$dia = static fn(int $atras, string $hora = '10:00:00'): string => (new DateTimeImmutable($hoje . ' ' . $hora, new DateTimeZone('America/Sao_Paulo')))->modify("-$atras days")->format('Y-m-d H:i:s');
$ins = $db->pdo()->prepare('INSERT INTO acoes_closer (vendedor_id, tipo, telefone, observacao, registrado_em) VALUES (?,?,?,?,?)');
foreach ($candidatos as $i => $vid) {
    $n = 0;
    $acao = static function (string $tipo, string $fone, int $atras) use ($ins, $vid, $MARCA, $dia, &$n): void {
        $ins->execute([$vid, $tipo, $fone, $MARCA, $dia($atras, sprintf('%02d:%02d:00', 8 + intdiv($n, 6), ($n * 7) % 60))]);
        $n++;
    };
    if ($i % 4 === 0) {            // muito ativo hoje (e 20 ligações: dispara "sem contratos" para quem não fechou)
        for ($k = 0; $k < 20; $k++) { $acao('ligacao', '1198800' . str_pad((string) ($i * 10 + $k % 7), 4, '0', STR_PAD_LEFT), 0); }
        $acao('followup', '11988000001', 0); $acao('lead_quente', '11988000002', 0);
    } elseif ($i % 4 === 1) {      // ativo no período anterior e parado agora: dispara "queda"
        for ($k = 0; $k < 12; $k++) { $acao('followup', '1197700' . str_pad((string) $k, 4, '0', STR_PAD_LEFT), 8 + $k % 5); }
        $acao('ligacao', '11977000099', 1);
    } elseif ($i % 4 === 2) {      // mesmo número em ações diferentes (conta 1 lead só)
        $acao('ligacao', '11966660000', 0); $acao('followup', '11966660000', 0); $acao('lead_quente', '11966660000', 1); $acao('ligacao', '11966661111', 2);
    }                              // i % 4 === 3: nenhuma ação (alerta "sem atividade" quando tem conta)
}

$normaliza = static fn($x) => json_encode(json_decode((string) json_encode($x), true));
foreach (array_merge([0], $equipes) as $equipe) {
    $primeiro = $equipe > 0 ? (int) $db->valor('SELECT MIN(id) FROM vendedores WHERE equipe_id = ? AND deleted_at IS NULL', [$equipe]) : 0;
    foreach ([0, $primeiro] as $closer) {
        if ($closer === 0 && $primeiro === 0 && $equipe > 0) {
            continue;
        }
        foreach ($periodos as $p) {
            $q = $p + ($equipe ? ['equipe_id' => $equipe] : []) + ($closer ? ['closer_id' => $closer] : []);
            $velho = $antigo($q);
            $novo = ['success' => true] + $servico->dados($p['periodo'], $p['from'] ?? '', $p['to'] ?? '', $equipe, $closer);
            $total++;
            $visto['acoes'] = max($visto['acoes'] ?? 0, (int) ($novo['kpis']['acoes'] ?? 0));
            $visto['leads'] = max($visto['leads'] ?? 0, (int) ($novo['kpis']['leads_unicos'] ?? 0));
            foreach ($novo['ranking'] as $linha) {
                foreach ($linha['alertas'] as $alerta) {
                    $visto['alertas'][$alerta['k']] = ($visto['alertas'][$alerta['k']] ?? 0) + 1;
                }
                $visto['variacao_nao_nula'] = ($visto['variacao_nao_nula'] ?? 0) + ($linha['variacao'] !== null ? 1 : 0);
            }
            if ($normaliza($velho) !== $normaliza($novo)) {
                $ruins++;
                echo 'DIFERENTE ' . json_encode($q) . "\n";
                foreach (['range', 'kpis', 'prev', 'funil', 'ranking', 'com_conta', 'participantes'] as $campo) {
                    if ($normaliza($velho[$campo] ?? null) !== $normaliza($novo[$campo] ?? null)) {
                        echo "   campo $campo: " . substr($normaliza($velho[$campo] ?? null), 0, 160) . "\n            ≠ " . substr($normaliza($novo[$campo] ?? null), 0, 160) . "\n";
                    }
                }
            }
        }
    }
}
echo "\nO que a comparação enxergou (maior valor entre os casos): " . json_encode($visto ?? [], JSON_UNESCAPED_UNICODE) . "\n";
echo "equivalência da produtividade: $total comparações, " . ($total - $ruins) . " iguais, $ruins diferentes\n";
exit($ruins ? 1 : 0);
