<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Limite de tentativas por endereço, em arquivo (storage/rate-limit/), sem tabela nova: "ratelimit:login,5,300"
 * = no máximo 5 chamadas da rota "login" a cada 300 s por IP; depois disso, 429 com Retry-After.
 * Janela fixa: a contagem recomeça quando a janela expira.
 *
 * Se a pasta não for gravável o limite NÃO bloqueia ninguém (não derruba o login por causa de permissão) e o problema
 * fica no log e no /health (storage_gravavel). Confira isso ao subir para o servidor.
 */
final class RateLimit implements Middleware
{
    public function handle(Request $requisicao, callable $proximo, array $argumentos): Response
    {
        $nome = $argumentos[0] ?? 'geral';
        $maximo = max(1, (int) ($argumentos[1] ?? 60));
        $janela = max(1, (int) ($argumentos[2] ?? 60));

        $espera = $this->registrar($nome . '|' . $requisicao->ip(), $maximo, $janela);
        if ($espera > 0) {
            return Response::json(
                ['success' => false, 'message' => 'Muitas tentativas. Tente novamente em ' . (int) ceil($espera / 60) . ' min.'],
                429,
                ['Retry-After' => (string) $espera]
            );
        }
        return $proximo($requisicao);
    }

    /** Conta esta chamada. Devolve 0 se pode passar, ou quantos segundos faltam para a janela acabar. */
    private function registrar(string $chave, int $maximo, int $janela): int
    {
        $pasta = Config::storage() . '/rate-limit';
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            error_log('[nucleo] rate-limit: pasta não gravável');
            return 0;
        }
        $arquivo = $pasta . '/' . hash('sha256', $chave) . '.json';
        $agora = time();

        $f = @fopen($arquivo, 'c+');
        if ($f === false) {
            error_log('[nucleo] rate-limit: não consegui abrir o arquivo de controle');
            return 0;
        }
        try {
            flock($f, LOCK_EX);
            $estado = json_decode((string) stream_get_contents($f), true);
            if (!is_array($estado) || !isset($estado['inicio'], $estado['n']) || $agora - (int) $estado['inicio'] >= $janela) {
                $estado = ['inicio' => $agora, 'n' => 0];
            }
            $estado['n'] = (int) $estado['n'] + 1;
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, (string) json_encode($estado));
            fflush($f);
            $faltam = (int) $estado['inicio'] + $janela - $agora;
            return $estado['n'] > $maximo ? max(1, $faltam) : 0;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
            if (random_int(1, 100) === 1) {
                $this->limpar($pasta, $agora);
            }
        }
    }

    /** De vez em quando apaga controles com mais de um dia, para a pasta não crescer sem fim. */
    private function limpar(string $pasta, int $agora): void
    {
        foreach (glob($pasta . '/*.json') ?: [] as $arquivo) {
            if ($agora - (int) @filemtime($arquivo) > 86400) {
                @unlink($arquivo);
            }
        }
    }
}
