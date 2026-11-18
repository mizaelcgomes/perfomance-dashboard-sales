<?php
declare(strict_types=1);

namespace App\Actions\Cerimonia;

use App\Core\Resultado;
use App\Repositories\CerimoniaRepository;
use App\Services\CerimoniaService;

/** Caso de uso: abrir a cerimônia na TV com quem bateu a meta na semana anterior (sem ninguém, não dispara). */
final class DispararCerimonia
{
    public function __construct(private CerimoniaService $cerimonia, private CerimoniaRepository $repositorio)
    {
    }

    /** @param string|null $inicio início da semana escolhida (null = a semana anterior) */
    public function executar(?string $inicio = null): Resultado
    {
        $semana = $this->cerimonia->semanaEscolhida($inicio);
        if ($semana === null) {
            return Resultado::falha('Semana inválida');
        }
        $resultado = $this->cerimonia->vencedoresDaSemana($semana);
        if (!$resultado['winners']) {
            return Resultado::falha($inicio === null ? 'Ninguém bateu a meta na semana anterior' : 'Ninguém bateu a meta nessa semana');
        }
        $this->repositorio->disparar((string) json_encode($resultado, JSON_UNESCAPED_UNICODE));
        return Resultado::ok(['count' => count($resultado['winners'])], 'Cerimônia disparada');
    }
}
