<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Regras puras da meta diária (templates Crescente, Uniforme e Personalizado): distribuir a meta do mês pelos dias, validar os intervalos
 * do template personalizado e comparar séries. Sem acesso a banco. Porte fiel das funções md* do api.php.
 */
final class MetaDiaria
{
    public const TIPOS = ['crescente', 'uniforme', 'personalizado'];

    /** Dias do mês (28 a 31). */
    public static function diasDoMes(int $ano, int $mes): int
    {
        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->format('t');
    }

    /** A chave em app_settings onde fica o template aplicado no mês. */
    public static function chave(int $ano, int $mes): string
    {
        return sprintf('meta_diaria_aplicada_%04d_%02d', $ano, $mes);
    }

    /**
     * Distribui $meta (contratos no mês) pelos dias; devolve [dia => meta]. Soma exata; só o último dia com resto recebe +1.
     * Personalizado ignora $meta e usa os intervalos.
     * @param array<int, array<string, mixed>> $segs
     * @return array<int, int>
     */
    public static function gerar(int $meta, int $dias, string $tipo, array $segs = []): array
    {
        $out = [];
        if ($tipo === 'personalizado') {
            foreach ($segs as $s) {
                for ($d = (int) $s['de']; $d <= (int) $s['ate'] && $d <= $dias; $d++) {
                    if ($d >= 1) {
                        $out[$d] = (int) $s['valor'];
                    }
                }
            }
            ksort($out);
            return $out;
        }
        $w = [];
        for ($d = 1; $d <= $dias; $d++) {
            $w[$d] = $tipo === 'crescente' ? ($d <= 7 ? 0.7 : ($d <= 14 ? 0.9 : ($d <= 21 ? 1.1 : 1.3))) : 1.0;
        }
        $soma = array_sum($w);
        $falta = max(0, $meta);
        foreach ($w as $d => $peso) {
            $out[$d] = (int) floor($meta * $peso / $soma + 1e-9);
            $falta -= $out[$d];
        }
        for ($d = $dias; $d >= 1 && $falta > 0; $d--, $falta--) {
            $out[$d]++;        // sobra distribuída do último dia para trás: nunca quebra a ordem
        }
        return $out;
    }

    /** Mensagem de erro dos intervalos do template personalizado, ou '' se estão certos. @param mixed $segs */
    public static function validarIntervalos($segs, int $dias): string
    {
        if (!is_array($segs) || !$segs) {
            return 'Defina ao menos um intervalo de dias.';
        }
        $usado = [];
        foreach ($segs as $s) {
            $de = (int) ($s['de'] ?? 0);
            $ate = (int) ($s['ate'] ?? 0);
            $v = $s['valor'] ?? null;
            if ($de < 1 || $ate < $de || $ate > $dias) {
                return 'Intervalo inválido: use dias entre 1 e ' . $dias . ', com o início antes do fim.';
            }
            if (!is_numeric($v) || (int) $v < 0 || (int) $v > 999) {
                return 'Informe a meta por dia (0 a 999) em cada intervalo.';
            }
            for ($d = $de; $d <= $ate; $d++) {
                if (isset($usado[$d])) {
                    return 'Os intervalos não podem se sobrepor (dia ' . $d . ').';
                }
                $usado[$d] = true;
            }
        }
        return '';
    }

    /**
     * Meta diária esperada de um closer segundo o template aplicado (a meta do mês é a da época da aplicação, quando houver).
     * @param array<string, mixed>|null $aplicado
     * @return array<int, int>|null
     */
    public static function esperada(?array $aplicado, int $closerId, int $metaMesAtual, int $dias): ?array
    {
        if (!$aplicado) {
            return null;
        }
        if ($aplicado['tipo'] === 'personalizado') {
            return self::gerar(0, $dias, 'personalizado', $aplicado['segs'] ?? []);
        }
        $m = (int) (($aplicado['base'] ?? [])[(string) $closerId] ?? $metaMesAtual);
        return self::gerar($m, $dias, $aplicado['tipo']);
    }

    /** @param array<int, int> $a @param array<int, int> $b */
    public static function iguais(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    /**
     * Sem template aplicado: a distribuição que MAIS closers têm igual (2 ou mais) é a "série atual" do mês; devolve [série|null, nº de closers].
     * @param array<int, array<int, int>> $gravadas [closer_id][dia] => meta
     * @param array<int, int> $ids
     * @return array{0: array<int, int>|null, 1: int}
     */
    public static function comum(array $gravadas, array $ids): array
    {
        $grupos = [];
        foreach ($ids as $id) {
            $s = $gravadas[$id] ?? [];
            if (!$s) {
                continue;
            }
            ksort($s);
            $grupos[json_encode($s)][] = $id;
        }
        $melhor = null;
        $n = 0;
        foreach ($grupos as $k => $g) {
            if (count($g) > $n) {
                $n = count($g);
                $melhor = $k;
            }
        }
        return ($melhor !== null && $n >= 2) ? [json_decode((string) $melhor, true), $n] : [null, 0];
    }
}
