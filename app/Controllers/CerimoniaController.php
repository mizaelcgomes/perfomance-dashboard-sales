<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Cerimonia\DispararCerimonia;
use App\Actions\Cerimonia\SalvarConfiguracaoCerimonia;
use App\Core\Request;
use App\Core\Response;
use App\Services\CerimoniaService;

/** Cerimônia de entrega das metas da semana anterior: a TV lê o que foi disparado (público); prévia, configuração e disparo exigem login. */
final class CerimoniaController
{
    public function __construct(
        private CerimoniaService $cerimonia,
        private SalvarConfiguracaoCerimonia $salvarConfiguracao,
        private DispararCerimonia $disparar
    ) {
    }

    /** O último disparo (homenageados, quando foi, música e duração da tela final). @param array<string, string> $p */
    public function ver(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true] + $this->cerimonia->estado());
    }

    /** Quem seria homenageado se disparasse agora, mais a música e a duração configuradas. @param array<string, string> $p */
    public function previa(Request $r, array $p = []): Response
    {
        $previa = $this->cerimonia->previa($this->inicioDaSemana($r->query('semana', '')));
        return $previa === null
            ? Response::json(['success' => false, 'message' => 'Semana inválida'])
            : Response::json(['success' => true] + $previa);
    }

    /** Campos: final_duration (3 a 300 s) e musica (arquivo MP3, opcional). @param array<string, string> $p */
    public function configurar(Request $r, array $p = []): Response
    {
        return $this->salvarConfiguracao->executar((int) $r->corpo('final_duration', 15), $r->arquivo('musica'))->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function disparar(Request $r, array $p = []): Response
    {
        return $this->disparar->executar($this->inicioDaSemana($r->corpo('semana', '')))->paraResponse(200);
    }

    /** O início da semana escolhida, ou null (= a semana anterior) quando não veio nenhum. */
    private function inicioDaSemana(mixed $valor): ?string
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';
        return $texto === '' ? null : $texto;
    }
}
