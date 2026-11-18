<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Limite de tentativas do portal do closer (login, cadastro, pedido e uso do token de senha), em arquivo, FORA da pasta pública
 * (storage/rate-limit/closer.json). Mesmas regras do closer-lib.php de antes: cada "balde" (login, login_ip, reg, forgot, reset) conta
 * SÓ as falhas, por identificador (IP, e-mail...) guardado como sha256: nenhum e-mail ou IP em texto puro no arquivo.
 * Sem permissão de escrita, o limite não trava ninguém (o login não cai por causa de permissão de pasta).
 */
final class LimiteDeTentativas
{
    public function __construct(private ?string $arquivo = null)
    {
    }

    private function caminho(): string
    {
        return $this->arquivo ?? Config::storage() . '/rate-limit/closer.json';
    }

    /** Lê, deixa o $fn alterar o estado sob trava e grava de volta. O que passou de 1 dia sem tentativa é descartado. @return mixed */
    private function atualizar(callable $fn)
    {
        $pasta = dirname($this->caminho());
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            error_log('[nucleo] rate-limit do closer: pasta não gravável');
            $vazio = [];
            return $fn($vazio);
        }
        $f = @fopen($this->caminho(), 'c+');
        if ($f === false) {
            error_log('[nucleo] rate-limit do closer: não consegui abrir o arquivo de controle');
            $vazio = [];
            return $fn($vazio);
        }
        try {
            flock($f, LOCK_EX);
            $todos = json_decode((string) stream_get_contents($f), true);
            if (!is_array($todos)) {
                $todos = [];
            }
            $agora = time();
            foreach ($todos as $k => $r) {
                if ($agora - (int) ($r['last'] ?? 0) > 86400) {
                    unset($todos[$k]);
                }
            }
            $resultado = $fn($todos);
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, (string) json_encode($todos));
            fflush($f);
            return $resultado;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }

    private static function chave(string $balde, string $id): string
    {
        return $balde . ':' . substr(hash('sha256', $id), 0, 24);
    }

    /** Segundos restantes de bloqueio (0 = liberado). */
    public function bloqueado(string $balde, string $id, int $maximo, int $janela): int
    {
        $k = self::chave($balde, $id);
        return (int) $this->atualizar(static function (array &$todos) use ($k, $maximo, $janela): int {
            $r = $todos[$k] ?? null;
            if (!$r) {
                return 0;
            }
            $resta = $janela - (time() - (int) $r['last']);
            return ((int) $r['count'] >= $maximo && $resta > 0) ? $resta : 0;
        });
    }

    /** Conta uma falha (a contagem recomeça quando a janela passa). */
    public function falhou(string $balde, string $id, int $janela): void
    {
        $k = self::chave($balde, $id);
        $this->atualizar(static function (array &$todos) use ($k, $janela): void {
            $r = $todos[$k] ?? ['count' => 0, 'last' => 0];
            if (time() - (int) $r['last'] > $janela) {
                $r['count'] = 0;
            }
            $r['count']++;
            $r['last'] = time();
            $todos[$k] = $r;
        });
    }

    public function limpar(string $balde, string $id): void
    {
        $k = self::chave($balde, $id);
        $this->atualizar(static function (array &$todos) use ($k): void {
            unset($todos[$k]);
        });
    }

    /** "Muitas tentativas. Tente novamente em N min." */
    public static function mensagem(int $segundos): string
    {
        return 'Muitas tentativas. Tente novamente em ' . max(1, (int) ceil($segundos / 60)) . ' min.';
    }
}
