<?php
declare(strict_types=1);

namespace App\Actions\Rotacao;

use App\Core\Resultado;
use App\Repositories\RotacaoRepository;

/**
 * Caso de uso: salvar a rotação do painel da TV (a lista de telas, na ordem, com a duração de cada uma). Só entram itens válidos:
 * um pódio de um dos modos conhecidos ou uma propaganda; as durações têm no mínimo 5 segundos.
 */
final class SalvarRotacao
{
    /** Modos de pódio aceitos (os mesmos do index.php da TV e da tela Rotação do admin). */
    public const MODOS = ['semanal', 'geral', 'equipe_semanal', 'equipe_mensal', 'retro_semana', 'retro_mes'];

    public function __construct(private RotacaoRepository $rotacao)
    {
    }

    /** @param string $json a lista em JSON, como o admin envia no campo "rotation" */
    public function executar(string $json): Resultado
    {
        $itens = json_decode($json, true);
        if (!is_array($itens)) {
            return Resultado::falha('Dados inválidos');
        }
        $validos = [];
        foreach ($itens as $item) {
            if (!isset($item['type'])) {
                continue;
            }
            if ($item['type'] === 'podium' && in_array($item['mode'] ?? '', self::MODOS)) {
                $validos[] = ['type' => 'podium', 'mode' => $item['mode'], 'duration' => max(5, (int) ($item['duration'] ?? 60))];
            } elseif ($item['type'] === 'ad' && isset($item['propaganda_id'])) {
                $validos[] = ['type' => 'ad', 'propaganda_id' => (int) $item['propaganda_id'], 'duration' => max(5, (int) ($item['duration'] ?? 15))];
            }
        }
        $this->rotacao->salvar($validos);
        return Resultado::ok([], 'Rotação salva!');
    }
}
