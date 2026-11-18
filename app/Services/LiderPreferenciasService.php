<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Relogio;
use App\Core\Resultado;
use App\Repositories\LiderPreferenciaRepository;

/**
 * Preferências do líder (Configurações do portal): a partir de quantos dias sem fechar contrato o closer vira alerta, a partir de quantos dias
 * sem feedback ele vira pendência, e quais tipos de alerta o líder quer ver. Quem nunca salvou nada usa os valores padrão.
 */
final class LiderPreferenciasService
{
    /** @var array<string, string> chave do alerta => nome mostrado nas Configurações */
    public const ALERTAS = [
        'sem_venda' => 'Closer sem fechar contrato há vários dias',
        'meta_batida' => 'Closer que bateu a meta da semana',
        'atividade' => 'Sem atividade, queda de ritmo e muitas ligações sem contrato',
        'sem_feedback' => 'Closer sem feedback há vários dias (pendência)',
        'agenda' => 'Agenda: reuniões para concluir e treinamentos para agendar (pendência)',
    ];
    public const LIMITE_DIAS_SEM_VENDA = [1, 14];
    public const DIAS_SEM_FEEDBACK = [7, 60];

    public function __construct(private LiderPreferenciaRepository $preferencias)
    {
    }

    /** @return array{limite_dias_sem_venda: int, dias_sem_feedback: int, alertas: array<string, bool>} */
    public function obter(int $vendedorId): array
    {
        $r = $this->preferencias->buscar($vendedorId);
        $alertas = array_fill_keys(array_keys(self::ALERTAS), true);
        if ($r !== null && isset($r['alertas']) && $r['alertas'] !== '') {
            $salvo = json_decode((string) $r['alertas'], true);
            foreach (is_array($salvo) ? $salvo : [] as $k => $v) {
                if (array_key_exists($k, $alertas)) {
                    $alertas[$k] = (bool) $v;
                }
            }
        }
        return [
            'limite_dias_sem_venda' => $r === null ? 3 : (int) $r['limite_dias_sem_venda'],
            'dias_sem_feedback' => $r === null ? 14 : (int) $r['dias_sem_feedback'],
            'alertas' => $alertas,
        ];
    }

    /**
     * Grava as preferências. Valores fora da faixa são recusados (nada é gravado); alertas que não vierem ficam como estão.
     * @param array<string, mixed> $entrada
     */
    public function salvar(int $vendedorId, array $entrada): Resultado
    {
        $atual = $this->obter($vendedorId);
        $erros = [];
        $limite = $this->inteiro($entrada['limite_dias_sem_venda'] ?? $atual['limite_dias_sem_venda'], self::LIMITE_DIAS_SEM_VENDA);
        if ($limite === null) {
            $erros['limite_dias_sem_venda'] = 'Use um número de ' . self::LIMITE_DIAS_SEM_VENDA[0] . ' a ' . self::LIMITE_DIAS_SEM_VENDA[1] . ' dias.';
        }
        $feedback = $this->inteiro($entrada['dias_sem_feedback'] ?? $atual['dias_sem_feedback'], self::DIAS_SEM_FEEDBACK);
        if ($feedback === null) {
            $erros['dias_sem_feedback'] = 'Use um número de ' . self::DIAS_SEM_FEEDBACK[0] . ' a ' . self::DIAS_SEM_FEEDBACK[1] . ' dias.';
        }
        if ($erros) {
            return Resultado::falha(reset($erros), $erros);
        }
        $alertas = $atual['alertas'];
        $enviados = $entrada['alertas'] ?? [];
        foreach (is_array($enviados) ? $enviados : [] as $k => $v) {
            if (array_key_exists((string) $k, $alertas)) {
                $alertas[(string) $k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }
        $this->preferencias->salvar($vendedorId, (int) $limite, (int) $feedback, (string) json_encode($alertas), Relogio::agora()->format('Y-m-d H:i:s'));
        return Resultado::ok(['preferencias' => $this->obter($vendedorId)], 'Preferências salvas.');
    }

    /** @param mixed $valor @param array{0: int, 1: int} $faixa */
    private function inteiro($valor, array $faixa): ?int
    {
        if (is_string($valor) && !preg_match('/^\d{1,3}$/', trim($valor))) {
            return null;
        }
        if (!is_numeric($valor) || (float) $valor !== (float) (int) $valor) {
            return null;
        }
        $n = (int) $valor;
        return $n >= $faixa[0] && $n <= $faixa[1] ? $n : null;
    }
}
