<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Actions\Tipo\ExcluirTipoCustom;
use App\Actions\Tipo\SalvarTipoCustom;
use App\Actions\Tipo\SalvarTipoFixo;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TipoBeneficioRepository;
use App\Services\TiposBeneficioService;

/**
 * Tipos de benefício (aba Metas & Regras → Tipos de Benefício). Cor, ícone e ordem valem em TODA a interface (Contratos, Painel, Portal do
 * Closer…). A lista crua dos personalizados é PÚBLICA, como sempre foi no api.php; a configuração e as gravações exigem o login do admin.
 * Falhas de validação respondem com HTTP 200 e {success:false, message}.
 */
final class TipoBeneficioController
{
    public function __construct(
        private TipoBeneficioRepository $tipos,
        private TiposBeneficioService $servico,
        private SalvarTipoCustom $salvarCustom,
        private SalvarTipoFixo $salvarFixo,
        private ExcluirTipoCustom $excluirCustom
    ) {
    }

    /** Os personalizados, como estão na tabela (leitura pública). @param array<string, string> $p */
    public function personalizados(Request $r, array $p = []): Response
    {
        return Response::json(['success' => true, 'data' => $this->tipos->todos()]);
    }

    /** Fixos e personalizados com cor, ícone e posição, mais a lista de ícones que dá para escolher. @param array<string, string> $p */
    public function configuracao(Request $r, array $p = []): Response
    {
        $fixos = [];
        $custom = [];
        foreach ($this->servico->estilo() as $e) {
            if ($e['fixo']) {
                $fixos[] = $e;
            } else {
                $custom[] = $e;
            }
        }
        return Response::json(['success' => true, 'fixos' => $fixos, 'custom' => $custom, 'icones' => TiposBeneficioService::ICONES]);
    }

    /** @param array<string, string> $p */
    public function salvarCustom(Request $r, array $p = []): Response
    {
        return $this->salvarCustom->executar($r->corpo())->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function salvarFixo(Request $r, array $p = []): Response
    {
        return $this->salvarFixo->executar($r->corpo())->paraResponse(200);
    }

    /** @param array<string, string> $p */
    public function excluirCustom(Request $r, array $p = []): Response
    {
        return $this->excluirCustom->executar($r->corpo('id', 0))->paraResponse(200);
    }
}
